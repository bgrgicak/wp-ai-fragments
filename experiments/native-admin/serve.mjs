// Loopback-only development host. Never deploy this credentialed test harness.
import './build.mjs';
import http from 'node:http';
import https from 'node:https';
import {homedir} from 'node:os';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {build} from 'vite';
import {viteSingleFile} from 'vite-plugin-singlefile';

const root = fileURLToPath(new URL('.', import.meta.url));
await build({configFile:false, root, plugins:[viteSingleFile()], build:{outDir:'dist', emptyOutDir:false, rollupOptions:{input:root + 'host.html'}}});
const tls = process.env.AIF_PROOF_HTTPS === '1';
const backend = process.env.WP_ENV_SITE_URL || 'http://localhost:8888';
const site = process.env.AIF_PROOF_PUBLIC_ORIGIN || (tls ? 'https://aif-proof-wp.test:8892' : backend);
const viewer = tls ? 'https://aif-proof-view.test:8891' : 'http://127.0.0.1:8891';
const host = tls ? 'https://aif-proof-host.test:8890' : 'http://127.0.0.1:8890';
const username = process.env.WP_MCP_USERNAME || 'wp-ai-agent';
const password = process.env.WP_API_PASSWORD || execFileSync('security', ['find-generic-password','-a',username,'-s','wp-ai-fragments-mcp','-w'], {encoding:'utf8'}).trim();
const authorization = 'Basic ' + Buffer.from(username + ':' + password).toString('base64');
const parents = [host, 'http://localhost:8890'];
async function makeServer(name, handler) {
  if (!tls) return http.createServer(handler);
  const certificates = process.env.AIF_PROOF_CERT_DIR || homedir() + '/Library/Application Support/Herd/config/valet/Certificates';
  return https.createServer({key:await readFile(`${certificates}/${name}.test.key`),cert:await readFile(`${certificates}/${name}.test.crt`)},handler);
}

(await makeServer('aif-proof-host', async (request, response) => {
  try {
    if (!['127.0.0.1:8890','localhost:8890','aif-proof-host.test:8890'].includes(request.headers.host)) { response.writeHead(403).end(); return; }
    response.setHeader('Cache-Control','no-store');
    if (request.url === '/mcp' && request.method === 'POST') {
      if (!parents.includes(request.headers.origin)) { response.writeHead(403).end(); return; }
      const parts = []; let size = 0;
      for await (const part of request) { size += part.length; if (size > 100000) throw Error('Request too large'); parts.push(part); }
      const result = await fetch(backend + '/wp-json/aif-proof/v1/mcp', {method:'POST',headers:{Authorization:authorization,'Content-Type':'application/json',...(tls ? {'X-Aif-Proof-Tls':'1'} : {})},body:Buffer.concat(parts)});
      response.writeHead(result.status, {'Content-Type':'application/json'}).end(await result.text());
      return;
    }
    if (request.method !== 'GET') { response.writeHead(405).end(); return; }
    const html = (await readFile(root + 'dist/host.html', 'utf8')).replaceAll('http://127.0.0.1:8891', viewer);
    response.writeHead(200, {'Content-Type':'text/html'}).end(html);
  } catch (error) { response.writeHead(500).end('Proof host request failed'); console.error(error.message); }
})).listen(8890, '127.0.0.1');

(await makeServer('aif-proof-view', (request, response) => {
  if (!['127.0.0.1:8891','aif-proof-view.test:8891'].includes(request.headers.host)) { response.writeHead(403).end(); return; }
  const url = new URL(request.url, 'http://127.0.0.1:8891');
  const parent = url.searchParams.get('parent');
  if (!parents.includes(parent)) { response.writeHead(403).end(); return; }
  const frames = url.searchParams.has('noframes') ? "'none'" : site;
  response.writeHead(200, {'Content-Type':'text/html', 'Cache-Control':'no-store',
    'Content-Security-Policy':`default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'none'; img-src data:; frame-src 'self' ${frames}; frame-ancestors ${parent}`});
  response.end(`<!doctype html><html><body style="margin:0"><script>
    const expectedParent = ${JSON.stringify(parent)};
    const inner = document.createElement('iframe');
    inner.id = 'view'; inner.style = 'width:100%;height:1100px;border:0';
    inner.sandbox = 'allow-scripts allow-same-origin allow-forms';
    document.body.append(inner);
    addEventListener('message', (event) => {
      if (event.source === parent && event.origin === expectedParent) {
        if (event.data?.method === 'ui/notifications/sandbox-resource-ready') {
          inner.sandbox = event.data.params.sandbox;
          inner.srcdoc = event.data.params.html;
        } else { inner.contentWindow.postMessage(event.data, location.origin); }
      } else if (event.source === inner.contentWindow && event.origin === location.origin) {
        parent.postMessage(event.data, expectedParent);
      }
    });
    parent.postMessage({jsonrpc:'2.0',method:'ui/notifications/sandbox-proxy-ready',params:{}}, expectedParent);
  </script></body></html>`);
})).listen(8891, '127.0.0.1');
// Local TLS termination forwards bytes only; no HTML, cookie, or CSP rewriting.
if (tls) (await makeServer('aif-proof-wp', (request, response) => {
  const upstream = http.request(backend + request.url, {method:request.method,headers:{...request.headers,'X-Aif-Proof-Tls':'1',host:new URL(backend).host}}, (result) => {
    response.writeHead(result.statusCode, result.headers); result.pipe(response);
  });
  upstream.on('error', () => response.writeHead(502).end());
  request.pipe(upstream);
})).listen(8892, '127.0.0.1');
console.log('Proof host: ' + host);
