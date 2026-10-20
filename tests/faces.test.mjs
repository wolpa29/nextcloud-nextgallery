import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildSubjectsQuery,
  buildActionPayload,
  buildResetPayload,
  buildBulkActionPayload,
  bulkActionsForFilter,
  coverBoxStyle,
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
  assert.equal(
    buildSubjectsQuery({ filter: 'filtered' }),
    'limit=60&filter=filtered'
  );
  assert.equal(
    buildSubjectsQuery({ filter: 'strangers' }),
    'limit=60&filter=strangers'
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

  // Rename action with label
  assert.deepEqual(
    buildActionPayload({ action: 'rename', label: 'Oma Maria', rules_revision: 5 }),
    { action: 'rename', label: 'Oma Maria', rules_revision: 5 }
  );

  // Set cover action
  assert.deepEqual(
    buildActionPayload({ action: 'set_cover', target_id: 'det-abc-123', rules_revision: 2 }),
    { action: 'set_cover', target_id: 'det-abc-123', rules_revision: 2 }
  );

  // Stranger and unstranger actions
  assert.deepEqual(
    buildActionPayload({ action: 'stranger', rules_revision: 1 }),
    { action: 'stranger', rules_revision: 1 }
  );
  assert.deepEqual(
    buildActionPayload({ action: 'unstranger', rules_revision: 1 }),
    { action: 'unstranger', rules_revision: 1 }
  );

  // Split with target_id
  assert.deepEqual(
    buildActionPayload({ action: 'split', detection_ids: ['d1'], target_id: 'target-uuid-123', rules_revision: 4 }),
    { action: 'split', detection_ids: ['d1'], target_id: 'target-uuid-123', rules_revision: 4 }
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

test('coverBoxStyle maps a whole-photo face box onto a square cover-cropped tile', () => {
  // Square photo: nothing is cropped.
  assert.deepEqual(coverBoxStyle([0.1, 0.2, 0.3, 0.4], 400, 400), { left: '10%', top: '20%', width: '30%', height: '40%' });
  // 4:3 landscape: the tile shows the middle 75% of the width.
  assert.deepEqual(coverBoxStyle([0.125, 0.5, 0.25, 0.2], 400, 300), { left: '0%', top: '50%', width: '33.33%', height: '20%' });
  assert.deepEqual(coverBoxStyle([0.5, 0.1, 0.1, 0.1], 400, 300), { left: '50%', top: '10%', width: '13.33%', height: '10%' });
  // 2:3 portrait: the tile shows the middle two thirds of the height.
  assert.deepEqual(coverBoxStyle([0.4, 0.5, 0.2, 0.1], 200, 300), { left: '40%', top: '50%', width: '20%', height: '15%' });
  // Unknown size (preview not loaded yet) falls back to the uncropped placement.
  assert.deepEqual(coverBoxStyle([0.1, 0.2, 0.3, 0.4], 0, 0), { left: '10%', top: '20%', width: '30%', height: '40%' });
});

test('buildBulkActionPayload validates group flag actions and deduplicates ids', () => {
  assert.deepEqual(
    buildBulkActionPayload({ action: 'stranger', ids: ['a', 'b', 'a', ''], rules_revision: 4 }),
    { action: 'stranger', ids: ['a', 'b'], rules_revision: 4 }
  );
  assert.throws(() => buildBulkActionPayload({ action: 'merge', ids: ['a'], rules_revision: 1 }), /Invalid bulk action/);
  assert.throws(() => buildBulkActionPayload({ action: 'hide', ids: [], rules_revision: 1 }), /At least one subject id/);
  assert.throws(() => buildBulkActionPayload({ action: 'hide', ids: ['a'] }), /rules_revision must be an integer/);
  assert.deepEqual(bulkActionsForFilter('single'), ['stranger', 'hide']);
  assert.deepEqual(bulkActionsForFilter('filtered'), ['stranger', 'hide']);
  assert.deepEqual(bulkActionsForFilter('strangers'), ['unstranger', 'hide']);
  assert.deepEqual(bulkActionsForFilter('hidden'), ['show']);
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

function setupMockDOM() {
  const elements = new Map();
  function makeEl(id, tag = 'div') {
    const children = [];
    const classList = new Set();
    const el = {
      id: id || '',
      tagName: tag.toUpperCase(),
      hidden: false,
      disabled: false,
      textContent: '',
      value: '',
      type: 'button',
      checked: false,
      dataset: {},
      children,
      classList: {
        add: (...cls) => cls.forEach(c => classList.add(c)),
        remove: (...cls) => cls.forEach(c => classList.delete(c)),
        toggle: (c, force) => {
          if (force === true) classList.add(c);
          else if (force === false) classList.delete(c);
          else if (classList.has(c)) classList.delete(c);
          else classList.add(c);
        },
        contains: (c) => classList.has(c)
      },
      get className() { return Array.from(classList).join(' '); },
      set className(val) {
        classList.clear();
        if (val) val.split(/\s+/).filter(Boolean).forEach(c => classList.add(c));
      },
      append: (...nodes) => {
        for (const n of nodes) {
          if (typeof n === 'string') {
            const txt = makeEl('', 'span');
            txt.textContent = n;
            children.push(txt);
          } else if (n) {
            children.push(n);
          }
        }
      },
      replaceChildren: (...nodes) => {
        children.length = 0;
        if (nodes.length) el.append(...nodes);
      },
      setAttribute: (k, v) => { el[k] = v; },
      getAttribute: (k) => el[k] ?? null,
      querySelector: (sel) => {
        for (const c of children) {
          if (sel.startsWith('#') && c.id === sel.slice(1)) return c;
          if (sel.startsWith('.') && c.classList?.contains(sel.slice(1))) return c;
          const found = c.querySelector?.(sel);
          if (found) return found;
        }
        return null;
      },
      querySelectorAll: (sel) => {
        const res = [];
        function walk(node) {
          if (sel.startsWith('.') && node.classList?.contains(sel.slice(1))) res.push(node);
          for (const c of (node.children || [])) walk(c);
        }
        walk(el);
        return res;
      },
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => true,
      focus: () => {},
      showModal: () => { el.open = true; },
      close: () => { el.open = false; }
    };
    if (id) elements.set(id, el);
    return el;
  }

  globalThis.document = {
    createElement: (tag) => makeEl('', tag),
    getElementById: (id) => elements.get(id) || null,
    querySelector: (sel) => elements.get(sel.replace('#', '')) || null,
    querySelectorAll: () => []
  };

  const ids = [
    'ch-people-view', 'ch-people-filter', 'ch-people-refresh', 'ch-people-reset',
    'ch-people-error', 'ch-people-loading', 'ch-people-list-container', 'ch-people-grid',
    'ch-people-more-wrap', 'ch-people-more', 'ch-people-empty', 'ch-people-empty-hint',
    'ch-person-detail', 'ch-people-back', 'ch-people-detail-title', 'ch-people-detail-count',
    'ch-people-toggle-hide', 'ch-people-merge', 'ch-people-resolve',
    'ch-people-detail-filter-note', 'ch-person-selection-bar', 'ch-people-selection-count',
    'ch-people-split-selected', 'ch-people-remove-selected', 'ch-people-resolve-selected',
    'ch-people-clear-selected', 'ch-person-media-grid', 'ch-people-media-more-wrap',
    'ch-people-media-more', 'ch-people-reset-dialog', 'ch-people-reset-confirm-input',
    'ch-people-reset-cancel', 'ch-people-reset-ok', 'ch-people-merge-dialog',
    'ch-people-merge-targets', 'ch-people-merge-more-wrap', 'ch-people-merge-more',
    'ch-people-merge-cancel', 'ch-people-merge-ok', 'ch-people-merge-search',
    'ch-people-action-dialog', 'ch-people-action-title', 'ch-people-action-text',
    'ch-people-action-cancel', 'ch-people-action-ok'
  ];
  for (const id of ids) makeEl(id);

  const root = elements.get('ch-people-view');
  root.querySelector = (sel) => {
    if (sel.startsWith('#')) return elements.get(sel.slice(1)) || null;
    return null;
  };
  root.querySelectorAll = () => [];

  return { root, elements, makeEl };
}

test('DOM: Detail media request carries filter query parameter matching active filter', async () => {
  const { root } = setupMockDOM();
  const apiCalls = [];
  const controller = createPeopleController({
    root,
    dialogRoot: root,
    api: async (endpoint) => {
      apiCalls.push(endpoint);
      if (endpoint.includes('subjects?')) {
        return { items: [{ id: 'subj-1', photo_count: 2, filtered: true }] };
      }
      if (endpoint.includes('/media?')) {
        return { items: [{ id: 101, name: 'weak_face.jpg', face_detection_ids: ['d101'] }] };
      }
      return { items: [] };
    },
    getGallery: () => 'default'
  });

  // Set filter to 'filtered'
  controller.setFilter('filtered');
  await new Promise((r) => setTimeout(r, 10));

  // Open subject detail
  await controller.openSubject({ id: 'subj-1', photo_count: 2, filtered: true });

  const mediaCall = apiCalls.find(c => c.includes('/media?'));
  assert.ok(mediaCall, 'media request was made');
  const url = new URL('https://example.com/' + mediaCall);
  assert.equal(url.searchParams.get('filter'), 'filtered', 'media request carries filter=filtered');
  assert.equal(url.searchParams.get('gallery'), 'default', 'media request carries gallery');
});

test('DOM: Filtered subject displays restrained badge in list and confirm button + filter note in detail', async () => {
  const { root, elements } = setupMockDOM();
  const apiCalls = [];
  const controller = createPeopleController({
    root,
    dialogRoot: root,
    api: async (endpoint, options = {}) => {
      apiCalls.push({ endpoint, options });
      if (endpoint.includes('subjects?')) {
        return { items: [{ id: 'subj-f1', photo_count: 3, filtered: true, review_count: 0 }] };
      }
      if (endpoint.includes('/media?')) {
        return { items: [{ id: 201, name: 'weak.jpg', face_detection_ids: ['det-1'] }] };
      }
      if (endpoint.includes('/action')) {
        return { ok: true };
      }
      return { items: [] };
    },
    getGallery: () => 'default'
  });

  controller.setFilter('filtered');
  await new Promise((r) => setTimeout(r, 10));

  // Verify card has restrained "Gefiltert" badge
  const grid = elements.get('ch-people-grid');
  assert.equal(grid.children.length, 1, '1 card rendered in grid');
  const badge = grid.children[0].querySelector('.ch-person-badge-filtered');
  assert.ok(badge, 'filtered badge rendered on card');
  assert.equal(badge.textContent, 'Gefiltert', 'badge text is Gefiltert');

  // Open subject detail
  await controller.openSubject({ id: 'subj-f1', photo_count: 3, filtered: true, review_count: 0 });

  const resolveBtn = elements.get('ch-people-resolve');
  const filterNote = elements.get('ch-people-detail-filter-note');
  assert.equal(resolveBtn.hidden, false, 'Resolve button is visible for filtered subject even without review_count');
  assert.equal(resolveBtn.textContent, 'Bestätigen', 'Resolve button text is Bestätigen');
  assert.equal(filterNote.hidden, false, 'Filter explanatory note is visible in filtered view');

  // Click top resolve button
  await resolveBtn.onclick();
  const actionCall = apiCalls.find(c => c.endpoint.includes('/action'));
  assert.ok(actionCall, 'action request was made');
  assert.equal(actionCall.endpoint, 'subjects/subj-f1/action');
  const payload = JSON.parse(actionCall.options.body);
  assert.equal(payload.action, 'resolve', 'Resolve action sent to backend');
  assert.equal(payload.rules_revision, 0, 'Rules revision included');
});

test('DOM: Regular subject in all view hides confirm button (without review_count) and hides filter note', async () => {
  const { root, elements } = setupMockDOM();
  const controller = createPeopleController({
    root,
    dialogRoot: root,
    api: async (endpoint) => {
      if (endpoint.includes('/media?')) {
        return { items: [{ id: 301, name: 'photo.jpg', face_detection_ids: ['det-reg'] }] };
      }
      return { items: [] };
    },
    getGallery: () => 'default'
  });

  controller.setFilter('all');
  await controller.openSubject({ id: 'subj-regular', photo_count: 8, filtered: false, review_count: 0 });

  const resolveBtn = elements.get('ch-people-resolve');
  const filterNote = elements.get('ch-people-detail-filter-note');
  assert.equal(resolveBtn.hidden, true, 'Resolve button hidden for regular subject with 0 review_count');
  assert.equal(filterNote.hidden, true, 'Filter explanatory note hidden for regular subject');
});

test('DOM: Empty state renders specific hint for filtered view', async () => {
  const { root, elements } = setupMockDOM();
  const controller = createPeopleController({
    root,
    dialogRoot: root,
    api: async () => ({ items: [] }),
    getGallery: () => 'default'
  });

  controller.setFilter('filtered');
  await new Promise((r) => setTimeout(r, 10));

  const emptyWrap = elements.get('ch-people-empty');
  const emptyHint = elements.get('ch-people-empty-hint');
  assert.equal(emptyWrap.hidden, false, 'Empty state wrap is visible');
  assert.equal(emptyHint.textContent, 'Keine gefilterten Gesichter gefunden', 'Filtered empty hint matches contract');
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
            <option value="suggested">Vorschläge</option>
            <option value="single">Seltene Personen</option>
            <option value="review">Überprüfung</option>
            <option value="strangers">Unbekannte Personen</option>
            <option value="filtered">Gefilterte Gesichter</option>
            <option value="hidden">Ausgeblendet</option>
          </select>
          <div id="ch-people-filter-pills" class="ch-filter-pills">
            <button data-people-filter="all" class="ch-filter-pill active">Alle <span class="ch-pill-count"></span></button>
            <button data-people-filter="suggested" class="ch-filter-pill">Vorschläge <span class="ch-pill-count"></span></button>
            <button data-people-filter="single" class="ch-filter-pill">Seltene Personen <span class="ch-pill-count"></span></button>
            <button data-people-filter="review" class="ch-filter-pill">Überprüfung <span class="ch-pill-count"></span></button>
            <button data-people-filter="strangers" class="ch-filter-pill">Unbekannte Personen <span class="ch-pill-count"></span></button>
            <button data-people-filter="filtered" class="ch-filter-pill">Gefilterte Gesichter <span class="ch-pill-count"></span></button>
            <button data-people-filter="hidden" class="ch-filter-pill">Ausgeblendet <span class="ch-pill-count"></span></button>
          </div>
        </div>
        <div class="ch-people-top-actions">
          <button type="button" id="ch-people-share-sorting">Sortierung teilen</button>
          <button type="button" id="ch-people-guide-toggle">Hilfe &amp; Tipps</button>
          <button id="ch-people-refresh">Aktualisieren</button>
          <button id="ch-people-reset" class="ch-danger">Zurücksetzen</button>
        </div>
      </div>
      <div id="ch-people-filter-explainer" class="ch-people-filter-explainer" hidden>
        <span class="ch-explainer-icon">ℹ</span>
        <div class="ch-explainer-body">
          <strong id="ch-people-explainer-title"></strong>
          <p id="ch-people-explainer-text"></p>
        </div>
      </div>
      <details id="ch-people-guide" class="ch-people-guide">
        <summary><strong>Hilfe &amp; Tipps zur Personenerkennung</strong></summary>
        <div class="ch-guide-content">
          <div class="ch-guide-grid">
            <div class="ch-guide-card"><h4>Zusammenführen (Mergen)</h4><p>Verbessert zukünftige Erkennungen.</p></div>
          </div>
        </div>
      </details>
      <div id="ch-people-undo-bar" class="ch-people-undo-bar" hidden>
        <span id="ch-people-undo-text"></span>
        <div class="ch-undo-actions">
          <button type="button" id="ch-people-undo-btn" class="ch-button ch-button-sm">Rückgängig</button>
          <button type="button" id="ch-people-undo-dismiss" class="ch-icon-btn">✕</button>
        </div>
      </div>
      <div id="ch-people-error" class="ch-error-banner" hidden></div>
      <div id="ch-people-loading" class="ch-people-loading" hidden><span class="ch-spinner"></span> Wird geladen …</div>
      <div id="ch-people-list-container">
        <div class="ch-people-list-tools"><button type="button" id="ch-people-select-mode" aria-pressed="false">Auswählen</button></div>
        <div id="ch-people-bulk-bar" hidden>
          <span id="ch-people-bulk-count"></span>
          <button type="button" id="ch-people-bulk-all">Alle geladenen auswählen</button>
          <button type="button" id="ch-people-bulk-stranger" disabled>Kenne ich nicht</button>
          <button type="button" id="ch-people-bulk-unstranger" hidden disabled>Als bekannt markieren</button>
          <button type="button" id="ch-people-bulk-hide" disabled>Ausblenden</button>
          <button type="button" id="ch-people-bulk-show" hidden disabled>Einblenden</button>
          <button type="button" id="ch-people-bulk-cancel">Fertig</button>
        </div>
        <p id="ch-people-bulk-note" hidden></p>
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
          <button type="button" id="ch-people-next-suggestion" hidden>Nächster Vorschlag →</button>
          <div id="ch-people-detail-avatar" class="ch-people-detail-avatar"></div>
          <div class="ch-people-detail-title-wrap">
            <div class="ch-people-detail-title-row">
              <h2 id="ch-people-detail-title">Person</h2>
              <button type="button" id="ch-people-rename-btn">✎ Benennen</button>
            </div>
            <div id="ch-people-rename-bar" hidden>
              <input type="text" id="ch-people-rename-input" maxlength="120" placeholder="Name der Person …">
              <button type="button" id="ch-people-rename-save">Speichern</button>
              <button type="button" id="ch-people-rename-cancel">Abbrechen</button>
            </div>
            <p id="ch-people-detail-count"></p>
          </div>
          <div class="ch-people-detail-actions">
            <button type="button" id="ch-people-inspect-self">Bildkontext</button>
            <button type="button" id="ch-people-toggle-stranger">Kenne ich nicht</button>
            <button id="ch-people-toggle-hide">Ausblenden</button>
            <button id="ch-people-merge">Zusammenführen</button>
            <button id="ch-people-resolve" hidden>Überprüfung auflösen</button>
          </div>
          <p id="ch-people-detail-filter-note" class="ch-hint" hidden>Kleine oder unscharfe Gesichter im Hintergrund werden hier gesammelt und können bestätigt werden.</p>
        </div>
        <section id="ch-people-similar" class="ch-people-similar" hidden>
          <div id="ch-people-similar-list" class="ch-people-similar-list" role="list"></div>
        </section>
        <div class="ch-person-media-toolbar">
          <button type="button" id="ch-people-toggle-boxes" class="ch-button ch-button-sm active" aria-pressed="true">✓ Gesichtsrahmen an</button>
        </div>
        <div id="ch-person-selection-bar" hidden>
          <span id="ch-people-selection-count"></span>
          <div class="ch-selection-actions">
            <button type="button" id="ch-people-cover-selected" hidden>★ Als Titelbild</button>
            <button id="ch-people-split-selected">Abtrennen</button>
            <button type="button" id="ch-people-move-selected">Zu Person verschieben</button>
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
          <button type="button" class="ch-seg-btn" data-merge-filter="single">Seltene Personen</button>
          <button type="button" class="ch-seg-btn" data-merge-filter="strangers">Unbekannte Personen</button>
        </div>
        <input type="search" id="ch-people-merge-search" placeholder="Personen suchen …">
      </div>
      <div id="ch-people-merge-targets" class="ch-people-merge-list"></div>
      <div id="ch-people-merge-more-wrap" hidden>
        <button type="button" id="ch-people-merge-more">Weitere laden</button>
      </div>
      <div id="ch-people-merge-selection-preview" class="ch-people-merge-selection-preview"></div>
      <div class="ch-confirm-actions">
        <button type="button" id="ch-people-merge-cancel">Abbrechen</button>
        <button type="button" id="ch-people-merge-ok" class="ch-primary" disabled>Zusammenführen</button>
      </div>
    </dialog>
    <dialog id="ch-people-inspect-dialog">
      <form method="dialog" class="ch-dialog-head">
        <h2>Bildkontext &amp; Fotos prüfen</h2>
      </form>
      <div id="ch-people-inspect-stage" class="ch-people-inspect-stage"></div>
      <div class="ch-people-inspect-photos-wrap">
        <div id="ch-people-inspect-photos" class="ch-people-inspect-photos"></div>
      </div>
      <div class="ch-confirm-actions">
        <button type="button" id="ch-people-inspect-close">Schließen</button>
        <button type="button" id="ch-people-inspect-merge" class="ch-primary" hidden>Mit dieser Person zusammenführen</button>
      </div>
    </dialog>
    <dialog id="ch-people-share-dialog">
      <form method="dialog" class="ch-dialog-head">
        <h2>Personensortierung teilen &amp; übernehmen</h2>
      </form>
      <input type="checkbox" id="ch-people-share-toggle">
      <div id="ch-people-share-status"></div>
      <div id="ch-people-share-list"></div>
      <div class="ch-confirm-actions">
        <button type="button" id="ch-people-share-close">Schließen</button>
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

test('Real DOM: Filtered view carries filter to media, shows confirm button and note, resolves weak faces', async (t) => {
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
    window.recordedCalls = [];
    window.actionCalls = [];
    window.controller = createPeopleController({
      root: document.getElementById('ch-people-view'),
      dialogRoot: document.getElementById('nextgallery'),
      api: async (endpoint, options = {}) => {
        window.recordedCalls.push({ endpoint, options });
        if (endpoint.includes('subjects?')) {
          return {
            items: [
              { id: 'subj-filt-1', photo_count: 2, filtered: true, review_count: 0, cover_id: 'c1' }
            ]
          };
        }
        if (endpoint.includes('/media?')) {
          return {
            items: [
              { id: 501, name: 'weak1.jpg', face_detection_ids: ['d501'] }
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

    window.controller.setFilter('filtered');
  }, facesMjsDataUri);

  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 1);

  // Badge Gefiltert is visible on card
  const badgeText = await page.locator('#ch-people-grid .ch-person-badge-filtered').textContent();
  assert.equal(badgeText.trim(), 'Gefiltert', 'Badge text is Gefiltert');

  // Click card to open detail
  await page.locator('#ch-people-grid .ch-person-card').click();
  await page.waitForFunction(() => !document.getElementById('ch-person-detail').hidden);

  // Filter note is visible
  const noteVisible = await page.evaluate(() => !document.getElementById('ch-people-detail-filter-note').hidden);
  assert.equal(noteVisible, true, 'Filter explanatory note is visible in detail view');

  // Top resolve button is visible and says Bestätigen even with review_count: 0
  const resolveVisible = await page.evaluate(() => !document.getElementById('ch-people-resolve').hidden);
  assert.equal(resolveVisible, true, 'Top confirm button visible for filtered subject');
  const resolveText = await page.locator('#ch-people-resolve').textContent();
  assert.equal(resolveText.trim(), 'Bestätigen', 'Confirm button text is Bestätigen');

  // Check media request carried filter=filtered
  const mediaCall = await page.evaluate(() => window.recordedCalls.find(c => c.endpoint.includes('/media?')));
  assert.ok(mediaCall.endpoint.includes('filter=filtered'), 'Media call carried filter=filtered');

  // Click confirm button
  await page.locator('#ch-people-resolve').click();
  await page.waitForFunction(() => window.actionCalls.length > 0);
  const action = await page.evaluate(() => window.actionCalls[0]);
  assert.equal(action.action, 'resolve', 'Resolve action sent');

  await browser.close();
});

test('Suggestions: query filter and dismiss action are part of the contract', () => {
  assert.equal(new URLSearchParams(buildSubjectsQuery({ filter: 'suggested' })).get('filter'), 'suggested');
  assert.deepEqual(
    buildActionPayload({ action: 'dismiss', target_id: 'other', rules_revision: 4 }),
    { action: 'dismiss', target_id: 'other', rules_revision: 4 }
  );
});

test('DOM: Detail view offers suggestions, merges into the open person and dismisses pairs', async () => {
  const { root, elements, makeEl } = setupMockDOM();
  makeEl('ch-people-similar');
  makeEl('ch-people-similar-list');
  const calls = [];
  let suggestions = [
    { id: 'other-1', photo_count: 1, cover_id: 'c1', similarity: 0.7 },
    { id: 'other-2', photo_count: 4, cover_id: 'c2', similarity: 0.5 }
  ];
  const controller = createPeopleController({
    root,
    dialogRoot: root,
    api: async (endpoint, options = {}) => {
      calls.push({ endpoint, options });
      if (endpoint.includes('/similar?')) return { items: suggestions, rules_revision: 5, subject: { id: 'subj-1', photo_count: 6 } };
      if (endpoint.includes('/action')) return { success: true, rules_revision: 6 };
      return { items: [] };
    },
    getGallery: () => 'default'
  });

  controller.setFilter('all');
  await controller.openSubject({ id: 'subj-1', photo_count: 3 });
  const section = elements.get('ch-people-similar');
  const list = elements.get('ch-people-similar-list');
  assert.equal(section.hidden, false, 'suggestion panel is shown');
  assert.equal(list.children.length, 2, 'one card per suggestion');
  assert.equal(elements.get('ch-people-detail-count').textContent, '6 Fotos', 'count refreshed from the server');
  const similarUrl = new URL('https://example.com/' + calls.find((c) => c.endpoint.includes('/similar?')).endpoint);
  assert.equal(similarUrl.pathname, '/subjects/subj-1/similar');
  assert.equal(similarUrl.searchParams.get('gallery'), 'default');

  // "Different person" is sent for the open person and removes only that card.
  const [first] = list.children;
  const [, dismiss] = first.querySelector('.ch-people-similar-actions').children;
  await dismiss.onclick();
  const dismissCall = calls.find((c) => c.endpoint.endsWith('/action'));
  assert.equal(dismissCall.endpoint, 'subjects/subj-1/action');
  assert.deepEqual(JSON.parse(dismissCall.options.body), { action: 'dismiss', target_id: 'other-1', rules_revision: 5 });
  assert.equal(list.children.length, 1);

  // Merging moves the suggested group into the open person after confirmation.
  suggestions = [];
  const [merge] = list.children[0].querySelector('.ch-people-similar-actions').children;
  const pending = merge.onclick();
  await new Promise((r) => setTimeout(r, 0));
  assert.equal(elements.get('ch-people-action-dialog').open, true, 'merge asks for confirmation');
  elements.get('ch-people-action-ok').onclick();
  await pending;
  const mergeCall = calls.filter((c) => c.endpoint.endsWith('/action')).at(-1);
  assert.equal(mergeCall.endpoint, 'subjects/other-2/action');
  assert.deepEqual(JSON.parse(mergeCall.options.body), { action: 'merge', target_id: 'subj-1', rules_revision: 6 });
  assert.equal(section.hidden, true, 'panel hides when no suggestions remain');
  assert.equal(controller.getSelectedSubject().id, 'subj-1', 'the open person stays open');
});

test('DOM: Suggestions are not requested for review, hidden or known filtered groups', async () => {
  const { root, makeEl } = setupMockDOM();
  makeEl('ch-people-similar');
  makeEl('ch-people-similar-list');
  const calls = [];
  const controller = createPeopleController({
    root,
    dialogRoot: root,
    api: async (endpoint) => { calls.push(endpoint); return { items: [] }; },
    getGallery: () => 'default'
  });
  for (const filter of ['review', 'hidden']) {
    controller.setFilter(filter);
    await controller.openSubject({ id: 'subj-' + filter, photo_count: 2 });
  }
  // A filtered card of a known person shows only its weak faces; merging it would move the good ones too.
  controller.setFilter('filtered');
  await controller.openSubject({ id: 'subj-named', photo_count: 1, filtered: true, label: 'Oma', good_count: 0 });
  await controller.openSubject({ id: 'subj-mixed', photo_count: 1, filtered: true, good_count: 3 });
  assert.equal(calls.filter((c) => c.includes('/similar')).length, 0);
});

test('DOM: Filtered faces get suggestions toward known people and move into them', async () => {
  const { root, elements, makeEl } = setupMockDOM();
  makeEl('ch-people-similar');
  makeEl('ch-people-similar-list');
  makeEl('ch-people-undo-bar').hidden = true;
  makeEl('ch-people-undo-text');
  const calls = [];
  const controller = createPeopleController({
    root,
    dialogRoot: root,
    api: async (endpoint, options = {}) => {
      calls.push({ endpoint, options });
      if (endpoint.includes('/similar?')) {
        return {
          items: [{ id: 'known-1', label: 'Max', photo_count: 12, cover_id: 'c1', similarity: 0.71 }],
          rules_revision: 7,
          subject: { id: 'weak-1', photo_count: 0, filtered_count: 2 }
        };
      }
      if (endpoint.includes('/action')) return { success: true, rules_revision: 8, merged_detection_ids: ['d1', 'd2'] };
      return { items: [] };
    },
    getGallery: () => 'default'
  });

  controller.setFilter('filtered');
  await controller.openSubject({ id: 'weak-1', photo_count: 1, filtered: true, good_count: 0 });
  const section = elements.get('ch-people-similar');
  const list = elements.get('ch-people-similar-list');
  assert.equal(section.hidden, false, 'suggestion panel is shown in the filtered view');
  assert.equal(list.children.length, 1);
  assert.equal(elements.get('ch-people-detail-count').textContent, '2 Fotos', 'count follows the filtered faces');

  const [merge] = list.children[0].querySelector('.ch-people-similar-actions').children;
  const pending = merge.onclick();
  await new Promise((r) => setTimeout(r, 0));
  assert.equal(elements.get('ch-people-action-dialog').open, true, 'assignment asks for confirmation');
  assert.match(elements.get('ch-people-action-text').textContent, /bestätigt/, 'dialog says the faces get confirmed');
  elements.get('ch-people-action-ok').onclick();
  await pending;
  const mergeCall = calls.filter((c) => c.endpoint.endsWith('/action')).at(-1);
  assert.equal(mergeCall.endpoint, 'subjects/weak-1/action', 'the filtered group is the source');
  assert.deepEqual(JSON.parse(mergeCall.options.body), { action: 'merge', target_id: 'known-1', rules_revision: 7 });
  assert.equal(controller.getSelectedSubject(), null, 'the merged filtered group closes');
  assert.equal(elements.get('ch-people-undo-bar').hidden, false, 'undo is offered');
});

test('Real DOM: Filtered person shows "Vielleicht dieselbe Person" and is assigned to a known person', async (t) => {
  if (!chromium) {
    t.skip('Playwright not available');
    return;
  }

  const browser = await chromium.launch({
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE || undefined,
    headless: true,
    args: ['--no-sandbox']
  });
  const page = await (await browser.newContext()).newPage();
  await page.route('**/faces.mjs', (route) => {
    route.fulfill({ status: 200, contentType: 'application/javascript', body: facesMjsContent });
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
          return { items: window.actionCalls.length ? [] : [{ id: 'weak-1', photo_count: 1, filtered: true, good_count: 0, review_count: 0, cover_id: 'c1' }] };
        }
        if (endpoint.includes('/similar?')) {
          return {
            items: [{ id: 'known-1', label: 'Max', photo_count: 12, cover_id: 'c2', similarity: 0.71 }],
            rules_revision: 3,
            subject: { id: 'weak-1', photo_count: 0, filtered_count: 1 }
          };
        }
        if (endpoint.includes('/media?')) return { items: [{ id: 601, name: 'crowd.jpg', face_detection_ids: ['d601'] }] };
        if (endpoint.includes('/action')) {
          window.actionCalls.push({ endpoint, body: JSON.parse(options.body) });
          return { success: true, rules_revision: 4, merged_detection_ids: ['d601'] };
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });
    window.controller.setFilter('filtered');
  }, facesMjsDataUri);

  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 1);
  await page.locator('#ch-people-grid .ch-person-card').click();
  await page.waitForFunction(() => !document.getElementById('ch-people-similar').hidden);
  assert.equal((await page.locator('#ch-people-similar .ch-person-name').textContent()).trim(), 'Max');
  assert.equal((await page.locator('#ch-people-similar .ch-people-similar-actions .ch-primary').textContent()).trim(), 'Zuordnen');

  await page.locator('#ch-people-similar .ch-people-similar-actions .ch-primary').click();
  await page.waitForFunction(() => document.getElementById('ch-people-action-dialog').open);
  await page.locator('#ch-people-action-ok').click();
  await page.waitForFunction(() => window.actionCalls.length === 1);
  const call = await page.evaluate(() => window.actionCalls[0]);
  assert.equal(call.endpoint, 'subjects/weak-1/action');
  assert.deepEqual(call.body, { action: 'merge', target_id: 'known-1', rules_revision: 3 });
  await page.waitForFunction(() => document.getElementById('ch-person-detail').hidden);

  await browser.close();
});

test('Real DOM: Suggestion badges mark cards and "Nächster Vorschlag" works through filtered people', async (t) => {
  if (!chromium) {
    t.skip('Playwright not available');
    return;
  }

  const browser = await chromium.launch({
    executablePath: process.env.PLAYWRIGHT_EXECUTABLE || undefined,
    headless: true,
    args: ['--no-sandbox']
  });
  const page = await (await browser.newContext()).newPage();
  await page.route('**/faces.mjs', (route) => {
    route.fulfill({ status: 200, contentType: 'application/javascript', body: facesMjsContent });
  });
  await page.setContent(HTML_SHELL);

  await page.evaluate(async (modUrl) => {
    const { createPeopleController } = await import(modUrl);
    window.actionCalls = [];
    window.assigned = new Set();
    const all = [
      { id: 'weak-1', photo_count: 1, filtered: true, good_count: 0, cover_id: 'c1', suggestion_count: 1 },
      { id: 'weak-2', photo_count: 1, filtered: true, good_count: 0, cover_id: 'c2', suggestion_count: 2 },
      { id: 'plain', photo_count: 1, filtered: true, good_count: 0, cover_id: 'c3', suggestion_count: 0 }
    ];
    window.controller = createPeopleController({
      root: document.getElementById('ch-people-view'),
      dialogRoot: document.getElementById('nextgallery'),
      api: async (endpoint, options = {}) => {
        if (endpoint.includes('subjects?')) {
          return { items: all.filter((s) => !window.assigned.has(s.id)), rules_revision: 3 };
        }
        if (endpoint.includes('/similar?')) {
          const id = endpoint.split('/')[1];
          return {
            items: [{ id: 'known-' + id, label: 'Max', photo_count: 12, cover_id: 'k1', similarity: 0.7 }],
            rules_revision: 3,
            subject: { id, photo_count: 0, filtered_count: 1 }
          };
        }
        if (endpoint.includes('/media?')) return { items: [{ id: 601, name: 'crowd.jpg', face_detection_ids: ['d601'] }] };
        if (endpoint.includes('/action')) {
          const id = endpoint.split('/')[1];
          window.actionCalls.push({ endpoint, body: JSON.parse(options.body) });
          window.assigned.add(id);
          return { success: true, rules_revision: 4, merged_detection_ids: ['d601'] };
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });
    window.controller.setFilter('filtered');
  }, facesMjsDataUri);

  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 3);
  const badges = await page.evaluate(() => [...document.querySelectorAll('#ch-people-grid .ch-person-card')]
    .map((c) => c.querySelector('.ch-person-badge-suggestion')?.textContent || ''));
  assert.deepEqual(badges, ['Vorschlag', '2 Vorschläge', ''], 'Only cards with open suggestions carry the badge');

  await page.locator('#ch-people-grid .ch-person-card[data-id="weak-1"]').click();
  await page.waitForFunction(() => !document.getElementById('ch-person-detail').hidden);
  assert.equal(await page.locator('#ch-people-next-suggestion').isVisible(), true, 'Next button offered while another suggestion is open');

  await page.locator('#ch-people-next-suggestion').click();
  await page.waitForFunction(() => window.controller.getSelectedSubject()?.id === 'weak-2');
  await page.waitForFunction(() => !document.getElementById('ch-people-similar').hidden);

  // Assigning moves straight on to the remaining person with a suggestion.
  await page.locator('#ch-people-similar .ch-people-similar-actions .ch-primary').click();
  await page.waitForFunction(() => document.getElementById('ch-people-action-dialog').open);
  await page.locator('#ch-people-action-ok').click();
  await page.waitForFunction(() => window.actionCalls.length === 1);
  const call = await page.evaluate(() => window.actionCalls[0]);
  assert.equal(call.endpoint, 'subjects/weak-2/action');
  assert.deepEqual(call.body, { action: 'merge', target_id: 'known-weak-2', rules_revision: 3 });
  await page.waitForFunction(() => window.controller.getSelectedSubject()?.id === 'weak-1');
  assert.equal(await page.evaluate(() => document.getElementById('ch-person-detail').hidden), false, 'Next person opened after assigning');
  assert.equal(await page.locator('#ch-people-next-suggestion').isVisible(), false, 'No next button when nothing else is open');

  await browser.close();
});

test('Real DOM: Merge dialog card selection shows active state, badge, and live preview bar with inspect trigger', async (t) => {
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
    window.controller = createPeopleController({
      root: document.getElementById('ch-people-view'),
      dialogRoot: document.getElementById('nextgallery'),
      api: async (endpoint) => {
        if (endpoint.includes('subjects?')) {
          return {
            items: [
              { id: 'subj-1', photo_count: 5, label: 'Oma Maria', cover_id: 'c1' },
              { id: 'subj-2', photo_count: 8, label: 'Onkel Hans', cover_id: 'c2' }
            ],
            filter_counts: { all: 2, suggested: 0, single: 0, review: 0, strangers: 0, filtered: 0, hidden: 0 }
          };
        }
        if (endpoint.includes('/media?')) {
          return {
            items: [
              { id: 101, name: 'p1.jpg', face_detection_ids: ['d1'], faces: [{ id: 'd1', box: [0.1, 0.1, 0.2, 0.2] }] }
            ]
          };
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });
    window.controller.refresh();
  }, facesMjsDataUri);

  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 2);

  // Open person 1
  await page.locator('#ch-people-grid .ch-person-card[data-id="subj-1"]').click();
  await page.waitForFunction(() => !document.getElementById('ch-person-detail').hidden);

  // Click merge button
  await page.locator('#ch-people-merge').click();
  await page.waitForFunction(() => document.getElementById('ch-people-merge-dialog').open);

  // Wait for candidate card
  await page.waitForFunction(() => document.querySelectorAll('#ch-people-merge-targets .ch-people-merge-choice').length === 1);

  // Click the candidate card (subj-2)
  await page.locator('#ch-people-merge-targets .ch-people-merge-choice').click();

  // Card now has active class
  const hasActiveClass = await page.evaluate(() => {
    const card = document.querySelector('#ch-people-merge-targets .ch-people-merge-choice');
    return card && card.classList.contains('active');
  });
  assert.equal(hasActiveClass, true, 'Selected merge card has active class');

  // Card contains ✓ Ausgewählt badge
  const badgeText = await page.locator('#ch-people-merge-targets .ch-people-merge-selected-badge').textContent();
  assert.ok(badgeText.includes('Ausgewählt'), 'Badge shows Ausgewählt');

  // Preview bar is visible and shows Onkel Hans
  const previewVisible = await page.evaluate(() => {
    const el = document.getElementById('ch-people-merge-selection-preview');
    return el && !el.hidden && el.textContent.includes('Onkel Hans');
  });
  assert.equal(previewVisible, true, 'Live merge selection preview bar is visible with target name');

  // Inspect button inside preview bar
  const inspectBtn = page.locator('#ch-people-merge-selection-preview button:has-text("Fotos prüfen")');
  assert.equal(await inspectBtn.isVisible(), true, 'Inspect button visible in preview bar');

  // Click inspect button -> opens inspect dialog
  await inspectBtn.click();
  await page.waitForFunction(() => document.getElementById('ch-people-inspect-dialog').open);
  const inspectOpen = await page.evaluate(() => document.getElementById('ch-people-inspect-dialog').open);
  assert.equal(inspectOpen, true, 'Inspect dialog opens from merge selection preview');

  // Close inspect dialog
  await page.locator('#ch-people-inspect-close').click();
  await page.waitForFunction(() => !document.getElementById('ch-people-inspect-dialog').open);

  // Close merge dialog
  await page.locator('#ch-people-merge-cancel').click();
  await page.waitForFunction(() => !document.getElementById('ch-people-merge-dialog').open);

  await browser.close();
});

test('Real DOM: Person renaming in detail header updates title and sends rename action', async (t) => {
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
            items: [{ id: 'subj-1', photo_count: 4, label: null, cover_id: 'c1' }],
            filter_counts: { all: 1, suggested: 0, single: 0, review: 0, strangers: 0, filtered: 0, hidden: 0 }
          };
        }
        if (endpoint.includes('/media?')) {
          return { items: [{ id: 1, name: 'f1.jpg', face_detection_ids: ['d1'] }] };
        }
        if (endpoint.includes('/action')) {
          const body = JSON.parse(options.body);
          window.actionCalls.push(body);
          return { ok: true, label: body.label, rules_revision: 2 };
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });
    window.controller.refresh();
  }, facesMjsDataUri);

  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 1);

  // Open person
  await page.locator('#ch-people-grid .ch-person-card').click();
  await page.waitForFunction(() => !document.getElementById('ch-person-detail').hidden);

  // Click rename button
  await page.locator('#ch-people-rename-btn').click();
  const renameBarVisible = await page.evaluate(() => !document.getElementById('ch-people-rename-bar').hidden);
  assert.equal(renameBarVisible, true, 'Rename bar is visible');

  // Type new name
  await page.locator('#ch-people-rename-input').fill('Oma Hilde');
  await page.locator('#ch-people-rename-save').click();

  // Verify action called
  await page.waitForFunction(() => window.actionCalls.length > 0);
  const action = await page.evaluate(() => window.actionCalls[0]);
  assert.equal(action.action, 'rename', 'Rename action called');
  assert.equal(action.label, 'Oma Hilde', 'Label passed in action payload');

  // Verify header title updated
  const titleText = await page.locator('#ch-people-detail-title').textContent();
  assert.equal(titleText.trim(), 'Oma Hilde', 'Detail title updated to Oma Hilde');

  await browser.close();
});

