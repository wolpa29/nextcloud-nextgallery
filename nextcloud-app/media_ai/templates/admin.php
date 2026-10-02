<?php
script('media_ai','admin'); style('media_ai','admin');
?>
<div id="media-ai-admin" class="mai">
 <h2>Media AI</h2><p class="mai-muted">Lokale Bildanalyse und Texterkennung</p>
 <div id="mai-message" role="status" aria-live="polite"></div>
 <section class="mai-panel"><div class="mai-title"><h3>Status</h3><span id="mai-online" class="mai-badge">Verbinde …</span></div>
  <div id="mai-counts" class="mai-counts"></div><p id="mai-current"></p><p id="mai-last" class="mai-muted"></p>
  <div class="mai-actions"><button data-action="start" class="primary">Lauf starten</button><button data-action="pause">Pausieren</button><button data-action="resume">Fortsetzen</button><button data-action="retry">Fehler erneut versuchen</button></div>
  <p class="mai-muted">Pausieren greift nach der aktuellen Datei. Ein Lauf hält die eingestellten Grenzen ein.</p>
 </section>
 <details class="mai-panel" id="mai-settings"><summary>Einstellungen</summary><form id="mai-form">
  <h3>Verarbeitung</h3><div class="mai-grid">
   <label>Nextcloud-Benutzer<input name="user_id" required></label><label>Max. Dateien pro Lauf<input type="number" name="limit" min="1" max="10000" required></label>
   <label>Ordner (ein Pfad je Zeile)<textarea name="folders" rows="3" required></textarea></label><label>Ausgeschlossene Ordner<textarea name="exclude_folders" rows="3"></textarea></label>
   <label>Täglicher Start<input type="time" name="schedule_time" required></label><label>Zeitzone<input name="timezone" required></label>
   <label>Max. Laufzeit (Sekunden)<input type="number" name="max_seconds" min="30" max="86400" required></label><label>Logs aufbewahren (Tage)<input type="number" name="log_days" min="1" max="365" required></label>
   <label>Max. Dateigröße (Bytes)<input type="number" name="max_download_bytes" required></label><label>Max. Bildpixel<input type="number" name="max_image_pixels" required></label>
  </div><label class="mai-check"><input name="enabled" type="checkbox"> Täglichen Lauf aktivieren</label>
  <div id="mai-model-settings"></div>
  <div class="mai-actions"><button type="submit" class="primary">Speichern</button><button id="mai-test" type="button">Modelle prüfen</button></div><p id="mai-model-result"></p>
 </form></details>
 <section class="mai-panel"><div class="mai-title"><h3>Aktivitätslog</h3><div class="mai-actions"><input id="mai-log-date" type="date" aria-label="Logdatum"><select id="mai-log-level" aria-label="Logfilter"><option value="all">Alle Ereignisse</option><option value="error">Nur Fehler</option></select><button id="mai-log-refresh">Aktualisieren</button></div></div>
  <p class="mai-muted">Zeit, Datei, Arbeitsschritt, Dauer und Cache-Nutzung. KI-Text steht bei der Datei im Tab „Media AI“.</p>
  <div class="mai-table"><table><thead><tr><th>Zeit</th><th>Ereignis</th><th>Datei / Details</th><th>Dauer</th></tr></thead><tbody id="mai-logs"></tbody></table></div><button id="mai-log-older" hidden>Ältere laden</button>
 </section>
</div>
