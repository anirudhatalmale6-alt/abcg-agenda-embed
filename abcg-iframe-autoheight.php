<?php
/**
 * Plugin Name: ABCG Agenda Embed
 * Description: Serves the admin.abcg.ch agenda pages from this domain so the embedded iframe can size itself to its content. Provides the [abcg_agenda] shortcode.
 * Version:     1.0.0
 * Author:      ABCG
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABCG_EMBED_VERSION', '1.0.0' );
define( 'ABCG_EMBED_PATH', plugin_dir_path( __FILE__ ) );

require_once ABCG_EMBED_PATH . 'includes/class-abcg-embed-core.php';

/**
 * Pretty endpoint: /abcg-embed/next/ and /abcg-embed/list/
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
function abcg_embed_fetch_via_wp_http( $view, array $request, array $failed ) {
	$args = array(
		'timeout'     => 20,
		'redirection' => 0,
		'sslverify'   => true,
		'user-agent'  => $request['user_agent'] ? $request['user_agent'] : 'Mozilla/5.0',
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

	$result = wp_remote_request( ABCG_Embed_Core::upstream_url( $view ), $args );

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
	);

	/*
	 * Some hosts block direct cURL to another host; WordPress's own HTTP layer
	 * may still get through. Whichever works is remembered, so the blocked
	 * transport is not retried on every single request.
	 */
	if ( 'wp_http' === get_option( 'abcg_embed_transport' ) ) {
		$response = abcg_embed_fetch_via_wp_http( $view, $request, array( 'error' => 'curl skipped' ) );
		if ( ! empty( $response['error'] ) ) {
			delete_option( 'abcg_embed_transport' );
			$response = ABCG_Embed_Core::fetch( $view, $request );
		}
	} else {
		$response = ABCG_Embed_Core::fetch( $view, $request );
		if ( ! empty( $response['error'] ) ) {
			$response = abcg_embed_fetch_via_wp_http( $view, $request, $response );
			if ( empty( $response['error'] ) ) {
				update_option( 'abcg_embed_transport', 'wp_http', false );
			}
		}
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

	if ( ! empty( $response['location'] ) ) {
		header( 'Location: ' . $proxy_url );
		status_header( 302 );
		exit;
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
	echo ABCG_Embed_Core::transform( $response['body'], $view, $proxy_url, $frame_id ); // phpcs:ignore WordPress.Security.EscapeOutput
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