test('Real DOM: Kenne ich nicht (Stranger) moves person, shows undo bar, and undo reverts', async (t) => {
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
            items: [{ id: 'subj-1', photo_count: 2, label: null, cover_id: 'c1' }],
            filter_counts: { all: 1, suggested: 0, single: 0, review: 0, strangers: 0, filtered: 0, hidden: 0 }
          };
        }
        if (endpoint.includes('/media?')) {
          return { items: [{ id: 1, name: 'f1.jpg', face_detection_ids: ['d1'] }] };
        }
        if (endpoint.includes('/action')) {
          const body = JSON.parse(options.body);
          window.actionCalls.push(body);
          return { ok: true, rules_revision: 2 };
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });
    window.controller.refresh();
  }, facesMjsDataUri);

  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 1);

  // Open person
  await page.locator('#ch-people-grid .ch-person-card').click();
  await page.waitForFunction(() => !document.getElementById('ch-person-detail').hidden);

  // Click "Kenne ich nicht" (executes directly and shows undo bar)
  await page.locator('#ch-people-toggle-stranger').click();
  await page.waitForFunction(() => window.actionCalls.length > 0);

  // Verify stranger action
  const strangerAction = await page.evaluate(() => window.actionCalls[0]);
  assert.equal(strangerAction.action, 'stranger', 'Stranger action sent');

  // Verify detail view closed
  const detailHidden = await page.evaluate(() => document.getElementById('ch-person-detail').hidden);
  assert.equal(detailHidden, true, 'Detail view closed after moving to strangers');

  // Verify undo bar is visible
  const undoBarVisible = await page.evaluate(() => !document.getElementById('ch-people-undo-bar').hidden);
  assert.equal(undoBarVisible, true, 'Undo bar is visible');
  const undoText = await page.locator('#ch-people-undo-text').textContent();
  assert.ok(undoText.includes('Unbekannte Personen'), 'Undo text mentions Unbekannte Personen');

  // Click Undo
  await page.locator('#ch-people-undo-btn').click();
  await page.waitForFunction(() => window.actionCalls.length > 1);

  const revertAction = await page.evaluate(() => window.actionCalls[1]);
  assert.equal(revertAction.action, 'unstranger', 'Undo sends reverse unstranger action');

  await browser.close();
});

