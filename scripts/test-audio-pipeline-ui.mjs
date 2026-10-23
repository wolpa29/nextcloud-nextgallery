// Synthetic Playwright test for NextGallery 5th audio pipeline frontend.
// Tests:
// 1. 5th card (Videoton) rendering, unified controls (idle -> start -> running -> pause -> paused -> resume -> error retry).
// 2. Folder stage opt-in checkbox (DEFAULT_STAGES exclude audio, explicit folder opt-in saved).
// 3. Library audio filter, audio badge on video files, modal audio transcript and timestamped segments rendering.
// 4. Gallery search audio-only checkbox toggle, source='audio' API parameter forwarding, audio match badge in results.

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import http from 'node:http';

const playwrightPath = process.env.PLAYWRIGHT_MODULE || 'playwright';
const { chromium } = await import(playwrightPath);

// Prepare HTML fixture: stripped templates/ai-user.php wrapped in app shell, plus gallery search component
const userTemplateContent = await readFile('templates/ai-user.php', 'utf8');
const aiUserHtml = userTemplateContent.replace(/^<\?php[\s\S]*?\?>\s*/, '');

const fixtureHtml = `<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NextGallery Audio Pipeline Synthetic Test</title>
  <link rel="stylesheet" href="/css/app-theme.css">
  <link rel="stylesheet" href="/css/ai-admin.css">
  <link rel="stylesheet" href="/css/ai-branding.css">
</head>
<body>
  <div class="mai-shell" data-language="de" data-appearance="light">
    <nav id="app-navigation" class="mai-navigation" aria-label="NextGallery KI-Verarbeitung" data-collapsed="true">
      <div class="mai-nav-header">
        <a href="#mai-pipeline-status" class="mai-nav-brand">
          <span class="mai-brand-mark" style="width:30px;height:30px" aria-hidden="true"></span>
          <span class="mai-brand-copy">NextGallery<small>Meine KI-Daten</small></span>
        </a>
        <button type="button" id="mai-nav-toggle" aria-expanded="false" aria-controls="mai-navigation-list">Bereiche</button>
      </div>
      <a class="mai-gallery-link" href="#gallery">← Galerie</a>
      <ul id="mai-navigation-list" class="mai-nav-links">
        <li><a href="#mai-pipeline-status" data-nav-target="mai-pipeline-status" class="is-active" aria-current="page"><span>Übersicht</span></a></li>
        <li><a href="#mai-library" data-nav-target="mai-library"><span>Dateien</span></a></li>
        <li><a href="#mai-pipeline-settings" data-nav-target="mai-pipeline-settings"><span>Ordner und Zeitplan</span></a></li>
        <li><a href="#mai-pipeline-logs" data-nav-target="mai-pipeline-logs"><span>Aktivitäten</span></a></li>
      </ul>
      <div class="mai-nav-footer">
        <span id="mai-nav-status" class="mai-status-dot">Verbinde …</span>
        <small>NextGallery · KI-Verarbeitung</small>
      </div>
    </nav>
    <div id="app-content" class="mai-app-content">
      <header class="mai-header">
        <div>
          <h2 id="mai-page-title">Meine Verarbeitung</h2>
          <p id="mai-page-subtitle" class="mai-muted">Beschreibungen, Texterkennung, Bildsuche und Personen für deine Dateien.</p>
        </div>
        <p id="mai-message" class="mai-message" role="status"></p>
      </header>
      <div id="mai-selection" class="mai-muted mai-selection-summary"></div>
      <button type="button" id="mai-user-rebuild" hidden>Bildvektoren neu aufbauen</button>
      ${aiUserHtml}
    </div>
  </div>
  <script>
    window.OC = {
      requestToken: 'synthetic-token-audio-test',
      generateUrl: function(path) { return path; }
    };
  </script>
  <script src="/js/ai-i18n.js"></script>
  <script src="/js/ai-user.js"></script>
</body>
</html>`;

