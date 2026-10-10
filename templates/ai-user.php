<?php
script('nextgallery','ai-i18n');script('nextgallery','ai-user');style('nextgallery','ai-admin');style('nextgallery','ai-branding');
?>
<div id="media-ai-user" class="mai mai-user">
 <div class="mai-header"><div><h2 id="mai-page-title">Meine Verarbeitung</h2><p class="mai-muted">Beschreibungen und Bildsuche für deine Dateien.</p></div></div>
 <div id="mai-message" role="status" aria-live="polite"></div>
 <section id="mai-pipeline-status" data-user-view="overview">
  <div class="mai-title"><h3>Deine KI-Daten</h3><span id="mai-online" class="mai-badge">Verbinde …</span></div>
  <div class="mai-processing-options">
   <section class="mai-processing-card"><h3>Beschreibungen und OCR</h3><strong id="mai-description-count" class="mai-data-count">—</strong><p class="mai-muted">Dateien mit Beschreibung, Tags, Objekten oder Text.</p><button type="button" data-start="descriptions" class="primary" disabled>Beschreibungen starten</button><p id="mai-description-progress" role="status"></p></section>
   <section class="mai-processing-card"><h3>Visuelle Bildsuche</h3><strong id="mai-vector-count" class="mai-data-count">—</strong><p class="mai-muted">Bilder und Video-Standbilder, auch ohne Beschreibung.</p><button type="button" data-start="embeddings" class="primary" disabled>Bildsuche vorbereiten</button><p id="mai-vector-progress" role="status"></p></section>
  </div>
  <section class="mai-run-surface"><h3>Aktueller Lauf</h3><p id="mai-user-run-kind" class="mai-muted"></p><p id="mai-current" role="status"></p><p id="mai-last" class="mai-muted"></p><div class="mai-actions"><button type="button" data-user-action="pause" disabled>Pausieren</button><button type="button" data-user-action="resume" disabled>Fortsetzen</button><button type="button" data-user-action="retry" disabled>Fehler erneut versuchen</button></div><p id="mai-queue-hint" class="mai-muted"></p></section>
  <p id="mai-selection" class="mai-muted"></p>
  <div id="mai-user-rebuild" class="mai-policy" hidden><strong>Bildvektoren neu aufbauen</strong><p>Der Administrator hat das Embedding-Modell geändert. Vor dem nächsten Bildsuch-Lauf bestätigst du den Neuaufbau. Alte Vektoren werden nach erfolgreichem Ersatz automatisch entfernt. Beschreibungen und Originale bleiben erhalten.</p></div>
  <details class="mai-data-section"><summary>Daten verwalten und löschen</summary><p class="mai-muted">Betrifft nur deine KI-Daten. Deine Originaldateien bleiben erhalten.</p><div class="mai-actions"><button type="button" data-delete="descriptions" class="mai-model-remove" disabled>Beschreibungsdaten löschen</button><button type="button" data-delete="embeddings" class="mai-model-remove" disabled>Bildvektoren löschen</button></div></details>
 </section>
 <section id="mai-library" data-user-view="library" hidden>
  <div class="mai-library-toolbar"><label class="mai-search"><span>Dateien suchen</span><input id="mai-user-search" type="search" maxlength="200" placeholder="Dateiname oder Pfad"></label><label>Beschreibungen<select id="mai-user-filter"><option value="all">Alle Dateien</option><option value="analyzed">Mit KI-Metadaten</option><option value="pending">Noch offen</option><option value="error">Fehler</option></select></label><button type="button" id="mai-user-library-refresh">Aktualisieren</button></div>
  <nav id="mai-user-breadcrumbs" aria-label="Ordnerpfad"></nav><div id="mai-user-file-list" aria-live="polite"></div><div class="mai-library-pagination"><span id="mai-user-file-count"></span><button type="button" id="mai-user-files-more" hidden>Weitere Dateien</button></div>
 </section>
 <section id="mai-pipeline-logs" data-user-view="activity" hidden><h3>Deine Läufe</h3><div class="mai-actions"><label>Datum<input id="mai-user-log-date" type="date"></label><button type="button" id="mai-user-log-refresh">Aktualisieren</button></div><div id="mai-user-runs" aria-live="polite"></div><button type="button" id="mai-user-logs-more" hidden>Weitere Ereignisse</button></section>
 <dialog id="mai-user-result" class="mai-model-dialog" aria-labelledby="mai-user-result-title"><h3 id="mai-user-result-title">KI-Daten</h3><div id="mai-user-result-content"></div><button type="button" id="mai-user-result-close">Schließen</button></dialog>
 <dialog id="mai-user-confirm" class="mai-model-dialog" aria-labelledby="mai-user-confirm-title" aria-describedby="mai-user-confirm-text"><h3 id="mai-user-confirm-title"></h3><p id="mai-user-confirm-text"></p><div class="mai-actions"><button type="button" id="mai-user-confirm-cancel">Abbrechen</button><button type="button" id="mai-user-confirm-ok" class="primary">Bestätigen</button></div></dialog>
</div>
