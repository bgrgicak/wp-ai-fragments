// Behavioral regression checks for the browser handoff and reconnect UI.
import assert from 'node:assert/strict';
import {createHash, randomFillSync} from 'node:crypto';
import {readFile} from 'node:fs/promises';
import {setImmediate} from 'node:timers/promises';
import vm from 'node:vm';

const source = (await readFile(new URL('./view.js', import.meta.url), 'utf8')).replace(/^import .*;\n/gm, '');
const target = {origin:'https://wordpress.test', path:'/blog/wp-admin/options-writing.php', bootstrapUrl:'https://wordpress.test/blog/wp-admin/admin-ajax.php?aif-proof-bootstrap=1'};
const settle = () => setImmediate();

async function viewer(results = [], connectError = false) {
  let app;
  const calls = [], messages = [], timers = new Map(), classes = new Set();
  const listeners = {}, status = {content:null};
  let timerId = 0;
  const frameListeners = {};
  const frame = {contentWindow:{postMessage:(data, origin) => messages.push({data, origin})}, addEventListener:(type, callback) => { frameListeners[type] = callback; }};
  class App {
    constructor() { app = this; }
    async connect() { if (connectError) throw Error('Disconnected'); }
    async callServerTool(params) {
      calls.push(params);
      const result = results.shift();
      if (result instanceof Error) throw result;
      return await result;
    }
  }
  await vm.runInNewContext(`(async () => {${source}})()`, {
    App, TextEncoder, Uint8Array, URL, window:{origin:'http://127.0.0.1:8891'}, Button:'Button', Notice:'Notice', Spinner:'Spinner',
    createElement:(type, props, ...children) => ({type, props, children}),
    createRoot:node => ({render:content => { node.content = content; }}),
    crypto:{getRandomValues:randomFillSync, subtle:{digest:async (_, input) => createHash('sha256').update(input).digest()}},
    document:{body:{classList:{add:name => classes.add(name), remove:name => classes.delete(name)}}, querySelector:selector => ({'#wordpress':frame, '#status':status})[selector]},
    addEventListener:(type, callback) => { listeners[type] = callback; },
    setTimeout:(callback, delay) => { const id = ++timerId; timers.set(id, {callback, delay}); return id; },
    clearTimeout:id => timers.delete(id),
  });
  const button = () => status.content?.children.find(child => child?.type === 'Button');
  return {
    app, calls, messages, timers, classes, frame, status, button,
    message:(data, origin = target.origin, eventSource = frame.contentWindow) => listeners.message({data, origin, source:eventSource}),
    click:async () => { button()?.props.onClick(); await settle(); },
    timeout:() => { for (const [id, timer] of [...timers]) { timers.delete(id); timer.callback(); } },
    load:() => frameListeners.load(),
  };
}

const idle = await viewer();
assert.equal(idle.status.content.children[0].type, 'Spinner');
idle.timeout();
assert(idle.status.content.children[0].includes('Ask in chat'));
assert(!idle.button());
const disconnected = await viewer([], true);
assert(disconnected.status.content.children[0].includes('Ask in chat'));
console.log('PASS: missing result and disconnected MCP provide recovery instructions');

for (const result of [Error('Offline'), {isError:true, _meta:{ticket:'rejected'}}, {}]) {
  const failed = await viewer([result]);
  await failed.app.ontoolresult({structuredContent:target});
  assert(failed.button());
  assert.equal(failed.frame.src, undefined);
  assert.equal(failed.messages.length, 0);
}
console.log('PASS: failed session requests offer Reconnect without using rejected handoffs');

