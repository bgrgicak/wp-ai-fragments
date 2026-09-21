// Narrow public transport for the disposable Jurassic Tube demo.
import http from 'node:http';
const origin = 'https://your-subdomain.jurassic.tube';
http.createServer((req,res)=>{
 const url=new URL(req.url,origin);
 const bootstrap=url.pathname==='/' && url.searchParams.has('aif-proof-bootstrap');
 const session=/__Host-aif-proof=[a-f0-9]{64}(?:;|$)/.test(req.headers.cookie||'');
 const asset=/^\/(wp-includes|wp-content)\//.test(url.pathname)&&/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|ico|webp|map)$/.test(url.pathname);
 if(req.headers.authorization || (!bootstrap && !asset && !session) || url.pathname==='/wp-login.php' || url.pathname==='/xmlrpc.php') {res.writeHead(403).end('This endpoint is available only through the MCP demo.');return;}
 const upstream=http.request({hostname:'127.0.0.1',port:8888,path:req.url,method:req.method,headers:{...req.headers,host:'your-subdomain.jurassic.tube','x-aif-proof-tls':'1'}},result=>{res.writeHead(result.statusCode,result.headers);result.pipe(res);});
 upstream.on('error',()=>res.writeHead(502).end('Demo offline'));req.pipe(upstream);
}).listen(8893,'127.0.0.1',()=>console.log('Restricted Jurassic Tube backend on 8893'));
