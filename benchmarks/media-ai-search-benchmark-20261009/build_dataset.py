"""Fixed, synthetic metadata and relevance labels; no private files are read."""
import json, random, hashlib
from pathlib import Path

docs = []
def doc(key, description, tags, objects, ocr="", kind="foto"):
    docs.append(dict(key=key, description=description, tags=tags.split("|"),
                     objects=objects.split("|"), ocr=ocr, kind=kind))

doc("bike_red_beach", "Ein rotes Fahrrad steht am Sandstrand neben einem blauen Sonnenschirm. Im Hintergrund ist das Meer zu sehen.", "Fahrrad|Strand|rot|Meer", "Fahrrad|Sonnenschirm")
doc("bike_blue_car_red", "Ein blaues Fahrrad lehnt neben einem roten Auto auf einem Parkplatz.", "Fahrrad|Auto|blau|rot|Parkplatz", "Fahrrad|Auto")
doc("bike_red_city", "Ein rotes Fahrrad steht vor einer Hauswand in einer belebten Stadtstraße.", "Fahrrad|rot|Stadt|Straße", "Fahrrad|Hauswand")
doc("bike_blue_beach", "Ein blaues Fahrrad liegt am Sandstrand. Auf dem Meer fahren Segelboote.", "Fahrrad|blau|Strand|Segelboot", "Fahrrad|Segelboot")
doc("dog_surf", "Ein Labrador läuft durch die Brandung am Meeresufer. Wasser spritzt um seine Pfoten.", "Labrador|Hund|Brandung|Meer|Wasser", "Hund")
doc("dog_sofa", "Ein Labrador schläft eingerollt auf einem Sofa im Wohnzimmer.", "Hund|Labrador|Sofa|schlafen", "Hund|Sofa")
doc("cat_water", "Eine Katze sitzt auf einem Steg und blickt auf einen See.", "Katze|Steg|See|Wasser", "Katze|Steg")
doc("dog_ball", "Ein Hund springt auf einer Wiese nach einem gelben Ball.", "Hund|Ball|Wiese|springen", "Hund|Ball")
doc("snow_hike", "Zwei Wanderer gehen auf einem schneebedeckten Bergpfad. Sie tragen Rucksäcke und halten Wanderstöcke.", "Wandern|Schnee|Berg|Winter|Rucksack", "Wanderer|Rucksack|Wanderstock")
doc("summer_hike", "Zwei Wanderer gehen auf einem trockenen Bergpfad zwischen grünen Wiesen. Sie tragen Rucksäcke.", "Wandern|Berg|Sommer|Wiese|Rucksack", "Wanderer|Rucksack")
doc("ski", "Eine Person fährt mit Skiern einen verschneiten Hang hinunter.", "Skifahren|Schnee|Winter|Berg", "Person|Ski")
doc("snow_city", "Mehrere Personen gehen durch eine verschneite Einkaufsstraße zwischen Geschäften.", "Schnee|Stadt|Winter|Geschäft", "Person|Geschäft")
doc("dinner_terrace", "Mehrere Personen sitzen bei Kerzenlicht an einem gedeckten Tisch auf einer Terrasse. Es ist dunkel, an der Wand hängen Lichterketten.", "Abendessen|Terrasse|Kerze|Nacht|Lichterkette", "Person|Tisch|Kerze|Lichterkette")
doc("dinner_indoor", "Mehrere Personen sitzen bei Kerzenlicht an einem gedeckten Tisch in einem Esszimmer.", "Abendessen|Kerze|Esszimmer|Tisch", "Person|Tisch|Kerze")
doc("terrace_day", "Auf einer sonnigen Terrasse stehen leere Gartenstühle und ein Tisch mit einer Blumenvase.", "Terrasse|Tag|Gartenstuhl|Blume", "Stuhl|Tisch|Blumenvase")
doc("concert", "Eine Band spielt auf einer Bühne unter bunten Scheinwerfern. Vor der Bühne stehen viele Zuschauer.", "Konzert|Band|Bühne|Publikum|Musik", "Musiker|Gitarre|Scheinwerfer")
doc("coffee", "Eine weiße Tasse mit Cappuccino und Milchschaum steht auf einem Holztisch neben einem Croissant.", "Cappuccino|Kaffee|Croissant|Frühstück", "Tasse|Croissant|Tisch")
doc("tea", "Eine weiße Tasse mit Tee steht neben einer Teekanne und Zitronenscheiben.", "Tee|Zitrone|Teekanne", "Tasse|Teekanne|Zitrone")
doc("breakfast", "Auf einem Frühstückstisch stehen ein Teller mit Rührei, Brot und ein Glas Orangensaft.", "Frühstück|Rührei|Brot|Orangensaft", "Teller|Brot|Glas")
doc("bread", "Ein Laib Brot und mehrere Brötchen liegen auf einem Schneidebrett in einer Küche.", "Brot|Brötchen|Küche", "Brot|Schneidebrett")
doc("boat_lake", "Ein kleines Segelboot fährt auf einem See vor bewaldeten Bergen.", "Segelboot|See|Berg|Wald", "Segelboot")
doc("boat_sea", "Ein Segelboot fährt auf dem offenen Meer. Im Hintergrund ist ein Leuchtturm zu sehen.", "Segelboot|Meer|Leuchtturm", "Segelboot|Leuchtturm")
doc("harbor", "Mehrere Motorboote liegen an einem Steg in einem Hafen.", "Motorboot|Hafen|Steg", "Motorboot|Steg")
doc("car_ferry", "Autos stehen dicht nebeneinander auf dem Deck einer Fähre.", "Auto|Fähre|Schiff|Deck", "Auto|Fähre")
doc("robot_vacuum", "Ein runder Saugroboter steht auf einem Holzboden neben seiner Ladestation.", "Saugroboter|Ladestation|Holzboden", "Saugroboter|Ladestation")
doc("washing_machine", "Eine Waschmaschine mit runder Glastür steht neben einem Wäschekorb.", "Waschmaschine|Wäschekorb", "Waschmaschine|Wäschekorb")
doc("vacuum", "Ein Bodenstaubsauger mit Schlauch und Bürste steht auf einem Teppich.", "Staubsauger|Teppich|Schlauch", "Staubsauger|Bürste")
doc("unknown_device", "Ein schwarzes rechteckiges Gerät mit einem kleinen Display und drei Tasten steht auf einem Tisch. Der Gerätetyp ist nicht erkennbar.", "Gerät|Display|Taste|schwarz", "Gerät|Display|Taste")
doc("invoice_471839", "Ein abfotografiertes Rechnungsblatt liegt auf einem Tisch.", "Rechnung|Dokument|Werkstatt", "Papier|Tisch", "Werkstatt Nord\nRechnung 471839\nBremsbeläge erneuert\nGesamtbetrag 248,90 EUR", "dokument")
doc("invoice_471838", "Ein abfotografiertes Rechnungsblatt liegt auf einem Tisch.", "Rechnung|Dokument|Werkstatt", "Papier|Tisch", "Werkstatt Nord\nRechnung 471838\nÖlwechsel\nGesamtbetrag 129,00 EUR", "dokument")
doc("invoice_4718390", "Ein Rechnungsblatt ist auf einem Schreibtisch zu sehen.", "Rechnung|Dokument|Elektronik", "Papier", "Elektro Süd\nRechnung 4718390\nMonitor\nGesamtbetrag 248,90 EUR", "dokument")
doc("receipt", "Ein Kassenbon liegt neben einem Einkaufskorb.", "Kassenbon|Einkauf|Lebensmittel", "Papier|Einkaufskorb", "Markt West\nMilch 1,49\nBrot 2,90\nSumme 4,39 EUR", "dokument")
doc("wifi", "Ein Zettel mit WLAN-Zugangsdaten hängt neben einem Router.", "WLAN|Router|Zugangsdaten", "Zettel|Router", "WLAN: Gastnetz\nPasswort: Sommer2026!", "dokument")
doc("ticket", "Ein Zugticket liegt auf einem Tisch neben einer Tasche.", "Zugticket|Bahn|Tasche", "Ticket|Tasche", "ÖBB\nWien Hbf → Graz Hbf\nZug RJ 755\nSitzplatz 42\n09.10.2026", "dokument")
doc("poster", "Ein Plakat für einen Musikabend hängt an einer Hauswand.", "Plakat|Musik|Veranstaltung", "Plakat|Hauswand", "Jazz im Park\nFreitag 18:30\nEintritt frei", "dokument")
doc("screen_error", "Ein Bildschirmfoto zeigt eine Fehlermeldung in einem Programmfenster.", "Screenshot|Fehlermeldung|Programm", "Fenster", "Verbindung fehlgeschlagen\nFehler E_CONN_042\nBitte erneut versuchen", "screenshot")
doc("dog_left_cat", "Ein Hund sitzt links neben einer Katze auf einer Bank.", "Hund|Katze|Bank", "Hund|Katze|Bank")
doc("cat_left_dog", "Eine Katze sitzt links neben einem Hund auf einer Bank.", "Hund|Katze|Bank", "Hund|Katze|Bank")
doc("empty_beach", "Ein leerer Sandstrand mit Wellen und einem Sonnenschirm ist zu sehen. Es sind keine Menschen sichtbar.", "Strand|Meer|Sonnenschirm|leer", "Sonnenschirm")
doc("busy_beach", "Viele Menschen liegen am Sandstrand unter Sonnenschirmen. Kinder spielen am Wasser.", "Strand|Meer|Menschen|Sonnenschirm|Kind", "Person|Sonnenschirm")
doc("red_car_blue_house", "Ein rotes Auto steht vor einem blauen Haus.", "Auto|Haus|rot|blau", "Auto|Haus")
doc("blue_car_red_house", "Ein blaues Auto steht vor einem roten Haus.", "Auto|Haus|rot|blau", "Auto|Haus")
doc("sunset_lake", "Die tief stehende Sonne spiegelt sich orange auf einem ruhigen See. Am Ufer steht eine einzelne Bank.", "Sonnenuntergang|See|Abend|Bank", "Sonne|Bank")
doc("sunrise_mountain", "Die aufgehende Sonne beleuchtet Berggipfel über einer Nebeldecke.", "Sonnenaufgang|Berg|Nebel|Morgen", "Sonne|Berg")
doc("flowers", "Rote Rosen wachsen zwischen grünen Blättern in einem Garten.", "Rose|Blume|Garten|rot", "Rose|Blatt")
doc("hospital", "Ein Krankenbett mit einem Infusionsständer steht in einem hellen Zimmer.", "Krankenbett|Infusion|Zimmer", "Bett|Infusionsständer")
doc("airport", "Eine Person zieht einen Rollkoffer durch eine Flughafenhalle.", "Flughafen|Koffer|Reise", "Person|Rollkoffer")
doc("train_platform", "Ein Zug steht an einem Bahnsteig. Mehrere Personen warten mit Koffern.", "Zug|Bahnsteig|Koffer|Reise", "Zug|Person|Koffer")
doc("birthday", "Ein Kind sitzt vor einer Torte mit brennenden Kerzen. Neben dem Tisch liegen verpackte Geschenke.", "Geburtstag|Kind|Torte|Kerze|Geschenk", "Kind|Torte|Kerze|Geschenk")
doc("wedding", "Zwei Personen in Hochzeitskleidung stehen vor einem Gebäude. Eine Person hält einen Blumenstrauß.", "Hochzeit|Blumenstrauß|Person", "Person|Blumenstrauß")
doc("rain_window", "Regentropfen bedecken eine Fensterscheibe. Dahinter sind unscharfe Häuser zu sehen.", "Regen|Fenster|Haus", "Fenster|Haus")
doc("umbrella_street", "Eine Person geht mit einem geöffneten Regenschirm über eine nasse Straße.", "Regen|Regenschirm|Straße", "Person|Regenschirm")
doc("ski_sticks_only", "Zwei Skistöcke lehnen an einer verschneiten Holzhütte. Es sind keine Personen sichtbar.", "Skistock|Schnee|Hütte", "Skistock|Hütte")
doc("mountain_bike", "Eine Person fährt mit einem Mountainbike über einen steinigen Waldweg.", "Mountainbike|Fahrrad|Wald|Radfahren", "Person|Mountainbike")
doc("bicycle_stand", "Mehrere leere Fahrradständer stehen vor einem Bahnhof.", "Fahrradständer|Bahnhof|Metall", "Fahrradständer")
doc("motorbike", "Ein rotes Motorrad steht vor einer Garage.", "Motorrad|rot|Garage", "Motorrad|Garage")

