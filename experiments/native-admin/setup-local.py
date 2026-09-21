"""Activate/deactivate the isolated proof on the disposable wp-env site."""
import html
import http.cookiejar
import json
import os
from pathlib import Path
import re
import sys
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parents[2]
entry = root / 'native-admin-proof.php'
target = 'experiments/native-admin/native-admin.php'
site = os.environ.get('WP_ENV_SITE_URL', 'http://localhost:8888')
action = 'deactivate' if '--deactivate' in sys.argv else 'activate'
if entry.exists() or entry.is_symlink():
    if not entry.is_symlink() or os.readlink(entry) != target:
        raise RuntimeError('Refusing to replace an unrelated native-admin-proof.php')
elif action == 'activate':
    entry.symlink_to(target)

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
opener.open(site + '/wp-login.php').read()
page = opener.open(site + '/wp-login.php', urllib.parse.urlencode({
    'log': os.environ.get('WP_ENV_ADMIN_USERNAME', 'admin'),
    'pwd': os.environ.get('WP_ENV_ADMIN_PASSWORD', 'password'),
    'redirect_to': site + '/wp-admin/plugins.php', 'testcookie': '1'
}).encode()).read().decode()
links = [html.unescape(link) for link in re.findall(r'href="([^"]+)"', page)
         if 'action=' + action in link and 'native-admin-proof.php' in link]
if len(links) == 1:
    opener.open(urllib.parse.urljoin(site + '/wp-admin/', links[0])).read()
elif 'native-admin-proof.php' not in page:
    raise RuntimeError('Proof plugin not found; check the local login and plugin mount')
if action == 'deactivate':
    entry.unlink(missing_ok=True)
    print('Proof deactivated and temporary entry removed.')
    sys.exit(0)

page = opener.open(site + '/wp-admin/').read().decode()
nonce = json.loads(re.search(r'wpApiSettings\s*=\s*(\{[^\n]+\});', page).group(1))['nonce']
headers = {'X-WP-Nonce': nonce, 'Content-Type': 'application/json'}
url = site + '/wp-json/wc/v3/products'
products = json.load(opener.open(urllib.request.Request(url + '?slug=mcp-embedding-proof-product', headers=headers)))
if products:
    product = products[0]
else:
    product = json.load(opener.open(urllib.request.Request(url, headers=headers, data=json.dumps({
        'name': 'MCP embedding proof product', 'slug': 'mcp-embedding-proof-product',
        'status': 'draft', 'type': 'simple', 'regular_price': '19.99',
        'description': 'Dedicated disposable fixture for native MCP Apps embedding tests.'
    }).encode())))
print('Proof activated. Draft product ID:', product['id'])
print('After starting serve.mjs, open: http://127.0.0.1:8890/?path=' + urllib.parse.quote('/wp-admin/post.php?post=' + str(product['id']) + '&action=edit', safe=''))