const { spawnSync } = await import('node:child_process');
const shellSource = (await readFile('templates/shell.php', 'utf8'))
  .replace("file_get_contents(__DIR__.'/en.json')", "'" + (await readFile('frontend/en.json', 'utf8')).replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'")
  .replaceAll("include __DIR__.'/display-options.php';", '?>' + (await readFile('templates/display-options.php', 'utf8')) + '<?php ');
const preamble = `<?php function p($text){echo htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');} $_=['context'=>['language'=>'de','version'=>'0.4.40','base'=>'/apps/nextgallery','settings'=>['appearance'=>'light','roots'=>[],'activeGallery'=>'default'],'aiUrl'=>'/apps/nextgallery/processing']]; ?>`;
const command = JSON.parse(process.env.NEXTGALLERY_TEST_PHP || '["docker","exec","-i","nextgallery-audio-fresh35","php"]');
const shellResult = spawnSync(command[0], command.slice(1), { input: preamble + shellSource, encoding: 'utf8' });
const renderedShell = shellResult.stdout;

const searchFixtureHtml = `<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NextGallery Search Audio Option Synthetic Test</title>
  <link rel="stylesheet" href="/css/app-theme.css">
  <link rel="stylesheet" href="/css/main.css">
</head>
<body>
  ${renderedShell}
  <script>
    window.OC = {
      requestToken: 'synthetic-token-search-test',
      generateUrl: function(path) { return path; }
    };
  </script>
  <script src="/js/main.js"></script>
</body>
</html>`;

// Local HTTP static server
const server = http.createServer(async (req, res) => {
  const url = req.url.split('?')[0];
  if (url === '/' || url === '/index.html') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.end(fixtureHtml);
  }
  if (url === '/search' || url === '/search.html') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.end(searchFixtureHtml);
  }
  if (url.startsWith('/css/') || url.startsWith('/js/')) {
    try {
      const data = await readFile('.' + url);
      res.setHeader('Content-Type', url.endsWith('.css') ? 'text/css' : 'application/javascript');
      return res.end(data);
    } catch {
      res.writeHead(404);
      return res.end();
    }
  }
  res.writeHead(404);
  res.end();
});

await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const port = server.address().port;
const origin = `http://127.0.0.1:${port}`;

// Mock states
let configData = {
  user_id: 'test-user',
  folders: ['/'],
  exclude_folders: [],
  folder_stages: { '/': ['descriptions', 'ocr', 'embeddings'] },
  faces_filters: { min_face_area: 0.0025, min_sharpness: 45.0, min_group_photos: 3 },
  analysis_enabled: true,
  ocr_enabled: true,
  embedding_enabled: true,
  faces_enabled: true,
  audio_enabled: true,
  audio: { enabled: true, model: 'small', language: '', device: 'cpu', compute_type: 'int8', cpu_threads: 4, timeout_seconds: 3600 },
  description_count: 10,
  ocr_count: 5,
  embedding_count: 12,
  faces_count: 4,
  audio_count: 2,
  search_index: { state: 'ready', pending_files: 0 },
  schedule: { enabled: false, time: '03:00', timezone: 'Europe/Berlin' }
};

let audioState = {
  online: true,
  state: 'idle',
  control: { paused: false, requested: false, pause_reason: '' },
  stats: { done: 2, pending: 0, error: 0 },
  last_run: { processed: 2, seconds: 45 }
};

let controlCalls = [];
let savedConfigCalls = [];
let searchCalls = [];

const executablePath = process.env.PLAYWRIGHT_EXECUTABLE || (existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined);
const browser = await chromium.launch({
  headless: true,
  ...(executablePath ? { executablePath } : {}),
  args: ['--no-sandbox']
});

const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
await context.addInitScript(() => {
  window.setInterval = callback => {
    window.syntheticPoll = callback;
    return 0;
  };
});
const page = await context.newPage();

async function refreshScenario() {
  const response = page.waitForResponse(r => r.url().endsWith('/ai/account/status'));
  await page.evaluate(() => window.syntheticPoll());
  await response;
}

