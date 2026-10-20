import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildSubjectsQuery,
  buildActionPayload,
  buildResetPayload,
  formatPhotoCount,
  formatPeopleCount,
  createPeopleController
} from '../frontend/faces.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');
const facesMjsContent = readFileSync(resolve(ROOT, 'frontend/faces.mjs'), 'utf8');
const facesMjsDataUri = 'data:text/javascript;base64,' + Buffer.from(facesMjsContent).toString('base64');

test('buildSubjectsQuery produces correct contract URL parameters', () => {
  assert.equal(
    buildSubjectsQuery(),
    'limit=60&filter=all'
  );
  assert.equal(
    buildSubjectsQuery({ gallery: 'vacation', cursor: 'c123', limit: 60, filter: 'review' }),
    'gallery=vacation&cursor=c123&limit=60&filter=review'
  );
  assert.equal(
    buildSubjectsQuery({ filter: 'hidden' }),
    'limit=60&filter=hidden'
  );
  assert.equal(
    buildSubjectsQuery({ filter: 'single' }),
    'limit=60&filter=single'
  );
  // Invalid filter falls back to 'all'
  assert.equal(
    buildSubjectsQuery({ filter: 'unknown' }),
    'limit=60&filter=all'
  );
});

test('buildActionPayload validates actions and structures contract payloads', () => {
  // Hide action
  assert.deepEqual(
    buildActionPayload({ action: 'hide', rules_revision: 3 }),
    { action: 'hide', rules_revision: 3 }
  );

  // Show action
  assert.deepEqual(
    buildActionPayload({ action: 'show', rules_revision: 0 }),
    { action: 'show', rules_revision: 0 }
  );

  // Merge action requires target_id
  assert.deepEqual(
    buildActionPayload({ action: 'merge', target_id: 'target-uuid-123', rules_revision: 7 }),
    { action: 'merge', target_id: 'target-uuid-123', rules_revision: 7 }
  );

  // Split action with detection_ids
  assert.deepEqual(
    buildActionPayload({ action: 'split', detection_ids: ['d1', 'd2'], rules_revision: 4 }),
    { action: 'split', detection_ids: ['d1', 'd2'], rules_revision: 4 }
  );

  // Remove action with detection_ids
  assert.deepEqual(
    buildActionPayload({ action: 'remove', detection_ids: ['d3'], rules_revision: 2 }),
    { action: 'remove', detection_ids: ['d3'], rules_revision: 2 }
  );

  // Resolve action
  assert.deepEqual(
    buildActionPayload({ action: 'resolve', rules_revision: 1 }),
    { action: 'resolve', rules_revision: 1 }
  );

  // Disallowed action throws
  assert.throws(() => {
    buildActionPayload({ action: 'delete_all', rules_revision: 1 });
  }, /Invalid action/);

  // Missing rules_revision throws
  assert.throws(() => {
    buildActionPayload({ action: 'hide' });
  }, /rules_revision must be an integer/);
});

test('buildResetPayload requires typed confirmation faces', () => {
  assert.deepEqual(
    buildResetPayload('faces'),
    { confirmation: 'faces' }
  );

  assert.throws(() => {
    buildResetPayload('yes');
  }, /confirmation must be "faces"/);

  assert.throws(() => {
    buildResetPayload('');
  }, /confirmation must be "faces"/);
});

test('formatPhotoCount and formatPeopleCount format numbers correctly', () => {
  const tr = (k, v) => {
    if (v && v.count !== undefined) return k.replace('{count}', String(v.count));
    return k;
  };

  assert.equal(formatPhotoCount(1, tr), '1 Foto');
  assert.equal(formatPhotoCount(0, tr), '0 Fotos');
  assert.equal(formatPhotoCount(12, tr), '12 Fotos');

  assert.equal(formatPeopleCount(1, tr), '1 Person');
  assert.equal(formatPeopleCount(0, tr), '0 Personen');
  assert.equal(formatPeopleCount(5, tr), '5 Personen');
});