test('Real DOM: Bulk selection moves several rare people at once, locks mixed filtered groups, and undo reverts', async (t) => {
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
    window.bulkCalls = [];
    window.singleCalls = [];
    window.controller = createPeopleController({
      root: document.getElementById('ch-people-view'),
      dialogRoot: document.getElementById('nextgallery'),
      api: async (endpoint, options = {}) => {
        if (endpoint.includes('subjects?')) {
          const filtered = endpoint.includes('filter=filtered');
          return {
            items: filtered
              ? [
                  { id: 'weak-only', photo_count: 1, label: null, cover_id: 'c1', good_count: 0, filtered: true },
                  { id: 'mama', photo_count: 2, label: null, cover_id: 'c2', good_count: 40, filtered: true }
                ]
              : [
                  { id: 'r1', photo_count: 1, label: null, cover_id: 'c1' },
                  { id: 'r2', photo_count: 2, label: null, cover_id: 'c2' },
                  { id: 'r3', photo_count: 1, label: null, cover_id: 'c3' }
                ],
            rules_revision: 7,
            filter_counts: { all: 0, suggested: 0, single: 3, review: 0, strangers: 0, filtered: 2, hidden: 0 }
          };
        }
        if (endpoint === 'subjects/bulk-action') {
          window.bulkCalls.push(JSON.parse(options.body));
          return { success: true, rules_revision: 8 + window.bulkCalls.length };
        }
        if (endpoint.includes('/action') || endpoint.includes('/media?')) {
          window.singleCalls.push(endpoint);
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });
    window.controller.setFilter('single');
  }, facesMjsDataUri);

  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 3);
  assert.equal(await page.evaluate(() => document.getElementById('ch-people-bulk-bar').hidden), true, 'Bulk bar hidden until select mode');

  await page.locator('#ch-people-select-mode').click();
  assert.equal(await page.evaluate(() => document.getElementById('ch-people-bulk-bar').hidden), false, 'Bulk bar visible in select mode');
  assert.equal(await page.locator('#ch-people-bulk-stranger').isDisabled(), true, 'Actions disabled without selection');

  await page.locator('#ch-people-grid .ch-person-card[data-id="r1"]').click();
  await page.locator('#ch-people-grid .ch-person-card[data-id="r3"]').click();
  assert.equal(await page.evaluate(() => document.getElementById('ch-person-detail').hidden), true, 'Card click selects instead of opening');
  assert.equal(await page.locator('#ch-people-grid .ch-person-card.is-selected').count(), 2);
  assert.ok((await page.locator('#ch-people-bulk-count').textContent()).includes('2 Personen'), 'Count label shows two people');

  await page.locator('#ch-people-bulk-stranger').click();
  await page.waitForFunction(() => window.bulkCalls.length === 1);
  const call = await page.evaluate(() => window.bulkCalls[0]);
  assert.deepEqual(call, { action: 'stranger', ids: ['r1', 'r3'], rules_revision: 7 }, 'One bulk request with both ids and current revision');
  assert.deepEqual(await page.evaluate(() => window.singleCalls), [], 'No per-person requests');

  await page.waitForFunction(() => !document.getElementById('ch-people-undo-bar').hidden);
  assert.ok((await page.locator('#ch-people-undo-text').textContent()).includes('Unbekannte Personen'));
  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 3);
  assert.equal(await page.locator('#ch-people-grid .ch-person-card.is-selected').count(), 0, 'Selection cleared after reload');

  await page.locator('#ch-people-undo-btn').click();
  await page.waitForFunction(() => window.bulkCalls.length === 2);
  const undo = await page.evaluate(() => window.bulkCalls[1]);
  assert.equal(undo.action, 'unstranger', 'Undo sends inverse bulk action');
  assert.deepEqual(undo.ids, ['r1', 'r3']);

  // Select all on the filtered view skips groups that also have good photos in the main view.
  await page.evaluate(() => window.controller.setFilter('filtered'));
  await page.waitForFunction(() => document.querySelector('#ch-people-grid .ch-person-card[data-id="mama"]'));
  assert.equal(await page.locator('#ch-people-grid .ch-person-card[data-id="mama"]').getAttribute('aria-disabled'), 'true');
  assert.equal(await page.evaluate(() => document.getElementById('ch-people-bulk-note').hidden), false, 'Locked groups are explained');
  await page.locator('#ch-people-bulk-all').click();
  assert.deepEqual(
    await page.evaluate(() => [...document.querySelectorAll('#ch-people-grid .ch-person-card.is-selected')].map((c) => c.dataset.id)),
    ['weak-only'],
    'Select all only picks weak-only groups'
  );
  await page.locator('#ch-people-bulk-hide').click();
  await page.waitForFunction(() => window.bulkCalls.length === 3);
  const hideCall = await page.evaluate(() => window.bulkCalls[2]);
  assert.equal(hideCall.action, 'hide');
  assert.deepEqual(hideCall.ids, ['weak-only']);

  await page.locator('#ch-people-bulk-cancel').click();
  assert.equal(await page.evaluate(() => document.getElementById('ch-people-bulk-bar').hidden), true, 'Done leaves select mode');

  await browser.close();
});

