<?php
/** Native wp-admin MCP Apps transport and browser session handoff. */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Test-fixture URL configuration for the optional local trusted-TLS proxy.
// A deployed plugin would use the site's existing HTTPS configuration.
$aif_proof_local = 'local' === wp_get_environment_type();
$aif_proof_public_origin = '';
if ( $aif_proof_local ) {
	$aif_proof_public_origin = getenv( 'AIF_PROOF_PUBLIC_ORIGIN' ) ?: ( file_exists( __DIR__ . '/.https-demo' ) ? trim( file_get_contents( __DIR__ . '/.https-demo' ) ) : '' );
	if ( '' !== $aif_proof_public_origin ) {
		$parts = wp_parse_url( $aif_proof_public_origin );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ! in_array( $parts['path'] ?? '', array( '', '/' ), true ) ) {
			throw new InvalidArgumentException( 'AIF_PROOF_PUBLIC_ORIGIN must be an HTTPS origin without credentials, a path, query, or fragment.' );
		}
		$aif_proof_public_origin = aif_proof_origin( $aif_proof_public_origin );
	}
}
$aif_proof_tls = $aif_proof_local && ( ( 'wp-mcp-demo.test' === ( $_SERVER['HTTP_HOST'] ?? '' ) && 'https' === ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) || '1' === ( $_SERVER['HTTP_X_AIF_PROOF_TLS'] ?? '' ) || ( '' !== $aif_proof_public_origin && str_contains( $_SERVER['REQUEST_URI'] ?? '', '/aif-proof/v1/mcp' ) ) );
if ( $aif_proof_tls ) {
	$_SERVER['HTTPS'] = 'on';
	$aif_proof_public_origin = $aif_proof_public_origin ?: 'https://aif-proof-wp.test:8892';
	add_filter( 'pre_option_home', static fn() => $aif_proof_public_origin );
	add_filter( 'pre_option_siteurl', static fn() => $aif_proof_public_origin );
	foreach ( array( 'plugins_url', 'content_url', 'script_loader_src', 'style_loader_src' ) as $url_filter ) {
		add_filter( $url_filter, static fn( $url ) => str_replace( array( 'http://localhost:8888', 'https://localhost:8888' ), $aif_proof_public_origin, $url ), 1000 );
	}
}
define( 'AIF_PROOF_VIEW', $aif_proof_tls ? 'https://aif-proof-view.test:8891' : 'http://127.0.0.1:8891' );
define( 'AIF_PROOF_HOST', $aif_proof_tls ? 'https://aif-proof-host.test:8890' : 'http://127.0.0.1:8890' );
const AIF_PROOF_COOKIE = '__Host-aif-proof';
const AIF_PROOF_RESOURCE = 'ui://wp-ai-fragments/wp-admin-v1.html';

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
	$viewers = aif_proof_session_viewers( $GLOBALS['aif_proof_active_session'] ?? array() );
	if ( isset( $GLOBALS['aif_proof_bootstrap_viewer'] ) ) {
		$viewers[] = $GLOBALS['aif_proof_bootstrap_viewer'];
	}
	header_remove( 'X-Frame-Options' );
	header( "Content-Security-Policy: frame-ancestors 'self' " . AIF_PROOF_HOST . ' ' . AIF_PROOF_VIEW . ' ' . implode( ' ', array_unique( $viewers ) ) . ( in_array( 'http://127.0.0.1:8891', $viewers, true ) ? ' http://127.0.0.1:8890' : '' ) );
	header( 'Cache-Control: private, no-store' );
	header( 'Referrer-Policy: no-referrer' );
}

function aif_proof_session_viewers( array $session ): array {
	return $session['viewers'] ?? array( $session['viewer'] ?? AIF_PROOF_VIEW );
}

