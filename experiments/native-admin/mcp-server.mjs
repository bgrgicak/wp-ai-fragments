// Local-only STDIO MCP entrypoint for the original wp-admin demo.
import {execFileSync} from 'node:child_process';
import {Server} from '@modelcontextprotocol/sdk/server/index.js';
import {StdioServerTransport} from '@modelcontextprotocol/sdk/server/stdio.js';
import {ListToolsRequestSchema, CallToolRequestSchema, ListResourcesRequestSchema, ReadResourceRequestSchema} from '@modelcontextprotocol/sdk/types.js';

const site = process.env.WP_ENV_SITE_URL || 'http://localhost:8888';
if (!['localhost', '127.0.0.1'].includes(new URL(site).hostname)) throw Error('Local demo only');
const username = process.env.WP_MCP_USERNAME || 'wp-ai-agent';
const backendUri = 'ui://aif-proof/native-admin.html';
const uri = 'ui://aif-proof/native-admin-jurassic-v4.html';
const path = '/wp-admin/post.php?post=12&action=edit';
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
const server = new Server({name:'wp-native-admin',version:'0.1.0'}, {capabilities:{tools:{},resources:{}}});
server.setRequestHandler(ListToolsRequestSchema, async () => ({tools:[
  {
    name:'show_product_description',
    description:'Display product 12 in the actual WooCommerce wp-admin editor as an inline MCP Apps component. Shows the native Product data settings (price, inventory, shipping, attributes) and Update button. Invoke when asked for product details here in chat.',
    inputSchema:{type:'object',properties:{},additionalProperties:false},
    annotations:{readOnlyHint:true},
    _meta:{ui:{resourceUri:uri},'openai/outputTemplate':uri},
  },
  {
    name:'open-session', description:'Component-only native local WordPress session handoff.',
    inputSchema:{type:'object',properties:{path:{type:'string',enum:[path]},challenge:{type:'string',pattern:'^[a-f0-9]{64}$'},viewer_origin:{type:'string'}},required:['path','challenge','viewer_origin'],additionalProperties:false},
    annotations:{readOnlyHint:false}, _meta:{ui:{visibility:['app']}},
  },
]}));
server.setRequestHandler(CallToolRequestSchema, async ({params}) => {
  if (params.name === 'show_product_description') {
    const result = await rpc('tools/call', {name:'show-admin',arguments:{path}});
    result._meta = {...result._meta,ui:{resourceUri:uri},'openai/outputTemplate':uri};
    return result;
  }
  if (params.name === 'open-session') {
    if (params.arguments?.path !== path) throw Error('This demo only supports product 12');
    return rpc('tools/call', params);
  }
  throw Error('Unknown tool');
});
server.setRequestHandler(ListResourcesRequestSchema, async () => ({resources:[{uri,name:'Native WooCommerce product editor',mimeType:'text/html;profile=mcp-app'}]}));
server.setRequestHandler(ReadResourceRequestSchema, async ({params}) => {
  if (!/^ui:\/\/aif-proof\/native-admin(?:-[a-z0-9-]+)?\.html$/.test(params.uri)) throw Error('Unknown resource');
  const result = await rpc('resources/read',{uri:backendUri});
  for (const content of result.contents) content.uri=params.uri;
  return result;
});
await server.connect(new StdioServerTransport());