test('Real DOM: Shared sorting dialog opens, toggles share_sorting, and adopts sorting', async (t) => {
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
    window.sharedCalls = [];
    window.controller = createPeopleController({
      root: document.getElementById('ch-people-view'),
      dialogRoot: document.getElementById('nextgallery'),
      api: async (endpoint, options = {}) => {
        window.sharedCalls.push({ endpoint, options });
        if (endpoint.includes('subjects?')) {
          return { items: [], filter_counts: {} };
        }
        if (endpoint.includes('subjects/shared-sorting')) {
          if (options.method === 'POST') {
            return { success: true };
          }
          return {
            sharing_enabled: false,
            available: [
              {
                user_id: 'alice',
                display_name: 'Alice',
                shared_files: 15,
                people_count: 4,
                named_count: 2,
                stranger_count: 1,
                sample_labels: ['Oma', 'Papa']
              }
            ]
          };
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });
    window.controller.refresh();
  }, facesMjsDataUri);

  // Click Sortierung teilen button
  await page.locator('#ch-people-share-sorting').click();
  await page.waitForFunction(() => document.getElementById('ch-people-share-dialog').open);

  const dialogOpen = await page.evaluate(() => document.getElementById('ch-people-share-dialog').open);
  assert.equal(dialogOpen, true, 'Shared sorting dialog is open');

  // Available users list contains Alice
  await page.waitForFunction(() => document.querySelectorAll('#ch-people-share-list .ch-people-share-item').length === 1);
  const cardText = await page.locator('#ch-people-share-list .ch-people-share-item').textContent();
  assert.ok(cardText.includes('Alice'), 'Alice listed in available sortings');
  assert.ok(cardText.includes('15 Fotos'), 'Lists shared photo count');

  // Click toggle to enable sharing
  await page.locator('#ch-people-share-toggle').click();
  await page.waitForFunction(() => window.sharedCalls.some(c => c.options.method === 'POST'));

  const toggleCall = await page.evaluate(() => window.sharedCalls.find(c => c.options.method === 'POST'));
  assert.ok(toggleCall.endpoint.includes('subjects/shared-sorting'), 'Saved shared sorting');
  const body = JSON.parse(toggleCall.options.body);
  assert.equal(body.mode, 'share');
  assert.equal(body.enabled, true);

  // Click adopt button for Alice (executes adoption)
  await page.locator('#ch-people-share-list button:has-text("Sortierung übernehmen")').click();
  await page.waitForFunction(() => window.sharedCalls.filter(c => c.options.method === 'POST').length >= 2);

  const adoptCall = await page.evaluate(() => window.sharedCalls.filter(c => c.options.method === 'POST')[1]);
  const adoptBody = JSON.parse(adoptCall.options.body);
  assert.equal(adoptBody.mode, 'adopt');
  assert.equal(adoptBody.source_user, 'alice');

  // Close share dialog
  await page.locator('#ch-people-share-close').click();
  await page.waitForFunction(() => !document.getElementById('ch-people-share-dialog').open);

  await browser.close();
});