queries=[]
def q(group, text, relevant, note=""):
    queries.append(dict(id=f"q{len(queries)+1:02}",group=group,text=text,relevant=relevant.split("|") if relevant else [],note=note))

# Labels are frozen before running either method; no model-specific tuning.
for text, rel in [
 ("Saugroboter Ladestation","robot_vacuum"),("Labrador Sofa","dog_sofa"),
 ("Segelboot See","boat_lake"),("Cappuccino Croissant","coffee"),
 ("Kerze Esszimmer","dinner_indoor"),("Skifahren Schnee","ski"),
 ("Fahrrad Strand","bike_red_beach|bike_blue_beach"),("Rose Garten","flowers"),
 ("Flughafen Koffer","airport"),("Geburtstag Torte","birthday")]:q("exakt",text,rel)
for text,rel in [
 ("Hund am Meeresufer","dog_surf"),("Rad am Sandstrand","bike_red_beach|bike_blue_beach"),
 ("Putzen mit einem Roboter","robot_vacuum"),("Winterwanderung","snow_hike"),
 ("Frühstück mit Milchkaffee","coffee"),("Menschen bei Livemusik","concert"),
 ("Boote am Liegeplatz","harbor"),("Reise mit dem Flugzeug","airport"),
 ("Feier mit Geburtstagskuchen","birthday"),("Hund jagt Spielzeug","dog_ball"),
 ("Niederschlag auf einer Glasscheibe","rain_window"),("Abendrot am Wasser","sunset_lake")]:q("umformuliert",text,rel)
