// Loopback-only TLS transport for the existing WordPress instance.
import https from 'node:https';
import http from 'node:http';
import {readFileSync} from 'node:fs';
import {homedir} from 'node:os';
const certDir = homedir() + '/Library/Application Support/Herd/config/valet/Certificates';
https.createServer({
  key:readFileSync(certDir + '/wp-mcp-demo.test.key'),
  cert:Buffer.concat([readFileSync(certDir + '/wp-mcp-demo.test.crt'), Buffer.from('\n'), readFileSync(certDir + '/../CA/LaravelValetCASelfSigned.pem')]),
}, (request, response) => {
  if (request.headers.host !== 'wp-mcp-demo.test:8892') { response.writeHead(403).end(); return; }
  const upstream = http.request({hostname:'127.0.0.1',port:8888,path:request.url,method:request.method,
    headers:{...request.headers,'x-aif-proof-tls':'1'}}, result => {
      response.writeHead(result.statusCode,result.headers);
      result.pipe(response);
    });
  upstream.on('error', () => response.writeHead(502).end('Local WordPress is unavailable'));
  request.pipe(upstream);
}).listen(8892,'127.0.0.1',()=>console.log('Native WordPress TLS transport: https://wp-mcp-demo.test:8892'));