/** Keep the cookie and WordPress nonce token stable across cards in one partition. */
function aif_proof_browser_session( int $user_id, string $viewer ): array {
	$session = $GLOBALS['aif_proof_active_session'] ?? null;
	if ( $session && $session['expires'] > time() && wp_validate_auth_cookie( $session['logged_in'], 'logged_in' ) === $session['user'] ) {
		if ( $session['user'] !== $user_id ) {
			throw new InvalidArgumentException( 'Another WordPress account is already connected in this browser partition.' );
		}
		$handle = $_COOKIE[ AIF_PROOF_COOKIE ];
		$session['viewers'] = array_values( array_unique( array_merge( aif_proof_session_viewers( $session ), array( $viewer ) ) ) );
	} else {
		$expires = time() + 20 * MINUTE_IN_SECONDS;
		$token = WP_Session_Tokens::get_instance( $user_id )->create( $expires );
		$handle = bin2hex( random_bytes( 32 ) );
		$session = array(
			'user' => $user_id,
			'viewer' => $viewer,
			'viewers' => array( $viewer ),
			'expires' => $expires,
			'auth' => wp_generate_auth_cookie( $user_id, $expires, 'auth', $token ),
			'secure_auth' => wp_generate_auth_cookie( $user_id, $expires, 'secure_auth', $token ),
			'logged_in' => wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token ),
		);
	}
	set_transient( 'aif_session_' . hash( 'sha256', $handle ), $session, $session['expires'] - time() );
	return array( 'handle' => $handle, 'session' => $session );
}

// Local demo host profile. Only the installed Codex app's isolated MCP origin,
// or the original test viewer, can receive this proof's browser handoff.
function aif_proof_viewer( $origin ): string {
	if ( 'http://127.0.0.1:8891' === $origin || AIF_PROOF_VIEW === $origin || ( is_string( $origin ) && preg_match( '~^codex-sandbox://mcp-app-[a-f0-9]{48}\.web-sandbox\.oaiusercontent\.com$~D', $origin ) ) ) {
		return $origin;
	}
	throw new InvalidArgumentException( 'Unapproved local MCP viewer origin.' );
}

/** Normalize a same-site admin URL without changing its query or fragment. */
function aif_proof_origin( string $url ): string {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
		throw new InvalidArgumentException( 'Expected a same-site WordPress admin URL.' );
	}
	$scheme = strtolower( $parts['scheme'] );
	$port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
	return $scheme . '://' . strtolower( $parts['host'] ) . ( ( 'https' === $scheme ? 443 : 80 ) === $port ? '' : ':' . $port );
}

