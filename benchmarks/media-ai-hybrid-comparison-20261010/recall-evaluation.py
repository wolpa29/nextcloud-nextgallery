"""Recall-first follow-up: all labeled matches, not merely one correct top hit.
Reuses original real inference. No new model calls, private media or pipeline changes.
"""
import hashlib
import json
from pathlib import Path
ROOT=Path(__file__).resolve().parent

def evaluate():
    data=json.loads((ROOT/'dataset.json').read_text())
    rankings=json.loads((ROOT/'results.json').read_text())['primary']['ranks']
    queries=data['queries']
    groups={'content':[q for q in queries if q['relevant'] and q['category'] not in ('OCR exakt','Dateiname')],
            'content_and_ocr':[q for q in queries if q['relevant'] and q['category']!='Dateiname'],
            'multiple_matches':[q for q in queries if q['relevant'] and q['category'] not in ('OCR exakt','Dateiname') and len(q['relevant'])>1],
            'ocr':[q for q in queries if q['category']=='OCR exakt'],
            'filename_control':[q for q in queries if q['category']=='Dateiname']}
    output={'priority':'Minimize missing labeled matches; additional suggestions are acceptable. Top1 is secondary.',
            'query_and_labels_unchanged':True,'new_inference':False,
            'inputs_sha256':{name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in ('dataset.json','results.json')},
            'methods':{}}
    for method in ('joint','image_text'):
        output['methods'][method]={}
        for group,qs in groups.items():
            rows=[]
            for depth in (1,3,5,10):
                found_total=0; desired_total=sum(len(q['relevant']) for q in qs)
                full=0; any_match=0; recall_sum=0; suggestions=0; missing=[]
                for q in qs:
                    wanted=set(q['relevant']);shown=rankings[method][q['id']][:depth]
                    found=wanted&set(shown)
                    found_total+=len(found);suggestions+=len(shown)
                    full+=found==wanted;any_match+=bool(found);recall_sum+=len(found)/len(wanted)
                    if found!=wanted:missing.append({'id':q['id'],'query':q['text'],'missing':sorted(wanted-found)})
                assert found_total<=desired_total
                rows.append({'depth':depth,'queries':len(qs),'queries_with_all_labeled_matches':full,
                             'queries_with_any_labeled_match':any_match,'labeled_matches_found':found_total,
                             'labeled_matches_total':desired_total,'micro_recall':found_total/desired_total,
                             'macro_recall':recall_sum/len(qs),
                             'labeled_precision':found_total/suggestions,'average_suggestions':suggestions/len(qs),
                             'missing':missing})
            output['methods'][method][group]=rows
        output['methods'][method]['absent_queries_nonempty']=sum(bool(rankings[method][q['id']]) for q in queries if not q['relevant'])
    # Known multi-label fixture: demonstrates why any-match Hit@k is not Recall@k.
    wanted={'a','b','c'};shown=['a','d','e']
    assert bool(wanted&set(shown)) and not wanted<=set(shown) and len(wanted&set(shown))/len(wanted)==1/3
    for method in output['methods']:
        rows=output['methods'][method]['content']
        assert all(a['labeled_matches_found']<=b['labeled_matches_found'] for a,b in zip(rows,rows[1:]))
        assert rows[2]['micro_recall']==rows[2]['macro_recall']==1
        for row in rows:
            assert row['queries_with_all_labeled_matches']==row['queries']-len(row['missing'])
    (ROOT/'recall-results.json').write_text(json.dumps(output,ensure_ascii=False,indent=2)+'\n')
    report=['# Galerie-Suche: möglichst kein passendes Bild übersehen', '',
            'Nutzerpriorität: lieber zusätzliche Vorschläge als ein fehlendes gesuchtes Bild. Deshalb ist vollständige Trefferabdeckung (Recall) jetzt wichtiger als der erste Treffer oder eine besonders kurze, saubere Liste.', '',
            'Verglichen werden A: reine Vektorsuche mit gemeinsamem Bild/Beschreibungs-Vektor und B: Bild-Vektorsuche kombiniert mit der normalen Textsuche in Beschreibung, Tags, Objekten und OCR. Die echten Gemma-Vektoren und SQL-Ergebnisse bleiben unverändert.', '',
            '## Hauptwertung: alle markierten Treffer je Inhaltsfrage', '',
            '| Vorschläge pro Frage | A: alle passenden Bilder enthalten | B: alle passenden Bilder enthalten |',
            '|---|---:|---:|']
    for a,b in zip(output['methods']['joint']['content'],output['methods']['image_text']['content']):
        report.append(f"| {a['depth']} | {a['queries_with_all_labeled_matches']}/{a['queries']} | {b['queries_with_all_labeled_matches']}/{b['queries']} |")
    report+=['', '„Alle passenden Bilder“ bezeichnet hier alle vorab markierten Solltreffer. Bei „Fahrrad“ müssen alle drei passenden Fahrradbilder enthalten sein; ein einziger richtiger Fahrradtreffer genügt nicht. Vier der 42 Inhaltsfragen haben mehrere Solltreffer.', '',
             '## Mehrfachtreffer ausdrücklich geprüft', '',
             '| Frage | Solltreffer | A: Ränge der Solltreffer | B: Ränge der Solltreffer |', '|---|---:|---|---|']
    for q in groups['multiple_matches']:
        positions={method:sorted(rankings[method][q['id']].index(k)+1 for k in q['relevant']) for method in ('joint','image_text')}
        report.append(f"| {q['text']} | {len(q['relevant'])} | {', '.join(map(str,positions['joint']))} | {', '.join(map(str,positions['image_text']))} |")
    report+=['', '## Konsequenz für die Galerie', '',
             '- Bei fünf Vorschlägen decken beide Verfahren sämtliche markierten passenden Bilder für alle 42 Inhaltsfragen ab. Für die bevorzugte großzügige Trefferauswahl ergibt sich hier kein Unterschied; A erreicht dieselbe Abdeckung bereits unter den ersten drei.',
             '- Große Kandidatenmenge abrufen, nach Ähnlichkeit sortieren und weitere Vorschläge nachladen. Ein großzügiges Abruflimit darf nicht als „garantiert vollständig“ gelten.',
             '- Eine hohe feste Ähnlichkeitsschwelle könnte das gesuchte Bild wegfiltern. Für die normale Inhaltssuche zuerst möglichst viele passende Bilder finden; weniger ähnliche Vorschläge können anschließend weiter unten angezeigt werden.',
             '- Exakte OCR-Werte und Dateinamen sind zusätzliche Suchwege. Fehlende lexikalische Treffer dürfen semantische Kandidaten nicht automatisch ausschließen. Dateinamen sind nicht Teil der hier geprüften Embedding- oder Metadaten-Textfelder.',
             '- Hauptkennzahlen für weitere Tests: Anteil der Fragen mit allen passenden Bildern sowie Recall@10/@20/@50; zusätzlich passende Bilder, die in der gesamten Kandidatenmenge fehlen. MRR/Top1 und der Anteil unpassender Vorschläge sind ergänzende Kennzahlen.', '',
             '## Grenzen', '',
             '- 20 künstliche Bilder, 42 Inhaltsfragen und nur vier Fragen mit mehreren markierten passenden Bildern. Kein großer neuer Testbestand, keine neue Inferenz, keine Prüfung von Qwen, Videos oder einer produktiven Galerie.',
             '- Die vorher festgelegten Labels bleiben unverändert. Eine zusätzliche, großzügigere Interpretation von „passend“ wurde hier nicht nachträglich zur Verbesserung der Messwerte eingesetzt.',
             '- Alle 20 Bilder zurückzugeben würde auf diesem winzigen Bestand triviale Vollständigkeit ergeben. Deshalb werden insbesondere drei und fünf Vorschläge betrachtet; die Resultate lassen sich nicht auf eine Galerie mit Tausenden Bildern übertragen.',
             '- Eine garantierte vollständige Trefferliste kann ein Embedding-Modell auch mit großzügiger Anzeige nicht versprechen. Vier Abfragen nach nicht vorhandenen Inhalten liefern bei beiden Verfahren trotzdem ähnliche Vorschläge. Das entspricht breiter Kandidatensuche, nicht einem bestätigten inhaltlichen Treffer.', '']
    (ROOT/'recall-report.md').write_text('\n'.join(report))
    print(json.dumps({m:output['methods'][m]['content'] for m in output['methods']},ensure_ascii=False,indent=2))

if __name__=='__main__':evaluate()
