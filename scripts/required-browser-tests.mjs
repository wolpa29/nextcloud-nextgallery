#!/usr/bin/env node
import {readdirSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
const files=readdirSync('tests').filter(file=>file.endsWith('.test.mjs')).sort().map(file=>'tests/'+file);
const result=spawnSync(process.execPath,['--test','--test-reporter=tap',...files],{
 encoding:'utf8',maxBuffer:16*1024*1024,env:{...process.env,REQUIRE_BROWSER_TESTS:'1'}
});
process.stdout.write(result.stdout||'');process.stderr.write(result.stderr||'');
if(result.error)throw result.error;
if(result.status!==0)process.exit(result.status||1);
const skipped=result.stdout.match(/^# skipped (\d+)$/m),tests=result.stdout.match(/^# tests (\d+)$/m);
if(!tests||Number(tests[1])===0||!skipped||Number(skipped[1])!==0){
 process.stderr.write('Required browser verification must execute tests with zero skips.\n');process.exit(1);
}
