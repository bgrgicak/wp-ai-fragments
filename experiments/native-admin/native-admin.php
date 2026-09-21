<?php
/**
 * Plugin Name: Native admin embedding proof (LOCAL ONLY)
 * Description: Isolated experiment. Activate only on the disposable wp-env site.
 */

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() ) {
	return;
}

// Test-fixture URL configuration for the optional local trusted-TLS proxy.
// A deployed plugin would use the site's existing HTTPS configuration.
$aif_proof_tls = ( 'wp-mcp-demo.test' === ( $_SERVER['HTTP_HOST'] ?? '' ) && 'https' === ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) || '1' === ( $_SERVER['HTTP_X_AIF_PROOF_TLS'] ?? '' ) || ( file_exists( __DIR__ . '/.https-demo' ) && str_contains( $_SERVER['REQUEST_URI'] ?? '', '/aif-proof/v1/mcp' ) );
if ( $aif_proof_tls ) {
	$_SERVER['HTTPS'] = 'on';
	add_filter( 'pre_option_home', static fn() => 'https://bero.jurassic.tube' );
	add_filter( 'pre_option_siteurl', static fn() => 'https://bero.jurassic.tube' );
	foreach ( array( 'plugins_url', 'content_url', 'script_loader_src', 'style_loader_src' ) as $url_filter ) {
		add_filter( $url_filter, static fn( $url ) => str_replace( array( 'http://localhost:8888', 'https://localhost:8888' ), 'https://bero.jurassic.tube', $url ), 1000 );
	}
}
define( 'AIF_PROOF_VIEW', $aif_proof_tls ? 'https://aif-proof-view.test:8891' : 'http://127.0.0.1:8891' );
define( 'AIF_PROOF_HOST', $aif_proof_tls ? 'https://aif-proof-host.test:8890' : 'http://127.0.0.1:8890' );
const AIF_PROOF_COOKIE = '__Host-aif-proof';
const AIF_PROOF_RESOURCE = 'ui://aif-proof/native-admin.html';

// Restore real WordPress cookies before normal authentication runs. The browser
// carries a distinct HttpOnly transport cookie, avoiding collisions with an
// existing top-level login. Core still validates signatures and session tokens.
$aif_proof_handle = $_COOKIE[ AIF_PROOF_COOKIE ] ?? '';
if ( is_string( $aif_proof_handle ) && preg_match( '/^[a-f0-9]{64}$/D', $aif_proof_handle ) ) {
	$aif_proof_session = get_transient( 'aif_session_' . hash( 'sha256', $aif_proof_handle ) );
	if ( is_array( $aif_proof_session ) && $aif_proof_session['expires'] > time() ) {
		$_COOKIE[ AUTH_COOKIE ] = $aif_proof_session['auth'];
		$_COOKIE[ SECURE_AUTH_COOKIE ] = $aif_proof_session['secure_auth'];
		$_COOKIE[ LOGGED_IN_COOKIE ] = $aif_proof_session['logged_in'];
		$GLOBALS['aif_proof_active_session'] = $aif_proof_session;
	}
}
unset( $aif_proof_handle, $aif_proof_session );

function aif_proof_frame_headers(): void {
	// No wildcard ancestors; every ancestor in the harness is listed.
	$viewer = $GLOBALS['aif_proof_active_session']['viewer'] ?? $GLOBALS['aif_proof_bootstrap_viewer'] ?? AIF_PROOF_VIEW;
	header_remove( 'X-Frame-Options' );
	header( "Content-Security-Policy: frame-ancestors 'self' " . AIF_PROOF_HOST . ' ' . AIF_PROOF_VIEW . ' ' . $viewer . ( 'http://127.0.0.1:8891' === $viewer ? ' http://127.0.0.1:8890' : '' ) );
	header( 'Cache-Control: private, no-store' );
	header( 'Referrer-Policy: no-referrer' );
}

// Local demo host profile. Only the installed Codex app's isolated MCP origin,
// or the original test viewer, can receive this proof's browser handoff.
function aif_proof_viewer( $origin ): string {
	if ( 'http://127.0.0.1:8891' === $origin || AIF_PROOF_VIEW === $origin || ( is_string( $origin ) && preg_match( '~^codex-sandbox://mcp-app-[a-f0-9]{48}\.web-sandbox\.oaiusercontent\.com$~D', $origin ) ) ) {
		return $origin;
	}
	throw new InvalidArgumentException( 'Unapproved local MCP viewer origin.' );
}

function aif_proof_path( $path ): string {
	if ( ! is_string( $path ) || ! str_starts_with( $path, '/wp-admin/' ) || preg_match( '/[\\\\\r\n#]/', $path ) || str_contains( $path, '..' ) ) {
		throw new InvalidArgumentException( 'Expected a local /wp-admin/ path.' );
	}
	return $path;
}

