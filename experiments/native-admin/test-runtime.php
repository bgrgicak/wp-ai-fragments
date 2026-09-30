<?php
/** Isolated WordPress fixtures for deployment and subdirectory regressions. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$environment = $argv[1] ?? 'production';
if ( false === getenv( 'AIF_PROOF_PUBLIC_ORIGIN' ) ) { putenv( 'AIF_PROOF_PUBLIC_ORIGIN=https://tunnel.example' ); }
$_SERVER['HTTP_X_AIF_PROOF_TLS'] = '1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$hooks = array();
$filters = array();
$transients = array();
$revoked = false;

function wp_get_environment_type() { return $GLOBALS['environment']; }
function add_action( $name, $callback, ...$args ) { $GLOBALS['hooks'][ $name ][] = $callback; }
function add_filter( $name, $callback, ...$args ) { $GLOBALS['filters'][ $name ][] = $callback; }
function admin_url( $path = '' ) { return 'https://wordpress.test/blog/wp-admin/' . $path; }
function home_url() { return 'https://frontend.test'; }
function site_url() { return 'https://wordpress.test/blog'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function untrailingslashit( $url ) { return rtrim( $url, '/' ); }
function add_query_arg( $key, $value, $url ) { return $url . '?' . http_build_query( array( $key => $value ) ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_unslash( $data ) { return $data; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; }
function wp_generate_auth_cookie( $user, $expires, $scheme, $token ) { return "$user|$expires|$scheme|$token"; }
function wp_validate_auth_cookie( $cookie, $scheme ) { return $GLOBALS['revoked'] ? 0 : (int) explode( '|', $cookie )[0]; }
function register_rest_route( $namespace, $route, $definition ) { $GLOBALS['route'] = $definition; }
class WP_REST_Request {
	public function __construct( public array $rpc ) {}
	public function get_json_params() { return $this->rpc; }
}
class WP_REST_Response {
	public function __construct( public $data, public int $status = 200 ) {}
}
class WP_Session_Tokens {
	public static int $created = 0;
	public static function get_instance( $user ) { return new self(); }
	public function create( $expires ) { return 'token-' . ++self::$created; }
}
function expect( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

require dirname( __DIR__, 2 ) . '/wp-ai-fragments.php';
expect( isset( $hooks['rest_api_init'] ), 'Runtime must load in every environment' );
expect( isset( $filters['pre_option_home'] ) === ( 'local' === $environment ), 'Demo headers must only override local site URLs' );
if ( 'local' === $environment ) {
	expect( $filters['pre_option_home'][0]() === aif_proof_origin( getenv( 'AIF_PROOF_PUBLIC_ORIGIN' ) ), 'Use the configured public origin' );
	expect( $filters['pre_option_siteurl'][0]() === $filters['pre_option_home'][0](), 'Home and site URLs must agree' );
	expect( $filters['script_loader_src'][0]( 'http://localhost:8888/wp-includes/test.js' ) === $filters['pre_option_home'][0]() . '/wp-includes/test.js', 'Assets must follow the configured origin' );
}

if ( in_array( '--bootstrap', $argv, true ) ) {
	$_GET = array( 'aif-proof-bootstrap' => '1', 'viewer' => 'http://127.0.0.1:8891' );
	$hooks['init'][0]();
	exit;
}

$hooks['rest_api_init'][0]();
$rpc = static function ( string $method, array $params = array() ) use ( $route ) {
	$response = $route['callback']( new WP_REST_Request( array( 'id' => 1, 'method' => $method, 'params' => $params ) ) );
	expect( ! isset( $response->data['error'] ), 'RPC must succeed: ' . $method );
	return $response->data['result'];
};
$display = $rpc( 'tools/call', array( 'name' => 'show_wp_admin', 'arguments' => array( 'url' => '/blog/wp-admin/options-writing.php?tab=1#settings' ) ) );
expect( $display['structuredContent']['path'] === '/blog/wp-admin/options-writing.php?tab=1#settings', 'Preserve admin query and fragment' );
expect( $display['structuredContent']['bootstrapUrl'] === 'https://wordpress.test/blog/wp-admin/admin-ajax.php?aif-proof-bootstrap=1', 'Bootstrap must use the admin installation path and origin' );
expect( count( $rpc( 'resources/list' )['resources'] ) === 1, 'Discover the viewer over HTTP' );
expect( $rpc( 'ping' ) instanceof stdClass, 'Ping must serialize as an empty object' );

$first = aif_proof_browser_session( 7, 'http://127.0.0.1:8891' );
$_COOKIE[ AIF_PROOF_COOKIE ] = $first['handle'];
$GLOBALS['aif_proof_active_session'] = $first['session'];
$second_viewer = 'codex-sandbox://mcp-app-' . str_repeat( 'a', 48 ) . '.web-sandbox.oaiusercontent.com';
$second = aif_proof_browser_session( 7, $second_viewer );
expect( $first['handle'] === $second['handle'] && $first['session']['logged_in'] === $second['session']['logged_in'], 'Cards must preserve cookie and nonce token' );
expect( $first['session']['expires'] === $second['session']['expires'], 'Reuse must not extend session lifetime' );
expect( count( $second['session']['viewers'] ) === 2, 'Retain both approved viewer origins' );
expect( WP_Session_Tokens::$created === 1, 'Second card must not mint a fresh WordPress token' );
try {
	aif_proof_browser_session( 8, $second_viewer );
	throw new RuntimeException( 'Different account must not replace a live shared session' );
} catch ( InvalidArgumentException $expected ) {}

$GLOBALS['revoked'] = true;
$replacement = aif_proof_browser_session( 7, $second_viewer );
expect( $replacement['handle'] !== $first['handle'], 'Revoked sessions must not be reused' );
$GLOBALS['revoked'] = false;
$GLOBALS['aif_proof_active_session']['expires'] = time() - 1;
$replacement = aif_proof_browser_session( 7, $second_viewer );
expect( $replacement['handle'] !== $first['handle'], 'Expired sessions must not be reused' );
echo "PASS: $environment runtime, subdirectory RPC URLs, session reuse and account/revocation boundaries\n";
