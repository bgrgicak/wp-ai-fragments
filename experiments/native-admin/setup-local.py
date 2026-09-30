"""Activate the single native-admin plugin on the existing disposable wp-env site."""
import html
import http.cookiejar
import os
import re
import sys
import urllib.parse
import urllib.request

site = os.environ.get('WP_ENV_SITE_URL', 'http://localhost:8888')
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
opener.open(site + '/wp-login.php', timeout=45).read()
opener.open(site + '/wp-login.php', urllib.parse.urlencode({
    'log': os.environ.get('WP_ENV_ADMIN_USERNAME', 'admin'),
    'pwd': os.environ.get('WP_ENV_ADMIN_PASSWORD', 'password'),
    'redirect_to': site + '/wp-admin/plugins.php', 'testcookie': '1'
}).encode(), timeout=45).read()


def plugin_action(plugin, action):
    page = opener.open(site + '/wp-admin/plugins.php', timeout=45).read().decode()
    for raw in re.findall(r'href="([^"]+)"', page):
        link = html.unescape(raw)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(link).query)
        if query.get('plugin') == [plugin] and query.get('action') == [action]:
            opener.open(urllib.parse.urljoin(site + '/wp-admin/', link), timeout=45).read()
            print(action.capitalize() + 'd:', plugin)
            return True
    return False


plugin = 'wp-ai-fragments/wp-ai-fragments.php'
if '--deactivate' in sys.argv:
    plugin_action(plugin, 'deactivate')
else:
    # Retire the prior second entrypoint and product-only development override.
    plugin_action('wp-ai-fragments/native-admin-proof.php', 'deactivate')
    plugin_action('local-woocommerce-gutenberg/local-woocommerce-gutenberg.php', 'deactivate')
    plugin_action(plugin, 'activate')
    page = opener.open(site + '/wp-admin/plugins.php', timeout=45).read().decode()
    if 'data-plugin="' + plugin + '"' not in page:
        raise RuntimeError('Plugin not found; check the local login and wp-env mount')
    print('Native WordPress admin plugin is ready. No content fixtures were created.')