test('createPeopleController exposes expected public API interface', () => {
  // Mock a minimal DOM root
  const elements = new Map();
  const makeEl = (id, tag = 'div') => {
    const el = {
      id,
      tagName: tag.toUpperCase(),
      hidden: false,
      textContent: '',
      dataset: {},
      replaceChildren: () => {},
      querySelector: (s) => null,
      querySelectorAll: (s) => [],
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => true,
      classList: {
        add: () => {},
        remove: () => {},
        toggle: () => {},
        contains: () => false
      }
    };
    elements.set(id, el);
    return el;
  };

  const root = {
    querySelector: (sel) => {
      if (sel.startsWith('#')) return elements.get(sel.slice(1)) || null;
      return null;
    },
    querySelectorAll: (sel) => []
  };

  ['ch-people-error', 'ch-people-count', 'ch-people-grid', 'ch-people-more',
   'ch-person-detail', 'ch-person-count-detail', 'ch-person-media-grid',
   'ch-person-media-more', 'ch-person-selection-bar', 'ch-person-selection-count',
   'ch-people-merge-dialog', 'ch-people-reset-dialog', 'ch-people-action-dialog'
  ].forEach(id => makeEl(id));

  const controller = createPeopleController({
    root,
    api: async () => ({ items: [] }),
    getGallery: () => 'default'
  });

  assert.ok(controller, 'controller created');
  assert.equal(typeof controller.show, 'function');
  assert.equal(typeof controller.hide, 'function');
  assert.equal(typeof controller.refresh, 'function');
  assert.equal(typeof controller.openSubject, 'function');
  assert.equal(typeof controller.closeDetail, 'function');
  assert.equal(typeof controller.setFilter, 'function');
  assert.equal(typeof controller.getFilter, 'function');
  assert.equal(typeof controller.getRulesRevision, 'function');
  assert.equal(typeof controller.getSelectedSubject, 'function');
  assert.equal(typeof controller.getState, 'function');
  assert.equal(typeof controller.onGalleryChange, 'function');

  assert.equal(controller.getFilter(), 'all');
  assert.equal(controller.getSelectedSubject(), null);
  const state = controller.getState();
  assert.equal(state.filter, 'all');
  assert.equal(state.selectedSubject, null);
  assert.equal(state.rulesRevision, 0);
});

