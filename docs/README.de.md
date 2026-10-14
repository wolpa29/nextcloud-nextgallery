<img src="../img/app-color.svg" width="64" alt="NextGallery-Symbol">

# NextGallery für Nextcloud

**Deine Fotos. Deine Nextcloud. Einfach mehr entdecken.**

Aus deinen vorhandenen Foto- und Videoordnern in Nextcloud wird eine Galerie, in der du gerne stöberst. Entdecke einen Urlaub neu, finde ein bestimmtes Bild oder sieh, wo deine Erinnerungen entstanden sind — direkt im Browser.

[English](../README.md) · [Installation](ADMINISTRATION.md#build-and-install) · [Android-App](https://github.com/wolpa29/app-nextgallery) · [Feedback & Probleme](https://github.com/wolpa29/nextcloud-nextgallery/issues)

## Was kann ich damit machen?

| Du möchtest… | NextGallery hilft dir dabei… |
| --- | --- |
| **Erinnerungen durchstöbern** | Fotos und Videos in einer Zeitleiste ansehen, zu einem Datum springen und im integrierten Betrachter öffnen. |
| **Sammlungen einfach ordnen** | Benannte Galerien aus ausgewählten Nextcloud-Ordnern erstellen — für Urlaub, Familie, Hobbys oder andere Themen. |
| **Ein bestimmtes Foto finden** | Dateinamen durchsuchen und mit optionaler KI auch Beschreibungen, Schlagwörter und erkannte Texte. |
| **Orte entdecken** | Fotos und Videos mit GPS-Informationen auf einer Weltkarte ansehen. |
| **Eine Galerie mitnehmen** | Vorschaubilder zum Offline-Stöbern speichern oder eine portable Offline-Sammlung herunterladen. |

Anpassbare Ansichten, helles/dunkles Design und deutsche/englische Bedienelemente funktionieren auf Computer und Smartphone.

## Optionale KI: mehr finden als einen Dateinamen

Du erinnerst dich an *einen Hund am Strand*, aber nicht an den Dateinamen? Optionale KI macht verarbeitete Medien nach Inhalten durchsuchbar:

- **Beschreibungen und Schlagwörter** helfen dir, Fotos und Videos wiederzufinden.
- **Texterkennung (OCR)** findet Wörter auf fotografierten Schildern oder Dokumenten.
- **Visuelle Suche** findet passende Bilder anhand deiner Beschreibung.

Du wählst Verarbeitungsordner und Zeitpläne und kannst jede KI-Funktion getrennt starten oder pausieren. **Die Galerie funktioniert auch ohne KI.**

## So geht’s los

1. **Lass deinen Administrator NextGallery Server installieren.** [Zur Installationsanleitung →](ADMINISTRATION.md#build-and-install)
2. **Öffne NextGallery → Einstellungen → Galerien verwalten.** Gib deiner Galerie einen Namen und wähle ihre Ordner.
3. **Entdecke deine Bilder.** Stöbere in der Zeitleiste, probiere die Suche oder öffne die Karte.

**Voraussetzungen:** Nextcloud 32–35, unterstütztes PHP (8.2+), funktionierende Vorschauen und Cron. KI benötigt zusätzlich einen Worker und Modelldienste.

## Deine Dateien, deine Entscheidungen

Deine Originalfotos und -videos bleiben unverändert in Nextcloud. KI verwendet die vom Administrator eingerichteten Modelldienste; diese können auf eigener Hardware laufen. [Details zur Einrichtung →](ADMINISTRATION.md#ai-administration-and-external-embedding-server)

Offline speichert **Vorschaubilder, kein vollständiges Backup**. Originalvideos benötigen eine Verbindung; das erneute Öffnen von Browser-Sammlungen setzt Service-Worker-Unterstützung voraus. Portable Offline-Dateien sind eine Alternative.

**Du nutzt Android?** Die separate [NextGallery-App](https://github.com/wolpa29/app-nextgallery) funktioniert ohne diese Erweiterung; der optionale Servermodus ergänzt den gemeinsamen Index. Die offizielle Nextcloud Android-App kann die Browser-Galerie über [External sites](ANDROID.md) öffnen.

---

Mehr erfahren: [Administration](ADMINISTRATION.md) · [KI & visuelle Suche](EMBEDDINGS.md) · [Embedding-Server einrichten](EXTERNAL_EMBEDDING_SERVER.md) · [Migration](MERGE.md) · [Validierung](VALIDATION.md) · [Versionshistorie](CHANGELOG.md)

Open Source unter [AGPL-3.0-or-later](../LICENSE). Drittanbieter: [NOTICE](../NOTICE). Bei öffentlichen Installationen beachte das [Quellcode-Angebot für Netzwerk-Nutzer](ADMINISTRATION.md#network-source-offer).