test('Real DOM: Toggle face frames button state and interactive zoom in context inspection', async (t) => {
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

  await page.setContent(HTML_SHELL);

  await page.evaluate(async (modUrl) => {
    const { createPeopleController } = await import(modUrl);
    window.controller = createPeopleController({
      root: document.getElementById('ch-people-view'),
      dialogRoot: document.getElementById('nextgallery'),
      api: async (endpoint) => {
        if (endpoint.includes('subjects?')) {
          return {
            items: [
              { id: 'subj-1', photo_count: 3, label: 'Lukas', cover_id: 'c1', cover_box: [0.2, 0.2, 0.3, 0.3] },
              { id: 'subj-2', photo_count: 5, label: 'Julia', cover_id: 'c2', cover_box: [0.4, 0.3, 0.2, 0.2] }
            ],
            filter_counts: { all: 2, suggested: 0, single: 0, review: 0, strangers: 0, filtered: 0, hidden: 0 }
          };
        }
        if (endpoint.includes('/media?')) {
          return {
            items: [
              {
                id: 101,
                name: 'lukas_pic1.jpg',
                face_detection_ids: ['d1'],
                faces: [{ id: 'd1', box: [0.25, 0.25, 0.3, 0.3] }]
              }
            ]
          };
        }
        return { items: [] };
      },
      getGallery: () => 'default'
    });
    window.controller.refresh();
  }, facesMjsDataUri);

  await page.waitForFunction(() => document.querySelectorAll('#ch-people-grid .ch-person-card').length === 2);

  // Open person Lukas
  await page.locator('#ch-people-grid .ch-person-card[data-id="subj-1"]').click();
  await page.waitForFunction(() => !document.getElementById('ch-person-detail').hidden);

  // 1. Verify Toggle Face Frames button initial active state
  const toggleBtn = page.locator('#ch-people-toggle-boxes');
  assert.equal(await toggleBtn.getAttribute('aria-pressed'), 'true', 'Toggle button starts with aria-pressed="true"');
  assert.ok((await toggleBtn.textContent()).includes('✓ Gesichtsrahmen an'), 'Initial button text shows active checkmark');
  assert.equal(await toggleBtn.evaluate(el => el.classList.contains('active')), true, 'Initial button has active class');

  // Toggle off
  await toggleBtn.click();
  assert.equal(await toggleBtn.getAttribute('aria-pressed'), 'false', 'Toggled button has aria-pressed="false"');
  assert.equal(await toggleBtn.textContent(), 'Gesichtsrahmen aus', 'Toggled button text shows Gesichtsrahmen aus');
  assert.equal(await toggleBtn.evaluate(el => el.classList.contains('active')), false, 'Toggled button does not have active class');

  // Toggle on again
  await toggleBtn.click();
  assert.equal(await toggleBtn.getAttribute('aria-pressed'), 'true', 'Button re-activated with aria-pressed="true"');
  assert.ok((await toggleBtn.textContent()).includes('✓ Gesichtsrahmen an'), 'Button re-activated text shows checkmark');
  assert.equal(await toggleBtn.evaluate(el => el.classList.contains('active')), true, 'Button re-activated has active class');

  // 2. Open merge dialog to test merge preview inspect button and no emoji
  await page.locator('#ch-people-merge').click();
  await page.waitForFunction(() => document.getElementById('ch-people-merge-dialog').open);
  await page.waitForFunction(() => document.querySelectorAll('#ch-people-merge-targets .ch-people-merge-choice').length === 1);

  // Select target person Julia
  await page.locator('#ch-people-merge-targets .ch-people-merge-choice').click();
  const inspectBtn = page.locator('#ch-people-merge-selection-preview button.ch-button');
  const inspectBtnText = await inspectBtn.textContent();
  assert.ok(!inspectBtnText.includes('🔍'), 'Inspect button text must not contain magnifying glass emoji: ' + inspectBtnText);
  assert.ok(inspectBtnText.includes('Ganzes Bild & Fotos prüfen'), 'Inspect button has clean text: Ganzes Bild & Fotos prüfen');

  // Open inspect dialog
  await inspectBtn.click();
  await page.waitForFunction(() => document.getElementById('ch-people-inspect-dialog').open);

  // 3. Inspect Zoom Functionality
  const zoomBar = page.locator('#ch-people-inspect-stage .ch-people-inspect-zoom-bar');
  assert.equal(await zoomBar.isVisible(), true, 'Zoom toolbar is visible in inspect dialog');

  const badge = page.locator('#ch-people-inspect-stage .ch-people-inspect-zoom-badge');
  assert.equal(await badge.textContent(), '100%', 'Initial zoom badge is 100%');

  const fullWrap = page.locator('#ch-people-inspect-stage .ch-people-inspect-full-wrap');
  assert.equal(await fullWrap.evaluate(el => el.classList.contains('is-zoomed')), false, 'Initially not zoomed');

  // Click Zoom In (+)
  const zoomInBtn = page.locator('#ch-people-inspect-stage button[aria-label="Vergrößern"]');
  await zoomInBtn.click();
  const zoomedText = await badge.textContent();
  assert.ok(zoomedText.includes('135%'), 'Zoom badge shows 135% after zoom in');
  assert.equal(await fullWrap.evaluate(el => el.classList.contains('is-zoomed')), true, 'fullWrap has is-zoomed class');

  // Click Fokus Gesicht
  const focusBtn = page.locator('#ch-people-inspect-stage .ch-people-inspect-zoom-face');
  await focusBtn.click();
  const focusedText = await badge.textContent();
  assert.ok(focusedText.includes('260%'), 'Zoom badge shows 260% after Fokus Gesicht');

  // Click Reset (100% badge)
  await badge.click();
  assert.equal(await badge.textContent(), '100%', 'Zoom reset back to 100% on badge click');
  assert.equal(await fullWrap.evaluate(el => el.classList.contains('is-zoomed')), false, 'is-zoomed class removed after reset');

  // Close dialogs
  await page.locator('#ch-people-inspect-close').click();
  await page.waitForFunction(() => !document.getElementById('ch-people-inspect-dialog').open);
  await page.locator('#ch-people-merge-cancel').click();
  await page.waitForFunction(() => !document.getElementById('ch-people-merge-dialog').open);

  await browser.close();
});