// Real DOM & Browser Interaction Tests via Playwright
let chromium = null;
try {
  ({chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright'));
} catch {}

const HTML_SHELL = `<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body>
  <div id="nextgallery">
    <section id="ch-people-view" class="ch-panel">
      <div class="ch-people-toolbar">
        <div class="ch-filters" role="group">
          <select id="ch-people-filter">
            <option value="all">Alle</option>
            <option value="review">Überprüfung</option>
            <option value="single">Einzelfunde</option>
            <option value="hidden">Ausgeblendet</option>
          </select>
          <button data-people-filter="all" class="active">Alle</button>
          <button data-people-filter="review">Überprüfung</button>
          <button data-people-filter="single">Einzelfunde</button>
          <button data-people-filter="hidden">Ausgeblendet</button>
        </div>
        <div class="ch-people-top-actions">
          <button id="ch-people-refresh">Aktualisieren</button>
          <button id="ch-people-reset" class="ch-danger">Zurücksetzen</button>
        </div>
      </div>
      <div id="ch-people-error" class="ch-error-banner" hidden></div>
      <div id="ch-people-loading" class="ch-people-loading" hidden><span class="ch-spinner"></span> Wird geladen …</div>
      <div id="ch-people-list-container">
        <div id="ch-people-grid" role="list"></div>
        <div id="ch-people-more-wrap" hidden>
          <button id="ch-people-more">Weitere Personen laden</button>
        </div>
        <div id="ch-people-empty" hidden>
          <h2>Keine Personen gefunden</h2>
          <p id="ch-people-empty-hint"></p>
        </div>
      </div>
      <div id="ch-person-detail" hidden>
        <div class="ch-people-detail-head">
          <button id="ch-people-back">← Alle Personen</button>
          <div class="ch-people-detail-title-wrap">
            <h2 id="ch-people-detail-title">Person</h2>
            <p id="ch-people-detail-count"></p>
          </div>
          <div class="ch-people-detail-actions">
            <button id="ch-people-toggle-hide">Ausblenden</button>
            <button id="ch-people-merge">Zusammenführen</button>
            <button id="ch-people-resolve" hidden>Überprüfung auflösen</button>
          </div>
        </div>
        <div id="ch-person-selection-bar" hidden>
          <span id="ch-people-selection-count"></span>
          <div class="ch-selection-actions">
            <button id="ch-people-split-selected">Abtrennen</button>
            <button id="ch-people-remove-selected" class="ch-danger">Entfernen</button>
            <button id="ch-people-resolve-selected">Bestätigen</button>
            <button id="ch-people-clear-selected">Auswahl aufheben</button>
          </div>
        </div>
        <div id="ch-person-media-grid" role="list"></div>
        <div id="ch-people-media-more-wrap" hidden>
          <button id="ch-people-media-more">Weitere Fotos laden</button>
        </div>
      </div>
    </section>

    <!-- Dialogs placed outside #ch-people-view, matching shell.php exactly -->
    <dialog id="ch-people-reset-dialog">
      <form method="dialog" class="ch-dialog-head">
        <h2>Personenerkennung zurücksetzen?</h2>
      </form>
      <input type="text" id="ch-people-reset-confirm-input" placeholder="faces">
      <div class="ch-confirm-actions">
        <button type="button" id="ch-people-reset-cancel">Abbrechen</button>
        <button type="button" id="ch-people-reset-ok" class="ch-danger" disabled>Zurücksetzen</button>
      </div>
    </dialog>
    <dialog id="ch-people-merge-dialog">
      <form method="dialog" class="ch-dialog-head">
        <h2>Person zusammenführen</h2>
      </form>
      <div class="ch-merge-toolbar">
        <div class="ch-segmented">
          <button type="button" class="ch-seg-btn active" data-merge-filter="all">Alle</button>
          <button type="button" class="ch-seg-btn" data-merge-filter="single">Einzelfunde</button>
        </div>
        <input type="search" id="ch-people-merge-search" placeholder="Personen suchen …">
      </div>
      <div id="ch-people-merge-targets" class="ch-people-merge-list"></div>
      <div id="ch-people-merge-more-wrap" hidden>
        <button type="button" id="ch-people-merge-more">Weitere laden</button>
      </div>
      <div class="ch-confirm-actions">
        <button type="button" id="ch-people-merge-cancel">Abbrechen</button>
        <button type="button" id="ch-people-merge-ok" class="ch-primary" disabled>Zusammenführen</button>
      </div>
    </dialog>
    <dialog id="ch-people-action-dialog">
      <form method="dialog" class="ch-dialog-head">
        <h2 id="ch-people-action-title"></h2>
      </form>
      <p id="ch-people-action-text"></p>
      <div class="ch-confirm-actions">
        <button type="button" id="ch-people-action-cancel">Abbrechen</button>
        <button type="button" id="ch-people-action-ok" class="ch-primary"></button>
      </div>
    </dialog>
  </div>
</body>
</html>`;

test('Real DOM: Reset dialog requires faces confirmation and fails closed on cancel', async (t) => {
  if (!chromium) {
    t.skip('Playwright not available');
    return;
  }

  const browser = await chromium.launch({
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE || undefined,
    headless: true,
    args: ['--no-sandbox']
  });
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.route('**/faces.mjs', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/javascript',
      body: facesMjsContent
    });
  });

  await page.setContent(HTML_SHELL);

  await page.evaluate(async (modUrl) => {
    const { createPeopleController } = await import(modUrl);
    window.resetCalls = 0;
    window.controller = createPeopleController({
      root: document.getElementById('ch-people-view'),
      dialogRoot: document.getElementById('nextgallery'),
      api: async (endpoint, options = {}) => {
        if (endpoint === 'subjects/reset') {
          window.resetCalls++;
          return { ok: true };
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });
  }, facesMjsDataUri);

  // Click reset button to open dialog
  await page.locator('#ch-people-reset').click();
  const isOpen = await page.evaluate(() => document.getElementById('ch-people-reset-dialog').open);
  assert.equal(isOpen, true, 'Reset dialog is opened');

  // Verify OK button is disabled initially
  const okDisabled = await page.locator('#ch-people-reset-ok').isDisabled();
  assert.equal(okDisabled, true, 'Reset OK button initially disabled');

  // Type wrong word
  await page.locator('#ch-people-reset-confirm-input').fill('yes');
  assert.equal(await page.locator('#ch-people-reset-ok').isDisabled(), true, 'Button still disabled for wrong input');

  // Type "faces"
  await page.locator('#ch-people-reset-confirm-input').fill('faces');
  assert.equal(await page.locator('#ch-people-reset-ok').isDisabled(), false, 'Button enabled when typing faces');

  // Click cancel -> dialog closes, no API call
  await page.locator('#ch-people-reset-cancel').click();
  const isClosed = await page.evaluate(() => !document.getElementById('ch-people-reset-dialog').open);
  assert.equal(isClosed, true, 'Dialog closed after cancel');
  const resetCalls = await page.evaluate(() => window.resetCalls);
  assert.equal(resetCalls, 0, 'No reset API called on cancel');

  await browser.close();
});

test('Real DOM: Gallery switch aborts in-flight request and drops stale responses', async (t) => {
  if (!chromium) {
    t.skip('Playwright not available');
    return;
  }

  const browser = await chromium.launch({
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE || undefined,
    headless: true,
    args: ['--no-sandbox']
  });
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.route('**/faces.mjs', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/javascript',
      body: facesMjsContent
    });
  });

  await page.setContent(HTML_SHELL);

  await page.evaluate(async (modUrl) => {
    const { createPeopleController } = await import(modUrl);
    window.currentGal = 'gallery-A';
    window.controller = createPeopleController({
      root: document.getElementById('ch-people-view'),
      dialogRoot: document.getElementById('nextgallery'),
      api: async (endpoint, options = {}) => {
        const query = new URLSearchParams(endpoint.split('?')[1] || '');
        const gal = query.get('gallery');
        if (gal === 'gallery-A') {
          // Slow response for gallery-A
          await new Promise((r) => setTimeout(r, 100));
          return {
            items: [{ id: 'person-A-1', photo_count: 5, cover_id: 'c1' }]
          };
        } else if (gal === 'gallery-B') {
          // Fast response for gallery-B
          return {
            items: [{ id: 'person-B-1', photo_count: 10, cover_id: 'c2' }]
          };
        }
        return { items: [] };
      },
      getGallery: () => window.currentGal
    });

    // Start loading gallery A
    window.controller.refresh();
    // Switch to gallery B while gallery A is in-flight
    window.currentGal = 'gallery-B';
    window.controller.onGalleryChange('gallery-B');
  }, facesMjsDataUri);

  // Wait 150ms for both to finish resolving
  await page.waitForTimeout(150);

  // Assert that only Gallery B items are rendered in DOM, never Gallery A
  const renderedIds = await page.evaluate(() => {
    const cards = document.querySelectorAll('#ch-people-grid .ch-person-card');
    return Array.from(cards).map(c => c.dataset.id);
  });

  assert.deepEqual(renderedIds, ['person-B-1'], 'Only gallery B items rendered; gallery A stale response dropped');
  await browser.close();
});

