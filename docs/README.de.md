<div align="center">

<img src="../img/app-color.svg" width="88" alt="NextGallery-Symbol">

# NextGallery

**Deine Nextcloud-Fotos als eine schöne Zeitleiste, im Browser und auf Android.**

Scrolle durch Jahre an Fotos und Videos, sieh auf der Weltkarte, wo sie entstanden sind, speichere Vorschauen für unterwegs und suche, wenn du möchtest, nach dem, was *auf* einem Bild zu sehen ist.<br>
Kein Import, keine Kopien: NextGallery zeigt die Ordner, die du schon hast.

[![Nextcloud 32–35](https://img.shields.io/badge/Nextcloud-32%E2%80%9335-0082c9?logo=nextcloud&logoColor=white)](ADMINISTRATION.md#build-and-install) [![Lizenz: AGPL-3.0-or-later](https://img.shields.io/badge/Lizenz-AGPL--3.0--or--later-2d6a4f)](../LICENSE) [![Android-App](https://img.shields.io/badge/Android-App-2d6a4f?logo=android&logoColor=white)](https://github.com/wolpa29/app-nextgallery)

[Installation](ADMINISTRATION.md#build-and-install) · [Android-App](https://github.com/wolpa29/app-nextgallery) · [English](../README.md) · [Feedback & Probleme](https://github.com/wolpa29/nextcloud-nextgallery/issues)

<br>

<img src="images/readme/hero.webp" alt="NextGallery-Zeitleiste in der Nextcloud-Weboberfläche neben der NextGallery-Android-App im dunklen Design" width="100%">

</div>

## Auf einen Blick

| 🗓️ Zeitleiste | 🗺️ Weltkarte | 🖼️ Galerien |
| --- | --- | --- |
| Alle Fotos und Videos aus deinen Ordnern, gruppiert nach Jahr, Monat oder Tag. | Fotos mit GPS-Daten auf einer Karte, die lokal lädt, ganz ohne externen Kartendienst. | Benannte Sammlungen aus Nextcloud-Ordnern: Urlaub, Familie, Hobbys, was du willst. |
| **📴 Offline** | **🔎 Suche** | **🌗 Jeder Bildschirm** |
| Vorschauen auf dem Gerät speichern oder eine portable Offline-Sammlung herunterladen. | Dateinamen durchsuchen und mit optionaler KI auch Beschreibungen, Schlagwörter und Texte in Bildern. | Computer und Smartphone, hell und dunkel, Deutsch und Englisch. |

## Ein kurzer Rundgang

### Deine ganze Sammlung, in der richtigen Reihenfolge

Wähle einmal deine Fotoordner. NextGallery fügt alles zu einer Zeitleiste zusammen: neueste oder älteste zuerst, in zwei bis sechs Spalten, mit Schnell-Scrollleiste und Zeitraumauswahl.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="images/readme/web-timeline-dark.webp">
  <img src="images/readme/web-timeline.webp" alt="Galerie-Zeitleiste in der Nextcloud-Weboberfläche, nach Monaten gruppiert">
</picture>

### Sieh, wo deine Erinnerungen entstanden sind

Fotos und Videos mit Standort erscheinen auf einer Weltkarte. Hineinzoomen, eine Gruppe öffnen, und du bist wieder dort.

<img src="images/readme/web-map.webp" alt="Weltkarte mit nach Ort gruppierten Fotos">

### Genauer hinsehen

Öffne jedes Foto und Video im integrierten Betrachter. Blättere mit den Pfeilen weiter, öffne das Original oder lies die KI-Beschreibung.

<img src="images/readme/web-viewer.webp" alt="Bildbetrachter im Vollbild">

### Direkt im Browser deines Smartphones

Die Web-Galerie passt sich kleinen Bildschirmen an. Du kannst sie auch in der offiziellen Nextcloud-Android-App als [External site](ANDROID.md) einbinden.

<img src="images/readme/web-mobile.webp" alt="Galerie, Karte und Betrachter auf einem Smartphone-Bildschirm">

## NextGallery für Android

Lieber eine App? Die [NextGallery-Android-App](https://github.com/wolpa29/app-nextgallery) ist eine schnelle, native Galerie für deine Nextcloud. Sie funktioniert **ohne** diese Server-Erweiterung. Mit dem Servermodus nutzt sie den gemeinsamen Galerie-Index und die KI-Suche auch am Handy.

<img src="images/readme/android.webp" alt="NextGallery-Android-App: Zeitleiste, Weltkarte mit Vorschaubildern und Bildbetrachter">

## Optionale KI: mehr finden als einen Dateinamen

Du erinnerst dich an *einen Hund am Strand*, aber nicht an den Dateinamen? Optionale KI macht verarbeitete Medien nach Inhalten durchsuchbar:

- **Beschreibungen und Schlagwörter** helfen dir, Fotos und Videos wiederzufinden.
- **Texterkennung (OCR)** findet Wörter auf fotografierten Schildern oder Dokumenten.
- **Visuelle Suche** findet passende Bilder anhand deiner Beschreibung.
- **Videoton** transkribiert gesprochene Inhalte lokal, speichert Zeitmarken und bietet eine eigene Suchoption. [Einrichtung und Grenzen →](AUDIO.md)
- **Personen** gruppiert Fotos nach Gesichtern, ohne Namen zu benötigen. Für ausgewählte Ordner ausdrücklich aktivierbar. [Einrichtung und Korrekturen →](FACES.md)

Du wählst Verarbeitungsordner und Zeitpläne und kannst jede KI-Funktion getrennt starten oder pausieren. Die Modelle können auf eigener Hardware laufen. **Die Galerie funktioniert auch ohne KI.** [KI & visuelle Suche →](EMBEDDINGS.md)

## So geht’s los

1. **Lass deinen Administrator NextGallery Server installieren.** [Zur Installationsanleitung →](ADMINISTRATION.md#build-and-install)
2. **Öffne NextGallery → Einstellungen → Galerien verwalten.** Gib deiner Galerie einen Namen und wähle ihre Ordner.
3. **Entdecke deine Bilder.** Stöbere in der Zeitleiste, probiere die Suche oder öffne die Karte.

**Voraussetzungen:** Nextcloud 32–35, unterstütztes PHP (8.2+), funktionierende Vorschauen und Cron. KI benötigt zusätzlich einen Worker und Modelldienste.

## Deine Dateien, deine Entscheidungen

- **Originale bleiben unverändert.** NextGallery liest deine Nextcloud-Ordner und verschiebt, benennt oder kopiert deine Fotos nie.
- **Standardmäßig keine externen Dienste.** Die Karte ist in der App enthalten. KI nutzt nur die Modelldienste, die dein Administrator einrichtet. [Details zur Einrichtung →](ADMINISTRATION.md#ai-administration-and-external-embedding-server)
- **Offline heißt Vorschaubilder, kein vollständiges Backup.** Originalvideos benötigen eine Verbindung. Das erneute Öffnen von Browser-Sammlungen setzt Service-Worker-Unterstützung voraus; portable Offline-Dateien sind eine Alternative.

<sub>Alle Screenshots zeigen synthetische Demo-Bilder, keine echten Fotos.</sub>

---

**Mehr erfahren:** [Administration](ADMINISTRATION.md) · [KI & visuelle Suche](EMBEDDINGS.md) · [Embedding-Server einrichten](EXTERNAL_EMBEDDING_SERVER.md) · [Android-Integration](ANDROID.md) · [Migration](MERGE.md) · [Validierung](VALIDATION.md) · [Versionshistorie](CHANGELOG.md)

Open Source unter [AGPL-3.0-or-later](../LICENSE). Drittanbieter: [NOTICE](../NOTICE). Bei öffentlichen Installationen beachte das [Quellcode-Angebot für Netzwerk-Nutzer](ADMINISTRATION.md#network-source-offer).