function aif_proof_path( $url ): string {
	if ( ! is_string( $url ) || '' === $url || preg_match( '/[\\\\\x00-\x20\x7f]/', $url ) ) {
		throw new InvalidArgumentException( 'Expected a same-site /wp-admin/ URL.' );
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		throw new InvalidArgumentException( 'Invalid WordPress admin URL.' );
	}
	if ( isset( $parts['scheme'] ) || isset( $parts['host'] ) ) {
		$allowed = array( aif_proof_origin( home_url() ), aif_proof_origin( site_url() ) );
		if ( ! in_array( aif_proof_origin( $url ), $allowed, true ) ) {
			throw new InvalidArgumentException( 'The admin URL must belong to this WordPress site.' );
		}
	}
	$path = $parts['path'] ?? '';
	$admin_path = wp_parse_url( admin_url(), PHP_URL_PATH );
	if ( untrailingslashit( $admin_path ) === $path ) {
		$path .= '/';
	}
	$decoded = rawurldecode( $path );
	if ( ! str_starts_with( $path, $admin_path ) || ! str_starts_with( $decoded, $admin_path ) || preg_match( '/[\\\\%\x00-\x20\x7f]/', $decoded ) || str_contains( $decoded, '//' ) || preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', $decoded ) ) {
		throw new InvalidArgumentException( 'Expected a path inside this site’s /wp-admin/ directory.' );
	}
	parse_str( $parts['query'] ?? '', $query );
	if ( $admin_path . 'post.php' === $path && 'edit' === ( $query['action'] ?? '' ) ) {
		$post = get_post( absint( $query['post'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			throw new InvalidArgumentException( 'Select an existing editable post.' );
		}
	}
	return $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
}

// Embedded posts and pages use Gutenberg; ordinary sessions keep their editor.
add_filter( 'use_block_editor_for_post', static function ( $use, $post ) {
	if ( ! empty( $GLOBALS['aif_proof_active_session'] ) && in_array( $post->post_type, array( 'post', 'page' ), true ) && current_user_can( 'edit_post', $post->ID ) ) {
		return true;
	}
	return $use;
}, PHP_INT_MAX, 2 );

// Apply the exception to authenticated embedded document requests throughout
// native navigation, including POST -> 302 -> GET, never to ordinary sessions.
add_action( 'init', static function (): void {
	$session = $GLOBALS['aif_proof_active_session'] ?? null;
	if ( isset( $_GET['aif-proof-session-check'] ) ) {
		wp_send_json_success( array( 'authenticated' => is_user_logged_in(), 'embedded' => (bool) $session ) );
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
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		if ( ( $_SERVER['HTTP_ORIGIN'] ?? '' ) !== aif_proof_origin( admin_url() ) ) {
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
		try {
			$connection = aif_proof_browser_session( $user->ID, $grant['viewer'] ?? AIF_PROOF_VIEW );
		} catch ( InvalidArgumentException $error ) {
			wp_send_json_error( $error->getMessage(), 409 );
		}
		$max_age = $connection['session']['expires'] - time();
		header( 'Set-Cookie: ' . AIF_PROOF_COOKIE . '=' . $connection['handle'] . '; Path=/; Max-Age=' . $max_age . '; Secure; HttpOnly; SameSite=None; Partitioned', false );
		wp_send_json_success( array( 'url' => aif_proof_origin( admin_url() ) . $grant['path'] ) );
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!doctype html><html><head><meta charset="utf-8"><title>Connect native WordPress</title></head>
<body><script>
const viewerOrigin = <?php echo wp_json_encode( $GLOBALS['aif_proof_bootstrap_viewer'] ); ?>;
const sessionCheckUrl = <?php echo wp_json_encode( add_query_arg( 'aif-proof-session-check', '1', admin_url( 'admin-ajax.php' ) ) ); ?>;
let redeemed = false;
addEventListener('message', async (event) => {
  if (event.source !== parent || event.origin !== viewerOrigin || event.data?.type !== 'aif-handoff' || redeemed) return;
  redeemed = true;
  try {
    const redeem = async () => {
      const response = await fetch(location.href, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify(event.data)});
      const result = await response.json();
      if (!response.ok || !result.success) throw Error('Session handoff failed');
      const check = await (await fetch(sessionCheckUrl, {credentials:'same-origin'})).json();
      if (!check.data.authenticated || !check.data.embedded) throw Error('Partitioned session cookie did not survive');
      parent.postMessage({type:'aif-admin-navigating'}, viewerOrigin);
      location.replace(result.data.url);
    };
    // Serialize simultaneous cards so the next request sees the shared cookie.
    if (navigator.locks) await navigator.locks.request('aif-proof-session', redeem);
    else await redeem();
  } catch (error) { parent.postMessage({type:'aif-session-error'}, viewerOrigin); }
});
parent.postMessage({type:'aif-bootstrap-ready'}, viewerOrigin);
</script></body></html>
	<?php
	exit;
}, 1 );

// Match the existing Gutenberg card's native admin presentation.
add_action( 'admin_head', static function (): void {
	if ( empty( $GLOBALS['aif_proof_active_session'] ) ) {
		return;
	}
	echo '<style>html.wp-toolbar{padding-top:0!important}#wpadminbar,#adminmenumain,#wpfooter{display:none!important}#wpcontent{margin-left:0!important}.interface-interface-skeleton{top:0!important;left:0!important}</style>';
}, 1000 );

function aif_proof_admin_ready(): void {
	if ( empty( $GLOBALS['aif_proof_active_session'] ) ) {
		return;
	}
	foreach ( aif_proof_session_viewers( $GLOBALS['aif_proof_active_session'] ) as $viewer ) {
		echo '<script>parent.postMessage({type:"aif-admin-ready",title:document.title},' . wp_json_encode( $viewer ) . ');</script>';
	}
}
add_action( 'admin_footer', 'aif_proof_admin_ready' );
add_action( 'customize_controls_print_footer_scripts', 'aif_proof_admin_ready' );

// Stateless MCP transport preserving native MCP Apps result envelopes.
add_action( 'rest_api_init', static function (): void {
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
					case 'ping':
						$result = new stdClass();
						break;
					case 'initialize':
						$result = array( 'protocolVersion' => '2025-11-25', 'capabilities' => array( 'tools' => new stdClass(), 'resources' => new stdClass() ), 'serverInfo' => array( 'name' => 'wp-native-admin', 'version' => '0.3.0' ) );
						break;
					case 'tools/list':
						$result = array( 'tools' => array(
							array( 'name' => 'show_wp_admin', 'description' => 'Display a same-site WordPress admin URL in the native interactive admin interface.', 'inputSchema' => array( 'type' => 'object', 'properties' => array( 'url' => array( 'type' => 'string' ) ), 'required' => array( 'url' ), 'additionalProperties' => false ), 'annotations' => array( 'readOnlyHint' => true ), '_meta' => array( 'ui' => array( 'resourceUri' => AIF_PROOF_RESOURCE ), 'openai/outputTemplate' => AIF_PROOF_RESOURCE ) ),
							array( 'name' => 'open-session', 'description' => 'Create a short-lived native browser session for this authenticated user.', 'inputSchema' => array( 'type' => 'object', 'properties' => array( 'path' => array( 'type' => 'string' ), 'challenge' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ), 'viewer_origin' => array( 'type' => 'string' ) ), 'required' => array( 'path', 'challenge', 'viewer_origin' ), 'additionalProperties' => false ), '_meta' => array( 'ui' => array( 'visibility' => array( 'app' ) ) ), 'annotations' => array( 'readOnlyHint' => false ) ),
						) );
						break;
					case 'resources/list':
						$result = array( 'resources' => array( array( 'uri' => AIF_PROOF_RESOURCE, 'name' => 'Native WordPress admin', 'mimeType' => 'text/html;profile=mcp-app' ) ) );
						break;
					case 'resources/templates/list':
						$result = array( 'resourceTemplates' => array() );
						break;
					case 'resources/read':
						if ( ( $rpc['params']['uri'] ?? '' ) !== AIF_PROOF_RESOURCE ) { throw new InvalidArgumentException( 'Unknown resource' ); }
						$result = array( 'contents' => array( array( 'uri' => AIF_PROOF_RESOURCE, 'mimeType' => 'text/html;profile=mcp-app', 'text' => file_get_contents( __DIR__ . '/dist/view.html' ), '_meta' => array( 'ui' => array( 'csp' => array( 'frameDomains' => array( aif_proof_origin( admin_url() ) ) ), 'prefersBorder' => true ) ) ) ) );
						break;
					case 'tools/call':
						$args = $rpc['params']['arguments'] ?? array();
						$path = aif_proof_path( 'show_wp_admin' === $rpc['params']['name'] ? ( $args['url'] ?? null ) : ( $args['path'] ?? null ) );
						$result = array( 'content' => array( array( 'type' => 'text', 'text' => 'Open the original WordPress admin interface.' ) ), 'structuredContent' => array( 'path' => $path, 'origin' => aif_proof_origin( admin_url() ), 'bootstrapUrl' => add_query_arg( 'aif-proof-bootstrap', '1', admin_url( 'admin-ajax.php' ) ) ) );
						if ( 'open-session' === $rpc['params']['name'] ) {
							$viewer = aif_proof_viewer( $args['viewer_origin'] ?? null );
							$challenge = $args['challenge'] ?? '';
							if ( ! is_string( $challenge ) || ! preg_match( '/^[a-f0-9]{64}$/D', $challenge ) ) { throw new InvalidArgumentException( 'Invalid challenge' ); }
							$ticket = bin2hex( random_bytes( 32 ) );
							add_option( 'aif_ticket_' . hash( 'sha256', $ticket ), array( 'user' => get_current_user_id(), 'challenge' => $challenge, 'path' => $path, 'viewer' => $viewer, 'expires' => time() + 60 ), '', false );
							$result['_meta'] = array( 'ticket' => $ticket );
						} elseif ( 'show_wp_admin' === $rpc['params']['name'] ) {
							$result['_meta'] = array( 'ui' => array( 'resourceUri' => AIF_PROOF_RESOURCE ), 'openai/outputTemplate' => AIF_PROOF_RESOURCE );
						} else {
							throw new InvalidArgumentException( 'Unknown tool' );
						}
						break;
					default:
						throw new InvalidArgumentException( 'Unsupported MCP method' );
				}
				return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $rpc['id'], 'result' => $result ) );
			} catch ( Throwable $error ) {
				return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $rpc['id'], 'error' => array( 'code' => -32602, 'message' => $error->getMessage() ) ) );
			}
		},
	) );
} );
