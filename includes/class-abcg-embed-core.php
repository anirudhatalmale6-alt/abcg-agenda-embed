<?php
/**
 * Core proxy + HTML rewriting for the agenda embeds.
 *
 * Pure PHP on purpose: no WordPress functions are used in this file so the
 * same code can be exercised by the test harness before it goes live.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ABCG_EMBED_STANDALONE' ) ) {
	exit;
}

class ABCG_Embed_Core {

	/** Cookie prefix used to keep upstream cookies away from the site's own cookies. */
	const COOKIE_PREFIX = 'abcgpx_';

	/** Only these views may be requested. Prevents the endpoint being used as an open proxy. */
	public static function views() {
		return array(
			'next' => 'https://admin.abcg.ch/public/frmActNext.aspx',
			'list' => 'https://admin.abcg.ch/public/frmActLst.aspx',
		);
	}

	public static function is_valid_view( $view ) {
		return is_string( $view ) && isset( self::views()[ $view ] );
	}

	public static function upstream_url( $view ) {
		$views = self::views();
		return isset( $views[ $view ] ) ? $views[ $view ] : '';
	}

	/** Directory the upstream page lives in, used for the injected <base>. */
	public static function upstream_base( $view ) {
		$url = self::upstream_url( $view );
		return $url ? substr( $url, 0, strrpos( $url, '/' ) + 1 ) : '';
	}

	private static function asset( $name ) {
		$file = dirname( __DIR__ ) . '/assets/' . $name;
		return is_readable( $file ) ? file_get_contents( $file ) : '';
	}

	/**
	 * Fetch the upstream page, forwarding the browser's method, body and
	 * (de-prefixed) cookies so ASP.NET postbacks keep working.
	 *
	 * @return array{status:int,body:string,content_type:string,set_cookie:string[],location:string}
	 */
	public static function fetch( $view, array $request ) {
		$url = self::upstream_url( $view );

		$headers    = array( 'Accept-Language: ' . ( $request['accept_language'] ?: 'fr-CH,fr;q=0.9' ) );
		$cookie_str = self::forwarded_cookie_header( $request['cookies'] );
		if ( '' !== $cookie_str ) {
			$headers[] = 'Cookie: ' . $cookie_str;
		}

		$set_cookies = array();
		$location    = '';

		$ch = curl_init();
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_ENCODING       => '',
				CURLOPT_USERAGENT      => $request['user_agent'] ?: 'Mozilla/5.0',
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$set_cookies, &$location ) {
					if ( stripos( $line, 'Set-Cookie:' ) === 0 ) {
						$set_cookies[] = trim( substr( $line, 11 ) );
					} elseif ( stripos( $line, 'Location:' ) === 0 ) {
						$location = trim( substr( $line, 9 ) );
					}
					return strlen( $line );
				},
			)
		);

		if ( 'POST' === strtoupper( $request['method'] ) ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $request['body'] );
			$headers[] = 'Content-Type: ' . ( $request['content_type'] ?: 'application/x-www-form-urlencoded' );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		}

		$body         = curl_exec( $ch );
		$status       = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$content_type = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
		$error        = curl_error( $ch );
		curl_close( $ch );

		if ( false === $body || $status >= 400 ) {
			return array(
				'status'       => 502,
				'body'         => '<!doctype html><meta charset="utf-8"><p>Agenda momentanément indisponible.</p>',
				'content_type' => 'text/html; charset=utf-8',
				'set_cookie'   => array(),
				'location'     => '',
				'error'        => $error ? $error : ( 'upstream HTTP ' . $status ),
			);
		}

		return array(
			'status'       => $status ?: 200,
			'body'         => $body,
			'content_type' => $content_type ?: 'text/html; charset=utf-8',
			'set_cookie'   => $set_cookies,
			'location'     => $location,
			'error'        => '',
		);
	}

	/** Rebuild the upstream Cookie header from our prefixed browser cookies. */
	public static function forwarded_cookie_header( array $cookies ) {
		$pairs = array();
		foreach ( $cookies as $name => $value ) {
			if ( strpos( $name, self::COOKIE_PREFIX ) === 0 ) {
				$pairs[] = substr( $name, strlen( self::COOKIE_PREFIX ) ) . '=' . $value;
			}
		}
		return implode( '; ', $pairs );
	}

	/** Re-emit an upstream Set-Cookie under our prefix, scoped to the proxy path. */
	public static function rewrite_set_cookie( $set_cookie, $proxy_path ) {
		$parts = explode( ';', $set_cookie );
		$first = array_shift( $parts );
		if ( strpos( $first, '=' ) === false ) {
			return '';
		}
		list( $name, $value ) = explode( '=', $first, 2 );
		$name                 = trim( $name );
		if ( '' === $name ) {
			return '';
		}

		$out = self::COOKIE_PREFIX . $name . '=' . $value;
		foreach ( $parts as $attr ) {
			$attr = trim( $attr );
			// Drop upstream Domain/Path: the cookie now belongs to this site.
			if ( stripos( $attr, 'domain=' ) === 0 || stripos( $attr, 'path=' ) === 0 ) {
				continue;
			}
			if ( '' !== $attr ) {
				$out .= '; ' . $attr;
			}
		}
		$out .= '; Path=' . $proxy_path . '; SameSite=Lax';
		return $out;
	}

	/**
	 * Rewrite the upstream HTML so it works from this origin and reports its height.
	 *
	 * @param string $html      Upstream markup.
	 * @param string $view      next|list
	 * @param string $proxy_url Absolute URL of the proxy endpoint (form target).
	 * @param string $frame_id  Carried through postbacks so the reloaded page
	 *                          still knows which iframe it belongs to.
	 */
	public static function transform( $html, $view, $proxy_url, $frame_id = '' ) {
		if ( '' === trim( $html ) || ! self::is_valid_view( $view ) ) {
			return $html;
		}

		if ( '' !== $frame_id ) {
			$proxy_url .= ( strpos( $proxy_url, '?' ) === false ? '?' : '&' )
				. 'abcgid=' . rawurlencode( $frame_id );
		}

		$base = self::upstream_base( $view );

		// Assets and any relative link keep resolving against the upstream folder.
		if ( stripos( $html, '<base ' ) === false ) {
			$html = preg_replace(
				'#<head([^>]*)>#i',
				'<head$1><base href="' . htmlspecialchars( $base, ENT_QUOTES ) . '">',
				$html,
				1
			);
		}

		// ...but the ASP.NET postback must come back through us, not go direct.
		$html = preg_replace_callback(
			'#(<form\b[^>]*\baction=")([^"]*)(")#i',
			function ( $m ) use ( $proxy_url ) {
				return $m[1] . htmlspecialchars( $proxy_url, ENT_QUOTES ) . $m[3];
			},
			$html,
			1
		);

		$css  = self::asset( 'child.css' );
		$css .= self::asset( 'child-' . $view . '.css' );
		$js = self::asset( 'child.js' );

		$inject = '<style id="abcg-embed-fix">' . $css . '</style>'
			. '<script id="abcg-embed-reporter">' . $js . '</script>';

		if ( stripos( $html, '</body>' ) !== false ) {
			$html = preg_replace( '#</body>#i', $inject . '</body>', $html, 1 );
		} else {
			$html .= $inject;
		}

		return $html;
	}

	/**
	 * Markup for the embed: an auto-sizing iframe plus its listener.
	 *
	 * @param bool $inline_script Emit parent.js inline. WordPress enqueues it
	 *                            instead, so it passes false.
	 */
	public static function render_embed( $view, $proxy_url, $frame_id, $initial_height = 300, $inline_script = true ) {
		$src = $proxy_url . ( strpos( $proxy_url, '?' ) === false ? '?' : '&' ) . 'abcgid=' . rawurlencode( $frame_id );

		$html  = '<div class="abcg-embed-wrap">';
		$html .= '<iframe id="' . htmlspecialchars( $frame_id, ENT_QUOTES ) . '"'
			. ' class="abcg-embed-frame"'
			. ' src="' . htmlspecialchars( $src, ENT_QUOTES ) . '"'
			. ' width="100%" height="' . (int) $initial_height . '"'
			. ' frameborder="0"'
			. ' style="display:block;width:100%;border:0;"'
			. ' title="Agenda"></iframe>';
		$html .= '</div>';
		if ( $inline_script ) {
			$html .= '<script>' . self::asset( 'parent.js' ) . '</script>';
		}

		return $html;
	}
}