for text,rel in [
 ("Zeig mir Bilder von einem Hund, der im Meer herumläuft","dog_surf"),
 ("Wo haben wir draußen bei Kerzenschein gegessen?","dinner_terrace"),
 ("Ich suche das Foto mit Wanderern im Schnee","snow_hike"),
 ("Ein kleines Boot auf einem See mit Bergen dahinter","boat_lake"),
 ("Das Bild mit einem Kaffee und etwas Gebäck dazu","coffee"),
 ("Menschen warten mit Gepäck auf einen Zug","train_platform"),
 ("Jemand ist auf einem Fahrrad im Wald unterwegs","mountain_bike"),
 ("Ein Zimmer, in dem ein Bett und eine Infusion stehen","hospital")]:q("natuerliche_frage",text,rel)
for text,rel in [
 ("rotes Fahrrad am Strand","bike_red_beach"),
 ("blaues Fahrrad neben rotem Auto","bike_blue_car_red"),
 ("rotes Auto vor blauem Haus","red_car_blue_house"),
 ("blaues Auto vor rotem Haus","blue_car_red_house"),
 ("Hund links neben Katze","dog_left_cat"),
 ("Katze links neben Hund","cat_left_dog"),
 ("Strand ohne Menschen","empty_beach"),
 ("Wanderer ohne Schnee","summer_hike")]:q("beziehung_verneinung",text,rel)
