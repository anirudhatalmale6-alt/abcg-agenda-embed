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

	$response  = ABCG_Embed_Core::fetch( $view, $request );
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

	header( 'Content-Type: text/html; charset=utf-8' );
	echo ABCG_Embed_Core::transform( $response['body'], $view, $proxy_url, $frame_id ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}
add_action( 'template_redirect', 'abcg_embed_maybe_serve', 0 );

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

	return ABCG_Embed_Core::render_embed(
		$view,
		abcg_embed_endpoint_url( $view ),
		$frame_id,
		(int) $atts['height']
	);
}
add_shortcode( 'abcg_agenda', 'abcg_embed_shortcode' );