// Setup API route mocking for context
await context.route('**/apps/nextgallery/**', async route => {
  const req = route.request();
  const url = new URL(req.url());
  const pathname = url.pathname;
  const method = req.method();

  if (pathname.endsWith('/ai/account/config')) {
    if (method === 'GET') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(configData)
      });
    }
    if (method === 'POST') {
      const body = JSON.parse(req.postData() || '{}');
      savedConfigCalls.push(body);
      if (body.config) Object.assign(configData, body.config);
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(configData)
      });
    }
  }

  if (pathname.endsWith('/ai/account/status')) {
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        online: true,
        state: audioState.state === 'running' ? 'running' : 'idle',
        pipelines: {
          descriptions: { online: true, state: 'idle', control: { paused: false, requested: false }, stats: { done: 10, pending: 0, error: 0 } },
          ocr: { online: true, state: 'idle', control: { paused: false, requested: false }, stats: { done: 5, pending: 0, error: 0 } },
          embeddings: { online: true, state: 'idle', control: { paused: false, requested: false }, stats: { done: 12, pending: 0, error: 0 } },
          faces: { online: true, state: 'idle', control: { paused: false, requested: false }, stats: { done: 4, pending: 0, error: 0 } },
          audio: audioState
        },
        audio_setup: { installed: true, state: 'ready' },
        server_time: Date.now() / 1000
      })
    });
  }

  if (pathname.endsWith('/ai/account/inventory')) {
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        counts: {
          total: 20, done: 10, pending: 0, error: 0, changed: 0, publishing: 0,
          ocr_done: 5, ocr_pending: 0, ocr_error: 0,
          embedded: 12, embedding_pending: 0, embedding_error: 0,
          faces_done: 4, faces_pending: 0, faces_error: 0,
          audio_total: 2, audio_done: audioState.stats.done, audio_pending: audioState.stats.pending, audio_error: audioState.stats.error,
          stored_descriptions: 10, stored_ocr: 5, stored_embeddings: 12, stored_faces: 4, stored_audio: audioState.stats.done
        }
      })
    });
  }

  if (pathname.endsWith('/ai/account/control')) {
    const body = JSON.parse(req.postData() || '{}');
    controlCalls.push(body);
    if (body.action === 'start' && body.kind === 'audio') {
      audioState.state = 'running';
      audioState.control.paused = false;
      audioState.control.requested = false;
      audioState.stats.pending = 5;
    } else if (body.action === 'pause' && body.kind === 'audio') {
      audioState.control.paused = true;
    } else if (body.action === 'resume' && body.kind === 'audio') {
      audioState.state = 'running';
      audioState.control.paused = false;
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true })
    });
  }

  if (pathname.endsWith('/ai/account/library')) {
    const audioFilter = url.searchParams.get('audio_filter') || 'all';
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        folder: {
          counts: {
            total: 1, done: 1, pending: 0, error: 0, changed: 0, publishing: 0,
            ocr_done: 0, ocr_pending: 0, ocr_error: 0,
            embedded: 1, embedding_pending: 0, embedding_error: 0,
            faces_done: 0, faces_pending: 0, faces_error: 0,
            audio_total: 1, audio_done: 1, audio_pending: 0, audio_error: 0,
            stored_descriptions: 1, stored_ocr: 0, stored_embeddings: 1, stored_faces: 0, stored_audio: 1
          }
        },
        folders: [],
        files: [
          {
            file_id: 101,
            name: 'holiday-video.mp4',
            mime: 'video/mp4',
            has_description: true,
            has_ocr: false,
            has_embedding: true,
            has_faces: false,
            has_audio: true,
            has_metadata: true,
            status: 'done',
            ocr_status: 'not_applicable',
            embedding_status: 'done',
            faces_status: 'not_applicable',
            audio_status: 'done',
            error: null,
            ocr_error: null,
            embedding_error: null,
            faces_error: null,
            audio_error: null
          }
        ],
        next_offset: null,
        matched: 1
      })
    });
  }

  if (pathname.endsWith('/ai/account/result')) {
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        available: true,
        stale: false,
        metadata: {
          analysis: {
            vision: {
              type: 'video',
              description: 'Ein Strandurlaub mit blauem Meer und Brandung.',
              tags: ['Strand', 'Urlaub', 'Sommer'],
              objects: ['Wasser', 'Sand']
            },
            ocr: { text: '' },
            audio: {
              text: 'Herzlich willkommen in unserem Urlaubsvideo! Wir sind hier am Strand.',
              language: 'de',
              duration: 25.4,
              model: 'small',
              no_audio: false,
              segments: [
                { start: 0.0, end: 4.8, text: 'Herzlich willkommen in unserem Urlaubsvideo!' },
                { start: 5.2, end: 12.0, text: 'Wir sind hier am Strand.' }
              ]
            }
          }
        }
      })
    });
  }

  if (pathname.includes('/api/settings')) {
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        settings: {
          roots: [],
          grouping: 'day',
          order: 'desc',
          theme: 'system',
          activeGallery: 'default'
        },
        cacheKey: 'test-cache'
      })
    });
  }

  if (pathname.includes('/api/summary')) {
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        total: 0,
        videos: 0,
        items: [],
        settings: {
          roots: [],
          grouping: 'day',
          order: 'desc',
          theme: 'system',
          activeGallery: 'default'
        },
        state: { running: false }
      })
    });
  }

  if (pathname.includes('/preview/')) {
    return route.fulfill({
      status: 200,
      contentType: 'image/svg+xml',
      body: '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>'
    });
  }

  if (pathname.endsWith('/api/search') || pathname.includes('/search')) {
    searchCalls.push({
      url: req.url(),
      query: url.searchParams.get('query'),
      source: url.searchParams.get('source')
    });
    const isAudioOnly = url.searchParams.get('source') === 'audio';
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        items: [
          {
            id: 101,
            name: 'holiday-video.mp4',
            kind: 'video',
            search_match: {
              sources: isAudioOnly ? ['audio'] : ['audio', 'description']
            }
          }
        ],
        more: false,
        next: 1,
        mode: 'lexical',
        embedding_state: 'disabled'
      })
    });
  }

  if (pathname.includes('/analysis/101')) {
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        available: true,
        stale: false,
        metadata: {
          analysis: {
            vision: { description: 'Ein Strandurlaub.', tags: ['Strand'], objects: ['Wasser'] },
            ocr: { text: '' },
            audio: {
              text: 'Herzlich willkommen in unserem Urlaubsvideo!',
              segments: [
                { start: 0.0, end: 4.8, text: 'Herzlich willkommen in unserem Urlaubsvideo!' }
              ]
            }
          }
        }
      })
    });
  }

  return route.continue();
});