// Apply the exception to authenticated embedded document requests throughout
// native navigation, including POST -> 302 -> GET, never to ordinary sessions.
add_action( 'init', static function (): void {
	$session = $GLOBALS['aif_proof_active_session'] ?? null;
	if ( isset( $_GET['aif-proof-render-check'] ) ) {
		if ( ! $session || ! is_user_logged_in() || 'POST' !== $_SERVER['REQUEST_METHOD'] || ( $_SERVER['HTTP_ORIGIN'] ?? '' ) !== untrailingslashit( home_url() ) ) {
			wp_send_json_error( 'Invalid render check', 403 );
		}
		$check = json_decode( file_get_contents( 'php://input' ), true );
		update_option( 'aif_proof_last_render', array( 'time' => gmdate( 'c' ), 'viewer' => $session['viewer'], 'post_id' => absint( $check['post_id'] ?? 0 ), 'editor_ready' => ! empty( $check['editor_ready'] ), 'width' => absint( $check['width'] ?? 0 ), 'height' => absint( $check['height'] ?? 0 ), 'text' => sanitize_text_field( $check['text'] ?? '' ), 'product_data_ready' => ! empty( $check['product_data_ready'] ) ), false );
		wp_send_json_success();
	}
	if ( isset( $_GET['aif-proof-session-check'] ) ) {
		wp_send_json_success( array( 'authenticated' => is_user_logged_in(), 'embedded' => (bool) $session, 'transport_received' => isset( $_COOKIE[ AIF_PROOF_COOKIE ] ), 'unpartitioned_control' => isset( $_COOKIE['aif_proof_unpartitioned_control'] ) ) );
	}
	if ( $session && wp_validate_auth_cookie( $session['logged_in'], 'logged_in' ) === $session['user'] ) {
		remove_action( 'admin_init', 'send_frame_options_header' );
		remove_action( 'login_init', 'send_frame_options_header' );
		add_action( 'admin_init', 'aif_proof_frame_headers', PHP_INT_MAX );
		add_action( 'login_init', 'aif_proof_frame_headers', PHP_INT_MAX );
	}

	if ( ! isset( $_GET['aif-proof-bootstrap'] ) ) {
		return;
	}
	try {
		$GLOBALS['aif_proof_bootstrap_viewer'] = aif_proof_viewer( wp_unslash( $_GET['viewer'] ?? AIF_PROOF_VIEW ) );
	} catch ( Throwable $error ) {
		wp_die( 'Unapproved viewer', '', array( 'response' => 403 ) );
	}
	aif_proof_frame_headers();
	update_option( 'aif_proof_bootstrap_observed', array( 'time' => gmdate( 'c' ), 'viewer' => $GLOBALS['aif_proof_bootstrap_viewer'], 'method' => $_SERVER['REQUEST_METHOD'] ), false );
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		if ( ( $_SERVER['HTTP_ORIGIN'] ?? '' ) !== untrailingslashit( home_url() ) ) {
			wp_send_json_error( 'Invalid redemption origin', 403 );
		}
		$input = json_decode( file_get_contents( 'php://input' ), true );
		$ticket = $input['ticket'] ?? '';
		$verifier = $input['verifier'] ?? '';
		if ( ! is_string( $ticket ) || ! is_string( $verifier ) ) {
			wp_send_json_error( 'Invalid handoff', 400 );
		}
		$key = 'aif_ticket_' . hash( 'sha256', $ticket );
		$grant = get_option( $key );
		if ( ! is_array( $grant ) || $grant['expires'] < time() || ! hash_equals( $grant['challenge'], hash( 'sha256', $verifier ) ) ) {
			wp_send_json_error( 'Expired or invalid handoff', 403 );
		}
		if ( ( $grant['viewer'] ?? AIF_PROOF_VIEW ) !== $GLOBALS['aif_proof_bootstrap_viewer'] ) {
			wp_send_json_error( 'Viewer does not match handoff', 403 );
		}
		// Atomic database deletion: only one concurrent redeemer can succeed.
		global $wpdb;
		$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => $key ), array( '%s' ) );
		wp_cache_delete( $key, 'options' );
		if ( 1 !== $deleted ) {
			wp_send_json_error( 'Handoff already consumed', 403 );
		}
		$user = get_user_by( 'id', $grant['user'] );
		if ( ! $user || ! user_can( $user, 'read' ) ) {
			wp_send_json_error( 'User unavailable', 403 );
		}
		$expires = time() + 20 * MINUTE_IN_SECONDS;
		$token = WP_Session_Tokens::get_instance( $user->ID )->create( $expires );
		$handle = bin2hex( random_bytes( 32 ) );
		set_transient( 'aif_session_' . hash( 'sha256', $handle ), array(
			'user' => $user->ID,
			'viewer' => $grant['viewer'] ?? AIF_PROOF_VIEW,
			'expires' => $expires,
			'auth' => wp_generate_auth_cookie( $user->ID, $expires, 'auth', $token ),
			'secure_auth' => wp_generate_auth_cookie( $user->ID, $expires, 'secure_auth', $token ),
			'logged_in' => wp_generate_auth_cookie( $user->ID, $expires, 'logged_in', $token ),
		), 20 * MINUTE_IN_SECONDS );
		header( 'Set-Cookie: ' . AIF_PROOF_COOKIE . '=' . $handle . '; Path=/; Max-Age=1200; Secure; HttpOnly; SameSite=None; Partitioned', false );
		header( 'Set-Cookie: aif_proof_unpartitioned_control=1; Path=/; Max-Age=60; Secure; HttpOnly; SameSite=None', false );
		wp_send_json_success( array( 'url' => home_url( $grant['path'] ) ) );
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!doctype html><html><head><meta charset="utf-8"><title>Connect native WordPress</title></head>
<body><p id="status">Connecting WordPress session…</p><script>
const viewerOrigin = <?php echo wp_json_encode( $GLOBALS['aif_proof_bootstrap_viewer'] ); ?>;
let redeemed = false;
addEventListener('message', async (event) => {
  if (event.source !== parent || event.origin !== viewerOrigin || event.data?.type !== 'aif-handoff' || redeemed) return;
  redeemed = true;
  try {
    const response = await fetch(location.href, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify(event.data)});
    const result = await response.json();
    if (!response.ok || !result.success) throw Error('Session handoff failed');
    const check = await (await fetch('/?aif-proof-session-check=1')).json();
    if (!check.data.authenticated || !check.data.embedded) throw Error('Partitioned session cookie did not survive: ' + JSON.stringify(check.data));
    parent.postMessage({type:'aif-cookie-check',unpartitioned:check.data.unpartitioned_control}, viewerOrigin);
    location.replace(result.data.url);
  } catch (error) { document.querySelector('#status').textContent = error.message; }
});
parent.postMessage({type:'aif-bootstrap-ready'}, viewerOrigin);
</script></body></html>
	<?php
	exit;
}, 1 );

