"""Deterministic artificial documents/diagrams; photo prompts and qrels frozen first."""
import json, math, random
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont
ROOT=Path(__file__).resolve().parent
OUT=ROOT/'images';OUT.mkdir(exist_ok=True)
FONT='/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
photos=[('beach_dog','IMG_0001.png','Hund mit rotem Ball im Meer'),('winter_hiker','IMG_0002.png','Wanderer im Schnee'),('summer_lake','IMG_0003.png','Picknick am See mit gelbem Kanu'),('bicycle_park','Fahrrad_Park_0004.png','Rotes Fahrrad im Park'),('robot_vacuum','IMG_0005.png','Weisser Saugroboter und schwarze Katze'),('rain_umbrella','IMG_0006.png','Gelber Regenschirm im Regen')]
docs=[dict(key=k,path=p,label=l,origin='imagegen') for k,p,l in photos]
def add(k,name,label,image,origin='procedural'):
    image.save(OUT/name); docs.append(dict(key=k,path=name,label=label,origin=origin))
def page(lines,header='#174463',bg='white'):
    im=Image.new('RGB',(1100,1000),bg);d=ImageDraw.Draw(im)
    d.rectangle((0,0,1100,150),fill=header)
    d.text((50,45),lines[0],font=ImageFont.truetype(FONT,48),fill='white')
    for i,s in enumerate(lines[1:]):d.text((55,205+i*110),s,font=ImageFont.truetype(FONT,39),fill='#151515')
    return im
for k,num,business,amount in [('invoice_bike','12345','Fahrradwerkstatt','42,50'),('invoice_coffee','12346','Kaffeehaus','18,90')]:
    add(k,{'invoice_bike':'SCAN_0007.png','invoice_coffee':'SCAN_0008.png'}[k],business+' Rechnung '+num,
        page(['RECHNUNG '+num,business,'Datum: 09.10.2026','Betrag: '+amount+' EUR','Vielen Dank fuer Ihren Einkauf']))
add('chat_beach','CHAT_0009.png','Strandtermin 17:30 Uhr',page(['Chat - Testansicht','Treffen am Strand um 17:30 Uhr.','Bitte bring das rote Fahrrad mit.'],bg='#eef4f5'))
add('chat_park','CHAT_0010.png','Parktermin 09:15 Uhr',page(['Chat - Testansicht','Treffen im Park um 09:15 Uhr.','Bitte bring den blauen Rucksack mit.'],bg='#eef4f5'))
add('menu_pizza','Speisekarte_0011.png','Pizza Speisekarte',page(['SPEISEKARTE','Pizza Margherita 9,50 EUR','Pizza Funghi 11,00 EUR','Apfelsaft 3,20 EUR']))
add('menu_coffee','SCAN_0012.png','Kaffee Speisekarte',page(['CAFE KARTE','Cappuccino 3,80 EUR','Espresso 2,50 EUR','Apfelstrudel 5,20 EUR']))
add('exit_sign','IMG_0013.png','Notausgang Schild',page(['NOTAUSGANG','Bitte freihalten'],header='#087d39',bg='#eaf5ed'))
add('train_ticket','Bahn_Ticket_0014.png','Zugticket Wien Graz',page(['FAHRKARTE','Wien Hbf -> Graz Hbf','Abfahrt: 14:22 Uhr','Zug: IC 507','Preis: 29,90 EUR']))
for k,reverse in [('circle_left',False),('circle_right',True)]:
    im=Image.new('RGB',(1100,800),'white');d=ImageDraw.Draw(im)
    cx,sx=(820,280) if reverse else (280,820)
    d.ellipse((cx-140,260,cx+140,540),fill='#ed2828');d.rectangle((sx-130,270,sx+130,530),fill='#176dec')
    add(k,{'circle_left':'IMG_0015.png','circle_right':'IMG_0016.png'}[k], 'Roter Kreis '+('rechts' if reverse else 'links')+' von blauem Quadrat',im)
for k,reverse in [('triangle_above',False),('triangle_below',True)]:
    im=Image.new('RGB',(900,1100),'white');d=ImageDraw.Draw(im)
    ty,sy=(800,290) if reverse else (290,800)
    d.polygon([(450,ty-130),(300,ty+130),(600,ty+130)],fill='#edc71e')
    points=[(450+ (145 if i%2==0 else 60)*math.sin(i*math.pi/5),sy- (145 if i%2==0 else 60)*math.cos(i*math.pi/5)) for i in range(10)]
    d.polygon(points,fill='#179847')
    add(k,{'triangle_above':'IMG_0017.png','triangle_below':'IMG_0018.png'}[k], 'Gelbes Dreieck '+('unter' if reverse else 'ueber')+' gruenem Stern',im)
