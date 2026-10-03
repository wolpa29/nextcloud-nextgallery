<?php
script('media_ai','i18n'); script('media_ai','admin'); style('media_ai','admin');
?>
<div id="media-ai-admin" class="mai">
 <div class="mai-title"><h2>Media AI</h2><label class="mai-language">Sprache<select id="mai-language" aria-label="Sprache"><option value="de">Deutsch</option><option value="en">English</option></select></label></div><p class="mai-muted">Lokale Bildanalyse und Texterkennung</p>
 <div id="mai-message" role="status" aria-live="polite"></div>
 <section class="mai-panel"><div class="mai-title"><h3>Status</h3><span id="mai-online" class="mai-badge">Verbinde …</span></div>
  <div id="mai-counts" class="mai-counts"></div><p id="mai-current"></p><p id="mai-last" class="mai-muted"></p>
  <div class="mai-actions"><button data-action="start" class="primary">Lauf starten</button><button data-action="pause">Pausieren</button><button data-action="resume">Fortsetzen</button><button data-action="retry">Fehler erneut versuchen</button></div>
  <p class="mai-muted">Ein Start verarbeitet alle offenen Bilder der gewählten Ordner. Pausieren greift nach der aktuellen Datei.</p>
 </section>
 <details class="mai-panel" id="mai-settings"><summary>Einstellungen</summary><form id="mai-form">
  <h3>Verarbeitung</h3><div class="mai-grid">
   <label>Nextcloud-Benutzer<input name="user_id" required></label><label>Max. Zeit pro Bild (Sekunden, 0 = unbegrenzt)<input type="number" name="image_timeout_seconds" min="0" max="86400" required></label>
   <label>Ordner (ein Pfad je Zeile)<textarea name="folders" rows="3" required></textarea></label><label>Ausgeschlossene Ordner<textarea name="exclude_folders" rows="3"></textarea></label>
   <div><button id="mai-pick-folders" type="button">Ordner auswählen</button><button id="mai-pick-excludes" type="button">Ausschlüsse auswählen</button></div>
   <label>Täglicher Start<input type="time" name="schedule_time" required></label><label>Zeitzone<input name="timezone" required></label>
   <label>Logs aufbewahren (Tage)<input type="number" name="log_days" min="1" max="365" required></label>
  </div><label class="mai-check"><input name="enabled" type="checkbox"> Täglichen Lauf aktivieren</label>
  <p class="mai-muted">Kein Datei- oder Gesamtzeitlimit: Unveränderte fertige Bilder werden übersprungen. Fortsetzen bearbeitet die restlichen Bilder.</p>
  <p class="mai-muted">Die Zeit pro Bild umfasst Download, Bildanalyse, OCR und Korrekturversuche zusammen. Bei Überschreitung wird ein Fehler protokolliert und das nächste Bild bearbeitet.</p>
  <details><summary>Speichergrenzen für Originaldateien</summary><p class="mai-muted">Diese Grenzen schützen den Worker beim Laden und Decodieren. Sie bestimmen nicht die Auflösung für das Modell. Größere Originale werden als Fehler gemeldet.</p><div class="mai-grid"><label>Max. Originaldateigröße (Bytes)<input type="number" name="max_download_bytes" min="1048576" max="536870912" required></label><label>Max. Originalpixel beim Decodieren<input type="number" name="max_image_pixels" min="1000000" max="200000000" required></label></div></details>
  <p id="mai-permissions" class="mai-muted"></p><div id="mai-model-settings"></div>
  <div class="mai-actions"><button type="submit" class="primary">Speichern</button><button id="mai-test" type="button">Modelle prüfen</button></div><p id="mai-model-result"></p>
 </form></details>
 <section class="mai-panel"><div class="mai-title"><h3>Aktivitätslog</h3><div class="mai-actions"><input id="mai-log-date" type="date" aria-label="Logdatum"><select id="mai-log-level" aria-label="Logfilter"><option value="files" selected>Dateien und Ergebnisse</option><option value="all">Alle Ereignisse</option><option value="error">Nur Fehler</option></select><label class="mai-check"><input id="mai-log-live" type="checkbox" checked>Live aktualisieren</label><button id="mai-log-refresh">Aktualisieren</button></div></div>
  <p class="mai-muted">Zeit, Datei, Arbeitsschritt, Dauer und Cache-Nutzung. KI-Text steht bei der Datei im Tab „Media AI“.</p>
  <div class="mai-table"><table><thead><tr><th>Zeit</th><th>Ereignis</th><th>Datei / Details</th><th>Dauer</th></tr></thead><tbody id="mai-logs"></tbody></table></div><button id="mai-log-older" hidden>Ältere laden</button>
 </section>
 <details class="mai-panel"><summary>Wartung</summary><p class="mai-muted">Diese Aktionen betreffen nur Media AI. Originaldateien und andere Nextcloud-Daten bleiben erhalten.</p><div class="mai-actions"><button id="mai-clear-logs" type="button">Aktivitätslog leeren</button><button id="mai-reset-ai" type="button">KI-Daten zurücksetzen</button></div></details>
 <dialog id="mai-maintenance"><h3 id="mai-maintenance-title"></h3><p id="mai-maintenance-info"></p><label class="mai-check"><input id="mai-maintenance-check" type="checkbox">Ich bestätige diese Aktion.</label><div class="mai-actions"><button id="mai-maintenance-confirm" type="button" disabled>Bestätigen</button><button id="mai-maintenance-cancel" type="button">Abbrechen</button></div></dialog>
 <dialog id="mai-picker"><h3>Ordner auswählen</h3><p id="mai-picker-path"></p><div class="mai-actions"><button id="mai-picker-parent" type="button">Übergeordneter Ordner</button><label class="mai-check"><input id="mai-picker-current" type="checkbox">Diesen Ordner einbeziehen</label></div><div id="mai-picker-list"></div><p id="mai-picker-selected" class="mai-muted"></p><div class="mai-actions"><button id="mai-picker-apply" class="primary" type="button">Übernehmen</button><button id="mai-picker-close" type="button">Abbrechen</button></div></dialog>
</div>
