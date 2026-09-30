"""Protocol/authentication checks for native wp-admin; never prints secrets."""
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
bootstrap = '/wp-admin/admin-ajax.php?aif-proof-bootstrap=1&viewer=' + urllib.parse.quote(viewer, safe='')
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
check('direct HTTP initialization advertises resources', 'resources' in rpc('initialize')['result']['capabilities'])
check('direct HTTP ping succeeds', rpc('ping')['result'] == {})
check('direct HTTP resource templates are empty', rpc('resources/templates/list')['result']['resourceTemplates'] == [])
tools = rpc('tools/list')['result']['tools']
check('one display tool and app-only session tool', [tool['name'] for tool in tools] == ['show_wp_admin', 'open-session'] and tools[1]['_meta']['ui']['visibility'] == ['app'])
resource = rpc('resources/read', {'uri': tools[0]['_meta']['ui']['resourceUri']})['result']['contents'][0]
check('direct HTTP resource discovery agrees with tools', rpc('resources/list')['result']['resources'][0]['uri'] == tools[0]['_meta']['ui']['resourceUri'])
check('resource is MCP Apps HTML with exact frame origin', resource['mimeType'] == 'text/html;profile=mcp-app' and resource['_meta']['ui']['csp']['frameDomains'] == [origin])
check('external navigation rejected', 'error' in rpc('tools/call', {'name': 'show_wp_admin', 'arguments': {'url': 'https://example.com/wp-admin/'}}))

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
check('embedded screen has no authentication debug controls', 'aif-proof-rest' not in page and 'aif-proof-diagnostics' not in page)
check('embedded headers restrict every ancestor', page_headers.get('X-Frame-Options') is None and all(ancestor in page_headers.get('Content-Security-Policy', '').split() for ancestor in ('http://127.0.0.1:8890', viewer)))
check('ordinary admin login keeps SAMEORIGIN', request('/wp-login.php', native=False)[1].get('X-Frame-Options') == 'SAMEORIGIN')
status, _, nonce = request('/wp-admin/admin-ajax.php?action=rest-nonce', headers=cookie_header)
check('native AJAX provides the session REST nonce', status == 200 and bool(re.fullmatch(r'[a-f0-9]{10}', nonce)))
status, _, text = request('/wp-json/wp/v2/users/me', headers={**cookie_header, 'X-WP-Nonce': nonce})
check('native REST nonce and user agree', status == 200 and json.loads(text)['slug'] == username)
# A second card in the same partition must retain the first card's WP nonces,
# even when the host gives each card a distinct approved viewer origin.
second_viewer = 'codex-sandbox://mcp-app-' + 'a' * 48 + '.web-sandbox.oaiusercontent.com'
second_verifier = secrets.token_hex(32)
second_grant = rpc('tools/call', {'name': 'open-session', 'arguments': {
    'path': '/wp-admin/options-writing.php', 'viewer_origin': second_viewer,
    'challenge': hashlib.sha256(second_verifier.encode()).hexdigest()
}})['result']
second_bootstrap = '/wp-admin/admin-ajax.php?aif-proof-bootstrap=1&viewer=' + urllib.parse.quote(second_viewer, safe='')
status, second_headers, _ = request(second_bootstrap, headers=cookie_header)
check('new viewer bootstrap can be framed before redemption', status == 200 and second_viewer in second_headers.get('Content-Security-Policy', '').split())
status, second_headers, _ = request(second_bootstrap, json.dumps({'ticket': second_grant['_meta']['ticket'], 'verifier': second_verifier}).encode(), {**headers, **cookie_header})
check('second card redeems in existing partition', status == 200)
second_cookie = next(value for value in second_headers.get_all('Set-Cookie') if value.startswith('__Host-aif-proof='))
check('second card preserves the shared cookie handle', second_cookie.split(';', 1)[0] == cookie_header['Cookie'])
status, _, text = request('/wp-json/wp/v2/users/me', headers={'Cookie': second_cookie.split(';', 1)[0], 'X-WP-Nonce': nonce})
check('first card nonce survives second handoff', status == 200 and json.loads(text)['slug'] == username)
status, shared_headers, shared_page = request('/wp-admin/options-writing.php', headers=cookie_header)
ready_origins = [json.loads(value) for value in re.findall(r'parent\.postMessage\(\{type:"aif-admin-ready",title:document\.title\},("(?:\\.|[^"\\])*")\)', shared_page)]
check('shared admin frames and readiness support both viewers', status == 200 and all(value in shared_headers.get('Content-Security-Policy', '').split() and value in ready_origins for value in (viewer, second_viewer)))
check('REST without nonce remains unauthenticated', request('/wp-json/wp/v2/users/me', headers=cookie_header)[0] == 401)
check('native form rejects invalid nonce', request('/wp-admin/options.php', b'action=update&option_page=writing&_wpnonce=invalid', {**cookie_header, 'Content-Type': 'application/x-www-form-urlencoded'})[0] == 403)

# Generic URLs must keep their query/hash and enforce the same boundary on handoff.
for path in ['/wp-admin/', '/wp-admin/edit.php?post_type=page&paged=2', '/wp-admin/options-writing.php', '/wp-admin/admin.php?page=plugin#/settings']:
    for url in [path, origin + path]:
        result = rpc('tools/call', {'name': 'show_wp_admin', 'arguments': {'url': url}})
        check('admin URL accepted: ' + url, result.get('result', {}).get('structuredContent', {}).get('path') == path)
        assert result['result']['structuredContent']['bootstrapUrl'] == origin + '/wp-admin/admin-ajax.php?aif-proof-bootstrap=1'