const connected = await viewer([{_meta:{ticket:'first'}}, {_meta:{ticket:'second'}}]);
await connected.app.ontoolresult({structuredContent:target});
assert.equal(new URL(connected.frame.src).pathname, '/blog/wp-admin/admin-ajax.php');
assert.equal(new URL(connected.frame.src).searchParams.get('viewer'), 'http://127.0.0.1:8891');
assert.equal(connected.timers.size, 1);
connected.timeout();
assert(connected.button());
assert(connected.status.content.children[0].includes('longer'));
connected.message({type:'aif-bootstrap-ready'}, 'https://other.test');
connected.message({type:'aif-bootstrap-ready'}, target.origin, {});
assert.equal(connected.messages.length, 0);
connected.message({type:'aif-bootstrap-ready'});
connected.message({type:'aif-bootstrap-ready'});
assert.equal(connected.messages.length, 1);
assert.equal(createHash('sha256').update(connected.messages[0].data.verifier).digest('hex'), connected.calls[0].arguments.challenge);
connected.message({type:'aif-admin-ready'});
assert(connected.classes.has('aif-ready'));
assert.equal(connected.status.content, null);
connected.message({type:'aif-session-error'}, 'https://other.test');
assert(!connected.button());
connected.message({type:'aif-session-error'});
await connected.click();
assert.equal(connected.calls.length, 2);
assert.equal(connected.calls[1].arguments.path, target.path);
assert.notEqual(connected.calls[0].arguments.challenge, connected.calls[1].arguments.challenge);
connected.message({type:'aif-bootstrap-ready'});
assert.equal(connected.messages[1].data.ticket, 'second');
console.log('PASS: subdirectory bootstrap URLs, stalled recovery, retained handoffs and fresh reconnect credentials work');

const blocked = await viewer([{_meta:{ticket:'blocked'}}, {_meta:{ticket:'retry'}}]);
await blocked.app.ontoolresult({structuredContent:target});
blocked.timeout();
const reconnect = blocked.button().props.onClick;
reconnect(); reconnect();
await settle();
assert.equal(blocked.calls.length, 2);
blocked.message({type:'aif-bootstrap-ready'});
assert.equal(blocked.messages[0].data.ticket, 'retry');
blocked.message({type:'aif-admin-ready'});
assert.equal(blocked.timers.size, 0);
assert.equal(blocked.status.content, null);
console.log('PASS: blocked iframe can reconnect and repeated clicks issue one request');

const wrongOrigin = await viewer([{_meta:{ticket:'rejected'}}]);
await wrongOrigin.app.ontoolresult({structuredContent:{...target, bootstrapUrl:'https://other.test/?aif-proof-bootstrap=1'}});
assert.equal(wrongOrigin.frame.src, undefined);
assert(wrongOrigin.button());
console.log('PASS: bootstrap must share the native admin origin');

const nativeError = await viewer([{_meta:{ticket:'native-error'}}]);
await nativeError.app.ontoolresult({structuredContent:target});
nativeError.load();
assert(!nativeError.classes.has('aif-ready'));
nativeError.message({type:'aif-admin-navigating'}, 'https://other.test');
nativeError.message({type:'aif-admin-navigating'}, target.origin, {});
nativeError.load();
assert(!nativeError.classes.has('aif-ready'));
nativeError.message({type:'aif-bootstrap-ready'});
nativeError.message({type:'aif-admin-navigating'});
assert(!nativeError.classes.has('aif-ready'));
nativeError.load();
assert(nativeError.classes.has('aif-ready'));
assert(nativeError.button());
assert(nativeError.status.content.children[0].includes('did not confirm'));
assert.equal(nativeError.timers.size, 0);
nativeError.message({type:'aif-admin-ready'});
assert.equal(nativeError.status.content, null);
console.log('PASS: native permission/error pages become visible without an admin footer; unconfirmed loads retain recovery until WordPress confirms readiness');

let resolveTicket;
const delayed = await viewer([new Promise(resolve => { resolveTicket = resolve; }), {_meta:{ticket:'retry'}}]);
const pending = delayed.app.ontoolresult({structuredContent:target});
await settle();
delayed.timeout();
assert(!delayed.button());
delayed.message({type:'aif-session-error'});
const retry = delayed.button().props.onClick;
retry(); retry();
await settle();
assert.equal(delayed.calls.length, 2);
resolveTicket({_meta:{ticket:'late'}});
await pending;
delayed.message({type:'aif-bootstrap-ready'});
assert.equal(delayed.messages[0].data.ticket, 'retry');
delayed.message({type:'aif-admin-ready'});
assert.equal(delayed.status.content, null);
console.log('PASS: late session responses cannot override a retry; repeated clicks issue one request');
