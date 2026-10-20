<?php
$english = json_decode(file_get_contents(__DIR__.'/en.json'), true);
$isGerman = str_starts_with($_['context']['language'] ?? 'de', 'de');
$tr = static fn(string $text): string => $isGerman ? $text : ($english[$text] ?? $text);
?>
<div id="nextgallery" data-appearance="<?php p($_['context']['settings']['appearance'] ?? 'system'); ?>" data-context="<?php echo htmlspecialchars(json_encode($_['context'],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP),ENT_QUOTES,'UTF-8'); ?>">
 <aside class="ch-sidebar" aria-label="NextGallery Server Navigation">
  <a class="ch-brand" href="#timeline"><svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="108" height="108" viewBox="0 0 108 108"><rect width="108" height="108" rx="27" fill="#103b3a"/><path d="M36,45 C28,45 24,40 25,34 C26,28 31,25 37,26 C40,16 53,16 59,25 C68,22 75,29 74,36 C83,38 81,45 73,45 Z" fill="#78c6b5"/><path d="M51,43 L51,54 L56,54 L56,43 Z" fill="#78c6b5"/><rect x="28" y="52" width="52" height="37" rx="9" fill="#edf6ef"/><circle cx="67" cy="62" r="4.5" fill="#eab974"/><path d="M33 80L44 65L57 79L65 70L75 81V82Q75 85 71 85H37Q33 85 33 81Z" fill="#2b6c5b"/><path d="M33 81L43 76L54 85H37Q33 85 33 81Z" fill="#78c6b5"/></svg>