check('admin directory gets trailing slash', rpc('tools/call', {'name': 'show_wp_admin', 'arguments': {'url': '/wp-admin'}})['result']['structuredContent']['path'] == '/wp-admin/')
for url in ['https://example.com/wp-admin/', '//example.com/wp-admin/', '/wp-admin/../wp-login.php', '/wp-admin/%2e%2e/wp-login.php', '/wp-admin/%252e%252e/wp-login.php', '/wp-admin/%5c..%5cwp-login.php', '/wp-admin//../wp-login.php', '/wp-administer/', '/wp-login.php', origin.replace('https:', 'http:') + '/wp-admin/', origin.replace('://', '://user:password@') + '/wp-admin/', '/wp-admin/post.php?post=2147483647&action=edit', None, 42]:
    for name, field in [('show_wp_admin', 'url'), ('open-session', 'path')]:
        assert 'error' in rpc('tools/call', {'name': name, 'arguments': {field: url, 'challenge': 'a' * 64, 'viewer_origin': viewer}}), (name, url)
print('PASS: external, traversal, invalid and inaccessible URLs rejected by display and session tools', flush=True)
check('unapproved viewer rejected', 'error' in rpc('tools/call', {'name': 'open-session', 'arguments': {'path': '/wp-admin/', 'challenge': 'a' * 64, 'viewer_origin': 'https://example.com'}}))
check('invalid session challenge rejected', 'error' in rpc('tools/call', {'name': 'open-session', 'arguments': {'path': '/wp-admin/', 'challenge': 'invalid', 'viewer_origin': viewer}}))
for path, marker in [('/wp-admin/', 'dashboard'), ('/wp-admin/edit.php', 'wp-list-table'), ('/wp-admin/upload.php', 'upload')]:
    status, _, page = request(path, headers=cookie_header)
    check('native admin page rendered: ' + path, status == 200 and marker in page)
    check('page has no retired feature assets', 'aif-product-data-only' not in page and 'aif-single-product-block' not in page and 'aifLoadTrace' not in page)

# Find an existing post/page instead of depending on a seeded product ID.
post = None
for post_type in ['pages', 'posts']:
    status, _, text = request('/wp-json/wp/v2/' + post_type + '?context=edit&per_page=1', headers={'Authorization': authorization})
    check('existing records can be inspected', status == 200)
    records = json.loads(text)
    if records:
        post = records[0]
        break
if post:
    path = '/wp-admin/post.php?post=' + str(post['id']) + '&action=edit'
    check('existing editable record accepted', 'result' in rpc('tools/call', {'name': 'show_wp_admin', 'arguments': {'url': path}}))
    status, _, page = request(path, headers=cookie_header)
    check('embedded post/page bootstraps native Gutenberg without mode flag', status == 200 and 'wp.editPost.initialize' in page and 'id="editor"' in page)
    assets = [html.unescape(url) for url in re.findall(r"<(?:script|link)[^>]+(?:src|href)=['\"]([^'\"]+)", page)]
    assets = [url for url in assets if urllib.parse.urlsplit(url).path.endswith(('.js', '.css'))]
    check('native editor scripts/styles use public HTTPS origin', bool(assets) and all(url.startswith(origin + '/') for url in assets))
else:
    print('SKIP: existing Gutenberg record unavailable')

# Verify native REST save/read using a temporary draft, then delete only that draft.
rest_headers = {**cookie_header, 'X-WP-Nonce': nonce, 'Content-Type': 'application/json'}
status, _, text = request('/wp-json/wp/v2/posts', json.dumps({'title': 'Temporary wp-admin protocol test', 'content': '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->', 'status': 'draft'}).encode(), rest_headers)
check('native session creates disposable draft', status == 201)
draft_id = json.loads(text)['id']
try:
    content = '<!-- wp:paragraph --><p>Saved through the native REST session</p><!-- /wp:paragraph -->'
    status, _, text = request('/wp-json/wp/v2/posts/' + str(draft_id), json.dumps({'content': content}).encode(), rest_headers)
    check('native REST saves with session nonce', status == 200 and json.loads(text)['content']['raw'] == content)
    status, _, text = request('/wp-json/wp/v2/posts/' + str(draft_id) + '?context=edit', headers=rest_headers)
    check('saved draft content persists independently', status == 200 and json.loads(text)['content']['raw'] == content)
finally:
    deletion = urllib.request.Request(site + '/wp-json/wp/v2/posts/' + str(draft_id) + '?force=true', method='DELETE', headers={**rest_headers, 'X-Aif-Proof-Tls': '1'})
    with urllib.request.urlopen(deletion, timeout=45) as response:
        check('disposable draft removed', response.status == 200 and json.load(response)['deleted'])

for removed in ['/wp-json/aif-proof/v1/load-timing', '/wp-json/aif-proof/v1/render-status', '/wp-json/wp-ai-fragments/v1/mcp']:
    check('retired endpoint removed: ' + removed, request(removed, headers={'Authorization': authorization})[0] == 404)
