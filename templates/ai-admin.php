<?php
script('nextgallery','ai-i18n'); script('nextgallery','ai-workspace'); script('nextgallery','ai-admin'); style('nextgallery','ai-admin'); style('nextgallery','ai-branding');
?>
<div id="media-ai-admin" class="mai" data-default-view="settings">
 <div class="mai-header"><div><h2 id="mai-page-title">KI-Verarbeitung</h2><p id="mai-page-description" class="mai-muted">Bestand, Fortschritt und nächste Ausführung.</p></div></div>
 <div id="mai-message" role="status" aria-live="polite"></div>
 <div class="mai-savebar" id="mai-savebar" hidden><span data-save-status role="status" aria-live="polite">Einstellungen werden geladen …</span><button type="submit" form="mai-form" class="primary" disabled>Speichern</button></div>
 <section id="mai-pipeline-status" data-view="overview" class="mai-view">
  <div class="mai-overview-top"><div class="mai-stock"><span class="mai-eyebrow">Ausgewählte Medien</span><div class="mai-stock-value" id="mai-total">—</div><p id="mai-stock-types" class="mai-muted">Bestand wird geladen …</p></div><div class="mai-progress-visual"><div class="mai-ring" id="mai-ring" role="img" aria-label="Fortschritt der Beschreibungen"><div><strong id="mai-percent">—</strong><span>mit Beschreibung</span></div></div></div></div>
  <div class="mai-stock-metrics" id="mai-stock-metrics"></div>
  <div class="mai-overview-grid">
   <section class="mai-run-surface"><div class="mai-title"><h3>Verarbeitung</h3><span id="mai-online" class="mai-badge">Verbinde …</span></div><p id="mai-current"></p><p id="mai-stage-progress" class="mai-muted" role="status"></p><p id="mai-embedding-health" class="mai-muted" role="status" hidden></p><div id="mai-counts" class="mai-counts"></div><p id="mai-last" class="mai-muted"></p><div class="mai-actions mai-run-actions"><button type="button" data-action="start" class="primary" disabled>Lauf starten</button><button type="button" data-action="pause" disabled>Pausieren</button><button type="button" data-action="resume" disabled>Fortsetzen</button><button type="button" data-action="retry" disabled>Fehler erneut versuchen</button></div><p id="mai-control-hint" class="mai-muted"></p></section>
   <section class="mai-schedule-surface"><span class="mai-eyebrow">Nächste Ausführung</span><h3 id="mai-next-time">—</h3><p id="mai-next-count"></p><p id="mai-next-hint" class="mai-muted"></p><button type="button" data-open-view="processing">Zeitplan bearbeiten</button></section>
  </div>
  <div class="mai-overview-bottom"><section><div class="mai-section-header"><h3>Beschreibungen nach Ordner</h3><button type="button" data-open-view="library">Alle Dateien</button></div><div id="mai-folder-progress"></div></section><section><div class="mai-section-header"><h3>Letzte Beschreibungen</h3></div><div id="mai-recent"></div></section></div>
  <p id="mai-inventory-freshness" class="mai-muted"></p>
 </section>
 <section id="mai-library" data-view="library" class="mai-view" hidden>
  <div class="mai-library-toolbar"><label class="mai-search"><span>Dateien suchen</span><input id="mai-library-search" type="search" placeholder="Dateiname oder Pfad" maxlength="200"></label><label><span>Beschreibungsstatus</span><select id="mai-library-filter"><option value="all">Alle Dateien</option><option value="analyzed">Mit KI-Metadaten</option><option value="done">Aktueller Dateistand</option><option value="pending">Noch offen</option><option value="error">Fehler</option><option value="changed">Älterer Dateistand</option></select></label><button id="mai-library-refresh" type="button">Aktualisieren</button></div>
  <div class="mai-library-layout"><aside class="mai-folder-tree" aria-label="Ordnerbaum"><button type="button" id="mai-library-root">Alle ausgewählten Dateien</button><div id="mai-tree"></div></aside><div class="mai-file-content"><nav id="mai-breadcrumbs" aria-label="Ordnerpfad"></nav><div id="mai-folder-heading"></div><div id="mai-file-list" aria-live="polite"></div><div class="mai-library-pagination"><span id="mai-file-count" class="mai-muted"></span><button type="button" id="mai-files-more" hidden>Weitere Dateien</button></div></div></div>
 </section>
 <dialog id="mai-model-confirm" class="mai-model-dialog" aria-labelledby="mai-model-confirm-title"><h3 id="mai-model-confirm-title"></h3><p id="mai-model-confirm-text"></p><div class="mai-actions"><button type="button" id="mai-model-confirm-cancel">Abbrechen</button><button type="button" id="mai-model-confirm-ok" class="primary"></button></div></dialog>
 <form id="mai-form">
 <section class="mai-view" id="mai-pipeline-logs" data-view="activity" hidden><div class="mai-section-header"><h3>Aktivitätslog</h3></div><div class="mai-actions mai-log-toolbar"><input id="mai-log-date" type="date" aria-label="Logdatum"><select id="mai-log-level" aria-label="Logfilter"><option value="files" selected>Dateien und Ergebnisse</option><option value="all">Alle Ereignisse</option><option value="error">Nur Fehler</option></select><label class="mai-check"><input id="mai-log-live" type="checkbox" checked>Live aktualisieren</label><button id="mai-log-refresh" type="button">Aktualisieren</button></div>
  <div class="mai-table"><table><thead><tr><th>Zeit</th><th>Ereignis</th><th>Datei / Details</th><th>Dauer</th></tr></thead><tbody id="mai-logs"></tbody></table></div><button id="mai-log-older" type="button" hidden>Ältere laden</button>
 </section>
 <section class="mai-view mai-settings-view" id="mai-settings" data-view="settings" hidden>
  <div class="mai-section-header"><div><h3>Server-Einstellungen</h3><p class="mai-muted">Modelle und Dienste für alle Benutzer.</p></div></div>
  <p class="mai-muted">Vorhandene Beschreibungen bleiben bei einem Modellwechsel erhalten.</p>
  <div class="mai-actions"><button id="mai-test" type="button">Beschreibungsmodelle prüfen</button></div><div id="mai-model-result" role="status"></div>
  <div id="mai-model-settings"></div>
  <details id="mai-embedding-settings" class="mai-model"><summary>Visuelle Bildsuche · Embeddings</summary><label class="mai-check"><input type="checkbox" name="embedding.enabled">Für Benutzer aktivieren</label><p class="mai-muted">Bilder und Video-Standbilder; unabhängig von Beschreibungen.</p><div class="mai-actions"><button id="mai-embedding-test" type="button">Verbindung prüfen</button></div><div id="mai-embedding-result" role="status"></div><div class="mai-grid">
   <label>Embedding-Dienst<select id="mai-embedding-provider" data-model-admin data-embedding-provider><option value="native">Gemma 2 · sparsam (CPU)</option><option value="full">Gemma 2 · Original, mehr Bilddetails (CPU)</option><option value="external">Externer Embedding-Server</option></select></label>
   <input type="hidden" name="embedding.location"><input type="hidden" name="embedding.local_profile"><input type="checkbox" name="embedding.local_auto_setup" hidden>
   <label data-embedding-family>Modellfamilie<select name="embedding.family"><option value="gemma2">EmbeddingGemma 2</option><option value="qwen3_vl">Qwen3-VL-Embedding</option></select></label>
   <label data-embedding-model-id>Modell-ID<input name="embedding.model" required></label>
   <label data-embedding-external>API-Adresse des externen Servers<input type="url" name="embedding.url" required></label>
   <input name="embedding.transport" type="hidden">
   <input name="embedding.local_model_path" type="hidden">
   <label data-embedding-external>API-Key (leer = beibehalten)<input type="password" name="embedding.api_key" autocomplete="new-password"></label>
  </div><label data-embedding-external class="mai-check"><input type="checkbox" name="embedding.clear_api_key">Gespeicherten Embedding-API-Key entfernen</label>
   <div data-embedding-external class="mai-external-install"><strong>Externen Dienst einrichten</strong><p>Der Embedding-Dienst läuft zusätzlich zu LM Studio.</p><div class="mai-actions"><a href="https://github.com/wolpa29/nextcloud-nextgallery/blob/main/docs/EXTERNAL_EMBEDDING_SERVER.md" target="_blank" rel="noopener noreferrer">Installationsanleitung</a><a href="https://github.com/wolpa29/nextcloud-nextgallery/blob/main/scripts/install-embedding-server.py" target="_blank" rel="noopener noreferrer">Installationsskript</a></div></div>
   <div data-embedding-requirements class="mai-model-requirements" role="note"></div>
   <p data-embedding-auto-help class="mai-muted">Download startet mit „Gemma installieren“.</p>
   <p id="mai-embedding-setup-settings" class="mai-muted" role="status" aria-live="polite" hidden></p><div class="mai-actions"><button id="mai-embedding-install-settings" type="button" data-embedding-install hidden>Gemma installieren</button><button type="button" data-embedding-remove class="mai-model-remove" hidden aria-label="Modell und Laufzeit deinstallieren" title="Modell und Laufzeit deinstallieren"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></svg> Deinstallieren</button></div>
   <details><summary>Auflösung und Video-Standbilder</summary><div class="mai-grid"><label>Max. Bildkante (Pixel)<input type="number" name="embedding.max_edge" min="256" max="4096" required></label><label>Standbilder pro Video<input type="number" name="embedding.video_frames" min="1" max="20" required></label><label>Wartezeit pro Embedding-Anfrage (Sekunden)<input type="number" name="embedding.timeout_seconds" min="15" max="7200" required></label></div><p>Mehr Standbilder erhöhen die Abdeckung und den Aufwand. Kurze Szenen zwischen den Stichproben können fehlen. Audio wird nicht eingebettet.</p></details>
   <input name="embedding.local_qwen_source" type="hidden"><input name="embedding.model_revision" type="hidden">
   <p class="mai-muted">Modellwechsel erfordern neue Bildvektoren. Der nächste Lauf fragt nach deiner Bestätigung.</p>
  </details>
  <div data-embedding-rebuild-notice class="mai-policy" hidden role="status"></div>
  <div id="mai-advanced-settings"><details class="mai-subsection"><summary>Speichergrenzen für Originaldateien</summary><p class="mai-muted">Diese Grenzen schützen den Worker beim Laden und Decodieren. Sie bestimmen nicht die Auflösung für das Modell. Größere Originale werden als Fehler gemeldet.</p><div class="mai-grid"><label>Max. Originaldateigröße (Bytes)<input type="number" name="max_download_bytes" min="1048576" max="536870912" required></label><label>Max. Videodateigröße (Bytes)<input type="number" name="max_video_download_bytes" min="1048576" max="2147483648" required></label><label>Max. Originalpixel beim Decodieren<input type="number" name="max_image_pixels" min="1000000" max="200000000" required></label></div></details>
  <p id="mai-permissions" class="mai-muted"></p>
  <div class="mai-actions mai-form-footer"><button type="submit" class="primary" disabled>Speichern</button><span data-save-status role="status" aria-live="polite">Einstellungen werden geladen …</span></div>
  </div>
 </section>
 <details class="mai-view mai-subsection" id="mai-pipeline-settings" data-view="settings" hidden><summary>Ausführung und Dateigrenzen</summary>

  <div data-embedding-rebuild-notice class="mai-policy" hidden role="status"></div>
  <p id="mai-search-explanation" hidden></p>
  <div class="mai-grid mai-folder-grid" hidden>
   <div class="mai-folder-field"><label>Ordner (ein Pfad je Zeile)<textarea name="folders" rows="2" required></textarea></label><button id="mai-pick-folders" type="button">Ordner auswählen</button></div>
   <div class="mai-folder-field"><label>Ausgeschlossene Ordner<textarea name="exclude_folders" rows="2"></textarea></label><button id="mai-pick-excludes" type="button">Ausschlüsse auswählen</button></div>
  </div>
  <div class="mai-grid mai-options-grid">
   <input type="hidden" name="user_id">
   <label>Max. Zeit pro Datei (Sekunden, 0 = unbegrenzt)<input type="number" name="image_timeout_seconds" min="0" max="86400" required></label>
   <label>Logs aufbewahren (Tage)<input type="number" name="log_days" min="1" max="365" required></label>
   <label>Standbilder pro Video für Beschreibungen<input type="number" name="video.frame_count" min="1" max="20" required></label>
  </div>
  <div class="mai-schedule" hidden>
   <label class="mai-check"><input name="enabled" type="checkbox"> Täglichen Lauf für das bestehende Verarbeitungskonto aktivieren</label>
   <div class="mai-grid mai-options-grid"><label>Täglicher Start<input type="time" name="schedule_time" required></label><label>Zeitzone<input name="timezone" required></label></div>
  </div>
  <details class="mai-help"><summary>Hinweise zur Verarbeitung</summary>
   <p class="mai-muted">Nur Dateien ohne KI-Metadaten werden analysiert. Vorhandene Analysen bleiben bei Modellwechseln und Dateiänderungen erhalten.</p>
   <p class="mai-muted">Die Zeit pro Datei umfasst Download, Vorbereitung, Modellanfragen und Korrekturversuche. Bei Überschreitung wird ein Fehler protokolliert und die nächste Datei bearbeitet.</p>
   <p class="mai-muted">Video-Frames werden gleichmäßig über die Dauer verteilt und gemeinsam analysiert. OCR wird bei Videos ausgelassen.</p>
  </details>
  <div class="mai-actions mai-form-footer"><button type="submit" class="primary" disabled>Speichern</button><span data-save-status role="status" aria-live="polite">Einstellungen werden geladen …</span></div>
 </details>
 </form>
 <section class="mai-view mai-settings-view" id="mai-accounts" data-view="accounts" hidden><h3>Benutzer</h3><p class="mai-muted">Ordner und Zeitplan je Benutzer. Die Modelle gelten für alle.</p>
  <div class="mai-actions"><input id="mai-account-search" type="search" placeholder="Benutzer suchen" maxlength="100"><button type="button" id="mai-account-search-button">Suchen</button></div>
  <label>Benutzer<select id="mai-account-select"></select></label><form id="mai-account-form"><div class="mai-grid"><label>Ordner (ein Pfad je Zeile)<textarea name="folders" rows="3" required></textarea></label><label>Ausgeschlossene Ordner<textarea name="exclude_folders" rows="3"></textarea></label></div><label class="mai-check"><input name="enabled" type="checkbox">Täglichen Lauf aktivieren</label><div class="mai-grid"><label>Startzeit<input name="schedule_time" type="time" required></label><label>Zeitzone<input name="timezone" required></label></div><p class="mai-muted">Zeitpläne laufen nach dem ersten Start. Pausieren stoppt auch automatische Läufe.</p><button type="submit" class="primary">Benutzereinstellungen speichern</button><p id="mai-account-message" role="status"></p></form></section>
 <section class="mai-view mai-settings-view" id="mai-pipeline-maintenance" data-view="maintenance" hidden><h3>Wartung</h3><p class="mai-muted">Diese Aktionen betreffen nur Media AI. Originaldateien und andere Nextcloud-Daten bleiben erhalten.</p><p id="mai-existing-count" class="mai-muted"></p><p class="mai-muted">Vorhandene Analysen werden nie automatisch überschrieben. Für eine vollständige Neuanalyse zuerst die KI-Daten löschen und danach einen Lauf starten.</p><div class="mai-actions mai-maintenance-actions"><button id="mai-clear-logs" type="button">Aktivitätslog leeren</button><button id="mai-reset-ai" type="button">Alle KI-Daten löschen</button></div></section>
 <dialog id="mai-maintenance"><h3 id="mai-maintenance-title"></h3><p id="mai-maintenance-info"></p><p id="mai-maintenance-count"></p><label class="mai-check"><input id="mai-maintenance-check" type="checkbox">Ich bestätige diese Aktion.</label><div class="mai-actions"><button id="mai-maintenance-confirm" type="button" disabled>Bestätigen</button><button id="mai-maintenance-cancel" type="button">Abbrechen</button></div></dialog>
 <dialog id="mai-picker"><h3>Ordner auswählen</h3><p id="mai-picker-path"></p><div class="mai-actions"><button id="mai-picker-parent" type="button">Übergeordneter Ordner</button><label class="mai-check"><input id="mai-picker-current" type="checkbox">Diesen Ordner einbeziehen</label></div><div id="mai-picker-list"></div><p id="mai-picker-selected" class="mai-muted"></p><div class="mai-actions"><button id="mai-picker-apply" class="primary" type="button">Übernehmen</button><button id="mai-picker-close" type="button">Abbrechen</button></div></dialog>
</div>
