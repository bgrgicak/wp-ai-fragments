import { AppBridge, PostMessageTransport } from '@modelcontextprotocol/ext-apps/app-bridge';

let id = 0;
async function rpc(method, params = {}) {
  const response = await fetch('/mcp', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({jsonrpc:'2.0', id:++id, method, params})});
  const body = await response.json();
  if (!response.ok || body.error) throw Error(body.error?.message || 'MCP request failed');
  return body.result;
}
const parameters = new URLSearchParams(location.search);
const path = parameters.get('path') || '/wp-admin/';
const noforms = parameters.has('noforms');
const noframes = parameters.has('noframes');
const bridge = new AppBridge(null, {name:'Native admin proof host',version:'0.1'}, {serverTools:{}});
bridge.oncalltool = (params) => {
  if (params.name !== 'open-session') throw Error('Tool not allowed for this app');
  return rpc('tools/call', params);
};
const sandbox = document.querySelector('#sandbox');
if (noforms) sandbox.setAttribute('sandbox', 'allow-scripts allow-same-origin');
const initialized = await rpc('initialize', {protocolVersion:'2025-11-25',capabilities:{extensions:{'io.modelcontextprotocol/ui':{}}},clientInfo:{name:'proof',version:'0.1'}});
const tool = (await rpc('tools/list')).tools.find((tool) => tool.name === 'show_wp_admin');
const resource = (await rpc('resources/read', {uri:tool._meta.ui.resourceUri})).contents[0];
bridge.onsandboxready = () => bridge.sendSandboxResourceReady({html:resource.text,sandbox:`allow-scripts allow-same-origin${noforms ? '' : ' allow-forms'}`,csp:resource._meta.ui.csp});
bridge.oninitialized = async () => {
  document.querySelector('#host-status').textContent = `MCP Apps initialized — ${initialized.serverInfo.name}; original plugin UI below`;
  await bridge.sendToolInput({arguments:{url:path}});
  const result = await rpc('tools/call', {name:'show_wp_admin', arguments:{url:path}});
  await bridge.sendToolResult(result);
};
await bridge.connect(new PostMessageTransport(sandbox.contentWindow, sandbox.contentWindow));
sandbox.src = `http://127.0.0.1:8891/sandbox?parent=${encodeURIComponent(location.origin)}${noframes ? '&noframes=1' : ''}`;