test('Real DOM: Back to list resets pagination so list refreshes cleanly', async (t) => {
  if (!chromium) {
    t.skip('Playwright not available');
    return;
  }

  const browser = await chromium.launch({
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE || undefined,
    headless: true,
    args: ['--no-sandbox']
  });
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.route('**/faces.mjs', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/javascript',
      body: facesMjsContent
    });
  });

  await page.setContent(HTML_SHELL);

  await page.evaluate(async (modUrl) => {
    const { createPeopleController } = await import(modUrl);
    window.loadCalls = [];
    window.controller = createPeopleController({
      root: document.getElementById('ch-people-view'),
      dialogRoot: document.getElementById('nextgallery'),
      api: async (endpoint) => {
        window.loadCalls.push(endpoint);
        if (endpoint.includes('subjects?')) {
          return {
            items: [{ id: 'subj-1', photo_count: 2, cover_id: 'cov-1' }]
          };
        }
        if (endpoint.includes('/media?')) {
          return {
            items: [{ id: 101, name: 'photo.jpg', face_detection_ids: ['d1'] }]
          };
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });

    window.controller.refresh();
  }, facesMjsDataUri);

  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 1);

  // Open subject detail
  await page.locator('#ch-people-grid .ch-person-card').click();
  await page.waitForFunction(() => !document.getElementById('ch-person-detail').hidden);

  // Click back button
  await page.locator('#ch-people-back').click();
  await page.waitForFunction(() => document.getElementById('ch-person-detail').hidden);

  const detailHidden = await page.evaluate(() => document.getElementById('ch-person-detail').hidden);
  assert.equal(detailHidden, true, 'Detail view closed');
  const selected = await page.evaluate(() => window.controller.getSelectedSubject());
  assert.equal(selected, null, 'Selected subject cleared');

  await browser.close();
});

