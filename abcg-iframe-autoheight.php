<?php
/**
 * Plugin Name: ABCG Agenda Embed
 * Description: Serves the admin.abcg.ch agenda pages from this domain so the embedded iframe can size itself to its content. Provides the [abcg_agenda] shortcode.
 * Version:     1.3.0
 * Author:      ABCG
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABCG_EMBED_VERSION', '1.3.0' );
define( 'ABCG_EMBED_PATH', plugin_dir_path( __FILE__ ) );

require_once ABCG_EMBED_PATH . 'includes/class-abcg-embed-core.php';

/**
 * Pretty endpoint: /abcg-embed/{next|list|last|agnext}/
 */
function abcg_embed_add_rewrite() {
	add_rewrite_rule( '^abcg-embed/([a-z]+)/?$', 'index.php?abcg_embed=$matches[1]', 'top' );
}
add_action( 'init', 'abcg_embed_add_rewrite' );

function abcg_embed_query_vars( $vars ) {
	$vars[] = 'abcg_embed';
	return $vars;
}
add_filter( 'query_vars', 'abcg_embed_query_vars' );

function abcg_embed_activate() {
	abcg_embed_add_rewrite();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'abcg_embed_activate' );

function abcg_embed_deactivate() {
	delete_option( 'abcg_embed_transport' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'abcg_embed_deactivate' );

/**
 * Absolute URL of the proxy endpoint for a view.
 */
function abcg_embed_endpoint_url( $view ) {
	if ( get_option( 'permalink_structure' ) ) {
		return home_url( '/abcg-embed/' . $view . '/' );
	}
	return home_url( '/?abcg_embed=' . $view );
}

/**
 * Fallback transport when the direct cURL call fails.
 */
function abcg_embed_fetch_via_wp_http( $view, array $request, array $failed, $url_override = '' ) {
	$args = array(
		'timeout'     => 20,
		'redirection' => 0,
		'sslverify'   => true,
		'user-agent'  => ABCG_Embed_Core::UPSTREAM_UA,
		'headers'     => array(),
	);

	$cookie_str = ABCG_Embed_Core::forwarded_cookie_header( $request['cookies'] );
	if ( '' !== $cookie_str ) {
		$args['headers']['Cookie'] = $cookie_str;
	}

	if ( 'POST' === strtoupper( $request['method'] ) ) {
		$args['method']                  = 'POST';
		$args['body']                    = $request['body'];
		$args['headers']['Content-Type'] = $request['content_type'] ? $request['content_type'] : 'application/x-www-form-urlencoded';
	}

	$target = '' !== $url_override ? $url_override : ABCG_Embed_Core::upstream_url( $view );
	$result = wp_remote_request( $target, $args );

	if ( is_wp_error( $result ) ) {
		$failed['error'] .= ' | wp_http: ' . $result->get_error_message();
		return $failed;
	}

	$code = (int) wp_remote_retrieve_response_code( $result );
	if ( $code >= 400 ) {
		$failed['error'] .= ' | wp_http HTTP ' . $code;
		return $failed;
	}

	$set_cookie = wp_remote_retrieve_header( $result, 'set-cookie' );
	if ( is_string( $set_cookie ) ) {
		$set_cookie = '' === $set_cookie ? array() : array( $set_cookie );
	}

	return array(
		'status'       => $code ? $code : 200,
		'body'         => wp_remote_retrieve_body( $result ),
		'content_type' => wp_remote_retrieve_header( $result, 'content-type' ),
		'set_cookie'   => is_array( $set_cookie ) ? $set_cookie : array(),
		'location'     => wp_remote_retrieve_header( $result, 'location' ),
		'error'        => '',
	);
}

/**
 * Fetch one upstream URL, retrying briefly when upstream fails.
 *
 * admin.abcg.ch answers 500 to one request whenever three arrive at the same
 * instant -- reproducible straight against that host, with no cookies and no
 * WordPress in the path, so it is a limit of theirs rather than of this proxy.
 * The home page embeds three frames, so the burst happens on every single load.
 * Retrying after a short pause clears it: the same three requests spaced 400ms
 * apart all succeed. The wait happens server-side, so a visitor sees a slightly
 * slower frame instead of an error box.
 */
function abcg_embed_fetch( $view, array $request, $url_override = '' ) {
	$backoff = array( 250000, 600000, 1100000 );

	foreach ( $backoff as $attempt => $pause ) {
		$response = abcg_embed_fetch_once( $view, $request, $url_override );
		if ( empty( $response['error'] ) ) {
			if ( $attempt > 0 ) {
				$response['error'] = ''; // Recovered; nothing to report.
			}
			return $response;
		}

		if ( $attempt < count( $backoff ) - 1 ) {
			usleep( $pause );
		}
	}

	return $response;
}

/**
 * One fetch attempt, choosing the transport.
 *
 * Some hosts block direct cURL to another host; WordPress's own HTTP layer may
 * still get through. Whichever works is remembered, so the blocked transport is
 * not retried on every single request.
 */
function abcg_embed_fetch_once( $view, array $request, $url_override = '' ) {
	if ( 'wp_http' === get_option( 'abcg_embed_transport' ) ) {
		$response = abcg_embed_fetch_via_wp_http( $view, $request, array( 'error' => 'curl skipped' ), $url_override );
		if ( empty( $response['error'] ) ) {
			return $response;
		}

		/*
		 * Only reconsider the remembered transport if the other one actually
		 * works. Clearing the memo on any single failure made every later
		 * request open with a transport that fails here every time.
		 */
		$fallback = ABCG_Embed_Core::fetch( $view, $request, $url_override );
		if ( empty( $fallback['error'] ) ) {
			delete_option( 'abcg_embed_transport' );
			return $fallback;
		}

		return $response;
	}

	$response = ABCG_Embed_Core::fetch( $view, $request, $url_override );
	if ( empty( $response['error'] ) ) {
		return $response;
	}

	$response = abcg_embed_fetch_via_wp_http( $view, $request, $response, $url_override );
	if ( empty( $response['error'] ) ) {
		update_option( 'abcg_embed_transport', 'wp_http', false );
	}

	return $response;
}

/**
 * Serve the proxied agenda page. Runs before redirect_canonical so the
 * query-string form of the endpoint is not rewritten away.
 */
function abcg_embed_maybe_serve() {
	$view = get_query_var( 'abcg_embed' );
	if ( '' === $view || null === $view ) {
		$view = isset( $_GET['abcg_embed'] ) ? sanitize_key( wp_unslash( $_GET['abcg_embed'] ) ) : '';
	}
	if ( '' === $view ) {
		return;
	}

	if ( ! ABCG_Embed_Core::is_valid_view( $view ) ) {
		status_header( 404 );
		exit;
	}

	$request = array(
		'method'          => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
		'body'            => file_get_contents( 'php://input' ),
		'content_type'    => isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '',
		'user_agent'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		'accept_language' => isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '',
		'cookies'         => $_COOKIE,
		'ca_bundle'       => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
	);

	$response  = abcg_embed_fetch( $view, $request );
	$final_url = ABCG_Embed_Core::upstream_url( $view );

	/*
	 * Follow an upstream redirect here rather than passing it to the browser.
	 * The endpoint URL is the same on every hop, so answering a redirect with a
	 * redirect to ourselves could only ever loop -- which is exactly what phones
	 * hit, because admin.abcg.ch 302s mobile user agents. Bounded, and confined
	 * to the upstream host so this cannot be walked off-site.
	 */
	for ( $hop = 0; $hop < 3 && ! empty( $response['location'] ); $hop++ ) {
		$next = ABCG_Embed_Core::resolve_upstream_redirect( $response['location'], $final_url );
		if ( '' === $next || $next === $final_url ) {
			break;
		}
		// A redirect is always followed as a GET, never re-posting the body.
		$hop_request           = $request;
		$hop_request['method'] = 'GET';
		$hop_request['body']   = '';

		$hop_response = abcg_embed_fetch( $view, $hop_request, $next );
		if ( ! empty( $hop_response['error'] ) ) {
			break;
		}

		$final_url  = $next;
		$set_cookie = array_merge( $response['set_cookie'], $hop_response['set_cookie'] );
		$response   = $hop_response;

		$response['set_cookie'] = $set_cookie;
	}

	$proxy_url = abcg_embed_endpoint_url( $view );

	nocache_headers();
	header_remove( 'X-Frame-Options' );
	header( 'X-Frame-Options: SAMEORIGIN' );

	$proxy_path = wp_parse_url( $proxy_url, PHP_URL_PATH );
	foreach ( $response['set_cookie'] as $cookie ) {
		$rewritten = ABCG_Embed_Core::rewrite_set_cookie( $cookie, $proxy_path ? $proxy_path : '/' );
		if ( '' !== $rewritten ) {
			header( 'Set-Cookie: ' . $rewritten, false );
		}
	}

	/*
	 * Still redirecting after the hops above: serve a readable notice instead of
	 * sending the browser back to this same URL, which would only loop.
	 */
	if ( ! empty( $response['location'] ) ) {
		$response['status']       = 200;
		$response['content_type'] = 'text/html; charset=utf-8';
		$response['error']       .= ' | unresolved upstream redirect to ' . $response['location'];
		$response['body']         = '<!doctype html><meta charset="utf-8">'
			. '<p>Agenda momentanément indisponible.</p>';
	}

	status_header( $response['status'] );

	if ( stripos( $response['content_type'], 'text/html' ) === false ) {
		header( 'Content-Type: ' . $response['content_type'] );
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	$frame_id = isset( $_GET['abcgid'] ) ? sanitize_text_field( wp_unslash( $_GET['abcgid'] ) ) : '';

	// Visible only to an administrator, so a fetch failure can be diagnosed
	// without guessing at the host's outbound rules.
	if ( ! empty( $response['error'] ) && current_user_can( 'manage_options' ) ) {
		$response['body'] .= "\n<!-- abcg upstream error: "
			. esc_html( $response['error'] ) . " -->\n";
	}

	header( 'Content-Type: text/html; charset=utf-8' );
	echo ABCG_Embed_Core::transform( $response['body'], $view, $proxy_url, $frame_id, $final_url ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}
add_action( 'template_redirect', 'abcg_embed_maybe_serve', 0 );

/**
 * The listener is a real script file so it also covers embeds written as plain
 * markup — Divi code modules do not always run a nested shortcode.
 */
function abcg_embed_register_script() {
	wp_register_script(
		'abcg-embed-parent',
		plugins_url( 'assets/parent.js', __FILE__ ),
		array(),
		ABCG_EMBED_VERSION,
		true
	);

	if ( is_singular() ) {
		$post = get_post();
		if ( $post && false !== strpos( $post->post_content, 'abcg-embed-frame' ) ) {
			wp_enqueue_script( 'abcg-embed-parent' );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'abcg_embed_register_script' );

/**
 * [abcg_agenda view="next"] / [abcg_agenda view="list"]
 */
function abcg_embed_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'view'   => 'next',
			'height' => 300,
		),
		$atts,
		'abcg_agenda'
	);

	$view = sanitize_key( $atts['view'] );
	if ( ! ABCG_Embed_Core::is_valid_view( $view ) ) {
		return '';
	}

	static $count = 0;
	$count++;
	$frame_id = 'abcg-agenda-' . $view . '-' . $count;

	wp_enqueue_script( 'abcg-embed-parent' );

	return ABCG_Embed_Core::render_embed(
		$view,
		abcg_embed_endpoint_url( $view ),
		$frame_id,
		(int) $atts['height'],
		false
	);
}
add_shortcode( 'abcg_agenda', 'abcg_embed_shortcode' );
