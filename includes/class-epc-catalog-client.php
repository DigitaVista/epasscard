<?php
/**
 * WebCartisan catalog client for consumer plugins (API fetch only).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'EPC_Catalog_Client', false ) ) {
	return;
}

$hub_client = WP_PLUGIN_DIR . '/webcartisan-plugin-hub/includes/class-catalog-client.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
if ( file_exists( $hub_client ) ) {
	require_once $hub_client;
	return;
}

/**
 * Remote catalog client when hub plugin is not installed locally.
 */
class EPC_Catalog_Client {

	const DEFAULT_API = 'https://webcartisan.com/wp-json/webcartisan/v1/catalog';

	/**
	 * Render catalog tab markup.
	 *
	 * @param array<string, string> $args Arguments.
	 * @return void
	 */
	public static function render_tab( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'host_slug' => '',
				'api_url'   => '',
			)
		);

		$payload = self::fetch_catalog( $args['api_url'], $args['host_slug'] );

		if ( is_wp_error( $payload ) ) {
			self::render_error( $payload );
			return;
		}

		if ( ! empty( $payload['css'] ) ) {
			printf(
				'<style id="wcs-catalog-remote-css" data-version="%s">%s</style>',
				esc_attr( (string) ( $payload['version'] ?? '' ) ),
				self::sanitize_css( $payload['css'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS is stripped and sanitized before output.
			);
		} elseif ( ! empty( $payload['css_url'] ) ) {
			wp_enqueue_style(
				'wcs-catalog-remote',
				esc_url( $payload['css_url'] ),
				array(),
				(string) ( $payload['version'] ?? '1' )
			);
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Remote HTML is passed through wp_kses() in sanitize_html().
		echo self::sanitize_html( (string) ( $payload['html'] ?? '' ) );
	}

	/**
	 * Sanitize remote catalog HTML.
	 *
	 * Scripts, inline event handlers and other unsafe markup are removed; layout,
	 * links, images and inline SVG icons are kept.
	 *
	 * @since 1.0.9
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function sanitize_html( $html ) {
		return wp_kses( (string) $html, self::get_allowed_html() );
	}

	/**
	 * Allowed tags for the remote catalog.
	 *
	 * @since 1.0.9
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function get_allowed_html() {
		$allowed = wp_kses_allowed_html( 'post' );

		$common = array(
			'class'        => true,
			'id'           => true,
			'style'        => true,
			'aria-hidden'  => true,
			'aria-label'   => true,
			'role'         => true,
			'focusable'    => true,
			'fill'         => true,
			'stroke'       => true,
			'stroke-width' => true,
			'opacity'      => true,
			'transform'    => true,
		);

		$svg_tags = array(
			'svg'      => array(
				'xmlns'               => true,
				'viewbox'             => true,
				'width'               => true,
				'height'              => true,
				'preserveaspectratio' => true,
			),
			'g'        => array(),
			'path'     => array(
				'd'               => true,
				'fill-rule'       => true,
				'clip-rule'       => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			),
			'circle'   => array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			),
			'rect'     => array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
			),
			'line'     => array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			),
			'polyline' => array( 'points' => true ),
			'polygon'  => array( 'points' => true ),
			'title'    => array(),
		);

		foreach ( $svg_tags as $tag => $attrs ) {
			$allowed[ $tag ] = array_merge( $common, $attrs );
		}

		foreach ( array( 'a', 'div', 'span', 'img', 'button', 'section', 'article', 'ul', 'li', 'p', 'h2', 'h3', 'h4' ) as $tag ) {
			if ( isset( $allowed[ $tag ] ) && is_array( $allowed[ $tag ] ) ) {
				$allowed[ $tag ] = array_merge( $allowed[ $tag ], $common, array( 'target' => true, 'rel' => true, 'loading' => true, 'srcset' => true, 'sizes' => true, 'type' => true ) );
			}
		}

		/**
		 * Filter allowed HTML for the remote plugin catalog.
		 *
		 * @since 1.0.9
		 *
		 * @param array $allowed wp_kses allowed HTML.
		 */
		return (array) apply_filters( 'epc_catalog_allowed_html', $allowed );
	}

	/**
	 * Fetch catalog payload.
	 *
	 * @param string $api_url   API URL override.
	 * @param string $host_slug Host plugin slug.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function fetch_catalog( $api_url = '', $host_slug = '' ) {
		if ( class_exists( 'WCS_Catalog_Renderer' ) && class_exists( 'WCS_Catalog_Data' ) ) {
			$renderer = WCS_Catalog_Renderer::instance();
			$data     = WCS_Catalog_Data::instance();
			return array(
				'version' => $data->get_catalog_version(),
				'html'    => $renderer->render( $host_slug ),
				'css'     => $renderer->get_css(),
				'css_url' => defined( 'WCS_HUB_URL' ) ? WCS_HUB_URL . 'assets/css/catalog.css' : '',
			);
		}

		$api_url = $api_url ? $api_url : self::get_api_url();
		if ( $host_slug ) {
			$api_url = add_query_arg( 'host', $host_slug, $api_url );
		}

		// Cache the remote catalog so the admin page does not block on every load.
		$cache_key = 'epc_catalog_' . md5( $api_url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			if ( isset( $cached['__error'] ) ) {
				return new WP_Error( 'epc_catalog_unavailable', (string) $cached['__error'] );
			}
			return $cached;
		}

		$result = self::request_catalog( $api_url );
		if ( is_wp_error( $result ) ) {
			// Short negative cache to avoid a slow request on every page view while offline.
			set_transient( $cache_key, array( '__error' => $result->get_error_message() ), 30 * MINUTE_IN_SECONDS );
			return $result;
		}

		set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Perform the remote catalog request.
	 *
	 * @since 1.0.9
	 *
	 * @param string $api_url Catalog URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function request_catalog( $api_url ) {
		$response = wp_safe_remote_get(
			$api_url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'        => 'application/json',
					'Cache-Control' => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'epc_catalog_http', sprintf( 'Catalog API returned HTTP %d.', $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['html'] ) ) {
			return new WP_Error( 'epc_catalog_invalid', 'Catalog API returned an invalid payload.' );
		}

		return $body;
	}

	/**
	 * Catalog API URL.
	 *
	 * @return string
	 */
	public static function get_api_url() {
		return (string) apply_filters( 'webcartisan_catalog_api_url', self::DEFAULT_API ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Strip unsafe CSS.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	private static function sanitize_css( $css ) {
		$css = wp_strip_all_tags( (string) $css );
		$css = preg_replace( '/expression\s*\(/i', '', $css );
		$css = preg_replace( '/(javascript|vbscript)\s*:/i', '', (string) $css );
		$css = preg_replace( '/(-moz-binding|behavior)\s*:/i', '', (string) $css );
		return $css ?? '';
	}

	/**
	 * Render fetch error fallback.
	 *
	 * @param \WP_Error $error Error.
	 * @return void
	 */
	private static function render_error( WP_Error $error ) {
		?>
		<div class="notice notice-warning inline wcs-catalog-fallback">
			<p>
				<strong><?php esc_html_e( 'Could not load the WebCartisan plugin catalog.', 'epasscard' ); ?></strong>
				<?php echo esc_html( $error->get_error_message() ); ?>
			</p>
			<p>
				<a href="https://profiles.wordpress.org/wooxperto/#content-plugins" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Browse our plugins on WordPress.org', 'epasscard' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
