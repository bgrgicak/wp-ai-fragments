import { App } from '@modelcontextprotocol/ext-apps';

const app = new App({name:'Native admin proof', version:'0.1'}, {});
const frame = document.querySelector('#wordpress');
const status = document.querySelector('#status');
const hex = (bytes) => [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
let origin;
let handoff;
let cookieStatus = '';
let connectionTimer;
addEventListener('message', (event) => {
  if (event.source !== frame.contentWindow || event.origin !== origin) return;
  if (event.data?.type === 'aif-bootstrap-ready' && handoff) {
    frame.contentWindow.postMessage({type:'aif-handoff', ...handoff}, origin);
    handoff = undefined;
  }
  if (event.data?.type === 'aif-admin-ready') {
    clearTimeout(connectionTimer);
    status.textContent = `Native WordPress loaded: ${event.data.title}${cookieStatus}`;
  }
  if (event.data?.type === 'aif-cookie-check') cookieStatus = ` — partitioned session confirmed; unpartitioned control ${event.data.unpartitioned ? 'also accepted' : 'blocked'}`;
});
app.ontoolresult = async ({structuredContent}) => {
  try {
    clearTimeout(connectionTimer);
    origin = structuredContent.origin;
    if (structuredContent.resume) {
      frame.src = origin + structuredContent.path;
      status.textContent = 'Testing an existing partition without a new handoff';
      return;
    }
    const verifier = hex(crypto.getRandomValues(new Uint8Array(32)));
    const challenge = hex(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier))));
    const viewerOrigin = window.origin; // srcdoc inherits an origin even though its location is about:srcdoc.
    const result = await app.callServerTool({name:'open-session', arguments:{path:structuredContent.path, challenge, viewer_origin:viewerOrigin}});
    if (!result._meta?.ticket) throw Error('Missing private tool-result metadata');
    handoff = {ticket:result._meta.ticket, verifier};
    frame.src = origin + '/?aif-proof-bootstrap=1&viewer=' + encodeURIComponent(viewerOrigin);
    status.textContent = 'Connecting native WordPress session…';
    connectionTimer = setTimeout(() => {
      status.textContent = 'The native WordPress frame has not confirmed loading. The host may have blocked its CSP, certificate, or session cookie.';
    }, 20000);
  } catch (error) { status.textContent = error.message; }
};
await app.connect();