for text,rel in [
 ("471839","invoice_471839"),("471838","invoice_471838"),
 ("4718390","invoice_4718390"),("E_CONN_042","screen_error"),
 ("Sommer2026","wifi"),("Jazz Eintritt frei","poster"),
 ("ÖBB Graz","ticket"),("Bremsbeläge erneuert","invoice_471839")]:q("ocr_exakt",text,rel)
for text,rel in [
 ("Rechnung für den Austausch der Bremsen","invoice_471839"),
 ("Beleg über einen Ölwechsel","invoice_471838"),
 ("Wie lautet der Zugang zum drahtlosen Gastnetz?","wifi"),
 ("Ein kostenloses Jazzkonzert am Freitag","poster")]:q("ocr_semantisch",text,rel)
for text in ["Pinguin auf einer Eisscholle","Unterwasseraufnahme eines Korallenriffs","Rechnung 9988776655","Hund fährt Motorrad"]:
 q("kein_treffer",text,"","Kein passendes Dokument vorhanden; Top-k allein darf nicht als richtiger Treffer zählen.")

random.Random(20261009).shuffle(docs)
for i,d in enumerate(docs,1): d['file_id']=i;d['path']=f"Synthetic/IMG_{i:04}.jpg"
assert len({d['key'] for d in docs})==len(docs)
assert all(set(x['relevant'])<=set(d['key'] for d in docs) for x in queries)
data=dict(schema=1, synthetic=True, description="Handgeschriebene synthetische Media-AI-Metadaten; keine Bildanalyse oder privaten Medien.",documents=docs,queries=queries)
out=Path(__file__).with_name('dataset.json')
out.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
print(f"{len(docs)} documents; {len(queries)} queries; sha256={hashlib.sha256(out.read_bytes()).hexdigest()}")
