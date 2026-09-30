// Exercise the real STDIO bridge; never print private handoff metadata.
import assert from 'node:assert/strict';
import {Client} from '@modelcontextprotocol/sdk/client/index.js';
import {StdioClientTransport} from '@modelcontextprotocol/sdk/client/stdio.js';
import {fileURLToPath} from 'node:url';

const client = new Client({name:'wp-admin-bridge-test', version:'1'});
try {
  await client.connect(new StdioClientTransport({command:process.execPath, args:[fileURLToPath(new URL('./mcp-server.mjs', import.meta.url))], env:process.env}));
  const {tools} = await client.listTools();
  assert.deepEqual(tools.filter(tool => !tool._meta?.ui?.visibility?.includes('app')).map(tool => tool.name), ['show_wp_admin']);
  assert.deepEqual(tools.map(tool => tool.name), ['show_wp_admin', 'open-session']);
  const tool = tools[0];
  assert.deepEqual(tool.inputSchema.required, ['url']);
  const {resources} = await client.listResources();
  assert.equal(resources.length, 1);
  assert.equal(resources[0].uri, tool._meta.ui.resourceUri);
  const resource = await client.readResource({uri:resources[0].uri});
  assert.equal(resource.contents[0].mimeType, 'text/html;profile=mcp-app');
  assert(resource.contents[0].text.includes('id="wordpress"'));
  const dashboard = await client.callTool({name:tool.name, arguments:{url:'/wp-admin/'}});
  assert(!dashboard.isError);
  assert.equal(dashboard.structuredContent.path, '/wp-admin/');
  const bootstrap = new URL(dashboard.structuredContent.bootstrapUrl);
  assert.equal(bootstrap.origin, dashboard.structuredContent.origin);
  assert.equal(bootstrap.pathname, '/wp-admin/admin-ajax.php');
  assert.equal(bootstrap.searchParams.get('aif-proof-bootstrap'), '1');
  assert.equal(dashboard._meta.ui.resourceUri, tool._meta.ui.resourceUri);
  for (const path of ['/wp-admin/edit.php?post_type=page&paged=2', '/wp-admin/options-writing.php', '/wp-admin/admin.php?page=example#/settings']) {
    for (const url of [path, dashboard.structuredContent.origin + path]) {
      const result = await client.callTool({name:tool.name, arguments:{url}});
      assert(!result.isError, url);
      assert.equal(result.structuredContent.path, path);
    }
  }
  for (const url of ['https://example.com/wp-admin/', '/wp-admin/../wp-login.php', '/wp-admin/%2e%2e/wp-login.php', '/wp-admin/%252e%252e/wp-login.php', '/wp-admin/%5c..%5cwp-login.php', '//example.com/wp-admin/', '/wp-administer/', '/wp-login.php', null]) {
    const result = await client.callTool({name:tool.name, arguments:{url}}).catch(() => ({isError:true}));
    assert(result.isError, String(url));
  }
  for (const name of ['show_gutenberg', 'show_product_description', 'show_product_block', 'ui/list-fragments']) {
    const result = await client.callTool({name, arguments:{post_id:2}}).catch(() => ({isError:true}));
    assert(result.isError, name);
  }
  await assert.rejects(() => client.readResource({uri:'ui://aif-proof/native-admin-gutenberg-v1.html'}));
  console.log('PASS: only show_wp_admin is user-facing; resource, generic URLs and removed-tool rejection work over STDIO');
} finally {
  await client.close();
}
