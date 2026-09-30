// STDIO bridge for the native WordPress admin MCP App.
import {execFileSync} from 'node:child_process';
import {Server} from '@modelcontextprotocol/sdk/server/index.js';
import {StdioServerTransport} from '@modelcontextprotocol/sdk/server/stdio.js';
import {ListToolsRequestSchema, CallToolRequestSchema, ListResourcesRequestSchema, ReadResourceRequestSchema} from '@modelcontextprotocol/sdk/types.js';

const site = process.env.WP_ENV_SITE_URL || 'http://localhost:8888';
if (!['localhost', '127.0.0.1'].includes(new URL(site).hostname)) throw Error('Local demo only');
const username = process.env.WP_MCP_USERNAME || 'wp-ai-agent';
const uri = 'ui://wp-ai-fragments/wp-admin-v1.html';
let id = 0;

async function rpc(method, params) {
  const password = process.env.WP_API_PASSWORD || execFileSync('security', ['find-generic-password', '-a', username, '-s', 'wp-ai-fragments-mcp', '-w'], {encoding:'utf8'}).trim();
  const authorization = 'Basic ' + Buffer.from(username + ':' + password).toString('base64');
  const response = await fetch(site + '/wp-json/aif-proof/v1/mcp', {
    method:'POST', headers:{Authorization:authorization, 'Content-Type':'application/json'},
    body:JSON.stringify({jsonrpc:'2.0', id:++id, method, params}), signal:AbortSignal.timeout(45000),
  });
  if (!response.ok) throw Error(`Native admin MCP endpoint returned HTTP ${response.status}`);
  const body = await response.json();
  if (body.error) throw Error(body.error.message);
  return body.result;
}

async function checkPublicTransport(result) {
  const origin = result.structuredContent?.origin;
  if (origin !== 'https://bero.jurassic.tube') return;
  try {
    const response = await fetch(result.structuredContent.bootstrapUrl, {method:'HEAD', redirect:'error', signal:AbortSignal.timeout(10000)});
    if (response.status !== 200) throw Error(`HTTP ${response.status}`);
  } catch (error) {
    throw Error(`Native admin public connection is unavailable (${error.message}). Start public-proxy.mjs and the Jurassic Tube SSH tunnel before opening the admin page.`);
  }
}

const server = new Server({name:'wp-native-admin',version:'0.3.0'}, {capabilities:{tools:{},resources:{}}});
server.setRequestHandler(ListToolsRequestSchema, () => rpc('tools/list', {}));
server.setRequestHandler(CallToolRequestSchema, async ({params}) => {
  if (!['show_wp_admin', 'open-session'].includes(params.name)) throw Error('Unknown tool');
  const result = await rpc('tools/call', params);
  if (params.name === 'show_wp_admin') await checkPublicTransport(result);
  return result;
});
server.setRequestHandler(ListResourcesRequestSchema, () => rpc('resources/list', {}));
server.setRequestHandler(ReadResourceRequestSchema, async ({params}) => {
  if (params.uri !== uri) throw Error('Unknown resource');
  return rpc('resources/read', {uri});
});
await server.connect(new StdioServerTransport());
