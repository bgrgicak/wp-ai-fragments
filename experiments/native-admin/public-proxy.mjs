// Narrow public transport for the disposable Jurassic Tube demo.
import http from 'node:http';
import {publicOrigin} from './public-origin.mjs';
const origin = publicOrigin();
if (!origin) throw Error('Configure AIF_PROOF_PUBLIC_ORIGIN or write the HTTPS origin to .https-demo before starting the tunnel proxy.');
http.createServer((req,res)=>{
 const url=new URL(req.url,origin);
 const bootstrap=['/','/wp-admin/admin-ajax.php'].includes(url.pathname) && url.searchParams.has('aif-proof-bootstrap');
 const session=/__Host-aif-proof=[a-f0-9]{64}(?:;|$)/.test(req.headers.cookie||'');
 const asset=/^\/(wp-includes|wp-content)\//.test(url.pathname)&&/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|ico|webp|map)$/.test(url.pathname);
 if(req.headers.authorization || (!bootstrap && !asset && !session) || url.pathname==='/wp-login.php' || url.pathname==='/xmlrpc.php') {res.writeHead(403).end('This endpoint is available only through the MCP demo.');return;}
 const upstream=http.request({hostname:'127.0.0.1',port:8888,path:req.url,method:req.method,headers:{...req.headers,host:new URL(origin).host,'x-aif-proof-tls':'1'}},result=>{res.writeHead(result.statusCode,result.headers);result.pipe(res);});
 upstream.on('error',()=>res.writeHead(502).end('Demo offline'));req.pipe(upstream);
}).listen(8893,'127.0.0.1',()=>console.log('Restricted Jurassic Tube backend on 8893'));