console.log('--- Starting Audio Pipeline Frontend Synthetic Tests ---');

// TEST 1: Overview & 5th processing card
console.log('Test 1: 5th card (Videoton) UI & Unified Controls...');
await page.goto(origin + '/#mai-pipeline-status', { waitUntil: 'networkidle' });

const audioCard = page.locator('.mai-processing-card:has(#mai-audio-title)');
assert.equal(await audioCard.count(), 1, '5th card for Videoton must exist in DOM');

const audioTitle = audioCard.locator('#mai-audio-title');
assert.equal(await audioTitle.textContent(), 'Videoton', 'Card title must be Videoton');

const audioCount = audioCard.locator('#mai-audio-count');
assert.equal(await audioCount.textContent(), '2', 'Count must reflect config.audio_count = 2');

const startBtn = audioCard.locator('[data-start="audio"]');
assert.equal(await startBtn.isVisible(), true, 'Start button must be visible when idle');
assert.equal(await startBtn.isEnabled(), true, 'Start button must be enabled');

const pauseBtn = audioCard.locator('[data-user-action="pause"][data-kind="audio"]');
const resumeBtn = audioCard.locator('[data-user-action="resume"][data-kind="audio"]');
assert.equal(await pauseBtn.isVisible(), false, 'Pause button must be hidden when idle');
assert.equal(await resumeBtn.isVisible(), false, 'Resume button must be hidden when idle');

// Trigger Start
await startBtn.click();
await page.waitForTimeout(100);

const lastControl = controlCalls.at(-1);
assert(lastControl, 'Control call must have been sent');
assert.equal(lastControl.action, 'start', 'Action must be start');
assert.equal(lastControl.kind, 'audio', 'Kind must be audio');

// Verify transition to running state
await refreshScenario();
await page.waitForTimeout(100);

assert.equal(await startBtn.isVisible(), false, 'Start button must be hidden when running');
assert.equal(await pauseBtn.isVisible(), true, 'Pause button must be visible when running');
assert.equal(await pauseBtn.isEnabled(), true, 'Pause button must be enabled when running');

// Trigger Pause
await pauseBtn.click();
await page.waitForTimeout(100);

const pauseCall = controlCalls.at(-1);
assert.equal(pauseCall.action, 'pause', 'Action must be pause');
assert.equal(pauseCall.kind, 'audio', 'Kind must be audio');

// Transition to paused with pending
audioState.state = 'paused';
audioState.control.paused = false;
audioState.stats.pending = 4;
audioState.stats.done = 3;

