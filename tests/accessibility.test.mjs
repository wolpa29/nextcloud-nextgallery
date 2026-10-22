import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {galleryFixture, ROOT} from './helpers/gallery-browser.mjs';

const axeScript = readFileSync(ROOT + '/node_modules/axe-core/axe.min.js', 'utf8');

async function audit(page, contextSelector = '#nextgallery') {
 await page.addScriptTag({content: axeScript});
 return page.evaluate(async sel => {
  const target = document.querySelector(sel);
  const result = await axe.run(target, {
   runOnly: {type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']}
  });
  return result.violations.map(({id, impact, nodes}) => ({
   id,
   impact,
   nodes: nodes.map(node => ({target: node.target, summary: node.failureSummary}))
  }));
 }, contextSelector);
}

test('Dialogs maintain escape dismissal and exact focus return to trigger elements', async t => {
 const f = await galleryFixture(t);
 if (!f) return;
 const {page} = f;

 const dialogCases = [
  {trigger: '#ch-display', dialog: '#ch-display-dialog'},
  {trigger: '#ch-period', dialog: '#ch-period-dialog'},
  {trigger: '#ch-gallery-switch', dialog: '#ch-galleries-dialog'}
 ];

 for (const {trigger, dialog} of dialogCases) {
  // Test Escape dismissal and focus return
  await page.locator(trigger).focus();
  await page.locator(trigger).click();
  assert.equal(await page.locator(dialog).evaluate(el => el.open), true);
  await page.keyboard.press('Escape');
  assert.equal(await page.locator(dialog).evaluate(el => el.open), false);
  const activeAfterEsc = await page.evaluate(() => document.activeElement?.id);
  assert.equal(activeAfterEsc, trigger.replace('#', ''), `Focus must return to ${trigger} after Escape`);

  // Test close button dismissal and focus return
  await page.locator(trigger).focus();
  await page.locator(trigger).click();
  assert.equal(await page.locator(dialog).evaluate(el => el.open), true);
  await page.locator(`${dialog} button.ch-dialog-close`).first().click();
  assert.equal(await page.locator(dialog).evaluate(el => el.open), false);
  const activeAfterBtn = await page.evaluate(() => document.activeElement?.id);
  assert.equal(activeAfterBtn, trigger.replace('#', ''), `Focus must return to ${trigger} after close button`);
 }

 // Test viewer dialog focus return to tile
 const tile = page.locator('.ch-tile:not([disabled])').first();
 await tile.focus();
 await tile.click();
 assert.equal(await page.locator('#ch-viewer').evaluate(el => el.open), true);
 await page.keyboard.press('Escape');
 assert.equal(await page.locator('#ch-viewer').evaluate(el => el.open), false);
 const activeTile = await page.evaluate(() => document.activeElement?.classList.contains('ch-tile'));
 assert.equal(activeTile, true, 'Focus must return to the media tile after viewer close');
});

test('Segmented radiogroups support roving tabindex and keyboard arrow navigation', async t => {
 const f = await galleryFixture(t);
 if (!f) return;
 const {page} = f;

 await page.locator('#ch-display').click();
 const activeOrderBtn = page.locator('#ch-display-dialog .ch-segmented[data-control=ch-order] .ch-seg-btn.active');
 await activeOrderBtn.focus();
 const initialVal = await activeOrderBtn.getAttribute('data-value');
 assert.equal(initialVal, 'desc');

 await page.keyboard.press('ArrowRight');
 const afterRightBtn = page.locator('#ch-display-dialog .ch-segmented[data-control=ch-order] .ch-seg-btn.active');
 assert.equal(await afterRightBtn.getAttribute('data-value'), 'asc');
 assert.equal(await afterRightBtn.getAttribute('aria-checked'), 'true');
 assert.equal(await afterRightBtn.getAttribute('tabindex'), '0');

 await page.keyboard.press('Home');
 const afterHomeBtn = page.locator('#ch-display-dialog .ch-segmented[data-control=ch-order] .ch-seg-btn.active');
 assert.equal(await afterHomeBtn.getAttribute('data-value'), 'desc');

 await page.keyboard.press('Escape');
});

test('Fast-scroll slider handles keyboard navigation and updates ARIA values', async t => {
 const f = await galleryFixture(t, {total: 60});
 if (!f) return;
 const {page} = f;

 const fast = page.locator('#ch-fast-scroll');
 assert.equal(await fast.isHidden(), false, 'Fast scroll must be visible with 60 items');
 assert.equal(await fast.getAttribute('role'), 'slider');
 assert.equal(await fast.getAttribute('aria-valuemin'), '0');
 assert.equal(await fast.getAttribute('aria-valuemax'), '100');

 await fast.focus();
 assert.equal(await fast.getAttribute('aria-valuenow'), '0');

 await page.keyboard.press('PageDown');
 await page.waitForTimeout(100);
 const pagedValue = Number(await fast.getAttribute('aria-valuenow'));
 assert(pagedValue > 0, 'PageDown must increase fast scroll value');

 await page.keyboard.press('Home');
 await page.waitForTimeout(100);
 assert.equal(await fast.getAttribute('aria-valuenow'), '0');
});

test('Media viewer supports keyboard arrow navigation between items', async t => {
 const f = await galleryFixture(t, {total: 5});
 if (!f) return;
 const {page} = f;

 const tiles = page.locator('.ch-tile:not([disabled])');
 await tiles.nth(1).click();
 assert.equal(await page.locator('#ch-viewer').evaluate(el => el.open), true);
 const name1 = await page.locator('#ch-viewer-name').innerText();

 await page.keyboard.press('ArrowRight');
 const name2 = await page.locator('#ch-viewer-name').innerText();
 assert.notEqual(name1, name2, 'ArrowRight must advance to next media item');

 await page.keyboard.press('ArrowLeft');
 const name3 = await page.locator('#ch-viewer-name').innerText();
 assert.equal(name1, name3, 'ArrowLeft must return to previous media item');

 await page.keyboard.press('Escape');
});

test('Axe-core confirms timeline, search, map, settings, offline, and dialogs have zero ARIA violations', async t => {
 const f = await galleryFixture(t);
 if (!f) return;
 const {page, state} = f;

 // 1. Timeline View (group role and retry labels)
 assert.deepEqual(await audit(page, '#ch-timeline-view'), []);

 // 2. Modal Dialogs (openable from timeline)
 for (const id of ['ch-display', 'ch-gallery-switch']) {
  await page.locator('#' + id).click();
  const dialogId = {'ch-display': '#ch-display-dialog', 'ch-gallery-switch': '#ch-galleries-dialog'}[id];
  assert.deepEqual(await audit(page, dialogId), []);
  await page.keyboard.press('Escape');
 }
 await page.locator('#ch-period').click();
 const periodStructuralAxe = await page.evaluate(async () => {
  const res = await axe.run(document.getElementById('ch-period-dialog'), {
   runOnly: {type: 'rule', values: ['aria-required-children', 'aria-roles', 'aria-prohibited-attr', 'button-name']}
  });
  return res.violations;
 });
 assert.deepEqual(periodStructuralAxe, []);
 await page.keyboard.press('Escape');

 // 3. Search View with results
 await page.route('**/api/search?**', route => route.fulfill({
  json: {items: state.items, more: false, next: null}
 }));
 await page.locator('#ch-header-search').click();
 await page.locator('#ch-search-query').fill('Synthetic');
 await page.locator('#ch-search-query').press('Enter');
 await page.locator('.ch-search-tile').first().waitFor();
 assert.deepEqual(await audit(page, '#ch-search-view'), []);

 // 4. Map View (region role with label)
 await page.locator('nav button[data-view=map]').click();
 const mapRole = await page.locator('#ch-map').getAttribute('role');
 const mapLabel = await page.locator('#ch-map').getAttribute('aria-label');
 assert.equal(mapRole, 'region');
 assert.equal(mapLabel, 'Weltkarte mit Medienstandorten');
 assert.deepEqual(await audit(page, '#ch-map-view'), []);

 // 5. Settings View
 await page.locator('button[data-view=settings]').click();
 assert.deepEqual(await audit(page, '#ch-settings-view'), []);

 // 6. Offline View
 await page.locator('nav button[data-view=offline]').click();
 assert.deepEqual(await audit(page, '#ch-offline-view'), []);
});

test('People detail view genuinely renders photos via subjects/<id>/media route and alias resolver', async t => {
 const f = await galleryFixture(t);
 if (!f) return;
 const {page,state} = f;

 await page.route('**/api/v1/subjects**', route => route.fulfill({
  json: {
   items: [
    { id: 1, label: 'Alice', photo_count: 2, cover_id: 101, suggestion_count: 0, filtered: false }
   ],
   rules_revision: 1
  }
 }));

 await page.route('**/api/v1/subjects/1/media?**', route => route.fulfill({
  json: {
   items: [
    { ...state.items[0], id: 101, item_id: 1 },
    { ...state.items[1], id: 102, item_id: 2 }
   ],
   more: false,
   next: null,
   subject: { id: 1, label: 'Alice', cover_id: 101 }
  }
 }));

 await page.locator('nav button[data-view=people]').click();
 await page.locator('.ch-person-card').first().click();

 await page.locator('#ch-person-detail').waitFor({state: 'visible'});
 await page.locator('.ch-person-media-tile').first().waitFor();

 const tileCount = await page.locator('.ch-person-media-tile').count();
 assert.equal(tileCount, 2, 'People detail view renders all media tiles via alias resolver');
 assert.equal(await page.locator('#ch-people-detail-title').innerText(), 'Alice');
 assert.deepEqual(await audit(page, '#ch-person-detail'), []);
 const photo=page.locator('.ch-person-media-tile img[role=button]').first();
 await photo.focus();await photo.press('Enter');
 await page.locator('#ch-viewer').waitFor({state:'visible'});
 await page.keyboard.press('Escape');
 assert.equal(await photo.evaluate(el=>document.activeElement===el),true);
});

test('Reflow at 320px viewport and 200% text zoom retains usable layout without horizontal overflow', async t => {
 const f = await galleryFixture(t, {width: 320, height: 640});
 if (!f) return;
 const {page} = f;

 const scrollWidth = await page.evaluate(() => document.body.scrollWidth);
 const clientWidth = await page.evaluate(() => document.body.clientWidth);
 assert.equal(scrollWidth <= clientWidth + 1, true, 'Body must not produce horizontal scrolling at 320px width');

 await page.evaluate(() => {
  document.documentElement.style.fontSize = '200%';
 });

 for (const viewName of ['timeline', 'settings', 'offline']) {
  if (viewName !== 'timeline') await page.locator(`[data-view=${viewName}]`).first().click();
  const overflowX = await page.evaluate(name => {
   const el = document.getElementById(`ch-${name}-view`);
   return el ? el.scrollWidth > el.clientWidth + 1 : false;
  }, viewName);
  assert.equal(overflowX, false, `View [${viewName}] must not horizontally overflow at 200% text zoom`);
 }
});

test('Firefox browser engine executes accessibility fixtures and verifies native dialog focus return and tab boundary', async t => {
 const f = await galleryFixture(t, {browserName: 'firefox'});
 if (!f) return;
 const {page} = f;

 // Verify timeline loaded in Firefox
 const tileCount = await page.locator('.ch-tile[data-id]').count();
 assert.equal(tileCount, 9, 'Firefox must load synthetic tiles');

 // Verify focus return in Firefox
 const displayBtn = page.locator('#ch-display');
 await displayBtn.focus();
 await displayBtn.click();
 assert.equal(await page.locator('#ch-display-dialog').evaluate(el => el.open), true);
 await page.keyboard.press('Escape');
 assert.equal(await page.locator('#ch-display-dialog').evaluate(el => el.open), false);
 const activeId = await page.evaluate(() => document.activeElement?.id);
 assert.equal(activeId, 'ch-display', 'Firefox must return focus to trigger element on dialog close');

 // Forward and reverse Tab wrap inside the modal, including Firefox.
 const tile = page.locator('.ch-tile:not([disabled])').first();
 await tile.click();
 assert.equal(await page.locator('#ch-viewer').evaluate(el => el.open), true);
 await page.locator('#ch-original').focus();
 await page.keyboard.press('Tab');
 assert.equal(await page.evaluate(() => document.activeElement?.id), 'ch-viewer-close');
 await page.keyboard.press('Shift+Tab');
 assert.equal(await page.evaluate(() => document.activeElement?.id), 'ch-original');
 await page.keyboard.press('Escape');
});
