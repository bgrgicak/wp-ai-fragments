import {App} from '@modelcontextprotocol/ext-apps';
import {Button, Notice, Spinner} from '@wordpress/components';
import {createElement, createRoot} from '@wordpress/element';
import '@wordpress/components/build-style/style.css';

const app = new App({name:'WordPress admin', version:'0.3.0'}, {});
const frame = document.querySelector('#wordpress');
const status = createRoot(document.querySelector('#status'));
const hex = (bytes) => [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
let origin;
let handoff;
let target;
let connecting = true;
let connectionAttempt = 0;
let nativeNavigation = false;
const ready = () => {
  clearTimeout(connectionTimer);
  connecting = false;
  nativeNavigation = false;
  document.body.classList.add('aif-ready');
  status.render(null);
};
const loading = () => status.render(createElement('div', {className:'aif-loading', role:'status', 'aria-label':'Connecting to WordPress'}, createElement(Spinner)));
const unavailable = () => {
  ++connectionAttempt;
  connecting = false;
  clearTimeout(connectionTimer);
  handoff = undefined;
  nativeNavigation = false;
  status.render(createElement(Notice, {status:'error', isDismissible:false},
    target ? 'WordPress disconnected.' : 'Ask in chat to open this admin page again.',
    target && createElement(Button, {variant:'primary', onClick:() => { if (!connecting) connectAdmin(target); }}, 'Reconnect')
  ));
};
// Offer a retry without discarding a slow iframe's pending handoff.
const stalled = (message = 'WordPress is taking longer to connect.') => {
  connecting = false;
  status.render(createElement(Notice, {status:'warning', isDismissible:false},
    message,
    createElement(Button, {variant:'primary', onClick:() => { if (!connecting) connectAdmin(target); }}, 'Reconnect')
  ));
};
loading();
let connectionTimer = setTimeout(unavailable, 20000);
// Native permission/error documents do not run WordPress's admin footer.
frame.addEventListener('load', () => {
  if (!nativeNavigation) return;
  nativeNavigation = false;
  clearTimeout(connectionTimer);
  document.body.classList.add('aif-ready');
  stalled('WordPress did not confirm this page loaded.');
});
addEventListener('message', (event) => {
  if (event.source !== frame.contentWindow || event.origin !== origin) return;
  if (event.data?.type === 'aif-bootstrap-ready' && handoff) {
    frame.contentWindow.postMessage({type:'aif-handoff', ...handoff}, origin);
    handoff = undefined;
  }
  if (event.data?.type === 'aif-admin-navigating') nativeNavigation = true;
  if (event.data?.type === 'aif-admin-ready') ready();
  if (event.data?.type === 'aif-session-error') unavailable();
});
const connectAdmin = async (structuredContent) => {
  const attempt = ++connectionAttempt;
  try {
    clearTimeout(connectionTimer);
    connecting = true;
    handoff = undefined;
    nativeNavigation = false;
    document.body.classList.remove('aif-ready');
    loading();
    origin = structuredContent.origin;
    const verifier = hex(crypto.getRandomValues(new Uint8Array(32)));
    const challenge = hex(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier))));
    const viewerOrigin = window.origin;
    const result = await app.callServerTool({name:'open-session', arguments:{path:structuredContent.path, challenge, viewer_origin:viewerOrigin}}, {timeout:20000});
    if (attempt !== connectionAttempt) return;
    if (result.isError || !result._meta?.ticket) throw Error('Missing private tool-result metadata');
    const bootstrap = new URL(structuredContent.bootstrapUrl);
    if (bootstrap.origin !== origin) throw Error('Invalid bootstrap origin');
    bootstrap.searchParams.set('viewer', viewerOrigin);
    handoff = {ticket:result._meta.ticket, verifier};
    connectionTimer = setTimeout(stalled, 20000);
    frame.src = bootstrap.href;
  } catch (error) {
    if (attempt === connectionAttempt) unavailable();
  }
};
app.ontoolresult = async ({structuredContent}) => {
  if (typeof structuredContent?.origin !== 'string' || typeof structuredContent?.path !== 'string' || typeof structuredContent?.bootstrapUrl !== 'string') {
    unavailable();
    return;
  }
  target = structuredContent;
  await connectAdmin(target);
};
try {
  await app.connect();
} catch (error) { unavailable(); }