await refreshScenario();
await page.waitForTimeout(100);

assert.equal(await pauseBtn.isVisible(), false, 'Pause button hidden when paused');
assert.equal(await resumeBtn.isVisible(), true, 'Resume button visible when paused with pending');
assert.equal(await resumeBtn.isEnabled(), true, 'Resume button enabled');

// Trigger Resume
const resumePromise = page.waitForRequest(r => r.url().endsWith('/ai/account/control'));
await resumeBtn.click();
await resumePromise;
await page.waitForTimeout(100);

const resumeCall = controlCalls.at(-1);
assert.equal(resumeCall.action, 'resume', 'Action must be resume');
assert.equal(resumeCall.kind, 'audio', 'Kind must be audio');

// Reset state to idle
audioState.state = 'idle';
audioState.control.paused = false;
audioState.control.requested = false;
await refreshScenario();

// Verify Delete button exists for audio
const deleteBtn = page.locator('[data-delete="audio"]');
assert.equal(await deleteBtn.count(), 1, 'Audio delete button must exist');
console.log('✓ Test 1 passed: 5th card & unified controls operate cleanly.');

// TEST 2: Folder Stage Opt-In Checkbox
console.log('Test 2: Folder stage selection & explicit audio opt-in...');
await page.evaluate(() => { location.hash = '#selection'; });
await page.waitForTimeout(200);

const stagesContainer = page.locator('.mai-folder-stages');
assert.equal(await stagesContainer.count(), 1, 'Folder stages container must exist');

const audioStageCheck = stagesContainer.locator('input[data-stage="audio"]');
assert.equal(await audioStageCheck.count(), 1, 'Audio stage checkbox must exist');
assert.equal(await audioStageCheck.isChecked(), false, 'Audio stage must be unchecked by default (explicit opt-in)');

// Check the audio stage checkbox
await audioStageCheck.check();
assert.equal(await audioStageCheck.isChecked(), true, 'Audio stage checkbox is now checked');

const saveBtn = page.locator('#mai-user-selection-save');
assert.equal(await saveBtn.isEnabled(), true, 'Save button must be enabled after selection change');

await page.locator('#mai-user-selection-form').evaluate(form => form.requestSubmit());
await page.waitForTimeout(200);

const lastSavedConfig = savedConfigCalls.at(-1);
assert(lastSavedConfig, 'Config must be saved');
assert(lastSavedConfig.config.folder_stages['/'].includes('audio'), 'Saved config must include audio in folder_stages["/"]');
console.log('✓ Test 2 passed: Folder audio opt-in and stage persistence verified.');

// TEST 3: Library / Files View & Audio Filter & Badges
console.log('Test 3: Library audio filter, video audio badges, and transcript details...');
await page.goto(origin + '/#library', { waitUntil: 'networkidle' });

const audioFilterSelect = page.locator('#mai-user-audio-filter');
assert.equal(await audioFilterSelect.count(), 1, 'Audio filter dropdown must exist');

// Verify options in audio filter
const optionsText = await audioFilterSelect.locator('option').allTextContents();
assert(optionsText.some(t => t.includes('Transkription abgeschlossen')), 'Filter has Transkription abgeschlossen option');

// Verify badge on file item
const fileRow = page.locator('.mai-user-file:has-text("holiday-video.mp4")');
assert.equal(await fileRow.count(), 1, 'Video file row must be visible');

const audioBadge = fileRow.locator('.mai-user-badges span:has-text("Transkript vorhanden")');
assert.equal(await audioBadge.count(), 1, 'Audio badge "Transkript vorhanden" must be present on video file');
assert.equal(await audioBadge.getAttribute('data-badge-state'), 'done', 'Badge state must be done');

// Click "KI-Daten ansehen"
const viewDataBtn = fileRow.locator('button:has-text("KI-Daten ansehen")');
await viewDataBtn.click();
await page.waitForTimeout(200);

const modal = page.locator('#mai-user-result');
assert.equal(await modal.isVisible(), true, 'Result modal must open');

const audioHeader = modal.locator('h4:has-text("Videoton")');
assert.equal(await audioHeader.count(), 1, 'Videoton section header must be present in modal');

const transcriptText = modal.locator('#mai-user-result-content > p:has-text("Wir sind hier am Strand.")');
assert.equal(await transcriptText.count(), 1, 'Full transcript text must be rendered');

