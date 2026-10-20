<?php
script('nextgallery','ai-i18n'); script('nextgallery','ai-workspace'); script('nextgallery','ai-admin'); style('nextgallery','ai-admin'); style('nextgallery','ai-branding');
?>
<div id="media-ai-admin" class="mai" data-default-view="settings">
 <div class="mai-header"><div><h2 id="mai-page-title">Server-Einstellungen</h2><p id="mai-page-description" class="mai-muted">Modelle und Dienste für alle Benutzer.</p></div></div>
 <div id="mai-message" role="status" aria-live="polite"></div>
 <div class="mai-savebar" id="mai-savebar" hidden><span data-save-status role="status" aria-live="polite">Einstellungen werden geladen …</span><button type="submit" form="mai-form" class="primary" disabled>Speichern</button><div data-save-blocked class="mai-save-blocked" hidden role="status"><span data-save-blocked-text></span><button type="button" data-show-processing>Benutzerläufe anzeigen</button></div></div>
 <dialog id="mai-model-confirm" class="mai-model-dialog" aria-labelledby="mai-model-confirm-title" aria-describedby="mai-model-confirm-text"><h3 id="mai-model-confirm-title"></h3><p id="mai-model-confirm-text"></p><div class="mai-actions"><button type="button" id="mai-model-confirm-cancel">Abbrechen</button><button type="button" id="mai-model-confirm-ok" class="primary"></button></div></dialog>
 <dialog id="mai-embedding-change" class="mai-model-dialog" aria-labelledby="mai-embedding-change-title" aria-describedby="mai-embedding-change-text"><h3 id="mai-embedding-change-title">Embedding-Modell wechseln</h3><p id="mai-embedding-change-text"></p><p id="mai-embedding-keep-reason" class="mai-muted"></p><label class="mai-check"><input type="checkbox" id="mai-embedding-auto-rebuild" checked>Ausgewählte Benutzerordner automatisch neu einreihen</label><p class="mai-muted">Gilt beim Zurücksetzen. Beschreibungen, OCR und Originale bleiben erhalten.</p><div class="mai-actions"><button type="button" id="mai-embedding-change-cancel">Abbrechen</button><button type="button" id="mai-embedding-change-keep">Vektoren behalten</button><button type="button" id="mai-embedding-change-reset" class="mai-model-remove">Alle Vektoren löschen</button></div></dialog>
 <form id="mai-form">
 <section class="mai-view mai-settings-view" id="mai-settings" data-view="settings" hidden>
  <div class="mai-section-header"><div><h3>Server-Einstellungen</h3><p class="mai-muted">Modelle und Dienste für alle Benutzer.</p></div></div>
  <p class="mai-muted">Modelle werden zentral eingerichtet. Benutzer starten ihre eigenen Läufe.</p>
  <div class="mai-actions"><button id="mai-test" type="button">Beschreibung und OCR prüfen</button></div><div id="mai-model-result" role="status"></div>
  <details id="mai-processing-admin" class="mai-model"><summary>Benutzerläufe</summary><p class="mai-muted">Beschreibungen, Texterkennung und Embeddings laufen unabhängig. Modellwechsel betreffen nur die jeweilige Verarbeitung.</p>
   <?php foreach(['descriptions'=>'Beschreibungen','ocr'=>'Texterkennung (OCR)','embeddings'=>'Visuelle Bildsuche · Embeddings','faces'=>'Personenerkennung · Gesichter'] as $kind=>$label): ?>
   <section data-admin-pipeline="<?php p($kind); ?>"><h4><?php p($label); ?></h4><p data-processing-status role="status"></p><div class="mai-actions"><button type="button" data-processing-action="pause_all" data-kind="<?php p($kind); ?>">Alle Läufe dieser Verarbeitung pausieren</button><button type="button" data-processing-action="allow_runs" data-kind="<?php p($kind); ?>" hidden>Läufe wieder erlauben</button></div><details class="mai-processing-accounts"><summary data-processing-accounts-summary>Benutzer</summary><ul data-processing-accounts></ul></details></section>
   <?php endforeach; ?>
   <p class="mai-muted">Pausieren schließt die aktuelle Datei ab, entfernt vorgemerkte Starts und sperrt neue sowie automatische Läufe dieser Verarbeitung.</p>
  </details>
  <div id="mai-model-settings"></div>
  <details id="mai-embedding-settings" class="mai-model"><summary>Visuelle Bildsuche · Embeddings</summary><label class="mai-check"><input type="checkbox" name="embedding.enabled">Für Benutzer aktivieren</label><p class="mai-muted">Bilder und Video-Standbilder; unabhängig von Beschreibungen.</p><div class="mai-grid">
   <label>Embedding-Dienst<select id="mai-embedding-provider" data-model-admin data-embedding-provider><option value="native">Gemma 2 · sparsam (CPU)</option><option value="full">Gemma 2 · Original (CPU)</option><option value="external">Externer Embedding-Server</option></select></label>
   <input type="hidden" name="embedding.location"><input type="hidden" name="embedding.local_profile"><input type="checkbox" name="embedding.local_auto_setup" hidden>
   <label data-embedding-family>Modellfamilie<select name="embedding.family"><option value="gemma2">EmbeddingGemma 2</option><option value="qwen3_vl">Qwen3-VL-Embedding</option><option value="custom">Eigenes Modell</option></select></label>
   <label data-embedding-model-id>Modell-ID<input name="embedding.model" required></label>
   <label data-embedding-external>API-Adresse des externen Servers<input type="url" name="embedding.url" required></label>
   <input name="embedding.transport" type="hidden">
   <input name="embedding.local_model_path" type="hidden">
   <label data-embedding-external>API-Key (leer = beibehalten)<input type="password" name="embedding.api_key" autocomplete="new-password"></label>
   <label data-embedding-external>Bilder/Frames pro Batch<input type="number" name="embedding.batch_size" min="1" max="64" step="1" required><span class="mai-muted">1 = einzeln; größere Werte verarbeiten mehrere Bilder gemeinsam. Ältere Dienste werden weiterhin einzeln angesprochen.</span></label>
  </div><label data-embedding-external class="mai-check"><input type="checkbox" name="embedding.clear_api_key">Gespeicherten Embedding-API-Key entfernen</label>
   <div data-embedding-external class="mai-external-install"><strong>Externen Dienst einrichten</strong><div class="mai-actions"><a data-embedding-guide href="https://github.com/wolpa29/nextcloud-nextgallery/blob/main/docs/EXTERNAL_EMBEDDING_SERVER.md" target="_blank" rel="noopener noreferrer">Installationsanleitung</a><a href="https://github.com/wolpa29/nextcloud-nextgallery/blob/main/scripts/install-embedding-server.py" target="_blank" rel="noopener noreferrer">Installationsskript</a></div></div>
   <p data-embedding-custom class="mai-muted" hidden>Eigene Modelle benötigen Bild- und Text-Embeddings. <a data-embedding-guide data-custom-guide href="https://github.com/wolpa29/nextcloud-nextgallery/blob/main/docs/EXTERNAL_EMBEDDING_SERVER.md#custom-models" target="_blank" rel="noopener noreferrer">Anleitung für eigene Modelle</a></p>
   <div class="mai-actions"><button id="mai-embedding-test" type="button">Verbindung prüfen</button></div><div id="mai-embedding-result" role="status"></div>
   <div data-embedding-requirements class="mai-model-requirements" role="note"></div>
   <p data-embedding-auto-help class="mai-muted">Download startet mit „Gemma installieren“.</p>
   <p id="mai-embedding-setup-settings" class="mai-muted" role="status" aria-live="polite" hidden></p><div class="mai-actions"><button id="mai-embedding-install-settings" type="button" data-embedding-install hidden>Gemma installieren</button><button type="button" data-embedding-remove class="mai-model-remove" hidden aria-label="Modell und Laufzeit deinstallieren" title="Modell und Laufzeit deinstallieren"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></svg> Deinstallieren</button></div>
   <details><summary>Auflösung und Video-Standbilder</summary><div class="mai-grid"><label>Max. Bildkante (Pixel)<input type="number" name="embedding.max_edge" min="256" max="4096" required></label><label>Standbilder pro Video<input type="number" name="embedding.video_frames" min="1" max="20" required></label><label>Wartezeit pro Embedding-Anfrage (Sekunden)<input type="number" name="embedding.timeout_seconds" min="15" max="7200" required></label></div><p>Mehr Standbilder erhöhen die Abdeckung und den Aufwand. Kurze Szenen zwischen den Stichproben können fehlen. Audio wird nicht eingebettet.</p></details>
   <input name="embedding.local_qwen_source" type="hidden"><input name="embedding.model_revision" type="hidden">
  </details>
  <details id="mai-faces-settings" class="mai-model"><summary>Personenerkennung · Gesichter</summary>
   <label class="mai-check"><input type="checkbox" name="faces.enabled">Für Benutzer aktivieren</label>
   <p class="mai-muted">Fotos nach Gesichtern durchsuchen und Personen zuordnen. Vollständig lokale Erkennung auf dem Server.</p>
   <label class="mai-check"><input type="checkbox" name="faces.share_inference">Gesichtserkennung zwischen Konten wiederverwenden</label>
   <p class="mai-muted">Dürfen mehrere Konten dieselbe Datei lesen, wird sie nur einmal analysiert. Wiederverwendet werden nur Gesichtspositionen und Merkmalsvektoren dieser Datei; Personengruppen und Korrekturen bleiben privat. Unabhängig von der Wiederverwendung für Beschreibungen, OCR und Bildsuche. Ausschalten entfernt den gemeinsamen Zwischenspeicher beim nächsten stündlichen Aufräumen.</p>
   <div class="mai-model-requirements" role="note">
    <strong>Modell-Herkunft und Lizenzen:</strong> YuNet (Gesichtserkennung: MIT-Lizenz) und SFace (Gesichtswiedererkennung: Apache-2.0-Lizenz) als ONNX-Modelle aus dem <a href="https://github.com/opencv/opencv_zoo" target="_blank" rel="noopener noreferrer">OpenCV Model Zoo</a>. OpenCV-Laufzeit unter Apache 2.0. Der genaue Trainingsdatensatz des SFace-Checkpoints ist im Upstream nicht näher spezifiziert (keine uneingeschränkte Rechtefreigabe). Vollständig lokale Ausführung auf dem Nextcloud-Server in isolierter Python-Umgebung.
   </div>
   <p data-faces-auto-help class="mai-muted">Modelle werden erst durch Klick auf „Gesichtsmodelle installieren“ heruntergeladen.</p>
   <p id="mai-faces-setup-settings" class="mai-muted" role="status" aria-live="polite" hidden></p>
   <div class="mai-actions"><button id="mai-faces-install-settings" type="button" data-faces-install hidden>Gesichtsmodelle installieren</button></div>
   <details><summary>Erkennungs- und Gruppierungsparameter</summary>
    <div class="mai-grid">
     <label>Kosinus-Schwellenwert (Ähnlichkeit)<input type="number" name="faces.cosine_threshold" min="0.3" max="0.95" step="0.01" required><span class="mai-muted">Standard: 0.55 (0.30 bis 0.95; höher = strengere Übereinstimmung)</span></label>
     <label>Erkennungs-Schwellenwert (Konfidenz)<input type="number" name="faces.detection_threshold" min="0.5" max="0.99" step="0.01" required><span class="mai-muted">Standard: 0.90 (0.50 bis 0.99; höher = nur deutliche Gesichter)</span></label>
     <label>Min. Gesichtsgröße (Pixel)<input type="number" name="faces.min_face_size" min="16" max="256" step="1" required><span class="mai-muted">Standard: 32 (16 bis 256; kleinere Gesichter werden ignoriert)</span></label>
     <label>Min. Gruppengröße<input type="number" name="faces.min_group_size" min="1" max="20" step="1" required><span class="mai-muted">Standard: 3 (1 bis 20; persönliche Personenfilter können diesen Wert überschreiben)</span></label>
     <label>Max. Bildkante (Pixel)<input type="number" name="faces.max_edge" min="640" max="4096" step="1" required><span class="mai-muted">Standard: 2048 (640 bis 4096)</span></label>
    </div>
   </details>
  </details>
  <div data-embedding-rebuild-notice class="mai-policy" hidden role="status"></div>
  <div id="mai-advanced-settings"><details class="mai-subsection"><summary>Speichergrenzen für Originaldateien</summary><p class="mai-muted">Diese Grenzen schützen den Worker beim Laden und Decodieren. Sie bestimmen nicht die Auflösung für das Modell. Größere Originale werden als Fehler gemeldet.</p><div class="mai-grid"><label>Max. Originaldateigröße (Bytes)<input type="number" name="max_download_bytes" min="1048576" max="536870912" required></label><label>Max. Videodateigröße (Bytes)<input type="number" name="max_video_download_bytes" min="1048576" max="4294967296" required></label><label>Max. Originalpixel beim Decodieren<input type="number" name="max_image_pixels" min="1000000" max="200000000" required></label></div></details>
  </div>
 </section>
 <details class="mai-view mai-subsection" id="mai-pipeline-settings" data-view="settings" hidden><summary>Ausführung und Dateigrenzen</summary>

  <div data-embedding-rebuild-notice class="mai-policy" hidden role="status"></div>
  <div class="mai-grid mai-options-grid">
   <input type="hidden" name="user_id">
   <label>Max. Zeit pro Datei (Sekunden, 0 = unbegrenzt)<input type="number" name="image_timeout_seconds" min="0" max="86400" required></label>
   <label>Logs aufbewahren (Tage)<input type="number" name="log_days" min="1" max="365" required></label>
   <label>Standbilder pro Video für Beschreibungen<input type="number" name="video.frame_count" min="1" max="20" required></label>
  </div>
  <details class="mai-help"><summary>Hinweise zur Verarbeitung</summary>
   <p class="mai-muted">Nur Dateien ohne KI-Metadaten werden analysiert. Vorhandene Analysen bleiben bei Modellwechseln und Dateiänderungen erhalten.</p>
   <p class="mai-muted">Die Zeit pro Datei umfasst Download, Vorbereitung, Modellanfragen und Korrekturversuche. Bei Überschreitung wird ein Fehler protokolliert und die nächste Datei bearbeitet.</p>
   <p class="mai-muted">Video-Frames werden gleichmäßig über die Dauer verteilt und gemeinsam analysiert. OCR wird bei Videos ausgelassen.</p>
  </details>
 </details>
  <div class="mai-view mai-actions mai-form-footer" data-view="settings"><button type="submit" class="primary" disabled>Speichern</button><span data-save-status role="status" aria-live="polite">Einstellungen werden geladen …</span><div data-save-blocked class="mai-save-blocked" hidden role="status"><span data-save-blocked-text></span><button type="button" data-show-processing>Benutzerläufe anzeigen</button></div></div>
 </form>

</div>
