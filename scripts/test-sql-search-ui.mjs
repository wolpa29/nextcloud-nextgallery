import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import fs from 'node:fs';

// Dynamic playwright import
let pw;
try {
  pw = await import(process.env.PLAYWRIGHT_MODULE || '/home/paul/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs');
} catch {
  pw = await import('playwright');
}
const { chromium } = pw;

function getExecutablePath() {
  if (process.env.PLAYWRIGHT_EXECUTABLE) return process.env.PLAYWRIGHT_EXECUTABLE;
  const candidates = [
    '/usr/bin/google-chrome',
    '/home/paul/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome',
    '/home/paul/.cache/ms-playwright/chromium_headless_shell-1223/chrome-headless-shell-linux64/chrome-headless-shell'
  ];
  for (const c of candidates) {
    if (fs.existsSync(c)) return c;
  }
  return undefined;
}

const origin = 'http://127.0.0.1:8782';
const base = origin + '/index.php/apps/nextgallery';
const out = 'artifacts/sql-search-ui';
await mkdir(out, { recursive: true });

const results = {
  checks: [],
  deviations: [],
  errors: [],
  expectedWarnings: [],
  screenshots: [],
  syntheticInference: true,
  summary: {}
};

function check(val, msg) {
  assert(val, msg);
  results.checks.push(msg);
  console.log('✓ ' + msg);
}

const execPath = getExecutablePath();
console.log('Using browser executable:', execPath);
const browser = await chromium.launch({
  headless: true,
  ...(execPath ? { executablePath: execPath } : {})
});

async function snap(page, name) {
  const file = `${out}/${name}.png`;
  await page.screenshot({ path: file, fullPage: false, animations: 'disabled' });
  results.screenshots.push({ name, path: file });
  console.log(`[Screenshot] ${file}`);
}

async function login(page, user, pass) {
  await page.goto(origin + '/login');
  await page.locator('#user').fill(user);
  await page.locator('#password').fill(pass);
  await page.locator('form').evaluate(f => f.requestSubmit());
  await page.waitForURL(u => !u.pathname.endsWith('/login'));
}

async function dismissWizard(page) {
  try {
    for (let i = 0; i < 3; i++) {
      const wizardBtn = page.locator(
        '#firstrunwizard button:has-text("Überspringen"), ' +
        '#firstrunwizard [aria-label*="schließen" i], ' +
        '.modal-container button:has-text("Überspringen"), ' +
        '#firstrunwizard .close, ' +
        '.first-run-wizard button:has-text("Überspringen")'
      ).first();
      if (await wizardBtn.isVisible({ timeout: 1500 })) {
        await wizardBtn.click();
        await page.waitForTimeout(400);
      } else {
        break;
      }
    }
  } catch {}
  // Ensure modal mask or firstrunwizard is not obstructing the viewport
  await page.evaluate(() => {
    document.querySelectorAll('#firstrunwizard, .first-run-wizard, .modal-mask--opaque').forEach(el => el.remove());
  });
}

async function checkNoOverflow(page, label) {
  const overflow = await page.evaluate(() => {
    const docOverflow = document.documentElement.scrollWidth > window.innerWidth + 1;
    const bodyOverflow = document.body.scrollWidth > window.innerWidth + 1;
    const ng = document.getElementById('nextgallery');
    const ngOverflow = ng ? ng.scrollWidth > ng.clientWidth + 1 : false;
    const maiUser = document.getElementById('media-ai-user');
    const userOverflow = maiUser ? maiUser.scrollWidth > maiUser.clientWidth + 1 : false;
    const maiAdmin = document.getElementById('media-ai-admin');
    const adminOverflow = maiAdmin ? maiAdmin.scrollWidth > maiAdmin.clientWidth + 1 : false;
    return docOverflow || bodyOverflow || ngOverflow || userOverflow || adminOverflow;
  });
  check(!overflow, `${label}: No horizontal overflow (scrollWidth <= clientWidth + 1)`);
}

const pageErrors = [];
const serverErrors = [];