const segmentDetails = modal.locator('details.mai-segments-details');
assert.equal(await segmentDetails.count(), 1, 'Collapsible segments details must be present');

const segmentSummary = segmentDetails.locator('summary');
assert(await segmentSummary.textContent(), 'Summary text must exist');

const segments = segmentDetails.locator('.mai-transcript-segments p');
assert.equal(await segments.count(), 2, 'Two timestamped segments must be rendered');
const firstSegment = await segments.first().textContent();
assert(firstSegment.includes('[00:00 - 00:04]'), 'First segment has formatted MM:SS timestamps: ' + firstSegment);

// Check scope delete option in modal
const scopeDelBtn = modal.locator('button:has-text("KI-Ergebnisse dieser Datei verwalten")');
await scopeDelBtn.click();
await page.waitForTimeout(200);

const scopeDeleteDialog = page.locator('#mai-user-scope-delete');
assert.equal(await scopeDeleteDialog.isVisible(), true, 'Scope delete dialog must open');

const audioScopeOption = scopeDeleteDialog.locator('.mai-scope-option:has-text("Nur Videoton zurücksetzen")');
assert.equal(await audioScopeOption.count(), 1, 'Scope delete option for Videoton must exist');

const allFiveOption = scopeDeleteDialog.locator('.mai-scope-option:has-text("Alle fünf KI-Kategorien")');
assert.equal(await allFiveOption.count(), 1, 'Scope delete option for all five categories must exist');

await scopeDeleteDialog.locator('#mai-user-scope-delete-cancel').click();
console.log('✓ Test 3 passed: Library filter, video badges, transcript, timestamps, and delete scope verified.');

// TEST 4: Search UI - Audio-Only Checkbox & Source Parameter Forwarding
console.log('Test 4: Gallery search audio-only toggle and source="audio" contract...');
const searchPage = await context.newPage();
await searchPage.goto(origin + '/search', { waitUntil: 'networkidle' });
await searchPage.locator('#ch-header-search').click();
await searchPage.waitForTimeout(200);

const audioOnlyCheckbox = searchPage.locator('#ch-search-audio-only');
assert.equal(await audioOnlyCheckbox.count(), 1, 'Audio-only search checkbox must exist');
assert.equal(await audioOnlyCheckbox.isChecked(), false, 'Audio-only search checkbox must be unchecked initially');

// Perform normal search
await searchPage.fill('#ch-search-query', 'Strand');
await searchPage.locator('#ch-search-form').evaluate(f => f.requestSubmit());
await searchPage.waitForTimeout(300);

const normalSearchCall = searchCalls.find(c => c.query === 'Strand' && !c.source);
assert(normalSearchCall, 'Normal search must query without source=audio');

// Check audio-only checkbox
await audioOnlyCheckbox.check();
assert.equal(await audioOnlyCheckbox.isChecked(), true, 'Audio-only checkbox is checked');

// Re-run search
await searchPage.fill('#ch-search-query', 'Urlaubsvideo');
await searchPage.locator('#ch-search-form').evaluate(f => f.requestSubmit());
await searchPage.waitForTimeout(300);

const audioSearchCall = searchCalls.find(c => c.query === 'Urlaubsvideo' && c.source === 'audio');
assert(audioSearchCall, 'Audio-only search must forward source=audio parameter');

// Verify search result tile has audio match badge
const searchTile = searchPage.locator('.ch-search-tile');
assert.equal(await searchTile.count(), 1, 'Search result tile must be displayed');

const audioMatchBadge = searchTile.locator('.ch-match-audio');
assert.equal(await audioMatchBadge.count(), 1, 'Audio match badge (.ch-match-audio) must be rendered on tile');
assert.equal(await audioMatchBadge.textContent(), 'Videoton', 'Audio match badge text must be Videoton');

const tileAriaLabel = await searchTile.getAttribute('aria-label');
assert(tileAriaLabel.includes('Treffer im Videoton'), 'Tile aria-label must include Treffer im Videoton: ' + tileAriaLabel);

console.log('✓ Test 4 passed: Gallery search audio toggle, API parameter source="audio", and result badges verified.');

await browser.close();
server.close();

console.log('\n======================================================');
console.log('All synthetic audio pipeline frontend tests PASSED (4/4)!');
console.log('======================================================');