for k,color in [('blue_bike_beach','#196ddd'),('red_bike_beach','#ce2828')]:
    im=Image.new('RGB',(1280,720),'#80cbf5');d=ImageDraw.Draw(im)
    d.rectangle((0,350,1280,520),fill='#1679aa');d.rectangle((0,520,1280,720),fill='#f7d798');d.ellipse((980,55,1110,185),fill='#ffe253')
    for x in (380,700):d.ellipse((x-90,485,x+90,665),outline='black',width=10)
    d.line([(380,575),(495,405),(565,575),(380,575),(635,405),(700,575)],fill=color,width=12);d.line([(495,405),(635,405)],fill=color,width=12)
    d.line([(495,405),(480,380),(535,380)],fill='black',width=8);d.line([(635,405),(620,370),(675,350)],fill='black',width=8)
    add(k,{'blue_bike_beach':'IMG_0019.png','red_bike_beach':'IMG_0020.png'}[k],('Blaues' if k.startswith('blue') else 'Rotes')+' Fahrrad am Strand (Grafik)',im)
queries=[]
def q(category,text,*keys):queries.append(dict(id=f'q{len(queries)+1:03}',category=category,text=text,relevant=list(keys)))
for text,keys in [('Hund',['beach_dog']),('Schnee',['winter_hiker']),('Picknick',['summer_lake']),('Fahrrad',['bicycle_park','blue_bike_beach','red_bike_beach']),('Saugroboter',['robot_vacuum']),('Regenschirm',['rain_umbrella']),('Rechnung',['invoice_bike','invoice_coffee']),('Cappuccino',['menu_coffee']),('Notausgang',['exit_sign']),('Fahrkarte',['train_ticket']),('roter Kreis',['circle_left','circle_right']),('gelbes Dreieck',['triangle_above','triangle_below'])]:q('Begriffe',text,*keys)
for text,key in [('Vierbeiner spielt im Wasser','beach_dog'),('Hund apportiert Spielzeug am Meer','beach_dog'),('Bergtour bei winterlichem Wetter','winter_hiker'),('Person wandert mit Rucksack im Winter','winter_hiker'),('Familie isst draussen am See','summer_lake'),('Sommerausflug mit Boot und Picknick','summer_lake'),('Zweirad neben einer Sitzbank','bicycle_park'),('Fahrrad unter Baeumen','bicycle_park'),('Geraet reinigt den Fussboden','robot_vacuum'),('Haustier beobachtet den Staubsauger','robot_vacuum'),('Schutz vor schlechtem Wetter','rain_umbrella'),('Person geht bei nassem Wetter durch die Stadt','rain_umbrella'),('Wann treffen wir uns am Meer','chat_beach'),('Zugreise zwischen zwei Staedten','train_ticket')]:q('Umschreibungen',text,key)
for text,key in [('dog with a ball on the beach','beach_dog'),('hiking in the snow','winter_hiker'),('family picnic near a lake','summer_lake'),('red bicycle next to a bench','bicycle_park'),('robot vacuum cleaner and a black cat','robot_vacuum'),('yellow umbrella in the rain','rain_umbrella'),('train ticket Vienna to Graz','train_ticket'),('coffee and apple strudel menu','menu_coffee')]:q('Englisch',text,key)
for text,key in [('RECHNUNG 12345','invoice_bike'),('RECHNUNG 12346','invoice_coffee'),('12345','invoice_bike'),('12346','invoice_coffee'),('42,50 EUR','invoice_bike'),('18,90 EUR','invoice_coffee'),('17:30','chat_beach'),('09:15','chat_park'),('Margherita 9,50','menu_pizza'),('IC 507','train_ticket')]:q('OCR exakt',text,key)
for text,key in [('Hund mit rotem Ball','beach_dog'),('gelbes Kanu auf dem See','summer_lake'),('schwarze Katze auf grauem Sofa','robot_vacuum'),('roter Kreis links vom blauen Quadrat','circle_left'),('roter Kreis rechts vom blauen Quadrat','circle_right'),('gelbes Dreieck ueber gruenem Stern','triangle_above'),('gelbes Dreieck unter gruenem Stern','triangle_below'),('blaues Fahrrad am Strand','blue_bike_beach')]:q('Visuelle Details',text,key)
for text,key in [('IMG_0001','beach_dog'),('IMG_0002','winter_hiker'),('Fahrrad_Park','bicycle_park'),('SCAN_0007','invoice_bike'),('Speisekarte_0011','menu_pizza'),('Bahn_Ticket','train_ticket')]:q('Dateiname',text,key)
for text in ['RECHNUNG 12349','Hund mit gruenem Ball','Elefant im Schnee','Abfahrt 23:59']:q('Nicht vorhanden',text)
random.Random(20261009).shuffle(docs)
for i,d in enumerate(docs,1):d['file_id']=i
(ROOT/'fixtures.json').write_text(json.dumps(dict(documents=docs,queries=queries,protocol='Frozen before Media AI inference or retrieval; photo prompts inspected separately; no filename fed to embeddings.'),ensure_ascii=False,indent=2)+'\n')
print(f'{len(docs)} images, {len(queries)} frozen queries')
