// Execute the PHP-generated bootstrap, including its subdirectory session check.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import vm from 'node:vm';

const fixture = fileURLToPath(new URL('./test-runtime.php', import.meta.url));
for (const environment of ['production', 'staging', 'development', 'local']) {
  process.stdout.write(execFileSync('php', [fixture, environment], {encoding:'utf8'}));
}
const html = execFileSync('php', [fixture, 'production', '--bootstrap'], {encoding:'utf8'});
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const viewerOrigin = 'http://127.0.0.1:8891';
const bootstrapUrl = 'https://wordpress.test/blog/wp-admin/admin-ajax.php?aif-proof-bootstrap=1';
let lockTail = Promise.resolve();
let active = 0, maxActive = 0, cookie = false;
const locks = {request:(_, callback) => {
  const result = lockTail.then(async () => {
    maxActive = Math.max(maxActive, ++active);
    try { return await callback(); } finally { --active; }
  });
  lockTail = result.catch(() => {});
  return result;
}};

function bootstrap(lockManager = locks, fail = false) {
  const parent = {postMessage:(data, origin) => messages.push({data, origin})};
  const messages = [], requests = [], redirects = [];
  let listener;
  vm.runInNewContext(script, {
    parent, navigator:{locks:lockManager},
    location:{href:bootstrapUrl, replace:url => redirects.push(url)},
    addEventListener:(_, callback) => { listener = callback; },
    fetch:async (url, options) => {
      requests.push({url, options, hadCookie:cookie});
      if (options?.method === 'POST') {
        await new Promise(resolve => setTimeout(resolve, 10));
        cookie = true;
        return {ok:!fail, json:async () => ({success:!fail, data:{url:'https://wordpress.test/blog/wp-admin/'}})};
      }
      assert.equal(url, 'https://wordpress.test/blog/wp-admin/admin-ajax.php?aif-proof-session-check=1');
      assert.equal(options.credentials, 'same-origin');
      return {json:async () => ({data:{authenticated:cookie, embedded:cookie}})};
    },
  });
  return {requests, redirects, messages, handoff:(origin = viewerOrigin, source = parent) => listener({source, origin, data:{type:'aif-handoff', ticket:'test', verifier:'test'}})};
}

const first = bootstrap(), second = bootstrap();
await first.handoff('https://other.test');
await first.handoff(viewerOrigin, {});
assert.equal(first.requests.length, 0);
await Promise.all([first.handoff(), second.handoff()]);
assert.equal(maxActive, 1);
assert.equal(first.requests[0].hadCookie, false);
assert.equal(second.requests[0].hadCookie, true);
assert.equal(first.redirects.length, 1);
assert.equal(second.redirects.length, 1);
assert.equal(first.messages.at(-1).data.type, 'aif-admin-navigating');
assert.equal(second.messages.at(-1).data.type, 'aif-admin-navigating');
await first.handoff();
assert.equal(first.requests.length, 2);
assert(first.messages.every(message => message.origin === viewerOrigin));
const fallback = bootstrap(null);
await fallback.handoff();
assert.equal(fallback.redirects.length, 1);
const failed = bootstrap(locks, true);
await failed.handoff();
assert.equal(failed.redirects.length, 0);
assert.equal(failed.messages.at(-1).data.type, 'aif-session-error');
console.log('PASS: bootstrap serializes shared-cookie handoffs, checks subdirectory sessions and rejects untrusted messages');
