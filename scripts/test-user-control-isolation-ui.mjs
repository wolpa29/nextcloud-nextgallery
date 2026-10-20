// Synthetic isolated Playwright test for NextGallery AI user control isolation.
// Tests pendingControls isolation per pipeline, forced refresh after mutations,
// parallel multi-pipeline dispatch, double-action prevention, non-unlocking of other lanes, and error recovery.

import assert from 'node:assert/strict';
import { mkdir, writeFile, readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import http from 'node:http';

const playwrightPath = process.env.PLAYWRIGHT_MODULE || 'playwright';
const { chromium } = await import(playwrightPath);

const outDir = 'artifacts/user-control-isolation-ui';
await mkdir(outDir, { recursive: true });

function createDeferred() {
  let resolve, reject;
  const promise = new Promise((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

// Prepare HTML fixture: stripped templates/ai-user.php wrapped in full app shell
const templateContent = await readFile('templates/ai-user.php', 'utf8');
const aiUserHtml = templateContent.replace(/^<\?php[\s\S]*?\?>\s*/, '');

const fixtureHtml = `<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NextGallery KI-Verarbeitung Synthetic Test</title>
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
      ${aiUserHtml}
    </div>
  </div>
  <script>
    window.OC = {
      requestToken: 'synthetic-token-user-isolation',
      generateUrl: function(path) { return path; }
    };
  </script>
  <script src="/js/ai-i18n.js"></script>
  <script src="/js/ai-user.js"></script>
</body>
</html>`;

// Local HTTP static server for assets
const server = http.createServer(async (req, res) => {
  const url = req.url.split('?')[0];
  if (url === '/' || url === '/index.html') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.end(fixtureHtml);
  }
  if (url.startsWith('/css/') || url.startsWith('/js/')) {
    try {
      const data = await readFile(url === '/js/ai-user.js' && process.env.CONTROL_SCRIPT_PATH ? process.env.CONTROL_SCRIPT_PATH : '.' + url);
      res.setHeader('Content-Type', url.endsWith('.css') ? 'text/css' : 'application/javascript');
      return res.end(data);
    } catch {
      res.writeHead(404);
      return res.end();
    }
  }
  if (url.endsWith('.svg')) {
    res.setHeader('Content-Type', 'image/svg+xml');
    return res.end('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"></svg>');
  }
  res.writeHead(404);
  res.end();
});

await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
// Bound deferred waits so a regression reports a failure instead of hanging.
async function waitForRequest(promise) {
  let timer;
  try {
    return await Promise.race([promise, new Promise((_, reject) => {
      timer = setTimeout(() => reject(Error('Expected request did not arrive within 10 seconds')), 10000);
    })]);
  } finally { clearTimeout(timer); }
}
const port = server.address().port;
const origin = `http://127.0.0.1:${port}`;

// State and Gate management
const configData = {
  description_count: 12,
  ocr_count: 7,
  embedding_count: 15,
  faces_enabled: true,
  faces_count: 3,
  analysis_enabled: true,
  ocr_enabled: true,
  embedding_enabled: true,
  folders: ['/'],
  exclude_folders: [],
  schedule: { enabled: false, time: '03:00', timezone: 'Europe/Berlin' }
};

let pipelineStates = {
  faces: {online:true,state:"running",control:{paused:false,requested:false},stats:{done:3,pending:2,error:1},last_run:{processed:3,seconds:10}},
  descriptions: {
    online: true,
    state: 'running',
    control: { paused: false, requested: false },
    stats: { done: 12, pending: 6, error: 1 },
    last_run: { processed: 12, seconds: 40 }
  },
  ocr: {
    online: true,
    state: 'running',
    control: { paused: false, requested: false },
    stats: { done: 7, pending: 4, error: 0 },
    last_run: { processed: 7, seconds: 25 }
  },
  embeddings: {
    online: true,
    state: 'running',
    control: { paused: false, requested: false },
    stats: { done: 15, pending: 8, error: 2 },
    last_run: { processed: 15, seconds: 55 }
  }
};

let activeGetGate = null;
const activePostGates = new Map();
const postCalls = [];
let getRefreshCounts = { config: 0, status: 0 };

class GateManager {
  static expectGet() {
    const arrived = createDeferred();
    const release = createDeferred();
    activeGetGate = { arrived, release };
    return {
      waitForArrival: () => waitForRequest(arrived.promise),
      release: () => release.resolve()
    };
  }

  static expectPost(kind) {
    const arrived = createDeferred();
    const release = createDeferred();
    activePostGates.set(kind, { arrived, release });
    return {
      waitForArrival: () => waitForRequest(arrived.promise),
      release: (customResult) => release.resolve(customResult)
    };
  }
}

const executablePath = process.env.PLAYWRIGHT_EXECUTABLE || (existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined);
const browser = await chromium.launch({
  headless: true,
  ...(executablePath ? { executablePath } : {}),
  args: ['--no-sandbox']
});

const errors = [];
const checks = [];
function check(value, label) {
  assert(value, label);
  checks.push(label);
}

async function getButtonSnapshot(page) {
  await page.mouse.move(0, 0);
  return await page.evaluate(() => {
    const cards = {};
    for (const kind of ['descriptions', 'ocr', 'embeddings', 'faces']) {
      const card = {};
      const start = document.querySelector(`[data-start="${kind}"]`);
      if (start) {
        const style = getComputedStyle(start);
        card.start = {
          disabled: start.disabled,
          hidden: start.hidden,
          color: style.color,
          opacity: style.opacity,
          backgroundColor: style.backgroundColor
        };
      }
      for (const action of ['pause', 'resume', 'retry']) {
        const btn = document.querySelector(`[data-user-action="${action}"][data-kind="${kind}"]`);
        if (btn) {
          const style = getComputedStyle(btn);
          card[action] = {
            disabled: btn.disabled,
            hidden: btn.hidden,
            color: style.color,
            opacity: style.opacity,
            backgroundColor: style.backgroundColor
          };
        }
      }
      const del = document.querySelector(`[data-delete="${kind}"]`);
      if (del) {
        const style = getComputedStyle(del);
        card.delete = {
          disabled: del.disabled,
          hidden: del.hidden,
          color: style.color,
          opacity: style.opacity,
          backgroundColor: style.backgroundColor
        };
      }
      cards[kind] = card;
    }
    return cards;
  });
}

function assertOtherPipelinesMatch(currentSnapshot, beforeSnapshot, targetKind, stageLabel) {
  for (const kind of ['descriptions', 'ocr', 'embeddings', 'faces']) {
    if (kind === targetKind) continue;
    for (const btnKey of Object.keys(beforeSnapshot[kind])) {
      const before = beforeSnapshot[kind][btnKey];
      const current = currentSnapshot[kind][btnKey];
      assert.equal(current.disabled, before.disabled,
        `${stageLabel}: Pipeline '${kind}' button '${btnKey}' changed disabled state from ${before.disabled} to ${current.disabled} during ${targetKind} action`);
      assert.equal(current.hidden, before.hidden,
        `${stageLabel}: Pipeline '${kind}' button '${btnKey}' changed hidden state from ${before.hidden} to ${current.hidden} during ${targetKind} action`);
      assert.equal(current.color, before.color,
        `${stageLabel}: Pipeline '${kind}' button '${btnKey}' changed color from '${before.color}' to '${current.color}' during ${targetKind} action`);
      assert.equal(current.backgroundColor, before.backgroundColor, `${stageLabel}: Pipeline '${kind}' button '${btnKey}' background changed`);
      assert.equal(current.opacity, before.opacity,
        `${stageLabel}: Pipeline '${kind}' button '${btnKey}' changed opacity from '${before.opacity}' to '${current.opacity}' during ${targetKind} action`);
    }
  }
}

function assertTargetButtonsDisabled(currentSnapshot, targetKind, stageLabel) {
  const target = currentSnapshot[targetKind];
  if (target.start) {
    assert.equal(target.start.disabled, true, `${stageLabel}: Target '${targetKind}' start button must be disabled`);
  }
  for (const action of ['pause', 'resume', 'retry']) {
    if (target[action] && !target[action].hidden) {
      assert.equal(target[action].disabled, true, `${stageLabel}: Target '${targetKind}' visible action button '${action}' must be disabled`);
    }
  }
  if (target.delete) {
    assert.equal(target.delete.disabled, true, `${stageLabel}: Target '${targetKind}' delete button must be disabled`);
  }
}

try {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  // Trigger polling explicitly for deterministic checks of overlapping refreshes.
  await context.addInitScript(() => { window.setInterval = callback => { window.syntheticPoll = callback; return 0; }; });
  const page = await context.newPage();
  page.on('pageerror', e => errors.push(e.message));

  // Set up API routes
  await page.route('**/apps/nextgallery/ai/account/config', async route => {
    getRefreshCounts.config++;
    if (activeGetGate) {
      activeGetGate.arrived.resolve();
      await activeGetGate.release.promise;
    }
    return route.fulfill({ json: configData });
  });

  await page.route('**/apps/nextgallery/ai/account/status', async route => {
    getRefreshCounts.status++;
    if (activeGetGate) {
      activeGetGate.arrived.resolve();
      await activeGetGate.release.promise;
    }
    return route.fulfill({
      json: {
        online: true,
        state: 'idle',
        pipelines: pipelineStates
      }
    });
  });

  await page.route('**/apps/nextgallery/ai/account/control', async route => {
    const data = route.request().postDataJSON();
    postCalls.push(data);
    const kind = data.kind;

    if (activePostGates.has(kind)) {
      const gate = activePostGates.get(kind);
      activePostGates.delete(kind);
      gate.arrived.resolve(data);
      const custom = await gate.release.promise;
      if (custom && custom.status && custom.status >= 400) {
        return route.fulfill({
          status: custom.status,
          json: custom.json || { error: 'Fehlgeschlagen' }
        });
      }
      return route.fulfill({ json: custom?.json || { ok: true } });
    }

    return route.fulfill({ json: { ok: true } });
  });

  // Navigate to synthetic page
  await page.goto(origin + '/');
  await page.waitForFunction(() => document.getElementById('media-ai-user')?.dataset.ready === '1');
  await page.waitForFunction(() => document.getElementById('mai-description-count')?.textContent === '12');

  check(await page.locator('.mai-shell').getAttribute('data-language') === 'de', 'Shell set to German language');
  check(await page.locator('#mai-online').textContent() === 'Bereit', 'Online state rendered');
  check(await page.locator('#mai-nav-status').textContent() === 'Bereit', 'Nav status synchronized');
  await page.screenshot({ path: `${outDir}/01-initial-overview.png`, fullPage: true });

  const pipelines = ['descriptions', 'ocr', 'embeddings', 'faces'];

  // Test Phase 1: Test all 3 pipelines for PAUSE with held GET davor and held POST
  for (const kind of pipelines) {
    const beforePause = await getButtonSnapshot(page);
    check(!beforePause[kind].pause.disabled && !beforePause[kind].pause.hidden, `${kind} Pause button initially visible and enabled`);

    // 1. Click Pause with held GET davor
    const getGate = GateManager.expectGet();
    await page.locator(`[data-user-action="pause"][data-kind="${kind}"]`).click();
    await getGate.waitForArrival();

    // Verify UI during held GET davor
    const duringGet = await getButtonSnapshot(page);
    assertTargetButtonsDisabled(duringGet, kind, `${kind} Pause during held GET`);
    assertOtherPipelinesMatch(duringGet, beforePause, kind, `${kind} Pause during held GET`);
    check(true, `${kind} Pause held GET davor: only target buttons disabled, other pipelines unchanged`);

    // 2. Prepare held POST, then release GET davor
    const postGate = GateManager.expectPost(kind);
    getGate.release();
    const postData = await postGate.waitForArrival();
    check(postData.action === 'pause' && postData.kind === kind, `${kind} POST control received pause action`);

    // Verify UI during held POST
    const duringPost = await getButtonSnapshot(page);
    assertTargetButtonsDisabled(duringPost, kind, `${kind} Pause during held POST`);
    assertOtherPipelinesMatch(duringPost, beforePause, kind, `${kind} Pause during held POST`);
    check(true, `${kind} Pause held POST: only target buttons disabled, other pipelines unchanged`);

    // 3. Fulfill POST and update mock state to paused
    pipelineStates[kind].control.paused = true;
    pipelineStates[kind].state = 'paused';
    const preRefreshConfigCount = getRefreshCounts.config;

    postGate.release({ json: { ok: true } });

    // Wait for mutation settling and forced refresh
    await page.waitForFunction((k) => {
      const resume = document.querySelector(`[data-user-action="resume"][data-kind="${k}"]`);
      return resume && !resume.hidden && !resume.disabled;
    }, kind);

    check(getRefreshCounts.config > preRefreshConfigCount, `${kind} refresh(true) executed after mutation`);
    const afterPause = await getButtonSnapshot(page);
    check(afterPause[kind].pause.hidden, `${kind} Pause button hidden after pause`);
    check(!afterPause[kind].resume.hidden && !afterPause[kind].resume.disabled, `${kind} Resume button visible and enabled after pause`);
    check(!afterPause[kind].start.disabled, `${kind} Start button re-enabled after pause`);
  }

  await page.screenshot({ path: `${outDir}/02-all-pipelines-paused.png`, fullPage: true });

  // Test Phase 2: Test all 3 pipelines for RESUME with held GET davor and held POST
  for (const kind of pipelines) {
    const beforeResume = await getButtonSnapshot(page);
    check(!beforeResume[kind].resume.disabled && !beforeResume[kind].resume.hidden, `${kind} Resume button initially visible and enabled`);

    // 1. Click Resume with held GET davor
    const getGate = GateManager.expectGet();
    await page.locator(`[data-user-action="resume"][data-kind="${kind}"]`).click();
    await getGate.waitForArrival();

    // Verify UI during held GET davor
    const duringGet = await getButtonSnapshot(page);
    assertTargetButtonsDisabled(duringGet, kind, `${kind} Resume during held GET`);
    assertOtherPipelinesMatch(duringGet, beforeResume, kind, `${kind} Resume during held GET`);
    check(true, `${kind} Resume held GET davor: only target buttons disabled, other pipelines unchanged`);

    // 2. Prepare held POST, then release GET davor
    const postGate = GateManager.expectPost(kind);
    getGate.release();
    const postData = await postGate.waitForArrival();
    check(postData.action === 'resume' && postData.kind === kind, `${kind} POST control received resume action`);

    // Verify UI during held POST
    const duringPost = await getButtonSnapshot(page);
    assertTargetButtonsDisabled(duringPost, kind, `${kind} Resume during held POST`);
    assertOtherPipelinesMatch(duringPost, beforeResume, kind, `${kind} Resume during held POST`);
    check(true, `${kind} Resume held POST: only target buttons disabled, other pipelines unchanged`);

    // 3. Fulfill POST and update mock state to running
    pipelineStates[kind].control.paused = false;
    pipelineStates[kind].state = 'running';
    const preRefreshConfigCount = getRefreshCounts.config;

    postGate.release({ json: { ok: true } });

    await page.waitForFunction((k) => {
      const pause = document.querySelector(`[data-user-action="pause"][data-kind="${k}"]`);
      return pause && !pause.hidden && !pause.disabled;
    }, kind);

    check(getRefreshCounts.config > preRefreshConfigCount, `${kind} refresh(true) executed after resume mutation`);
    const afterResume = await getButtonSnapshot(page);
    check(afterResume[kind].resume.hidden, `${kind} Resume button hidden after resume`);
    check(!afterResume[kind].pause.hidden && !afterResume[kind].pause.disabled, `${kind} Pause button visible and enabled after resume`);
  }

  await page.screenshot({ path: `${outDir}/03-all-pipelines-resumed.png`, fullPage: true });

  // Test Phase 3: Concurrency, Double Target Action Prevention, Non-Unlocking, and Error Recovery
  // Pipeline A = 'descriptions', Pipeline B = 'ocr'
  const beforeConcurrent = await getButtonSnapshot(page);

  // 1. Start Pause on Pipeline A (descriptions) and hold its POST
  const gateA = GateManager.expectPost('descriptions');
  await page.locator('[data-user-action="pause"][data-kind="descriptions"]').click();
  await gateA.waitForArrival();

  check(await page.locator('[data-user-action="pause"][data-kind="descriptions"]').isDisabled(), 'Pipeline A Pause button disabled during its held POST');
  check(!await page.locator('[data-user-action="pause"][data-kind="ocr"]').isDisabled(), 'Pipeline B Pause button remains enabled while Pipeline A is pending');

  // 2. Double Target Action Prevention: Attempting another click on Pipeline A must be ignored
  const initialPostCount = postCalls.length;
  await page.locator('[data-user-action="pause"][data-kind="descriptions"]').evaluate(btn => { btn.onclick(); });
  await page.locator('[data-start="descriptions"]').evaluate(btn => { btn.onclick(); });
  await page.waitForTimeout(100);
  check(postCalls.length === initialPostCount, 'Double target action prevented: no additional POST was dispatched for pipeline in pendingControls');

  // 3. Parallel action on another pipeline: Click Pause on Pipeline B (ocr) while Pipeline A is held
  const gateB = GateManager.expectPost('ocr');
  await page.locator('[data-user-action="pause"][data-kind="ocr"]').click();
  const postDataB = await gateB.waitForArrival();
  check(postDataB.action === 'pause' && postDataB.kind === 'ocr', 'Parallel action on Pipeline B reached POST control while Pipeline A is still pending');

  // Both Pipeline A and Pipeline B are now concurrently held in-flight!
  const concurrentSnapshot = await getButtonSnapshot(page);
  assertTargetButtonsDisabled(concurrentSnapshot, 'descriptions', 'Concurrent Pipeline A');
  assertTargetButtonsDisabled(concurrentSnapshot, 'ocr', 'Concurrent Pipeline B');
  // Pipeline C ('embeddings') must still match its initial snapshot!
  for (const btnKey of Object.keys(beforeConcurrent.embeddings)) {
    assert.equal(concurrentSnapshot.embeddings[btnKey].disabled, beforeConcurrent.embeddings[btnKey].disabled,
      `Pipeline C 'embeddings' button '${btnKey}' unaffected by concurrent A & B pending actions`);
    assert.equal(concurrentSnapshot.embeddings[btnKey].hidden, beforeConcurrent.embeddings[btnKey].hidden,
      `Pipeline C 'embeddings' button '${btnKey}' hidden state unaffected`);
  }
  check(true, 'Parallel actions: both pipelines pending, third pipeline fully isolated');
  await page.screenshot({ path: `${outDir}/04-concurrent-pending-pipelines.png`, fullPage: true });

  // 4. Completion of Pipeline B must NOT unlock Pipeline A
  pipelineStates.ocr.control.paused = true;
  pipelineStates.ocr.state = 'paused';
  gateB.release({ json: { ok: true } });

  // Wait for Pipeline B to settle to paused
  await page.waitForFunction(() => {
    const resumeB = document.querySelector('[data-user-action="resume"][data-kind="ocr"]');
    return resumeB && !resumeB.hidden && !resumeB.disabled;
  });

  // CRITICAL ASSERTION: Pipeline A must STILL be disabled while Pipeline B finished!
  const snapshotAfterBSettled = await getButtonSnapshot(page);
  assertTargetButtonsDisabled(snapshotAfterBSettled, 'descriptions', 'Pipeline A after Pipeline B settled');
  check(snapshotAfterBSettled.descriptions.pause.disabled, 'Pipeline A pause button STILL disabled after Pipeline B finished');
  check(snapshotAfterBSettled.descriptions.start.disabled, 'Pipeline A start button STILL disabled after Pipeline B finished');
  check(true, 'Settlement of Pipeline B did NOT unlock pending Pipeline A');
  await page.screenshot({ path: `${outDir}/05-b-settled-a-still-pending.png`, fullPage: true });

  // 5. Error Recovery: Reject Pipeline A's POST with an error. Pipeline A must unlock!
  gateA.release({ status: 500, json: { error: 'Synthetischer Dienstfehler beim Pausieren' } });

  // Wait for error message to appear
  await page.waitForFunction(() => {
    const msg = document.getElementById('mai-message');
    return msg && msg.classList.contains('mai-error') && msg.textContent.includes('Synthetischer Dienstfehler');
  });

  const snapshotAfterError = await getButtonSnapshot(page);
  check(!snapshotAfterError.descriptions.pause.disabled, 'Pipeline A pause button re-enabled (unlocked) after error');
  check(true, 'Error unlocks target pipeline correctly');
  await page.screenshot({ path: `${outDir}/06-error-unlocked-recovery.png`, fullPage: true });

  // An old poll started before a successful control response must not be the final status.
  const stalePost = GateManager.expectPost('embeddings');
  await page.locator('[data-user-action="pause"][data-kind="embeddings"]').click();
  await stalePost.waitForArrival();
  const oldStatus = structuredClone(pipelineStates);
  const pollGate = GateManager.expectGet();
  let staleStatusServed = false;
  await page.route('**/apps/nextgallery/ai/account/status', async route => {
    if (staleStatusServed) return route.fallback();
    staleStatusServed = true;
    await pollGate.waitForArrival();
    await activeGetGate.release.promise;
    return route.fulfill({ json: { online: true, state: 'idle', pipelines: oldStatus } });
  });
  await page.evaluate(() => window.syntheticPoll());
  await pollGate.waitForArrival();
  pipelineStates.embeddings.control.paused = true;
  pipelineStates.embeddings.state = 'paused';
  const oldRefreshCount = getRefreshCounts.config;
  stalePost.release({ json: { ok: true } });
  pollGate.release();
  await page.waitForFunction(() => {
    const resume = document.querySelector('[data-user-action="resume"][data-kind="embeddings"]');
    return resume && !resume.hidden && !resume.disabled;
  });
  check(staleStatusServed && getRefreshCounts.config > oldRefreshCount, 'A fresh status request replaces a stale overlapping poll after mutation');

  // A failed People run does not establish that a remote model service is unavailable.
  pipelineStates.faces.state = 'error';
  await page.evaluate(() => window.syntheticPoll());
  await page.waitForFunction(() => document.getElementById('mai-faces-queue-hint').textContent.includes('Fehlermeldungen'));
  check(!(await page.locator('#mai-faces-queue-hint').innerText()).includes('Modelldienst'), 'People errors do not invent an unavailable model service');
  const counts={total:3,done:0,changed:0,pending:0,publishing:0,error:0,ocr_done:0,ocr_pending:0,ocr_error:0,embedded:0,embedding_pending:0,embedding_error:0,faces_done:0,faces_pending:0,faces_error:3};
  await page.route('**/apps/nextgallery/ai/account/library?**',route=>route.fulfill({json:{folder:{counts},folders:[],matched:3,next_offset:null,files:[
    {file_id:1,name:'synthetic-empty.jpg',mime:'image/jpeg',faces_error:'Image data is empty (0 bytes)',faces_status:'error'},
    {file_id:2,name:'synthetic-large.jpg',mime:'image/jpeg',faces_error:'Image exceeds max pixel guard',faces_status:'error'},
    {file_id:3,name:'synthetic-response.jpg',mime:'image/jpeg',faces_error:'Expecting value: line 1 column 1 (char 0)',faces_status:'error'}
  ]}}));
  await page.locator('[data-nav-target="mai-library"]').click();
  await page.waitForFunction(()=>document.querySelectorAll('.mai-file-error').length===3);
  const messages=await page.locator('.mai-file-error>p').allTextContents();
  check(messages[0].includes('leer')&&messages[1].includes('Pixelgrenze')&&messages[2].includes('Verarbeitungsantwort'),'People file errors explain three distinct causes');
  check(!(await page.locator('.mai-file-error code').first().isVisible()),'Raw processing errors are initially collapsed');
  await page.locator('.mai-file-error summary').first().click();
  check(await page.locator('.mai-file-error code').first().isVisible(),'Original technical details remain accessible');
  await page.screenshot({path:`${outDir}/07-people-file-errors.png`,fullPage:true});

  // Verify no unexpected browser errors
  check(errors.length === 0, `No uncaught browser errors: ${errors.join('; ')}`);

  const resultData = {
    checks: checks.length,
    labels: checks,
    errors,
    synthetic_only: true,
    isolated_playwright_test: true,
    pipelines_tested: pipelines,
    pause_resume_tested: true,
    held_get_and_post_verified: true,
    parallel_action_verified: true,
    double_action_prevention_verified: true,
    cross_pipeline_unlock_prevention_verified: true,
    error_unlock_recovery_verified: true,
    refresh_force_verified: true
  };

  await writeFile(`${outDir}/result.json`, JSON.stringify(resultData, null, 2));
  console.log(JSON.stringify({ checks: checks.length, errors, success: true }));
} finally {
  await browser.close();
  server.close();
}