test('Real DOM: Multi-detection selection is honest and merge dialog renders human labels', async (t) => {
  if (!chromium) {
    t.skip('Playwright not available');
    return;
  }

  const browser = await chromium.launch({
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE || undefined,
    headless: true,
    args: ['--no-sandbox']
  });
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.route('**/faces.mjs', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/javascript',
      body: facesMjsContent
    });
  });

  await page.setContent(HTML_SHELL);

  await page.evaluate(async (modUrl) => {
    const { createPeopleController } = await import(modUrl);
    window.actionCalls = [];
    window.controller = createPeopleController({
      root: document.getElementById('ch-people-view'),
      dialogRoot: document.getElementById('nextgallery'),
      api: async (endpoint, options = {}) => {
        if (endpoint.includes('subjects?')) {
          return {
            items: [
              { id: 'subj-1', photo_count: 3, cover_id: 'c1' },
              { id: 'subj-2', photo_count: 7, cover_id: 'c2' }
            ]
          };
        }
        if (endpoint.includes('/media?')) {
          return {
            items: [
              { id: 201, name: 'multi-face.jpg', face_detection_ids: ['det-uuid-1', 'det-uuid-2'] },
              { id: 202, name: 'no-face.jpg', face_detection_ids: [] }
            ]
          };
        }
        if (endpoint.includes('/action')) {
          window.actionCalls.push(JSON.parse(options.body));
          return { ok: true };
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });

    window.controller.refresh();
  }, facesMjsDataUri);

  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 2);

  // Click person 1 to open detail
  await page.locator('#ch-people-grid .ch-person-card[data-id="subj-1"]').click();
  await page.waitForFunction(() => document.querySelectorAll('#ch-person-media-grid .ch-person-media-tile').length === 2);

  // Select multi-face photo
  await page.locator('#ch-person-media-grid .ch-person-media-tile[data-id="201"] input').click();

  // Selection bar is visible and shows 1 photo selected
  const selectionText = await page.locator('#ch-people-selection-count').textContent();
  assert.ok(selectionText.includes('1 Foto'), 'Selection bar shows 1 photo selected');

  // Trigger remove action -> destructive, so opens confirmation dialog
  await page.locator('#ch-people-remove-selected').click();
  await page.waitForFunction(() => document.getElementById('ch-people-action-dialog').open);
  await page.locator('#ch-people-action-ok').click();

  const lastAction = await page.evaluate(() => window.actionCalls[window.actionCalls.length - 1]);
  assert.equal(lastAction.action, 'remove');
  assert.deepEqual(lastAction.detection_ids, ['det-uuid-1', 'det-uuid-2'], 'Honest multi-detection IDs sent without file_id fallback');

  // Test Merge Dialog: Click Merge button
  await page.locator('#ch-people-merge').click();
  await page.waitForFunction(() => document.getElementById('ch-people-merge-dialog').open);

  // Verify merge targets list has human readable label and thumbnail, never raw UUID
  const targetLabels = await page.evaluate(() => {
    const items = document.querySelectorAll('#ch-people-merge-targets .ch-people-merge-choice');
    return Array.from(items).map(el => el.textContent.trim());
  });

  assert.ok(targetLabels.length > 0, 'Merge dialog targets loaded');
  assert.ok(targetLabels.some(l => l.includes('Fotos')), 'Label contains photo count');
  assert.ok(!targetLabels.some(l => l.includes('subj-2')), 'Label does NOT display code UUID string');

  // Close merge dialog
  await page.locator('#ch-people-merge-cancel').click();
  assert.equal(await page.evaluate(() => document.getElementById('ch-people-merge-dialog').open), false, 'Merge dialog closed');

  await browser.close();
});
