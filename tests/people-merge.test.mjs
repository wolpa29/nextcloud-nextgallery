import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync, mkdirSync} from 'node:fs';
import {resolve} from 'node:path';
const ROOT = resolve(new URL('..', import.meta.url).pathname);
let chromium;
try { ({chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright')); } catch {}
const moduleURL = 'data:text/javascript;base64,' + Buffer.from(readFileSync(resolve(ROOT, 'frontend/faces.mjs'))).toString('base64');
const shell = readFileSync(resolve(ROOT, 'templates/shell.php'), 'utf8')
  .replace(/<\?php p\(\$tr\('([^']*)'\)\); \?>/g, (_, text) => text).replace(/<\?php[\s\S]*?\?>/g, '');
const css = readFileSync(resolve(ROOT, 'css/main.css'), 'utf8');
const out = resolve(ROOT, 'artifacts/people-merge-ux');

async function fixture(t, {width = 1100, height = 850, touch = false} = {}) {
  if (!chromium) { t.skip('Playwright unavailable'); return; }
  const browser = await chromium.launch({executablePath: process.env.PLAYWRIGHT_EXECUTABLE || undefined, headless: true, args: ['--no-sandbox']});
  t.after(() => browser.close());
  const page = await browser.newPage({viewport: {width, height}, hasTouch: touch});
  page.setDefaultTimeout(5000);
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  t.after(() => assert.deepEqual(errors, [], 'No uncaught browser errors'));
  await page.route('https://people.test/**', route => {
    if (route.request().url() === 'https://people.test/') return route.fulfill({contentType: 'text/html', body: '<!doctype html><html></html>'});
    const context = new URL(route.request().url()).searchParams.get('context') === '1';
    const portrait = route.request().url().includes('/b');
    const box = context ? (portrait ? '0 0 160 300' : '0 0 400 200') : '0 0 100 100';
    return route.fulfill({contentType: 'image/svg+xml', body: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${box}"><rect width="100%" height="100%" fill="#c9d6c2"/><circle cx="50" cy="35" r="20" fill="#f7e8db"/><path d="M10 100a40 40 0 0 1 80 0" fill="#36564d"/>${context ? '<rect x="28" y="13" width="44" height="44" fill="none" stroke="#0082c9" stroke-width="3"/>' : ''}</svg>`});
  });
  await page.goto('https://people.test/');
  await page.setContent(`<html><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{height:100%;margin:0}${css}</style>${shell}</html>`);
  await page.evaluate(async url => {
    const {createPeopleController} = await import(url);
    document.querySelectorAll('main>section').forEach(s => s.hidden = s.id !== 'ch-people-view');
    document.getElementById('ch-title').textContent = 'Personen';
    document.getElementById('ch-gallery-name').textContent = 'Synthetische Galerie';
    document.getElementById('ch-source-count').textContent = 'Nur synthetische Testbilder';
    window.groups = [{id:'a',label:'Ada',cover_id:'a',photo_count:7,suggestion_count:1}, {id:'b',label:'Bert',cover_id:'b',photo_count:4}, {id:'c',cover_id:'c',photo_count:1}];
    window.activeGallery = 'synthetic'; window.revision = 10; window.writes = []; window.loads = []; window.reads = [];
    const api = async (endpoint, options = {}) => {
      if (options.method !== 'POST') window.reads.push(endpoint);
      if (endpoint.startsWith('subjects?')) {
        if (window.holdLoads) return new Promise(resolve => window.loads.push({endpoint, resolve}));
        const q = new URLSearchParams(endpoint.split('?')[1]);
        let items = q.get('scope') === 'account' && window.accountGroups ? window.accountGroups : window.groups;
        if (q.get('query')) items = items.filter(g => (g.label || '').toLocaleLowerCase().includes(q.get('query').toLocaleLowerCase()));
        return {items:structuredClone(items), rules_revision:window.revision};
      }
      if (endpoint.includes('/similar?')) return {items:window.accountGroups ? structuredClone(window.accountGroups) : [structuredClone(window.groups.find(g => g.id === 'b'))].filter(Boolean),rules_revision:window.revision};
      if (endpoint.includes('/media?')) return {items:[],rules_revision:window.revision};
      if (options.method === 'POST') {
        const body = JSON.parse(options.body); window.writes.push({endpoint,body});
        if (window.holdWrite) await new Promise(resolve => window.releaseWrite = resolve);
        if (window.failWrite) { const failure=window.failWrite;window.failWrite=null;throw Object.assign(Error(failure.message),{status:failure.status}); }
        if (body.rules_revision !== window.revision) throw Object.assign(Error('Stale revision'), {status:409});
        window.revision++;
        if (body.action === 'merge') {
          const target = window.groups.find(g => g.id === body.target_id) || window.accountGroups?.find(g => g.id === body.target_id);
          const sources = window.groups.filter(g => body.ids.includes(g.id));
          target.photo_count += sources.reduce((n,g) => n + g.photo_count, 0);
          if (!window.groups.some(g => g.id === target.id)) window.groups.push(target);
          window.groups = window.groups.filter(g => !body.ids.includes(g.id));
          return {success:true,rules_revision:window.revision,target_id:target.id,merged_detection_ids:sources.map(g=>'d-'+g.id),merged_groups:sources.map(g=>({source_id:g.id,detection_ids:['d-'+g.id]}))};
        }
        return {success:true,rules_revision:window.revision,target_id:body.target_id || 'restored-'+window.revision};
      }
      return {items:[]};
    };
    window.mount = () => window.controller = createPeopleController({root:document.getElementById('ch-people-view'),dialogRoot:document.getElementById('nextgallery'),api,getGallery:()=>window.activeGallery,previewUrl:id=>`https://people.test/preview/${id}?gallery=${window.activeGallery}`});
    window.mount().show();
  }, moduleURL);
  await page.locator('#ch-people-grid .ch-person-card').first().waitFor();
  return page;
}
async function bulk(page, ids) {
  await page.locator('#ch-people-select-mode').click();
  for (const id of ids) await page.locator(`#ch-people-grid [data-id="${id}"]`).click();
  await page.locator('#ch-people-bulk-merge').click();
  await page.locator('#ch-people-merge-dialog').waitFor();
}

test('People: stored preview state, browser-restored checkbox and blocked storage stay consistent', async t => {
  const page = await fixture(t); if (!page) return;
  const toggle = page.locator('#ch-people-context-previews');
  assert.equal(await toggle.isChecked(), false);
  await toggle.check();
  await page.evaluate(() => { document.getElementById('ch-people-context-previews').checked=false;window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true})); });
  assert.equal(await toggle.isChecked(), true);
  assert.equal(await page.locator('#ch-people-grid').evaluate(el=>el.classList.contains('is-context')), true);
  await page.evaluate(() => window.mount().show());
  assert.equal(await toggle.isChecked(), true);
  await toggle.uncheck();
  await page.evaluate(() => { document.getElementById('ch-people-context-previews').checked=true;window.controller.hide();window.controller.show(); });
  assert.equal(await toggle.isChecked(), false);
  assert.equal(await page.locator('#ch-people-grid img').first().evaluate(el=>new URL(el.src).searchParams.has('context')), false);
  await page.evaluate(() => { Object.defineProperty(window,'localStorage',{configurable:true,get(){throw Error('Storage blocked');}});window.mount().show(); });
  await toggle.check();
  assert.equal(await page.locator('#ch-people-grid img').first().evaluate(el=>new URL(el.src).searchParams.get('context')), '1');
});

test('People: three selected groups merge in one request into chosen survivor and split separately', async t => {
  const page=await fixture(t);if(!page)return;
  await bulk(page,['a','b','c']);
  assert.equal(await page.locator('#ch-people-merge-ok').isDisabled(), true);
  assert.equal(await page.evaluate(()=>window.writes.length),0);
  await page.locator('#ch-people-merge-picked [data-target-id="b"]').click();
  assert.match(await page.locator('#ch-people-merge-selection-preview').textContent(),/Bleibt bestehen: Bert/);
  assert.match(await page.locator('#ch-people-merge-selection-preview').textContent(),/allen Galerien/);
  await page.locator('#ch-people-merge-ok').dblclick();
  await page.waitForFunction(()=>!document.getElementById('ch-people-merge-dialog').open);
  assert.deepEqual(await page.evaluate(()=>window.writes),[{endpoint:'subjects/bulk-action',body:{action:'merge',ids:['a','c'],rules_revision:10,target_id:'b'}}]);
  await page.locator('#ch-people-undo-btn').click();
  await page.waitForFunction(()=>window.writes.length===3);
  const splits=await page.evaluate(()=>window.writes.slice(1));
  assert.deepEqual(splits.map(s=>s.body.detection_ids),[['d-a'],['d-c']]);
  assert.deepEqual(splits.map(s=>s.body.rules_revision),[11,12]);
});

test('People: single merge can reverse direction before saving and keeps surviving detail open', async t => {
  const page=await fixture(t);if(!page)return;
  await page.locator('#ch-people-grid [data-id="a"]').click();
  await page.locator('#ch-people-merge').click();
  await page.locator('#ch-people-merge-targets [data-target-id="b"]').click();
  await page.locator('#ch-people-merge-swap').click();
  assert.match(await page.locator('#ch-people-merge-selection-preview').textContent(),/Bleibt bestehen: Ada/);
  await page.locator('#ch-people-merge-ok').click();
  await page.waitForFunction(()=>window.writes.length===1&&!document.getElementById('ch-people-merge-dialog').open);
  assert.deepEqual(await page.evaluate(()=>window.writes[0].body),{action:'merge',ids:['b'],rules_revision:10,target_id:'a'});
  assert.equal(await page.evaluate(()=>window.controller.getSelectedSubject()?.id),'a');
});

test('People: failed merge retains target for retry and revision conflicts refresh with explanation', async t => {
  const page=await fixture(t);if(!page)return;
  await bulk(page,['a','b']);
  await page.locator('#ch-people-merge-picked [data-target-id="a"]').click();
  await page.evaluate(()=>window.failWrite={message:'Temporary failure',status:503});
  await page.locator('#ch-people-merge-ok').click();
  await page.locator('#ch-people-merge-error').waitFor();
  assert.equal(await page.locator('#ch-people-merge-error').textContent(),'Temporary failure');
  assert.equal(await page.locator('#ch-people-merge-picked [data-target-id="a"]').getAttribute('aria-pressed'),'true');
  assert.equal(await page.locator('#ch-people-merge-ok').isEnabled(),true);
  await page.evaluate(()=>window.failWrite={message:'Sorting changed, please retry',status:409});
  await page.locator('#ch-people-merge-ok').click();
  await page.locator('#ch-people-error').waitFor();
  assert.equal(await page.locator('#ch-people-merge-dialog').isVisible(),false);
  assert.match(await page.locator('#ch-people-error').textContent(),/Sorting changed/);
  assert.equal(await page.locator('#ch-people-grid .ch-person-card').count(),3);
});

test('People: late filter results and gallery changes cannot replace current merge candidates', async t => {
  const page=await fixture(t);if(!page)return;
  await bulk(page,['a','b']);
  await page.locator('#ch-people-merge-browse>summary').click();
  await page.evaluate(()=>window.holdLoads=true);
  await page.locator('[data-merge-filter="single"]').click();
  await page.locator('[data-merge-filter="strangers"]').click();
  await page.evaluate(()=>window.loads[1].resolve({items:[{id:'fresh',label:'Newest',photo_count:3}]}));
  await page.locator('#ch-people-merge-targets [data-target-id="fresh"]').waitFor();
  await page.evaluate(()=>window.loads[0].resolve({items:[{id:'old',label:'Outdated',photo_count:3}]}));
  assert.equal(await page.locator('#ch-people-merge-targets [data-target-id="old"]').count(),0);
  await page.locator('[data-merge-filter="filtered"]').click();
  await page.evaluate(()=>{window.holdLoads=false;window.activeGallery='other';window.controller.onGalleryChange('other');window.loads[2].resolve({items:[{id:'old',photo_count:3}]});});
  assert.equal(await page.locator('#ch-people-merge-dialog').isVisible(),false);
  assert.equal(await page.evaluate(()=>window.writes.length),0);
  await page.locator('#ch-people-grid .ch-person-card').first().waitFor();
});

test('People: a saved merge finishing while hidden invalidates stale selection and detail', async t => {
  const page=await fixture(t);if(!page)return;
  await bulk(page,['a','b']);
  await page.locator('#ch-people-merge-picked [data-target-id="a"]').click();
  await page.evaluate(()=>window.holdWrite=true);
  await page.locator('#ch-people-merge-ok').click();
  await page.waitForFunction(()=>Boolean(window.releaseWrite));
  await page.evaluate(()=>{window.controller.hide();window.releaseWrite();});
  await page.waitForFunction(()=>window.groups.length===2);
  await page.evaluate(()=>window.controller.show());
  await page.waitForFunction(()=>document.querySelectorAll('#ch-people-grid .ch-person-card').length===2);
  assert.equal(await page.locator('#ch-people-select-mode').getAttribute('aria-pressed'),'false');
});

test('People: real mobile and desktop template fits with explicit merge roles in light and dark', async t => {
  const page=await fixture(t,{width:390,height:844,touch:true});if(!page)return;
  mkdirSync(out,{recursive:true});
  for(const width of [320,390,768,1360])for(const theme of ['light','dark']){
    await page.setViewportSize({width,height:844});
    await page.evaluate(theme=>document.getElementById('nextgallery').dataset.appearance=theme,theme);
    assert.equal(await page.locator('#ch-people-view').evaluate(el=>el.scrollWidth>el.clientWidth+1),false,`Overview fits ${width}/${theme}`);
    assert.equal(await page.locator('#ch-people-filter').isVisible(),width<=700);
    await page.screenshot({path:resolve(out,`overview-${theme}-${width}.png`)});
    await bulk(page,['a','b']);
    await page.locator('#ch-people-merge-picked [data-target-id="a"]').click();
    assert.equal(await page.locator('#ch-people-merge-dialog').evaluate(el=>el.scrollWidth>el.clientWidth+1),false,`Dialog fits ${width}/${theme}`);
    assert.match(await page.locator('#ch-people-merge-selection-preview').textContent(),/Wird hinzugefügt: Bert.*Bleibt bestehen: Ada/s);
    await page.locator('#ch-people-merge-dialog').evaluate(el=>Promise.all(el.getAnimations().map(a=>a.finished)));
    const button=await page.locator('#ch-people-merge-ok').boundingBox();assert.ok(button.height>=44);
    await page.locator('#ch-people-merge-ok').scrollIntoViewIfNeeded();
    await page.screenshot({path:resolve(out,`merge-${theme}-${width}.png`)});
    await page.locator('#ch-people-merge-cancel').click();
    await page.locator('#ch-people-bulk-cancel').click();
  }
});

test('People: restored filter controls agree with the first request, show and pageshow', async t => {
  const page = await fixture(t); if (!page) return;
  await page.evaluate(() => {
    window.controller.hide();
    window.mount();
    document.getElementById('ch-people-filter').value = 'single';
    window.reads = [];
    window.controller.show();
  });
  assert.equal(await page.locator('#ch-people-filter').inputValue(), 'all');
  assert.equal(await page.evaluate(() => new URLSearchParams(window.reads[0].split('?')[1]).get('filter')), 'all');
  await page.evaluate(() => window.controller.setFilter('single'));
  await page.evaluate(() => {
    document.getElementById('ch-people-filter').value='all';
    window.dispatchEvent(new PageTransitionEvent('pageshow', {persisted:true}));
  });
  assert.equal(await page.locator('#ch-people-filter').inputValue(), 'single');
  await page.evaluate(() => { controller.hide(); document.getElementById('ch-people-filter').value='all'; controller.show(); });
  assert.equal(await page.locator('#ch-people-filter').inputValue(), 'single');
});

test('People: first rare photo can merge into a named person from another gallery with global search and previews', async t => {
  const page = await fixture(t, {width:390, height:844, touch:true}); if (!page) return;
  await page.evaluate(async () => {
    window.groups = [{id:'c',cover_id:'c',photo_count:1}];
    window.accountGroups = [{id:'maria', label:'Maria', cover_id:'maria', photo_count:20, scope:'account'}];
    await controller.setFilter('single');
  });
  assert.equal(await page.locator('#ch-people-grid .ch-person-card').count(), 1);
  await page.locator('#ch-people-grid [data-id="c"]').click();
  await page.locator('#ch-people-similar-list [data-id="maria"]').waitFor();
  const similar = page.locator('#ch-people-similar-list [data-id="maria"]');
  assert.match(await similar.textContent(), /Alle meine Galerien/);
  assert.equal(await similar.locator('img').evaluate(el=>new URL(el.src).searchParams.get('scope')), 'account');
  assert.ok(await page.evaluate(()=>window.reads.some(e=>e.includes('/similar?') && new URLSearchParams(e.split('?')[1]).get('scope')==='account')));
  await page.locator('#ch-people-merge').click();
  const dialog = page.locator('#ch-people-merge-dialog');
  await dialog.locator('[data-target-id="maria"]').waitFor();
  assert.ok(await page.evaluate(()=>window.reads.some(e=>e.startsWith('subjects?') && new URLSearchParams(e.split('?')[1]).get('scope')==='account')));
  await page.locator('#ch-people-merge-search').fill('Maria');
  await page.waitForFunction(()=>window.reads.some(e=>new URLSearchParams(e.split('?')[1]).get('query')==='Maria'));
  await dialog.locator('[data-target-id="maria"]').click();
  assert.equal(await dialog.evaluate(el=>{const r=el.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1;}), true, 'Account target dialog fits phone width');
  assert.equal(await dialog.locator('#ch-people-merge-picked [data-target-id="maria"] .ch-person-count').evaluate(el=>el.scrollWidth<=el.clientWidth+1), true, 'Global count wraps inside its card');
  mkdirSync(out,{recursive:true});
  await page.screenshot({path:resolve(out,'account-scope-mobile.png')});
  await dialog.getByRole('button', {name:'Ganzes Bild & Fotos prüfen', exact:true}).click();
  await page.locator('#ch-people-inspect-dialog').waitFor();
  assert.equal(await page.locator('#ch-people-inspect-stage img').first().evaluate(el=>new URL(el.src).searchParams.get('scope')), 'account');
  assert.ok(await page.evaluate(()=>window.reads.some(e=>e.startsWith('subjects/maria/media?') && new URLSearchParams(e.split('?')[1]).get('scope')==='account')));
  await page.locator('#ch-people-inspect-close').click();
  await page.locator('#ch-people-merge-ok').click();
  await page.waitForFunction(()=>window.writes.length===1);
  assert.deepEqual(await page.evaluate(()=>window.writes[0].body.ids), ['c']);
  assert.equal(await page.evaluate(()=>window.writes[0].body.target_id), 'maria');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth), true, 'No page overflow on phone');
});