<span class="ch-brand-copy"><strong>NextGallery</strong><small>Nextcloud</small></span></a>
  <nav><button data-view="timeline" class="active"><span data-icon="gallery"></span><?php p($tr('Galerie')); ?></button><button data-view="map"><span data-icon="map"></span><?php p($tr('Karte')); ?></button><button data-view="people"><span data-icon="people"></span><?php p($tr('Personen')); ?></button><button data-view="offline"><span data-icon="download"></span><?php p($tr('Offline')); ?></button><?php if (!empty($_['context']['aiUrl'])): ?><a id="ch-ai-link" data-view="ai" class="ch-ai-nav" aria-label="<?php p($tr('KI-Verarbeitung')); ?>" data-ai-entry href="<?php p($_['context']['aiUrl']); ?>"><span data-icon="processing"></span><span class="ch-ai-nav-full"><?php p($tr('KI-Verarbeitung')); ?></span><span class="ch-ai-nav-short" aria-hidden="true"><?php p($tr('KI')); ?></span></a><?php endif; ?></nav>
  <div class="ch-side-bottom"><p id="ch-source-count"><?php p($tr('Keine Ordner ausgewählt')); ?></p><button data-view="settings"><span data-icon="settings"></span><?php p($tr('Einstellungen')); ?></button><span class="ch-version">NextGallery Server · <?php echo htmlspecialchars($_['context']['version'],ENT_QUOTES,'UTF-8'); ?></span><?php if (!empty($_['context']['sourceUrl'])): ?><a class="ch-version" href="<?php p($_['context']['sourceUrl']); ?>"><?php p($isGerman ? 'Server-Quellcode (AGPL)' : 'Server source (AGPL)'); ?></a><?php endif; ?></div>
 </aside>
 <main class="ch-main" id="app-content">
  <header id="ch-top" class="ch-top"><div><h1 id="ch-title"><?php p($tr('Galerie')); ?></h1><button id="ch-gallery-switch" class="ch-gallery-switch" aria-haspopup="dialog"><span id="ch-gallery-name"></span><span aria-hidden="true">⌄</span></button><p id="ch-count" aria-live="polite"><?php p($tr('Wird geladen …')); ?></p></div><div class="ch-top-actions"><span id="ch-connection" hidden><?php p($tr('Offline')); ?></span><button id="ch-live" hidden><?php p($tr('Live öffnen')); ?></button><button id="ch-header-search" data-view="search" aria-label="<?php p($tr('Suche')); ?>" title="<?php p($tr('Suche')); ?>"><span data-icon="search"></span></button><button id="ch-refresh" aria-label="<?php p($tr('Galerie aktualisieren')); ?>" title="<?php p($tr('Galerie aktualisieren')); ?>"><span data-icon="refresh"></span></button></div></header>
  <div id="ch-message" role="status" hidden></div>
  <section id="ch-timeline-view">
   <?php if (!empty($_['context']['aiUrl'])): ?><section id="ch-ai-feature" class="ch-ai-feature" data-ai-entry aria-labelledby="ch-ai-feature-title"><span class="ch-ai-feature-icon" data-icon="processing"></span><div><h2 id="ch-ai-feature-title"><?php p($tr('Mehr finden mit KI')); ?></h2><p><?php p($tr('Bildinhalte erkennen, Texte lesen und deine Medien danach durchsuchen.')); ?></p></div><a class="ch-button ch-primary" href="<?php p($_['context']['aiUrl']); ?>"><?php p($tr('KI-Verarbeitung öffnen')); ?><span aria-hidden="true">→</span></a></section><?php endif; ?>

   <div class="ch-toolbar"><div class="ch-filters" role="group" aria-label="<?php p($tr('Medientyp')); ?>"><button data-kind="" class="active"><?php p($tr('Alle')); ?></button><button data-kind="image"><?php p($tr('Bilder')); ?></button><button data-kind="video"><?php p($tr('Videos')); ?></button></div>
    <button id="ch-period" aria-label="<?php p($tr('Zeitraum')); ?>"><?php p($tr('Zeitraum')); ?></button><button id="ch-display" aria-label="<?php p($tr('Darstellung')); ?>" title="<?php p($tr('Darstellung')); ?>"><span data-icon="settings"></span></button>
   </div>
   <div class="ch-gallery-wrap"><div id="ch-scroll" tabindex="0" aria-label="<?php p($tr('Chronologische Galerie')); ?>"><div id="ch-empty" hidden><span data-icon="gallery"></span><h2><?php p($tr('Deine Galerie beginnt mit einem Ordner')); ?></h2><p><?php p($tr('Wähle die Ordner aus, die in deiner Zeitleiste erscheinen sollen.')); ?></p><button id="ch-choose" class="ch-primary"><?php p($tr('Ordner auswählen')); ?></button></div><div id="ch-canvas" role="list" aria-label="<?php p($tr('Fotos und Videos')); ?>"></div></div><div id="ch-fast-scroll" role="slider" tabindex="0" aria-label="<?php p($tr('Schnell durch Medien scrollen')); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span class="ch-fast-track"></span><span id="ch-fast-thumb"></span><span id="ch-fast-label"><strong id="ch-fast-date"></strong><small id="ch-fast-folder"></small></span></div><div id="ch-floating-date" aria-live="off"></div></div>
  </section>
  <section id="ch-search-view" class="ch-panel" hidden>
   <div class="ch-toolbar"><div class="ch-filters" role="group" aria-label="<?php p($tr('Medientyp')); ?>"><button data-kind="" class="active"><?php p($tr('Alle')); ?></button><button data-kind="image"><?php p($tr('Bilder')); ?></button><button data-kind="video"><?php p($tr('Videos')); ?></button></div></div>
   <form id="ch-search-form" role="search"><label for="ch-search-query"><?php p($tr('Galerie durchsuchen')); ?></label><div class="ch-search-input"><input id="ch-search-query" type="search" maxlength="200" placeholder="<?php p($tr('Dateiname, Bildinhalt oder erkannter Text')); ?>"><button class="ch-primary" type="submit"><?php p($tr('Suchen')); ?></button></div></form>
   <p id="ch-search-status" role="status"><?php p($tr('Sucht in Dateinamen, Beschreibung, Tags, Objekten und OCR deiner Galerie.')); ?></p>
   <div id="ch-search-results" role="list"></div>
   <div id="ch-search-pages" class="ch-search-pages" hidden><button id="ch-search-prev" disabled><?php p($tr('Zurück')); ?></button><button id="ch-search-next" disabled><?php p($tr('Weitere Treffer')); ?></button></div>
  </section>
  <section id="ch-map-view" hidden><div class="ch-map-top"><p id="ch-map-count"><?php p($tr('Fotos und Videos mit Standort')); ?></p><button id="ch-map-reset"><?php p($tr('Weltkarte')); ?></button></div><div id="ch-map" aria-label="<?php p($tr('Weltkarte mit Medienstandorten')); ?>"></div><p class="ch-map-note"><?php p($tr('Kartendaten: Natural Earth · Lokal geladen, ohne externe Kartenanfragen.')); ?></p></section>
  <section id="ch-people-view" class="ch-panel" hidden>
   <div class="ch-people-toolbar">
    <div class="ch-filters" role="group" aria-label="<?php p($tr('Filter')); ?>">
     <select id="ch-people-filter" aria-label="<?php p($tr('Filter')); ?>">
      <option value="all"><?php p($tr('Alle')); ?></option>
      <option value="suggested"><?php p($tr('Vorschläge')); ?></option>
      <option value="review"><?php p($tr('Überprüfung')); ?></option>
      <option value="single"><?php p($tr('Seltene Personen')); ?></option>
      <option value="filtered"><?php p($tr('Gefilterte Gesichter')); ?></option>
      <option value="hidden"><?php p($tr('Ausgeblendet')); ?></option>
     </select>
    </div>
    <div class="ch-people-toolbar-actions">
     <button id="ch-people-refresh" class="ch-button" aria-label="<?php p($tr('Aktualisieren')); ?>" title="<?php p($tr('Aktualisieren')); ?>"><span data-icon="refresh"></span></button>
     <button id="ch-people-reset" class="ch-button ch-danger" aria-label="<?php p($tr('Personenerkennung zurücksetzen')); ?>"><?php p($tr('Zurücksetzen')); ?></button>
    </div>
   </div>
   <div id="ch-people-error" class="ch-error-banner" role="alert" hidden></div>
   <div id="ch-people-loading" class="ch-people-loading" role="status" hidden><span class="ch-spinner" aria-hidden="true"></span><span><?php p($tr('Wird geladen …')); ?></span></div>
   <div id="ch-people-list-container">
    <div id="ch-people-grid" class="ch-people-grid" role="list" aria-label="<?php p($tr('Personen')); ?>"></div>
    <div id="ch-people-more-wrap" class="ch-panel-actions" hidden>
     <button id="ch-people-more" class="ch-button"><?php p($tr('Weitere Personen laden')); ?></button>
    </div>
    <div id="ch-people-empty" class="ch-empty-state" hidden>
     <span data-icon="people"></span>
     <h2><?php p($tr('Keine Personen gefunden')); ?></h2>
     <p id="ch-people-empty-hint"><?php p($tr('Starte die Personenerkennung in der KI-Verarbeitung, um Fotos nach Personen zu sortieren.')); ?></p>
    </div>
   </div>
   <div id="ch-person-detail" class="ch-person-detail" hidden>
    <div class="ch-person-detail-bar">
     <button id="ch-people-back" class="ch-button">← <?php p($tr('Alle Personen')); ?></button>
     <div class="ch-people-detail-title-wrap">
      <h2 id="ch-people-detail-title"><?php p($tr('Person')); ?></h2>
      <p id="ch-people-detail-count" class="ch-hint"></p>
     </div>
     <div class="ch-person-detail-actions">
      <button id="ch-people-toggle-hide" class="ch-button"><?php p($tr('Ausblenden')); ?></button>
      <button id="ch-people-merge" class="ch-button"><?php p($tr('Zusammenführen')); ?></button>
      <button id="ch-people-resolve" class="ch-button" hidden><?php p($tr('Überprüfung auflösen')); ?></button>
     </div>
    </div>
    <p id="ch-people-detail-filter-note" class="ch-hint" hidden><?php p($tr('Kleine oder unscharfe Gesichter im Hintergrund werden hier gesammelt und können bestätigt werden.')); ?></p>
    <section id="ch-people-similar" class="ch-people-similar" aria-labelledby="ch-people-similar-title" hidden>
     <h3 id="ch-people-similar-title"><?php p($tr('Vielleicht dieselbe Person')); ?></h3>
     <p class="ch-hint"><?php p($tr('Vorschläge aus ähnlichen Gesichtern. Zusammengeführt wird nur, was du bestätigst.')); ?></p>
     <div id="ch-people-similar-list" class="ch-people-similar-list" role="list"></div>
    </section>
    <div id="ch-person-selection-bar" class="ch-selection-bar" hidden>
     <span id="ch-people-selection-count"></span>
     <div class="ch-selection-actions">
      <button id="ch-people-split-selected" class="ch-button"><?php p($tr('Abtrennen')); ?></button>
      <button id="ch-people-remove-selected" class="ch-button ch-danger"><?php p($tr('Entfernen')); ?></button>
      <button id="ch-people-resolve-selected" class="ch-button"><?php p($tr('Bestätigen')); ?></button>
      <button id="ch-people-clear-selected" class="ch-button"><?php p($tr('Auswahl aufheben')); ?></button>
     </div>
    </div>
    <div id="ch-person-media-grid" class="ch-person-media-grid" role="list" aria-label="<?php p($tr('Fotos dieser Person')); ?>"></div>
    <div id="ch-people-media-more-wrap" class="ch-panel-actions" hidden>
     <button id="ch-people-media-more" class="ch-button"><?php p($tr('Weitere Fotos laden')); ?></button>
    </div>
   </div>
  </section>
  <section id="ch-offline-view" class="ch-panel" hidden><h2><?php p($tr('Offline für alle Galerien')); ?></h2><p class="ch-hint"><?php p($tr('Wähle die Galerie, deren Vorschauen du verwalten möchtest.')); ?></p><p><?php p($tr('Speichere kleine Vorschauen auf diesem Gerät. Bilder und Video-Vorschauen lassen sich dann ohne Verbindung ansehen. Originale und Videos bleiben auf Nextcloud.')); ?></p><div class="ch-storage"><span data-icon="download"></span><div><strong id="ch-offline-count"><?php p($tr('Noch keine Sammlung')); ?></strong><p id="ch-offline-size"><?php p($tr('Vorschauen benötigen nur einen Teil des Speicherplatzes.')); ?></p></div></div><label for="ch-offline-gallery"><?php p($tr('Galerie')); ?></label><select id="ch-offline-gallery"></select><label for="ch-offline-limit"><?php p($tr('Maximale Anzahl Vorschauen')); ?></label><input type="number" id="ch-offline-limit" min="1" max="5000" value="1000"><p class="ch-hint"><?php p($tr('Bis zu 5.000 Vorschauen · Speicherlimit 300 MB. Gespeichert wird in der aktuellen Sortierung.')); ?></p><progress id="ch-offline-progress" value="0" max="1" hidden></progress><div class="ch-panel-actions"><button id="ch-pin" class="ch-primary"><?php p($tr('Sammlung speichern')); ?></button><button id="ch-pin-cancel" hidden><?php p($tr('Abbrechen')); ?></button><button id="ch-show-saved" disabled><?php p($tr('Gespeicherte Galerie ansehen')); ?></button><a id="ch-open-offline" class="ch-button"><?php p($tr('Offline öffnen')); ?></a><button id="ch-export" disabled><?php p($tr('Offline-Datei herunterladen')); ?></button><button id="ch-clear-offline"><?php p($tr('Sammlung entfernen')); ?></button></div><p class="ch-hint"><?php p($tr('Die Sammlung bleibt auf diesem Gerät gespeichert. Entferne sie auf gemeinsam genutzten Geräten. Der Browser kann lokalen Speicher bei Platzmangel freigeben. Für erneutes Öffnen ohne Verbindung muss HTTPS auf diesem Gerät vertrauenswürdig sein. Alternativ öffnest du die heruntergeladene Offline-Datei im Browser.')); ?></p></section>
  <section id="ch-ai-view" data-ai-entry hidden></section>
  <section id="ch-settings-view" class="ch-panel" hidden>
   <p class="ch-hint"><?php p($tr('Für alle Galerien in diesem Nextcloud-Konto')); ?></p>
   <button id="ch-manage-galleries" class="ch-manage-row"><span data-icon="gallery"></span><span><strong><?php p($tr('Galerien verwalten')); ?></strong><small><?php p($tr('Namen und Nextcloud-Ordner bearbeiten')); ?></small></span><span>›</span></button>
   <section class="ch-settings-group"><h2><?php p($tr('Galerieansicht')); ?></h2><p class="ch-hint"><?php p($tr('Reihenfolge, Datumsgruppen und Raster gelten für alle Galerien.')); ?></p><?php $displayDialog=false; include __DIR__.'/display-options.php'; ?></section>
   <section class="ch-settings-group"><h2><?php p($tr('Datum')); ?></h2><label for="ch-date-mode"><?php p($tr('Datum bestimmen nach')); ?></label><select id="ch-date-mode"><option value="folder"><?php p($tr('Ordnername, sonst Aufnahmedaten')); ?></option><option value="metadata"><?php p($tr('Aufnahmedaten, sonst Dateiname oder Ordner')); ?></option><option value="filename"><?php p($tr('Dateiname, sonst Aufnahmedaten oder Ordner')); ?></option></select><label for="ch-timezone"><?php p($tr('Zeitzone')); ?></label><select id="ch-timezone"><option>Europe/Vienna</option><option>Europe/Berlin</option><option>UTC</option><option>America/New_York</option><option>Asia/Tokyo</option></select></section>
   <section class="ch-settings-group"><h2><?php p($tr('Darstellung')); ?></h2><div class="ch-field"><span class="ch-field-label"><?php p($tr('Design')); ?></span><div class="ch-segmented" role="radiogroup" aria-label="<?php p($tr('Design')); ?>" data-control="ch-appearance"><button type="button" class="ch-seg-btn" data-value="system"><?php p($tr('System')); ?></button><button type="button" class="ch-seg-btn" data-value="light"><?php p($tr('Hell')); ?></button><button type="button" class="ch-seg-btn" data-value="dark"><?php p($tr('Dunkel')); ?></button></div></div><select id="ch-appearance" hidden><option value="system"><?php p($tr('System')); ?></option><option value="light"><?php p($tr('Hell')); ?></option><option value="dark"><?php p($tr('Dunkel')); ?></option></select><div class="ch-field"><span class="ch-field-label"><?php p($tr('Sprache')); ?></span><div class="ch-segmented" role="radiogroup" aria-label="<?php p($tr('Sprache')); ?>" data-control="ch-language"><button type="button" class="ch-seg-btn" data-value="system"><?php p($tr('System')); ?></button><button type="button" class="ch-seg-btn" data-value="de"><?php p($tr('Deutsch')); ?></button><button type="button" class="ch-seg-btn" data-value="en"><?php p($tr('English')); ?></button></div></div><select id="ch-language" hidden><option value="system"><?php p($tr('System')); ?></option><option value="de">Deutsch</option><option value="en">English</option></select></section>
   <p id="ch-settings-status" class="ch-hint" role="status"><?php p($tr('Änderungen werden automatisch gespeichert.')); ?></p>
   <section class="ch-settings-group"><h2><?php p($tr('Indexierung')); ?></h2><p id="ch-index-state"><?php p($tr('Noch nicht gestartet.')); ?></p><button id="ch-reindex"><?php p($tr('Ordner neu einlesen')); ?></button><p class="ch-hint"><?php p($tr('Neue Uploads und verschobene Dateien werden durch Nextclouds Hintergrundaufgaben regelmäßig eingelesen. „Galerie aktualisieren“ startet die Prüfung sofort.')); ?></p></section>
   <?php if (!empty($_['context']['aiUrl'])): ?><a id="ch-settings-ai-link" data-ai-entry class="ch-manage-row" href="<?php p($_['context']['aiUrl']); ?>"><span data-icon="processing"></span><span><strong><?php p($tr('KI-Verarbeitung')); ?></strong><small><?php p($tr('Läufe, Dateien und deine KI-Daten')); ?></small></span><span>›</span></a><?php endif; ?>
  </section>
 </main>
 <a id="ch-download-file" hidden download="NextGallery Server-Offline.html"><?php p($tr('Offline-Datei speichern')); ?></a>
 <dialog id="ch-offline-confirm" aria-labelledby="ch-offline-confirm-title"><h2 id="ch-offline-confirm-title"><?php p($tr('Lokale Sammlung entfernen?')); ?></h2><p><?php p($tr('Nur die gespeicherten Vorschauen dieser Galerie werden auf diesem Gerät entfernt. Originaldateien auf Nextcloud bleiben erhalten.')); ?></p><div class="ch-confirm-actions"><button type="button" id="ch-offline-confirm-cancel"><?php p($tr('Abbrechen')); ?></button><button type="button" id="ch-offline-confirm-ok" class="ch-danger"><?php p($tr('Sammlung entfernen')); ?></button></div></dialog>
 <dialog id="ch-period-dialog" aria-labelledby="ch-period-title"><form method="dialog" class="ch-dialog-head"><h2 id="ch-period-title"><?php p($tr('Zeitraum')); ?></h2><button aria-label="<?php p($tr('Schließen')); ?>" class="ch-dialog-close"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></form><aside id="ch-rail" aria-label="<?php p($tr('Zu einem Zeitraum springen')); ?>"></aside></dialog>
 <dialog id="ch-display-dialog" aria-labelledby="ch-display-title"><form method="dialog" class="ch-dialog-head"><h2 id="ch-display-title"><?php p($tr('Galerieansicht')); ?></h2><button aria-label="<?php p($tr('Schließen')); ?>" class="ch-dialog-close"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></form><?php $displayDialog=true; include __DIR__.'/display-options.php'; ?><div class="ch-dialog-hint"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg><span><?php p($tr('Gilt für alle Galerien.')); ?></span></div></dialog>
 <dialog id="ch-galleries-dialog" aria-labelledby="ch-galleries-title"><form method="dialog" class="ch-dialog-head"><h2 id="ch-galleries-title"><?php p($tr('Galerie wechseln')); ?></h2><button aria-label="<?php p($tr('Schließen')); ?>" class="ch-dialog-close"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></form><div id="ch-gallery-list"></div><button id="ch-new-gallery" class="ch-primary"><?php p($tr('Neue Galerie')); ?></button></dialog>
 <dialog id="ch-gallery-editor" aria-labelledby="ch-gallery-editor-title"><form method="dialog" class="ch-dialog-head"><h2 id="ch-gallery-editor-title"><?php p($tr('Galerie bearbeiten')); ?></h2><button aria-label="<?php p($tr('Schließen')); ?>" class="ch-dialog-close"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></form><label for="ch-gallery-edit-name"><?php p($tr('Name')); ?></label><input id="ch-gallery-edit-name" type="text" maxlength="80" required><p class="ch-hint"><?php p($tr('Diese Ordner bestimmen die Inhalte der Galerie und ihrer Karte.')); ?></p><div id="ch-roots"></div><button id="ch-add-root"><span data-icon="folder"></span><?php p($tr('Ordner hinzufügen')); ?></button><div class="ch-dialog-foot"><button id="ch-save-gallery" class="ch-primary"><?php p($tr('Speichern')); ?></button></div></dialog>
 <dialog id="ch-folder-dialog" aria-labelledby="ch-folder-title"><form method="dialog" class="ch-dialog-head"><h2 id="ch-folder-title"><?php p($tr('Ordner auswählen')); ?></h2><button aria-label="<?php p($tr('Ordnerauswahl schließen')); ?>" class="ch-dialog-close"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></form><div class="ch-folder-path"><button id="ch-folder-up" aria-label="<?php p($tr('Übergeordneter Ordner')); ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg></button><span id="ch-folder-path">/</span></div><div id="ch-folder-list"></div><div class="ch-dialog-foot"><button id="ch-select-folder" class="ch-primary" disabled><?php p($tr('Diesen Ordner auswählen')); ?></button></div></dialog>
 <dialog id="ch-viewer" aria-label="<?php p($tr('Medienansicht')); ?>"><div class="ch-viewer-top"><div><strong id="ch-viewer-name"></strong><p id="ch-viewer-date"></p></div><button id="ch-viewer-close" aria-label="<?php p($tr('Medienansicht schließen')); ?>" class="ch-viewer-icon-btn"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></div><button id="ch-viewer-prev" class="ch-viewer-nav" aria-label="<?php p($tr('Vorheriges Medium')); ?>"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button><div id="ch-viewer-media"></div><button id="ch-viewer-next" class="ch-viewer-nav" aria-label="<?php p($tr('Nächstes Medium')); ?>"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button><div class="ch-viewer-bottom"><span id="ch-viewer-detail"></span><details id="ch-analysis" hidden><summary><?php p($tr('Beschreibung und erkannter Text')); ?></summary><div id="ch-analysis-body"></div></details><a id="ch-original" target="_blank" rel="noopener" class="ch-button"><?php p($tr('Original öffnen')); ?></a></div></dialog>
  <dialog id="ch-people-reset-dialog" aria-labelledby="ch-people-reset-title">
   <form method="dialog" class="ch-dialog-head"><h2 id="ch-people-reset-title"><?php p($tr('Personenerkennung zurücksetzen?')); ?></h2><button aria-label="<?php p($tr('Schließen')); ?>" class="ch-dialog-close"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></form>
   <p><?php p($tr('Alle erkannten Gesichter, Zuordnungen und Korrekturen werden gelöscht. Originaldateien bleiben erhalten.')); ?></p>
   <p class="ch-hint"><?php p($tr('Erkennungen, die andere Konten für dieselben Dateien wiederverwenden, bleiben für diese Konten erhalten.')); ?></p>
   <label for="ch-people-reset-confirm-input"><?php p($tr('Zur Bestätigung „faces“ eingeben:')); ?></label>
   <input type="text" id="ch-people-reset-confirm-input" placeholder="faces" autocomplete="off">
   <div class="ch-confirm-actions">
    <button type="button" id="ch-people-reset-cancel"><?php p($tr('Abbrechen')); ?></button>
    <button type="button" id="ch-people-reset-ok" class="ch-danger" disabled><?php p($tr('Zurücksetzen')); ?></button>
   </div>
  </dialog>
  <dialog id="ch-people-merge-dialog" aria-labelledby="ch-people-merge-title">
   <form method="dialog" class="ch-dialog-head"><h2 id="ch-people-merge-title"><?php p($tr('Person zusammenführen')); ?></h2><button aria-label="<?php p($tr('Schließen')); ?>" class="ch-dialog-close"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></form>
   <p><?php p($tr('Wähle die Person aus, mit der diese zusammengelegt werden soll.')); ?></p>
   <div class="ch-merge-toolbar">
    <div class="ch-segmented" role="radiogroup" aria-label="<?php p($tr('Filter')); ?>">
     <button type="button" class="ch-seg-btn active" data-merge-filter="all"><?php p($tr('Alle')); ?></button>
     <button type="button" class="ch-seg-btn" data-merge-filter="single"><?php p($tr('Seltene Personen')); ?></button>
    </div>
    <input type="search" id="ch-people-merge-search" placeholder="<?php p($tr('Personen suchen …')); ?>" aria-label="<?php p($tr('Personen suchen')); ?>">
   </div>
   <div id="ch-people-merge-targets" class="ch-people-merge-list"></div>
   <div id="ch-people-merge-more-wrap" class="ch-panel-actions" hidden>
    <button type="button" id="ch-people-merge-more" class="ch-button"><?php p($tr('Weitere laden')); ?></button>
   </div>
   <div class="ch-confirm-actions">
    <button type="button" id="ch-people-merge-cancel"><?php p($tr('Abbrechen')); ?></button>
    <button type="button" id="ch-people-merge-ok" class="ch-primary" disabled><?php p($tr('Zusammenführen')); ?></button>
   </div>
  </dialog>
  <dialog id="ch-people-action-dialog" aria-labelledby="ch-people-action-title">
   <form method="dialog" class="ch-dialog-head"><h2 id="ch-people-action-title"></h2><button aria-label="<?php p($tr('Schließen')); ?>" class="ch-dialog-close"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></form>
   <p id="ch-people-action-text"></p>
   <div class="ch-confirm-actions">
    <button type="button" id="ch-people-action-cancel"><?php p($tr('Abbrechen')); ?></button>
    <button type="button" id="ch-people-action-ok" class="ch-primary"></button>
   </div>
  </dialog>
</div>