// Presentation-only crop, restricted to the authenticated embedded product editor.
add_action( 'admin_head', static function (): void {
	if ( empty( $GLOBALS['aif_proof_active_session'] ) || 'product' !== get_current_screen()->post_type || 'post' !== get_current_screen()->base ) {
		return;
	}
	echo '<style id="aif-product-data-only">' . file_get_contents( __DIR__ . '/product-data-only.css' ) . '</style>';
}, 1000 );

// Diagnostics stay in the native document. Only non-secret status crosses frames.
add_action( 'admin_footer', static function (): void {
	if ( empty( $GLOBALS['aif_proof_active_session'] ) ) {
		return;
	}
	?>
<div id="aif-proof-diagnostics" style="position:fixed;bottom:0;right:0;background:white;border:1px solid #888;padding:8px;z-index:99999">
  <button type="button" id="aif-proof-rest">Check native REST authentication</button>
  <span id="aif-proof-rest-result"></span>
</div>
<script>
document.querySelector('#aif-proof-rest').onclick = async () => {
  const response = await fetch(<?php echo wp_json_encode( rest_url( 'wp/v2/users/me' ) ); ?>, {headers:{'X-WP-Nonce':<?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>}});
  const user = await response.json();
  document.querySelector('#aif-proof-rest-result').textContent = response.ok ? 'REST authenticated as ' + user.slug : 'REST failed: ' + user.code;
};
parent.postMessage({type:'aif-admin-ready',title:document.title}, <?php echo wp_json_encode( $GLOBALS['aif_proof_active_session']['viewer'] ?? AIF_PROOF_VIEW ); ?>);
// Report actual initialized native editor DOM, not just a successful HTTP load.
let renderAttempts = 0;
const renderCheck = setInterval(() => {
  const editor = window.tinymce?.get('content');
  if (!editor?.initialized && ++renderAttempts < 40) return;
  clearInterval(renderCheck);
  const box = document.querySelector('#wp-content-wrap')?.getBoundingClientRect();
  fetch('/?aif-proof-render-check=1', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({post_id:document.querySelector('#post_ID')?.value,editor_ready:!!editor?.initialized,width:box?.width,height:box?.height,text:editor?.getContent({format:'text'}) || '',product_data_ready:!!document.querySelector('#woocommerce-product-data ul.product_data_tabs li.active') && getComputedStyle(document.querySelector('#inventory_product_data')).display === 'none' && getComputedStyle(document.querySelector('#general_product_data')).display !== 'none'})});
}, 500);
</script>
	<?php
} );