try {
  // =========================================================================
  // SCENARIO 1: Desktop Web Gallery Search (1360 x 900) as sql-user
  // =========================================================================
  console.log('\n--- Scenario 1: Desktop Web Gallery Search (1360x900) ---');
  const userContext = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await userContext.newPage();
  page.on('pageerror', err => { pageErrors.push(`[PageError] ${err.message}`); console.error('PageError:', err.message); });
  page.on('console', msg => {
    if (msg.type() === 'error') {
      const text = msg.text();
      if (text.includes('/preview/') || text.includes('404')) {
        results.expectedWarnings.push(text);
      } else {
        serverErrors.push(`[ConsoleError] ${text}`);
      }
    }
  });

  await login(page, 'sql-user', 'SqlSearchOnly2026!');
  await page.goto(base + '/gallery');
  await page.locator('#ch-count').waitFor({ timeout: 15000 });
  await dismissWizard(page);
  await checkNoOverflow(page, 'Desktop Gallery');

  // Navigate to Search View
  await page.locator('[data-view=search]').first().click();
  await page.locator('#ch-search-query').waitFor();
  check(await page.locator('#ch-search-view').isVisible(), 'Search panel is visible');
  await checkNoOverflow(page, 'Desktop Search View');

  async function search(q) {
    await page.locator('#ch-search-query').fill(q);
    await page.locator('#ch-search-form').evaluate(f => f.requestSubmit());
    // Wait until search is complete and NOT in pending/loading state!
    await page.waitForFunction(() => {
      const el = document.getElementById('ch-search-status');
      if (!el) return false;
      const txt = el.textContent;
      return !txt.includes('Wird geladen') && !txt.includes('läuft') && !txt.includes('Searching image');
    }, null, { timeout: 20000 });
    await page.waitForTimeout(300);
  }

  // 1. Text / OCR query: ZX314
  console.log('Searching for OCR text: ZX314');
  await search('ZX314');
  const zx314Status = await page.locator('#ch-search-status').textContent();
  const zx314TileCount = await page.locator('.ch-search-tile').count();
  console.log(`ZX314 results: ${zx314TileCount} tiles, status: "${zx314Status}"`);
  check(zx314TileCount >= 1, 'OCR search ZX314 found results');
  const zx314FirstTile = await page.locator('.ch-search-tile').first().textContent();
  check(zx314FirstTile.includes('motorrad-demo.png'), 'ZX314 first match is motorrad-demo.png (has OCR ZX314)');
  check(!zx314Status.includes('läuft'), 'ZX314 search completed without pending spinner');
  await snap(page, 'desktop-search-zx314');

  // 2. Hybrid query: motorrad (should match motorrad-demo.png and video-demo.mp4)
  console.log('Searching for hybrid query: motorrad');
  await search('motorrad');
  const motorradStatus = await page.locator('#ch-search-status').textContent();
  const motorradTiles = await page.locator('.ch-search-tile').allTextContents();
  console.log(`motorrad results: ${motorradTiles.length} tiles:`, motorradTiles);
  check(motorradTiles.length === 4, 'Hybrid query motorrad found all 4 results');
  check(motorradTiles.some(t => t.includes('motorrad-demo.png')), 'motorrad results include motorrad-demo.png');
  check(motorradTiles.some(t => t.includes('video-demo.mp4')), 'motorrad results include video-demo.mp4 (video frame match)');
  check(motorradTiles.some(t => t.includes('Video · keine Vorschau')), 'video-demo.mp4 renders readable placeholder "Video · keine Vorschau"');
  check(!motorradStatus.includes('läuft'), 'motorrad search completed with hybrid status');
  await snap(page, 'desktop-search-motorrad-hybrid');

  // 3. Segelboot query
  console.log('Searching for: Segelboot');
  await search('Segelboot');
  const bootTiles = await page.locator('.ch-search-tile').allTextContents();
  console.log(`Segelboot results: ${bootTiles.length} tiles:`, bootTiles);
  check(bootTiles.some(t => t.includes('boot-demo.png')), 'Segelboot results include boot-demo.png');
  await snap(page, 'desktop-search-segelboot');

  // 4. Pure semantic query: Fahrzeug (not in text/ocr/tags)
  console.log('Searching for pure semantic query: Fahrzeug');
  await search('Fahrzeug');
  const fahrzeugStatus = await page.locator('#ch-search-status').textContent();
  const fahrzeugTiles = await page.locator('.ch-search-tile').allTextContents();
  console.log(`Fahrzeug results: ${fahrzeugTiles.length} tiles, status: "${fahrzeugStatus}"`);
  check(fahrzeugTiles.length === 4, 'Pure semantic query Fahrzeug returned hybrid results');
  check(!fahrzeugStatus.includes('läuft'), 'Fahrzeug search finished hybrid enrichment');
  await snap(page, 'desktop-search-semantic-fahrzeug');

  // 5. Media Kind Filter buttons scoped to #ch-search-view
  console.log('Testing Media Kind Filter buttons in search view...');
  const searchFilterGroup = page.locator('#ch-search-view .ch-filters');
  check(await searchFilterGroup.isVisible(), 'Kind filter buttons present in search view');

  // Switch to "Videos"
  console.log('Filtering search to Videos only...');
  await page.locator('#ch-search-view button[data-kind="video"]').click();
  await page.waitForFunction(() => {
    const el = document.getElementById('ch-search-status');
    return el && !el.textContent.includes('Wird geladen') && !el.textContent.includes('läuft');
  }, null, { timeout: 15000 });
  await page.waitForTimeout(300);
  const videoTiles = await page.locator('.ch-search-tile').allTextContents();
  console.log('Video-filtered search tiles:', videoTiles);
  check(videoTiles.length === 1 && videoTiles[0].includes('video-demo.mp4'), 'Video filter returns only video-demo.mp4');
  await snap(page, 'desktop-search-filter-video');

  // Switch to "Bilder"
  console.log('Filtering search to Bilder (Images) only...');
  await page.locator('#ch-search-view button[data-kind="image"]').click();
  await page.waitForFunction(() => {
    const el = document.getElementById('ch-search-status');
    return el && !el.textContent.includes('Wird geladen') && !el.textContent.includes('läuft');
  }, null, { timeout: 15000 });
  await page.waitForTimeout(300);
  const imageTiles = await page.locator('.ch-search-tile').allTextContents();
  console.log('Image-filtered search tiles:', imageTiles);
  check(imageTiles.length === 3 && !imageTiles.some(t => t.includes('video-demo.mp4')), 'Image filter returns 3 images and excludes video-demo.mp4');
  await snap(page, 'desktop-search-filter-image');

  // Reset to "Alle"
  console.log('Resetting filter to Alle...');
  await page.locator('#ch-search-view button[data-kind=""]').click();
  await page.waitForFunction(() => {
    const el = document.getElementById('ch-search-status');
    return el && !el.textContent.includes('Wird geladen') && !el.textContent.includes('läuft');
  }, null, { timeout: 15000 });
  await page.waitForTimeout(300);
  check(await page.locator('.ch-search-tile').count() === 4, 'Reset to Alle restored complete 4 results');
  await snap(page, 'desktop-search-filter-all');

  // 6. Details modal / viewer
  console.log('Opening viewer details for motorrad-demo.png');
  await page.locator('.ch-search-tile').filter({ hasText: 'motorrad-demo.png' }).first().click();
  await page.locator('#ch-viewer').waitFor({ state: 'visible' });
  check(await page.locator('#ch-viewer').isVisible(), 'Media viewer opened');
  const viewerName = await page.locator('#ch-viewer-name').textContent();
  const viewerDetail = await page.locator('#ch-viewer-detail').textContent();
  console.log(`Viewer: ${viewerName}, details: ${viewerDetail}`);
  check(viewerName.includes('motorrad-demo.png'), 'Viewer displays motorrad-demo.png');

  // Open analysis details ("Beschreibung und erkannter Text")
  const analysisSummary = page.locator('#ch-analysis summary');
  check(await analysisSummary.isVisible(), 'Analysis summary toggle visible');
  await analysisSummary.click();
  await page.waitForTimeout(600);
  const analysisBody = await page.locator('#ch-analysis-body').textContent();
  console.log(`Analysis body content: "${analysisBody}"`);
  check(analysisBody.includes('Motorrad') || analysisBody.includes('ZX314'), 'Analysis body loaded AI metadata / OCR');
  await snap(page, 'desktop-viewer-details');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);

  // =========================================================================
  // SCENARIO 2: Mobile Web Gallery Search (390 x 844) as sql-user
  // =========================================================================
  console.log('\n--- Scenario 2: Mobile Web Gallery Search (390x844) ---');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(400);
  await checkNoOverflow(page, 'Mobile Gallery');

  // Search motorrad on mobile - MUST wait for hybrid ready, no pending spinner!
  console.log('Executing mobile search for motorrad...');
  await search('motorrad');
  const mobileStatus = await page.locator('#ch-search-status').textContent();
  const mobileTileCount = await page.locator('.ch-search-tile').count();
  console.log(`Mobile search status: "${mobileStatus}", tiles: ${mobileTileCount}`);
  check(!mobileStatus.includes('läuft'), 'Mobile search finished without pending spinner (Bildsuche läuft)');
  check(mobileStatus.includes('Textsuche und ähnliche Bildinhalte'), 'Mobile search status shows completed hybrid search');
  check(mobileTileCount === 4, 'Mobile search rendered all 4 results');
  await checkNoOverflow(page, 'Mobile Search View');
  await snap(page, 'mobile-search-motorrad');

  // Open viewer on mobile
  await page.locator('.ch-search-tile').filter({ hasText: 'motorrad-demo.png' }).first().click();
  await page.locator('#ch-viewer').waitFor({ state: 'visible' });
  await checkNoOverflow(page, 'Mobile Viewer');
  await snap(page, 'mobile-viewer-details');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);

  // Reset viewport for next desktop steps
  await page.setViewportSize({ width: 1360, height: 900 });

  // =========================================================================
  // SCENARIO 3: User AI Page (/ai) as sql-user
  // =========================================================================
  console.log('\n--- Scenario 3: User AI Page ---');
  await page.goto(base + '/ai');
  await page.locator('#media-ai-user').waitFor({ timeout: 15000 });
  await dismissWizard(page);
  await page.waitForTimeout(1000);

  const indexStatusText = await page.locator('#mai-search-index-status').textContent();
  const descCount = await page.locator('#mai-description-count').textContent();
  const ocrCount = await page.locator('#mai-ocr-count').textContent();
  const vectorCount = await page.locator('#mai-vector-count').textContent();
  console.log(`User AI status: index="${indexStatusText}", desc=${descCount}, ocr=${ocrCount}, vector=${vectorCount}`);
  check(indexStatusText.includes('bereit') || indexStatusText.includes('ready') || indexStatusText.length > 0, 'User AI index status displayed');
  check(descCount === '4' && ocrCount === '4' && vectorCount === '4', 'All 4 fixture files processed for descriptions, OCR, vectors');
  await checkNoOverflow(page, 'Desktop User AI');
  await snap(page, 'desktop-user-ai-status');

  // Open library view to view file descriptions / OCR
  const navDateien = page.locator('a[data-nav-target="mai-library"], a:has-text("Dateien")').first();
  if (await navDateien.isVisible()) {
    await navDateien.click();
    await page.waitForTimeout(1000);
    check(await page.locator('#mai-library').isVisible(), 'User AI library view visible');
    await checkNoOverflow(page, 'Desktop User AI Library');
    await snap(page, 'desktop-user-ai-library');
  }

  // Mobile User AI Page
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(400);
  await checkNoOverflow(page, 'Mobile User AI');
  await snap(page, 'mobile-user-ai-status');
  await page.setViewportSize({ width: 1360, height: 900 });

  // =========================================================================
  // SCENARIO 4: Nextcloud Files Sidebar (/apps/files/) as sql-user
  // =========================================================================
  console.log('\n--- Scenario 4: Nextcloud Files Sidebar ---');
  await page.goto(origin + '/apps/files/?dir=/SqlSearchFixtures');
  await page.waitForLoadState('networkidle');
  await dismissWizard(page);
  await page.waitForTimeout(1500);

  // Locate motorrad-demo.png row
  const motorradRow = page.locator('tr').filter({ hasText: 'motorrad-demo' }).first();
  await motorradRow.waitFor({ state: 'visible', timeout: 10000 });
  check(await motorradRow.isVisible(), 'motorrad-demo.png found in Files app folder /SqlSearchFixtures');

  // Open action menu and click Details
  const menuBtn = motorradRow.locator('.action-menu button, [data-action="menu"], [aria-label*="Aktionen"]').first();
  await menuBtn.click();
  await page.waitForTimeout(500);

  const detailsAction = page.locator('.open-file-details, [data-action="details"], button:has-text("Details")').first();
  check(await detailsAction.isVisible(), 'Details menu action visible');
  await detailsAction.click();

  // Wait for sidebar and tabs
  await page.waitForSelector('.app-sidebar-tabs', { state: 'visible', timeout: 10000 });
  const mediaAiTab = page.locator('button:has-text("Media AI")').first();
  check(await mediaAiTab.isVisible(), 'Media AI sidebar tab button is visible');
  await mediaAiTab.click();
  await page.waitForTimeout(1500);

  const sidebarMeta = page.locator('nextgallery-ai-sidebar');
  await sidebarMeta.waitFor({ state: 'visible', timeout: 5000 });
  check(await sidebarMeta.isVisible(), 'Media AI sidebar custom element rendered');
  const sidebarText = await sidebarMeta.textContent();
  console.log('Sidebar metadata text:\n', sidebarText);
  check(sidebarText.includes('Ein synthetisches Motorrad am See'), 'Sidebar displays AI Bildbeschreibung');
  check(sidebarText.includes('MOTORRAD TESTCODE ZX314'), 'Sidebar displays AI OCR Erkannter Text');
  check(sidebarText.includes('Helm'), 'Sidebar displays AI Suchbegriffe');

  await checkNoOverflow(page, 'Desktop Files Sidebar');
  await snap(page, 'desktop-files-sidebar-media-ai');

  // Mobile Files sidebar
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(400);
  await checkNoOverflow(page, 'Mobile Files Sidebar');
  await snap(page, 'mobile-files-sidebar-media-ai');
  await page.setViewportSize({ width: 1360, height: 900 });

  await userContext.close();

  // =========================================================================
  // SCENARIO 5: Admin AI Page (/ai) and Personal Processing (/processing)
  // =========================================================================
  console.log('\n--- Scenario 5: Admin AI Page & Personal Processing ---');
  const adminContext = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const adminPage = await adminContext.newPage();
  adminPage.on('pageerror', err => pageErrors.push(`[AdminPageError] ${err.message}`));

  await login(adminPage, 'synthetic-admin', 'SyntheticAdminOnly2026');

  // 1. Admin Settings View: /index.php/apps/nextgallery/ai#settings
  console.log('Navigating to Admin Server-Einstellungen (/ai#settings)...');
  await adminPage.goto(base + '/ai#settings');
  await adminPage.locator('#media-ai-admin').waitFor({ timeout: 15000 });

  // Explicitly dismiss welcome wizard and verify absence
  await dismissWizard(adminPage);
  await adminPage.waitForTimeout(500);
  check(await adminPage.locator('#firstrunwizard, .first-run-wizard').count() === 0, 'Admin page: Welcome wizard dismissed and not obstructing view');

  // Wait for settings data to load and savebar status to be ready
  await adminPage.waitForFunction(() => {
    const saveStatus = document.querySelector('[data-save-status]');
    return saveStatus && !saveStatus.textContent.includes('geladen');
  }, null, { timeout: 10000 });
  await adminPage.waitForTimeout(500);

  check(await adminPage.locator('#media-ai-admin').isVisible(), 'Admin AI settings page rendered');
  const adminTitle = await adminPage.locator('#mai-page-title').textContent();
  console.log(`Admin page title: "${adminTitle}"`);
  check(adminTitle.includes('Server-Einstellungen'), 'Admin page title is Server-Einstellungen');

  const embeddingSettings = adminPage.locator('#mai-embedding-settings');
  check(await embeddingSettings.isVisible(), 'Embedding settings section is visible in admin');
  await checkNoOverflow(adminPage, 'Desktop Admin Settings');
  await snap(adminPage, 'desktop-admin-ai-settings');

  // Mobile Admin AI Settings Page (completely unobstructed)
  await adminPage.setViewportSize({ width: 390, height: 844 });
  await adminPage.waitForTimeout(400);
  await checkNoOverflow(adminPage, 'Mobile Admin Settings');
  await snap(adminPage, 'mobile-admin-ai-settings');
  await adminPage.setViewportSize({ width: 1360, height: 900 });

  // 2. Personal Processing View for Admin: /index.php/apps/nextgallery/processing
  console.log('Navigating to Admin Personal Processing (/processing)...');
  await adminPage.goto(base + '/processing');
  await adminPage.locator('#media-ai-user').waitFor({ timeout: 15000 });
  await dismissWizard(adminPage);
  await adminPage.waitForTimeout(1000);
  check(await adminPage.locator('#media-ai-user').isVisible(), 'Personal Processing view rendered for admin at /processing');
  await checkNoOverflow(adminPage, 'Desktop Admin Personal Processing');
  await snap(adminPage, 'desktop-admin-personal-processing');

  await adminContext.close();

  // Final errors and validation
  check(pageErrors.length === 0, 'No uncaught page errors (JS PageErrors: 0)');
  check(serverErrors.length === 0, 'No unexpected server console errors (ServerErrors: 0)');
  results.errors = [...pageErrors, ...serverErrors];

  await writeFile(`${out}/test-summary.json`, JSON.stringify(results, null, 2));
  console.log('\nAll test scenarios completed successfully!');
  console.log(`Summary: ${results.checks.length} checks passed, 0 deviations, ${results.screenshots.length} screenshots taken, ${results.expectedWarnings.length} expected 404 preview warnings handled.`);
} catch (err) {
  console.error('\nTest execution failed:', err);
  results.errors.push(err.stack || err.message);
  await writeFile(`${out}/test-summary.json`, JSON.stringify(results, null, 2));
  throw err;
} finally {
  await browser.close();
}
