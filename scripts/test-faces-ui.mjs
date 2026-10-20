#!/usr/bin/env node
// Run the People UI behavior tests. Set PLAYWRIGHT_MODULE/PLAYWRIGHT_EXECUTABLE
// when Playwright or Chromium is supplied by an external test environment.
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const result=spawnSync(process.execPath,['--test',fileURLToPath(new URL('../tests/faces.test.mjs',import.meta.url))],{stdio:'inherit',env:process.env});
if(result.error)throw result.error;
process.exit(result.status ?? 1);
