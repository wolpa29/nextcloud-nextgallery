import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {galleryFixture,ROOT} from './helpers/gallery-browser.mjs';
const axe=readFileSync(ROOT+'/node_modules/axe-core/axe.min.js','utf8');
async function audit(page){
 await page.addScriptTag({content:axe});
 return page.evaluate(async()=>{
  const result=await axe.run(document.getElementById('nextgallery'),{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}});
  return result.violations.map(({id,impact,nodes})=>({id,impact,nodes:nodes.map(node=>({target:node.target,summary:node.failureSummary}))}));
 });
}
for (const browserName of ['chromium','webkit']) test(`${browserName}: gallery and search controls expose consistent accessible names and valid groups`,async t=>{
 const f=await galleryFixture(t,{browserName});if(!f)return;const{page,state}=f;
 assert.deepEqual(await audit(page),[]);
 await page.route('**/api/search?**',route=>route.fulfill({json:{items:state.items,more:false,next:null,mode:'lexical',embedding_state:'disabled',index_state:'ready'}}));
 await page.locator('#ch-header-search').click();await page.locator('#ch-search-query').fill('Synthetic');
 await page.locator('#ch-search-query').press('Enter');await page.locator('.ch-search-tile').first().waitFor();
 assert.deepEqual(await audit(page),[]);
});