// Minimal, stateless MCP transport for the proof. It preserves CallToolResult
// envelopes, which the installed MCP Adapter 0.6.1 does not for normal handlers.
add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'aif-proof/v1', '/render-status', array( 'methods' => 'GET', 'permission_callback' => static fn() => current_user_can( 'manage_options' ), 'callback' => static fn() => array( 'bootstrap' => get_option( 'aif_proof_bootstrap_observed', array() ), 'render' => get_option( 'aif_proof_last_render', array() ) ) ) );
	register_rest_route( 'aif-proof/v1', '/mcp', array(
		'methods' => 'POST',
		'permission_callback' => static fn() => current_user_can( 'read' ),
		'callback' => static function ( WP_REST_Request $request ) {
			$rpc = $request->get_json_params();
			if ( ! isset( $rpc['id'] ) ) {
				return new WP_REST_Response( null, 202 );
			}
			try {
				switch ( $rpc['method'] ) {
					case 'initialize':
						$result = array( 'protocolVersion' => '2025-11-25', 'capabilities' => array( 'tools' => new stdClass(), 'resources' => new stdClass() ), 'serverInfo' => array( 'name' => 'native-admin-proof', 'version' => '0.1' ) );
						break;
					case 'tools/list':
						$result = array( 'tools' => array(
							array( 'name' => 'show-admin', 'description' => 'Show the original WordPress admin page.', 'inputSchema' => array( 'type' => 'object', 'properties' => array( 'path' => array( 'type' => 'string' ) ), 'required' => array( 'path' ) ), '_meta' => array( 'ui' => array( 'resourceUri' => AIF_PROOF_RESOURCE ) ) ),
							array( 'name' => 'open-session', 'description' => 'Create a short-lived native browser session for this authenticated user.', 'inputSchema' => array( 'type' => 'object', 'properties' => array( 'path' => array( 'type' => 'string' ), 'challenge' => array( 'type' => 'string' ) ), 'required' => array( 'path', 'challenge' ) ), '_meta' => array( 'ui' => array( 'visibility' => array( 'app' ) ) ), 'annotations' => array( 'readOnlyHint' => false ) ),
						) );
						break;
					case 'resources/read':
						if ( ( $rpc['params']['uri'] ?? '' ) !== AIF_PROOF_RESOURCE ) { throw new InvalidArgumentException( 'Unknown resource' ); }
						$result = array( 'contents' => array( array( 'uri' => AIF_PROOF_RESOURCE, 'mimeType' => 'text/html;profile=mcp-app', 'text' => file_get_contents( __DIR__ . '/dist/view.html' ), '_meta' => array( 'ui' => array( 'csp' => array( 'frameDomains' => array( untrailingslashit( home_url() ) ) ), 'prefersBorder' => true ) ) ) ) );
						break;
					case 'tools/call':
						$args = $rpc['params']['arguments'] ?? array();
						$path = aif_proof_path( $args['path'] ?? null );
						$result = array( 'content' => array( array( 'type' => 'text', 'text' => 'Open the original WordPress admin interface.' ) ), 'structuredContent' => array( 'path' => $path, 'origin' => untrailingslashit( home_url() ) ) );
						if ( 'open-session' === $rpc['params']['name'] ) {
							$viewer = aif_proof_viewer( $args['viewer_origin'] ?? AIF_PROOF_VIEW );
							// Compatibility for already-open MCP cards from before the product-ID change.
							if ( str_starts_with( $viewer, 'codex-sandbox://' ) && '/wp-admin/post.php?post=13&action=edit' === $path ) {
								if ( 'product' !== get_post_type( 12 ) ) { throw new InvalidArgumentException( 'Selected demo product is unavailable.' ); }
								$path = '/wp-admin/post.php?post=12&action=edit';
							}
							$challenge = $args['challenge'] ?? '';
							if ( ! is_string( $challenge ) || ! preg_match( '/^[a-f0-9]{64}$/D', $challenge ) ) { throw new InvalidArgumentException( 'Invalid challenge' ); }
							$ticket = bin2hex( random_bytes( 32 ) );
							add_option( 'aif_ticket_' . hash( 'sha256', $ticket ), array( 'user' => get_current_user_id(), 'challenge' => $challenge, 'path' => $path, 'viewer' => $viewer, 'expires' => time() + 60 ), '', false );
							$result['_meta'] = array( 'ticket' => $ticket );
						} elseif ( 'show-admin' !== $rpc['params']['name'] ) { throw new InvalidArgumentException( 'Unknown tool' ); }
						break;
					default:
						throw new InvalidArgumentException( 'Unsupported proof method' );
				}
				return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $rpc['id'], 'result' => $result ) );
			} catch ( Throwable $error ) {
				return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $rpc['id'], 'error' => array( 'code' => -32602, 'message' => $error->getMessage() ) ) );
			}
		},
	) );
} );
