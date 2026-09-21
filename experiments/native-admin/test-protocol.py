"""Protocol/authentication checks for the local proof; never prints secrets."""
import base64
import hashlib
import html
import urllib.parse
import json
import os
import re
import secrets
import subprocess
import urllib.error
import urllib.request

site = os.environ.get('WP_ENV_SITE_URL', 'http://localhost:8888')
origin = os.environ.get('AIF_PROOF_PUBLIC_ORIGIN', 'https://your-subdomain.jurassic.tube').rstrip('/')
viewer = 'http://127.0.0.1:8891'
bootstrap = '/?aif-proof-bootstrap=1&viewer=' + urllib.parse.quote(viewer, safe='')
username = os.environ.get('WP_MCP_USERNAME', 'wp-ai-agent')
password = os.environ.get('WP_API_PASSWORD') or subprocess.check_output([
    'security', 'find-generic-password', '-a', username, '-s', 'wp-ai-fragments-mcp', '-w'
]).decode().strip()
authorization = 'Basic ' + base64.b64encode((username + ':' + password).encode()).decode()


def request(path, data=None, headers=None, native=True):
    transport_headers = {'X-Aif-Proof-Tls': '1'} if native and origin != site else {}
    req = urllib.request.Request(site + path, data=data, headers={**transport_headers, **(headers or {})})
    try:
        response = urllib.request.urlopen(req, timeout=45)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.headers, response.read().decode()


def rpc(method, params=None):
    status, _, text = request('/wp-json/aif-proof/v1/mcp', json.dumps({
        'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params or {}
    }).encode(), {'Authorization': authorization, 'Content-Type': 'application/json'})
    assert status == 200
    return json.loads(text)


def check(label, condition):
    assert condition, label
    print('PASS:', label, flush=True)


check('MCP requires authentication', request('/wp-json/aif-proof/v1/mcp', b'{}', {'Content-Type': 'application/json'})[0] in (401, 403))
tools = rpc('tools/list')['result']['tools']
check('session tool is app-only', tools[1]['_meta']['ui']['visibility'] == ['app'])
resource = rpc('resources/read', {'uri': tools[0]['_meta']['ui']['resourceUri']})['result']['contents'][0]
check('resource is MCP Apps HTML with exact frame origin', resource['mimeType'] == 'text/html;profile=mcp-app' and resource['_meta']['ui']['csp']['frameDomains'] == [origin])
check('external navigation rejected', 'error' in rpc('tools/call', {'name': 'show-admin', 'arguments': {'path': 'https://example.com/wp-admin/'}}))

verifier = secrets.token_hex(32)
grant = rpc('tools/call', {'name': 'open-session', 'arguments': {
    'path': '/wp-admin/options-writing.php', 'viewer_origin': viewer, 'challenge': hashlib.sha256(verifier.encode()).hexdigest()
}})['result']
ticket = grant['_meta']['ticket']
check('handoff absent from model-visible result', ticket not in json.dumps(grant['content']) + json.dumps(grant['structuredContent']))
headers = {'Origin': origin, 'Content-Type': 'application/json'}
body = json.dumps({'ticket': ticket, 'verifier': verifier}).encode()
bad = json.dumps({'ticket': ticket, 'verifier': 'wrong'}).encode()
check('wrong verifier rejected', request(bootstrap, bad, headers)[0] == 403)
check('wrong redemption origin rejected', request(bootstrap, body, {**headers, 'Origin': 'https://example.com'})[0] == 403)
status, response_headers, _ = request(bootstrap, body, headers)
check('valid handoff redeems', status == 200)
set_cookie = next(cookie for cookie in response_headers.get_all('Set-Cookie') if cookie.startswith('__Host-aif-proof='))
check('cookie uses all required attributes', all(part in set_cookie for part in ('Path=/', 'Secure', 'HttpOnly', 'SameSite=None', 'Partitioned')))
check('replay rejected', request(bootstrap, body, headers)[0] == 403)
cookie_header = {'Cookie': set_cookie.split(';', 1)[0]}
# Sending the cookie explicitly tests the server mapping, not browser CHIPS.
status, _, text = request('/?aif-proof-session-check=1', headers=cookie_header)
check('transport restores native WP authentication', status == 200 and json.loads(text)['data']['authenticated'] and json.loads(text)['data']['embedded'])
status, page_headers, page = request('/wp-admin/options-writing.php', headers=cookie_header)
check('original admin screen rendered', status == 200 and 'options.php' in page and 'Writing Settings' in page)
check('embedded headers restrict every ancestor', page_headers.get('X-Frame-Options') is None and all(ancestor in page_headers.get('Content-Security-Policy', '').split() for ancestor in ('http://127.0.0.1:8890', viewer)))
check('ordinary admin login keeps SAMEORIGIN', request('/wp-login.php', native=False)[1].get('X-Frame-Options') == 'SAMEORIGIN')
nonce = re.search(r"'X-WP-Nonce':\s*\"([^\"]+)\"", page).group(1)
status, _, text = request('/wp-json/wp/v2/users/me', headers={**cookie_header, 'X-WP-Nonce': nonce})
check('native REST nonce and user agree', status == 200 and json.loads(text)['slug'] == username)
check('REST without nonce remains unauthenticated', request('/wp-json/wp/v2/users/me', headers=cookie_header)[0] == 401)
check('native form rejects invalid nonce', request('/wp-admin/options.php', b'action=update&option_page=writing&_wpnonce=invalid', {**cookie_header, 'Content-Type': 'application/x-www-form-urlencoded'})[0] == 403)

# Regression: HTTPS upgrades previously left WooCommerce assets pointing at localhost.
product_id = int(os.environ.get('AIF_PROOF_PRODUCT_ID', '12'))
status, _, product_page = request(f'/wp-admin/post.php?post={product_id}&action=edit', headers=cookie_header)
check('selected record is a WooCommerce product', status == 200 and 'id="woocommerce-product-data"' in product_page)
asset_urls = [html.unescape(url) for url in re.findall(r"<(?:script|link)[^>]+(?:src|href)=['\"]([^'\"]+)", product_page)]
plugin_assets = [url for url in asset_urls if '/wp-content/plugins/' in url]
check('plugin assets use the advertised HTTPS origin', bool(plugin_assets) and all(url.startswith(origin + '/') for url in plugin_assets))
check('native product panel and update control are retained', 'aif-product-data-only' in product_page and 'id="publish"' in product_page)
