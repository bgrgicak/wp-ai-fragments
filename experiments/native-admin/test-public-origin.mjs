import assert from 'node:assert/strict';
import {mkdtempSync, writeFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {execFileSync} from 'node:child_process';
import {publicOrigin} from './public-origin.mjs';

const dir = mkdtempSync(join(tmpdir(), 'aif-origin-'));
const marker = join(dir, '.https-demo');
try {
  assert.equal(publicOrigin({}, marker), '');
  writeFileSync(marker, 'https://tunnel.example/\n');
  assert.equal(publicOrigin({}, marker), 'https://tunnel.example');
  assert.equal(publicOrigin({AIF_PROOF_PUBLIC_ORIGIN:'https://other.example:9443'}, marker), 'https://other.example:9443');
  for (const origin of ['http://tunnel.example', 'https://user:password@tunnel.example', 'https://tunnel.example/path', 'https://tunnel.example/?query=1', 'https://tunnel.example/#fragment', 'not a URL']) {
    assert.throws(() => publicOrigin({AIF_PROOF_PUBLIC_ORIGIN:origin}, marker));
    assert.throws(() => execFileSync('php', [new URL('./test-runtime.php', import.meta.url).pathname, 'local'], {
      env:{...process.env, AIF_PROOF_PUBLIC_ORIGIN:origin}, stdio:'pipe',
    }));
  }
  for (const environment of ['production', 'staging', 'development', 'local']) {
    execFileSync('php', [new URL('./test-runtime.php', import.meta.url).pathname, environment], {
      env:{...process.env, AIF_PROOF_PUBLIC_ORIGIN:'https://tunnel.example:9443/'}, stdio:'pipe',
    });
  }
  console.log('PASS: private origin configuration, environment precedence, ports, local-only overrides and invalid-origin rejection');
} finally {
  rmSync(dir, {recursive:true, force:true});
}
