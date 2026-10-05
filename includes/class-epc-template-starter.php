<?php
/**
 * Ready-made pass designs per integration ("Create a pass design for me").
 *
 * Policy: one integration (module) = one EpassCard template. The template is
 * created through the v2 simplified API, its fields are mapped exactly (the
 * plugin defines them), and the mapping is applied to every plan / event /
 * product of that integration that is not mapped yet. Existing mappings are
 * never changed.
 *
 * The design can be customized before it is created and edited afterwards
 * (logo, strip image, colors, labels, barcode), like the WooCommerce Loyalty
 * pass designer.
 *
 * @package EpassCard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EPC_Template_Starter
 */
class EPC_Template_Starter {

	/**
	 * Option prefix for the created template, per module slug.
	 */
	const OPTION_PREFIX = 'epc_starter_template_';

	/**
	 * Largest image (bytes) sent inline as a data URI.
	 */
	const MAX_INLINE_IMAGE_BYTES = 1000000;

	/**
	 * Allowed barcode formats.
	 *
	 * @var string[]
	 */
	private static $barcode_formats = array( 'QR', 'PDF417', 'AZTEC', 'CODE128' );

	/**
	 * Whether the designer modal was printed on this request.
	 *
	 * @var bool
	 */
	private static $modal_printed = false;

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_epc_starter_template', array( __CLASS__, 'ajax_handle' ) );
	}

	/**
	 * Placeholder field definition helper.
	 *
	 * @param string $name     Placeholder name (stable, used for mapping).
	 * @param string $label    Label shown on the pass.
	 * @param string $source   Module source field slug.
	 * @param bool   $required Required in EpassCard.
	 * @param bool   $unique   Unique in EpassCard.
	 * @param string $sample   Sample value for the admin preview.
	 * @return array<string, mixed>
	 */
	private static function field( $name, $label, $source, $required = false, $unique = false, $sample = '' ) {
		return array(
			'name'     => $name,
			'label'    => $label,
			'source'   => $source,
			'required' => $required,
			'unique'   => $unique,
			'sample'   => $sample,
		);
	}

	/**
	 * Membership-style preset.
	 *
	 * @param array<string, string> $src Sources: status, plan, expires, id.
	 * @param string                $expires_label Label for the expiry field.
	 * @return array<string, mixed>
	 */
	private static function membership_preset( array $src, $expires_label = '' ) {
		return array(
			'type'      => 'membership',
			'card_type' => 'StoreCard',
			'header'    => array( self::field( 'Status', __( 'Status', 'epasscard' ), $src['status'], false, false, __( 'Active', 'epasscard' ) ) ),
			'primary'   => array( self::field( 'Plan', __( 'Plan', 'epasscard' ), $src['plan'], false, false, __( 'Gold Membership', 'epasscard' ) ) ),
			'secondary' => array(
				self::field( 'Name', __( 'Name', 'epasscard' ), 'user_full_name', false, false, 'Alex Rivera' ),
				self::field( 'Expires', '' !== $expires_label ? $expires_label : __( 'Expires', 'epasscard' ), $src['expires'], false, false, '2027-12-31' ),
			),
			'auxiliary' => array(),
			'back'      => array(
				self::field( 'Email', __( 'Email', 'epasscard' ), 'user_email', false, false, 'alex@example.com' ),
			),
			'barcode'   => self::field( 'Member ID', __( 'Member ID', 'epasscard' ), $src['id'], true, true, '10042' ),
		);
	}

	/**
	 * Event-style preset.
	 *
	 * @param array<string, string> $src           Sources: date, event, ticket, venue, id.
	 * @param array<int, array>     $back          Extra back fields.
	 * @param string                $ticket_label  Label for the ticket field.
	 * @param string                $id_label      Label for the ticket number.
	 * @param string                $ticket_sample Sample ticket value.
	 * @return array<string, mixed>
	 */
	private static function event_preset( array $src, array $back, $ticket_label, $id_label, $ticket_sample = '' ) {
		return array(
			'type'      => 'events',
			'card_type' => 'Event',
			'header'    => array( self::field( 'Date', __( 'Date', 'epasscard' ), $src['date'], false, false, '2026-11-20 18:00' ) ),
			'primary'   => array( self::field( 'Event', __( 'Event', 'epasscard' ), $src['event'], false, false, __( 'Summer Concert', 'epasscard' ) ) ),
			'secondary' => array(
				self::field( 'Name', __( 'Name', 'epasscard' ), 'user_full_name', false, false, 'Alex Rivera' ),
				self::field( 'Ticket', $ticket_label, $src['ticket'], false, false, '' !== $ticket_sample ? $ticket_sample : __( 'General admission', 'epasscard' ) ),
			),
			'auxiliary' => array(
				self::field( 'Venue', __( 'Venue', 'epasscard' ), $src['venue'], false, false, __( 'City Hall', 'epasscard' ) ),
			),
			'back'      => array_merge(
				$back,
				array( self::field( 'Email', __( 'Email', 'epasscard' ), 'user_email', false, false, 'alex@example.com' ) )
			),
			'barcode'   => self::field( 'Ticket No', $id_label, $src['id'], true, true, '58213' ),
		);
	}

	/**
	 * Gift-card-style preset.
	 *
	 * @param string $to_source   Source for the recipient.
	 * @param string $from_source Source for the sender.
	 * @return array<string, mixed>
	 */
	private static function gift_card_preset( $to_source, $from_source ) {
		return array(
			'type'      => 'gift-cards',
			'card_type' => 'StoreCard',
			'header'    => array( self::field( 'Expires', __( 'Expires', 'epasscard' ), 'expire_date', false, false, '2027-12-31' ) ),
			'primary'   => array( self::field( 'Balance', __( 'Balance', 'epasscard' ), 'balance_formatted', false, false, '$50.00' ) ),
			'secondary' => array(
				self::field( 'To', __( 'To', 'epasscard' ), $to_source, false, false, 'Sam Lee' ),
				self::field( 'From', __( 'From', 'epasscard' ), $from_source, false, false, 'Alex Rivera' ),
			),
			'auxiliary' => array(),
			'back'      => array(
				self::field( 'Gift card', __( 'Gift card', 'epasscard' ), 'product_name', false, false, __( 'Store gift card', 'epasscard' ) ),
				self::field( 'Message', __( 'Message', 'epasscard' ), 'message', false, false, __( 'Happy birthday!', 'epasscard' ) ),
			),
			'barcode'   => self::field( 'Card code', __( 'Card code', 'epasscard' ), 'card_number', true, true, 'GIFT-7Q2K-91XZ' ),
		);
	}

	/**
	 * Preset per module slug.
	 *
	 * @param string $slug Module slug.
	 * @return array<string, mixed>|null
	 */
	public static function get_preset( $slug ) {
		$presets = array(
			'memberpress'               => self::membership_preset(
				array(
					'status'  => 'membership_status',
					'plan'    => 'membership_title',
					'expires' => 'membership_expires',
					'id'      => 'membership_id',
				)
			),
			'paid-memberships-pro'      => self::membership_preset(
				array(
					'status'  => 'membership_status',
					'plan'    => 'level_name',
					'expires' => 'expire_date',
					'id'      => 'membership_row_id',
				)
			),
			'simple-membership'         => self::membership_preset(
				array(
					'status'  => 'membership_status',
					'plan'    => 'level_name',
					'expires' => 'expire_date',
					'id'      => 'member_id',
				)
			),
			'ultimate-membership-pro'   => self::membership_preset(
				array(
					'status'  => 'membership_status',
					'plan'    => 'level_name',
					'expires' => 'expire_date',
					'id'      => 'membership_id',
				)
			),
			'woocommerce-subscriptions' => self::membership_preset(
				array(
					'status'  => 'subscription_status',
					'plan'    => 'product_name',
					'expires' => 'next_payment_date',
					'id'      => 'subscription_id',
				),
				__( 'Next payment', 'epasscard' )
			),
			'the-events-calendar'       => self::event_preset(
				array(
					'date'   => 'event_start',
					'event'  => 'event_title',
					'ticket' => 'ticket_name',
					'venue'  => 'venue_name',
					'id'     => 'attendee_id',
				),
				array(
					self::field( 'Address', __( 'Address', 'epasscard' ), 'venue_address', false, false, '1 Main Street' ),
					self::field( 'Organizer', __( 'Organizer', 'epasscard' ), 'organizer_name', false, false, 'Events Team' ),
					self::field( 'Event page', __( 'Event page', 'epasscard' ), 'event_url', false, false, 'https://example.com/event' ),
				),
				__( 'Ticket', 'epasscard' ),
				__( 'Ticket No', 'epasscard' )
			),
			'events-manager'            => self::event_preset(
				array(
					'date'   => 'event_start',
					'event'  => 'event_name',
					'ticket' => 'booking_spaces',
					'venue'  => 'location_name',
					'id'     => 'booking_id',
				),
				array(
					self::field( 'Booking status', __( 'Booking status', 'epasscard' ), 'booking_status', false, false, __( 'Approved', 'epasscard' ) ),
				),
				__( 'Spaces', 'epasscard' ),
				__( 'Booking No', 'epasscard' ),
				'2'
			),
			'pw-gift-cards'             => self::gift_card_preset( 'recipient_email', 'from_name' ),
			'yith-gift-cards'           => self::gift_card_preset( 'recipient_name', 'sender_name' ),
		);

		$preset = isset( $presets[ $slug ] ) ? $presets[ $slug ] : null;

		/**
		 * Filter the ready-made pass design for an integration.
		 *
		 * @param array|null $preset Preset definition (null = not supported).
		 * @param string     $slug   Module slug.
		 */
		$preset = apply_filters( 'epc_starter_template_preset', $preset, $slug );

		return is_array( $preset ) ? $preset : null;
	}

	/**
	 * Whether a module offers "Create a pass design for me".
	 *
	 * @param EPC_Module $module Module.
	 * @return bool
	 */
	public static function supports( $module ) {
		return $module instanceof EPC_Module && null !== self::get_preset( $module->get_slug() );
	}

	/**
	 * Saved starter template for a module.
	 *
	 * @param string $slug Module slug.
	 * @return array<string, mixed>|null
	 */
	public static function get_saved( $slug ) {
		$saved = get_option( self::OPTION_PREFIX . sanitize_key( $slug ), array() );
		return ( is_array( $saved ) && ! empty( $saved['template_uid'] ) ) ? $saved : null;
	}

	/**
	 * All fields of a preset, in pass order (barcode field last).
	 *
	 * @param array<string, mixed> $preset Preset.
	 * @return array<int, array<string, mixed>>
	 */
	private static function preset_fields( array $preset ) {
		$fields = array();
		foreach ( array( 'header', 'primary', 'secondary', 'auxiliary', 'back' ) as $area ) {
			foreach ( (array) ( $preset[ $area ] ?? array() ) as $field ) {
				$fields[ $field['name'] ] = $field;
			}
		}
		$fields[ $preset['barcode']['name'] ] = $preset['barcode'];
		return array_values( $fields );
	}

	/* -------------------------------------------------------------------------
	 * Design (logo, strip, colors, labels, barcode)
	 * ---------------------------------------------------------------------- */

	/**
	 * Default design for a module (used when nothing was customized).
	 *
	 * @param EPC_Module           $module Module.
	 * @param array<string, mixed> $preset Preset.
	 * @return array<string, mixed>
	 */
	public static function default_design( $module, array $preset ) {
		$site = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$org  = '' !== $site ? $site : 'EpassCard';

		$labels = array();
		foreach ( self::preset_fields( $preset ) as $field ) {
			$labels[ (string) $field['name'] ] = (string) $field['label'];
		}

		return array(
			/* translators: 1: site name, 2: integration name */
			'template_name'     => sprintf( __( '%1$s – %2$s', 'epasscard' ), $org, $module->get_label() ),
			'organization_name' => $org,
			'logo_id'           => 0,
			'logo_url'          => '',
			'strip_id'          => 0,
			'strip_url'         => '',
			'colors'            => array(
				'background' => '#1E1B4B',
				'text'       => '#FFFFFF',
				'label'      => '#C7D2FE',
			),
			'barcode_format'    => 'QR',
			'labels'            => $labels,
		);
	}

	/**
	 * Current design for a module: saved design (if any) over the defaults.
	 *
	 * @param EPC_Module $module Module.
	 * @return array<string, mixed>
	 */
	public static function get_design( $module ) {
		$preset = self::get_preset( $module->get_slug() );
		if ( null === $preset ) {
			return array();
		}
		$saved = self::get_saved( $module->get_slug() );
		$raw   = ( $saved && ! empty( $saved['design'] ) && is_array( $saved['design'] ) ) ? $saved['design'] : array();
		if ( $saved && empty( $raw['template_name'] ) && ! empty( $saved['template_name'] ) ) {
			$raw['template_name'] = (string) $saved['template_name'];
		}
		return self::sanitize_design( $module, $preset, $raw );
	}

	/**
	 * Sanitize a design from storage or the admin form.
	 *
	 * @param EPC_Module           $module Module.
	 * @param array<string, mixed> $preset Preset.
	 * @param array<string, mixed> $raw    Raw design.
	 * @return array<string, mixed>
	 */
	public static function sanitize_design( $module, array $preset, array $raw ) {
		$design = self::default_design( $module, $preset );

		foreach ( array( 'template_name', 'organization_name' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$value = sanitize_text_field( (string) $raw[ $key ] );
				if ( '' !== $value ) {
					$design[ $key ] = mb_substr( $value, 0, 120 );
				}
			}
		}

		foreach ( array( 'logo', 'strip' ) as $slot ) {
			$id  = absint( $raw[ $slot . '_id' ] ?? 0 );
			$url = trim( (string) ( $raw[ $slot . '_url' ] ?? '' ) );
			if ( '' !== $url && ! self::is_data_image_uri( $url ) ) {
				$url = esc_url_raw( $url, array( 'http', 'https' ) );
			}
			if ( $id > 0 && ! wp_attachment_is_image( $id ) ) {
				$id = 0;
			}
			if ( '' === $url && $id > 0 ) {
				$from_id = wp_get_attachment_image_url( $id, 'full' );
				$url     = is_string( $from_id ) ? esc_url_raw( $from_id ) : '';
			}
			$design[ $slot . '_id' ]  = '' !== $url ? $id : 0;
			$design[ $slot . '_url' ] = $url;
		}

		$colors = isset( $raw['colors'] ) && is_array( $raw['colors'] ) ? $raw['colors'] : array();
		foreach ( array( 'background', 'text', 'label' ) as $key ) {
			$color = sanitize_hex_color( (string) ( $colors[ $key ] ?? '' ) );
			if ( is_string( $color ) && '' !== $color ) {
				$design['colors'][ $key ] = strtoupper( $color );
			}
		}

		$format                   = strtoupper( sanitize_text_field( (string) ( $raw['barcode_format'] ?? 'QR' ) ) );
		$design['barcode_format'] = in_array( $format, self::$barcode_formats, true ) ? $format : 'QR';

		$labels = isset( $raw['labels'] ) && is_array( $raw['labels'] ) ? $raw['labels'] : array();
		foreach ( $design['labels'] as $name => $default_label ) {
			if ( isset( $labels[ $name ] ) ) {
				$label                     = mb_substr( sanitize_text_field( (string) $labels[ $name ] ), 0, 40 );
				$design['labels'][ $name ] = '' !== $label ? $label : $default_label;
			}
		}

		return $design;
	}

	/**
	 * Whether a value is a PNG or JPEG data URI.
	 *
	 * @param string $value Image reference.
	 * @return bool
	 */
	private static function is_data_image_uri( $value ) {
		return (bool) preg_match( '#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', (string) $value );
	}

	/**
	 * Whether EpassCard can download an image URL (not localhost / .test / private IP).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_public_url( $url ) {
		$url  = (string) $url;
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return false;
		}
		if ( preg_match( '/\.(test|local|localhost|internal|lan|invalid|example)$/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true );
	}

	/**
	 * Image reference EpassCard can use: a public URL as-is, otherwise the
	 * Media Library file as a (resized) PNG/JPEG data URI.
	 *
	 * @param string $url           Image URL.
	 * @param int    $attachment_id Media Library ID (0 = none).
	 * @param int    $max_w         Max width for the inline copy.
	 * @param int    $max_h         Max height for the inline copy.
	 * @return string Empty when the image cannot be used.
	 */
	public static function remote_image_reference( $url, $attachment_id, $max_w, $max_h ) {
		$url = (string) $url;
		if ( self::is_data_image_uri( $url ) ) {
			return $url;
		}
		if ( '' !== $url && self::is_public_url( $url ) ) {
			return $url;
		}

		$path = $attachment_id > 0 ? get_attached_file( $attachment_id ) : '';
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		$type = wp_check_filetype( $path );
		$mime = (string) ( $type['type'] ?? '' );
		$size = (int) filesize( $path );
		$dims = getimagesize( $path );
		$fits = is_array( $dims ) && $dims[0] <= $max_w * 2 && $dims[1] <= $max_h * 2;

		if ( in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) && $size > 0 && $size <= self::MAX_INLINE_IMAGE_BYTES && $fits ) {
			$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return is_string( $bytes ) ? 'data:' . $mime . ';base64,' . base64_encode( $bytes ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Resize / convert (WebP, GIF, large files) to PNG.
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return '';
		}
		$editor->resize( $max_w, $max_h, false );
		$tmp = wp_tempnam( 'epc-pass-image' ) . '.png';
		$out = $editor->save( $tmp, 'image/png' );
		if ( is_wp_error( $out ) || empty( $out['path'] ) || ! is_readable( $out['path'] ) ) {
			return '';
		}
		$bytes = file_get_contents( $out['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $out['path'] );
		if ( ! is_string( $bytes ) || strlen( $bytes ) > self::MAX_INLINE_IMAGE_BYTES ) {
			return '';
		}
		return 'data:image/png;base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/* -------------------------------------------------------------------------
	 * Payload
	 * ---------------------------------------------------------------------- */

	/**
	 * Build the v2 create/update-pass-template payload.
	 *
	 * @param EPC_Module                $module Module.
	 * @param array<string, mixed>      $preset Preset.
	 * @param array<string, mixed>|null $design Design (null = defaults).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function build_payload( $module, array $preset, $design = null ) {
		$design = is_array( $design ) ? $design : self::default_design( $module, $preset );
		$media  = EPC_Setup_Wizard::get_default_pass_media();

		$logo = '';
		if ( '' !== (string) $design['logo_url'] ) {
			$logo = self::remote_image_reference( (string) $design['logo_url'], (int) $design['logo_id'], 480, 150 );
			if ( '' === $logo ) {
				return new WP_Error( 'epc_starter_logo', __( 'EpassCard cannot download the logo from this site because the site is not public. Choose a PNG or JPEG from the Media Library, or use a public https URL.', 'epasscard' ) );
			}
		}
		$strip = '';
		if ( '' !== (string) $design['strip_url'] ) {
			$strip = self::remote_image_reference( (string) $design['strip_url'], (int) $design['strip_id'], 1125, 432 );
			if ( '' === $strip ) {
				return new WP_Error( 'epc_starter_strip', __( 'EpassCard cannot download the strip image from this site because the site is not public. Choose a PNG or JPEG from the Media Library, or use a public https URL.', 'epasscard' ) );
			}
		}
		if ( '' === $logo ) {
			$logo = (string) ( $media['logo_url'] ?? '' );
		}
		if ( '' === $strip ) {
			$strip = (string) ( $media['strip_url'] ?? '' );
		}

		$expire = class_exists( 'EPC_Loyalty_Pass_Design_Service' )
			? EPC_Loyalty_Pass_Design_Service::resolve_expire_date( '' )
			: gmdate( 'Y-m-d H:i:s', strtotime( '+99 years' ) );

		$labels = (array) $design['labels'];

		$to_pass_fields = static function ( array $fields ) use ( $labels ) {
			$out = array();
			foreach ( $fields as $field ) {
				$name  = (string) $field['name'];
				$out[] = array(
					'label' => isset( $labels[ $name ] ) ? (string) $labels[ $name ] : (string) $field['label'],
					'value' => '{' . $name . '}',
				);
			}
			return $out;
		};

		$hints = array();
		foreach ( self::preset_fields( $preset ) as $field ) {
			$hints[] = array(
				'name'     => (string) $field['name'],
				'type'     => 'text',
				'required' => (bool) $field['required'],
				'unique'   => (bool) $field['unique'],
			);
		}

		$payload = array(
			'template_name'     => (string) $design['template_name'],
			'organization_name' => (string) $design['organization_name'],
			'certificate'       => 'pass.com.epasscard.public',
			'card_type'         => (string) $preset['card_type'],
			'logo'              => $logo,
			'icon'              => $logo,
			'strip_image'       => $strip,
			'pass_limit'        => 0,
			'expire_date'       => $expire,
			'colors'            => array(
				'background' => (string) $design['colors']['background'],
				'text'       => (string) $design['colors']['text'],
				'label'      => (string) $design['colors']['label'],
			),
			'header_fields'     => $to_pass_fields( (array) $preset['header'] ),
			'secondary_fields'  => $to_pass_fields( (array) $preset['secondary'] ),
			'barcode'           => array(
				'format' => (string) $design['barcode_format'],
				'value'  => '{' . $preset['barcode']['name'] . '}',
			),
			'fields'            => $hints,
		);

		if ( ! empty( $preset['primary'] ) ) {
			$payload['primary_fields'] = $to_pass_fields( (array) $preset['primary'] );
		}
		if ( ! empty( $preset['auxiliary'] ) ) {
			$payload['auxiliary_fields'] = $to_pass_fields( (array) $preset['auxiliary'] );
		}
		$back                   = (array) $preset['back'];
		$back[]                 = $preset['barcode'];
		$payload['back_fields'] = $to_pass_fields( $back );

		/**
		 * Filter the create/update-template payload for a ready-made design.
		 *
		 * @param array      $payload Payload.
		 * @param EPC_Module $module  Module.
		 * @param array      $preset  Preset.
		 * @param array      $design  Design (logo, strip, colors, labels, barcode).
		 */
		return (array) apply_filters( 'epc_starter_template_payload', $payload, $module, $preset, $design );
	}

	/* -------------------------------------------------------------------------
	 * Create / update
	 * ---------------------------------------------------------------------- */

	/**
	 * Create the module's template (once) and save it.
	 *
	 * @param EPC_Module                $module     Module.
	 * @param array<string, mixed>|null $raw_design Customized design (null = defaults).
	 * @return array<string, mixed>|\WP_Error Saved record.
	 */
	public static function create_for_module( $module, $raw_design = null ) {
		$slug   = $module->get_slug();
		$preset = self::get_preset( $slug );
		if ( null === $preset ) {
			return new WP_Error( 'epc_starter_unsupported', __( 'This integration has no ready-made pass design.', 'epasscard' ) );
		}

		$existing = self::get_saved( $slug );
		if ( null !== $existing ) {
			return $existing;
		}

		$design  = self::sanitize_design( $module, $preset, is_array( $raw_design ) ? $raw_design : array() );
		$payload = self::build_payload( $module, $preset, $design );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// One create at a time per integration (double clicks, two tabs).
		$lock = 'epc_starter_lock_' . $slug;
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'epc_starter_busy', __( 'The pass design is already being created. Wait a moment and reload the page.', 'epasscard' ) );
		}
		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

		$result = EPC_Api_Client::create_pass_template_v2( $payload, $slug . ':create_pass_template' );

		if ( is_wp_error( $result ) ) {
			delete_transient( $lock );
			return $result;
		}

		$uid = isset( $result['uid'] ) ? EPC_Api_Client::sanitize_uid( (string) $result['uid'] ) : false;
		if ( false === $uid ) {
			delete_transient( $lock );
			return new WP_Error( 'epc_starter_uid', __( 'EpassCard created the template but did not return its ID. Check your templates in EpassCard before trying again.', 'epasscard' ) );
		}

		// Save the uid straight away so a retry never creates a second template.
		$record = array(
			'template_uid'  => $uid,
			'template_name' => (string) ( $result['template_name'] ?? $payload['template_name'] ),
			'template_id'   => isset( $result['template_id'] ) ? absint( $result['template_id'] ) : 0,
			'pass_fields'   => array(),
			'field_mapping' => array(),
			'design'        => $design,
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		);
		update_option( self::OPTION_PREFIX . $slug, $record, false );

		$record['pass_fields']   = self::fetch_pass_fields( $slug, $uid, $result );
		$record['field_mapping'] = self::build_field_mapping( $preset, $record['pass_fields'] );
		update_option( self::OPTION_PREFIX . $slug, $record, false );
		delete_transient( $lock );

		return $record;
	}

	/**
	 * Update the design of the module's template in EpassCard.
	 *
	 * Field names never change (only labels), so mappings stay valid. If
	 * EpassCard returns new field uids, every mapping that uses this template
	 * is moved to the new uids, keeping what each field is mapped to.
	 *
	 * @param EPC_Module           $module     Module.
	 * @param array<string, mixed> $raw_design Design from the form.
	 * @return array<string, mixed>|\WP_Error Saved record.
	 */
	public static function update_design( $module, array $raw_design ) {
		$slug   = $module->get_slug();
		$preset = self::get_preset( $slug );
		$saved  = self::get_saved( $slug );
		if ( null === $preset || null === $saved ) {
			return new WP_Error( 'epc_starter_missing', __( 'Create the pass design first.', 'epasscard' ) );
		}

		$design  = self::sanitize_design( $module, $preset, $raw_design );
		$payload = self::build_payload( $module, $preset, $design );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$result = EPC_Api_Client::update_pass_template_v2( (string) $saved['template_uid'], $payload, $slug . ':update_pass_template' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$old_fields = (array) ( $saved['pass_fields'] ?? array() );
		$new_fields = self::fetch_pass_fields( $slug, (string) $saved['template_uid'], is_array( $result ) ? $result : array() );
		if ( empty( $new_fields ) ) {
			$new_fields = $old_fields;
		}

		$record                  = $saved;
		$record['design']        = $design;
		$record['template_name'] = (string) $design['template_name'];
		$record['pass_fields']   = $new_fields;
		$record['field_mapping'] = self::build_field_mapping( $preset, $new_fields );
		$record['updated_at']    = current_time( 'mysql' );
		update_option( self::OPTION_PREFIX . $slug, $record, false );

		self::resync_entity_mappings( $module, $record, $old_fields );

		/**
		 * Fires after a ready-made pass design is updated in EpassCard.
		 *
		 * @param array      $record Saved record.
		 * @param EPC_Module $module Module.
		 */
		do_action( 'epc_starter_template_updated', $record, $module );

		return $record;
	}

	/**
	 * Fetch the template's pass fields (falls back to the API response).
	 *
	 * @param string               $slug   Module slug.
	 * @param string               $uid    Template uid.
	 * @param array<string, mixed> $result Create/update response.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch_pass_fields( $slug, $uid, array $result ) {
		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( $slug . ':get_pass_fields' );
		}
		$fields = EPC_Api_Client::get_pass_fields( $uid );
		if ( ! is_wp_error( $fields ) && ! empty( $fields['passFields'] ) && is_array( $fields['passFields'] ) ) {
			return $fields['passFields'];
		}
		if ( ! empty( $result['fields'] ) && is_array( $result['fields'] ) ) {
			return $result['fields'];
		}
		return array();
	}

	/**
	 * Normalized field name of an EpassCard pass field.
	 *
	 * @param array<string, mixed> $pass_field Field.
	 * @return string
	 */
	private static function pass_field_name( array $pass_field ) {
		return strtolower( trim( (string) ( $pass_field['field_name'] ?? $pass_field['name'] ?? '' ), "{} \t" ) );
	}

	/**
	 * Move mappings that use the template to new field uids and refresh their template name.
	 *
	 * @param EPC_Module                       $module     Module.
	 * @param array<string, mixed>             $record     Updated record.
	 * @param array<int, array<string, mixed>> $old_fields Fields before the update.
	 * @return void
	 */
	private static function resync_entity_mappings( $module, array $record, array $old_fields ) {
		$old_uid_to_name = array();
		foreach ( $old_fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['uid'] ) ) {
				$old_uid_to_name[ (string) $field['uid'] ] = self::pass_field_name( $field );
			}
		}
		$new_name_to_uid = array();
		foreach ( (array) $record['pass_fields'] as $field ) {
			if ( is_array( $field ) && ! empty( $field['uid'] ) ) {
				$new_name_to_uid[ self::pass_field_name( $field ) ] = (string) $field['uid'];
			}
		}

		foreach ( $module->get_mappings() as $entity_id => $mapping ) {
			if ( ! is_array( $mapping ) || (string) ( $mapping['template_uid'] ?? '' ) !== (string) $record['template_uid'] ) {
				continue;
			}
			$moved = array();
			foreach ( (array) ( $mapping['field_mapping'] ?? array() ) as $uid => $entry ) {
				$name          = $old_uid_to_name[ (string) $uid ] ?? '';
				$key           = ( '' !== $name && isset( $new_name_to_uid[ $name ] ) ) ? $new_name_to_uid[ $name ] : (string) $uid;
				$moved[ $key ] = $entry;
			}
			$mapping['field_mapping'] = $moved;
			$mapping['pass_fields']   = (array) $record['pass_fields'];
			$mapping['template_name'] = (string) $record['template_name'];
			$module->save_mapping( (int) $entity_id, $mapping );
		}
	}

	/**
	 * Map the created template's field uids to the preset's source fields.
	 *
	 * @param array<string, mixed>             $preset      Preset.
	 * @param array<int, array<string, mixed>> $pass_fields Fields returned by EpassCard.
	 * @return array<string, array<string, string>>
	 */
	public static function build_field_mapping( array $preset, array $pass_fields ) {
		$by_name = array();
		foreach ( self::preset_fields( $preset ) as $field ) {
			$by_name[ strtolower( (string) $field['name'] ) ] = (string) $field['source'];
		}

		$out = array();
		foreach ( $pass_fields as $pass_field ) {
			if ( ! is_array( $pass_field ) ) {
				continue;
			}
			$uid = EPC_Api_Client::sanitize_uid( (string) ( $pass_field['uid'] ?? '' ) );
			if ( false === $uid ) {
				continue;
			}
			$name = self::pass_field_name( $pass_field );
			if ( isset( $by_name[ $name ] ) && '' !== $by_name[ $name ] ) {
				$out[ $uid ] = array(
					'type'   => 'source',
					'source' => $by_name[ $name ],
				);
			}
		}

		return $out;
	}

	/**
	 * Map the saved design to plans/events/products that have no mapping yet.
	 *
	 * @param EPC_Module $module    Module.
	 * @param int        $entity_id Optional single entity (0 = all unmapped).
	 * @return int Number of entities mapped.
	 */
	public static function apply_to_unmapped( $module, $entity_id = 0 ) {
		$saved = self::get_saved( $module->get_slug() );
		if ( null === $saved || empty( $saved['field_mapping'] ) ) {
			return 0;
		}

		$mappings = $module->get_mappings();
		$applied  = 0;

		foreach ( $module->get_filtered_mappable_entities() as $entity ) {
			$eid = isset( $entity['id'] ) ? absint( $entity['id'] ) : 0;
			if ( $eid <= 0 || ( $entity_id > 0 && $eid !== (int) $entity_id ) ) {
				continue;
			}
			$current = isset( $mappings[ (string) $eid ] ) && is_array( $mappings[ (string) $eid ] ) ? $mappings[ (string) $eid ] : array();
			if ( ! empty( $current['template_uid'] ) ) {
				continue; // Never overwrite an existing mapping.
			}
			$module->save_mapping(
				$eid,
				array(
					'template_uid'  => (string) $saved['template_uid'],
					'template_name' => (string) $saved['template_name'],
					'template_id'   => (int) ( $saved['template_id'] ?? 0 ),
					'field_mapping' => (array) $saved['field_mapping'],
					'pass_fields'   => (array) $saved['pass_fields'],
				)
			);
			++$applied;
		}

		return $applied;
	}

	/**
	 * Whether "Create pass design for me" should be offered for this module now.
	 *
	 * Only when the plugin has not created a design yet and at least one item is unmapped.
	 *
	 * @param EPC_Module $module Module.
	 * @return bool
	 */
	public static function should_offer_create( $module ) {
		if ( ! self::supports( $module ) || ! EPC_Connection::is_connected() || null !== self::get_saved( $module->get_slug() ) ) {
			return false;
		}
		$mappings = $module->get_mappings();
		foreach ( $module->get_filtered_mappable_entities() as $entity ) {
			$key = (string) absint( $entity['id'] ?? 0 );
			if ( empty( $mappings[ $key ]['template_uid'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Upgrade link for plan limits.
	 *
	 * @return string
	 */
	public static function upgrade_url() {
		return (string) apply_filters( 'epc_upgrade_url', 'https://app.epasscard.com' );
	}

	/* -------------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------------- */

	/**
	 * Design posted as JSON in "design".
	 *
	 * @return array<string, mixed>|null
	 */
	private static function posted_design() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in ajax_handle().
		if ( empty( $_POST['design'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; every key is sanitized in sanitize_design().
		$decoded = json_decode( wp_unslash( (string) $_POST['design'] ), true );
		// phpcs:enable
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * AJAX: create (op=create), apply (op=apply) or edit the design (op=save_design).
	 *
	 * @return void
	 */
	public static function ajax_handle() {
		check_ajax_referer( 'epc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epasscard' ) ), 403 );
		}

		$slug   = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( (string) $_POST['module'] ) ) : '';
		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : 'create';
		$module = function_exists( 'epc_plugin' ) ? epc_plugin()->get_module( $slug ) : null;

		if ( ! $module || ! $module->is_available() || ! self::supports( $module ) ) {
			wp_send_json_error( array( 'message' => __( 'This integration is not available.', 'epasscard' ) ), 400 );
		}
		if ( ! EPC_Connection::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your EpassCard account first.', 'epasscard' ) ), 400 );
		}

		if ( 'save_design' === $op ) {
			if ( null === self::get_saved( $slug ) ) {
				$op = 'create'; // No template yet: create it with this design.
			} else {
				$design = self::posted_design();
				$record = self::update_design( $module, is_array( $design ) ? $design : array() );
				if ( is_wp_error( $record ) ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								/* translators: %s: error from EpassCard */
								__( 'EpassCard could not update the pass design: %s', 'epasscard' ),
								$record->get_error_message()
							),
						),
						400
					);
				}
				wp_send_json_success(
					array(
						'updated' => true,
						'message' => __( 'Pass design updated in EpassCard. Reloading…', 'epasscard' ),
					)
				);
			}
		}

		$created = false;
		if ( 'create' === $op && null === self::get_saved( $slug ) ) {
			$record = self::create_for_module( $module, self::posted_design() );
			if ( is_wp_error( $record ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error from EpassCard */
							__( 'EpassCard could not create the pass design: %s', 'epasscard' ),
							$record->get_error_message()
						),
					),
					400
				);
			}
			$created = true;
		}

		$applied = self::apply_to_unmapped( $module );
		$saved   = self::get_saved( $slug );

		wp_send_json_success(
			array(
				'created' => $created,
				'applied' => $applied,
				'message' => sprintf(
					/* translators: 1: template name, 2: number of items mapped */
					_n(
						'Pass design "%1$s" is ready and mapped to %2$d item. Reloading…',
						'Pass design "%1$s" is ready and mapped to %2$d items. Reloading…',
						$applied,
						'epasscard'
					),
					$saved ? (string) $saved['template_name'] : '',
					$applied
				),
			)
		);
	}

	/* -------------------------------------------------------------------------
	 * Admin UI
	 * ---------------------------------------------------------------------- */

	/**
	 * Card shown above the mapping table.
	 *
	 * @param EPC_Module                         $module   Module.
	 * @param array<int, array<string, mixed>>   $entities Entities.
	 * @param array<string, array<string,mixed>> $mappings Saved mappings.
	 * @return void
	 */
	public static function render_card( $module, array $entities, array $mappings ) {
		if ( ! self::supports( $module ) || ! EPC_Connection::is_connected() || empty( $entities ) ) {
			return;
		}

		$slug     = $module->get_slug();
		$saved    = self::get_saved( $slug );
		$preset   = self::get_preset( $slug );
		$design   = self::get_design( $module );
		$unmapped = 0;
		$using    = 0;
		foreach ( $entities as $entity ) {
			$key = (string) absint( $entity['id'] ?? 0 );
			$uid = isset( $mappings[ $key ]['template_uid'] ) ? (string) $mappings[ $key ]['template_uid'] : '';
			if ( '' === $uid ) {
				++$unmapped;
			} elseif ( $saved && $uid === (string) $saved['template_uid'] ) {
				++$using;
			}
		}

		if ( null === $saved && 0 === $unmapped ) {
			return; // Everything is mapped already; nothing to offer.
		}

		$labels = array();
		foreach ( array( 'primary', 'header', 'secondary', 'auxiliary' ) as $area ) {
			foreach ( (array) $preset[ $area ] as $field ) {
				$labels[] = (string) ( $design['labels'][ $field['name'] ] ?? $field['label'] );
			}
		}
		?>
		<div class="epc-card epc-starter-card" data-module="<?php echo esc_attr( $slug ); ?>">
			<?php if ( null === $saved ) : ?>
				<h3><?php esc_html_e( 'No pass template yet? Create one in one click', 'epasscard' ); ?></h3>
				<p>
					<?php
					printf(
						/* translators: 1: field list, 2: number of items */
						esc_html( _n( 'EpassCard creates a ready-made pass with %1$s and a QR code, and maps it to the %2$d item below that is not mapped yet. Add your logo, strip image, colors and labels first, or change them any time later.', 'EpassCard creates a ready-made pass with %1$s and a QR code, and maps it to the %2$d items below that are not mapped yet. Add your logo, strip image, colors and labels first, or change them any time later.', $unmapped, 'epasscard' ) ),
						esc_html( implode( ', ', $labels ) ),
						(int) $unmapped
					);
					?>
				</p>
				<p class="epc-starter-actions">
					<button type="button" class="button button-primary epc-starter-action" data-op="create" data-module="<?php echo esc_attr( $slug ); ?>">
						<?php esc_html_e( 'Create pass design for me', 'epasscard' ); ?>
					</button>
					<button type="button" class="button epc-starter-customize" data-module="<?php echo esc_attr( $slug ); ?>">
						<span class="dashicons dashicons-art" aria-hidden="true"></span>
						<?php esc_html_e( 'Customize design first', 'epasscard' ); ?>
					</button>
					<span class="epc-starter-status" aria-live="polite"></span>
				</p>
				<p class="description">
					<?php esc_html_e( 'Uses one template in your EpassCard account. Free plan allows 3 templates & 50 passes.', 'epasscard' ); ?>
					<a href="<?php echo esc_url( self::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade your plan', 'epasscard' ); ?></a>
				</p>
			<?php else : ?>
				<div class="epc-starter-summary">
					<span class="epc-starter-swatch" style="<?php echo esc_attr( 'background:' . $design['colors']['background'] . ';color:' . $design['colors']['text'] ); ?>" aria-hidden="true">
						<?php if ( '' !== (string) $design['logo_url'] ) : ?>
							<img src="<?php echo esc_attr( (string) $design['logo_url'] ); ?>" alt="" />
						<?php else : ?>
							<span class="dashicons dashicons-id-alt"></span>
						<?php endif; ?>
					</span>
					<div>
						<h3>
							<?php
							printf(
								/* translators: %s: template name */
								esc_html__( 'Your pass design: %s', 'epasscard' ),
								esc_html( (string) $saved['template_name'] )
							);
							?>
						</h3>
						<p>
							<?php
							printf(
								/* translators: 1: items using the design, 2: total items */
								esc_html( _n( 'Used by %1$d of %2$d item.', 'Used by %1$d of %2$d items.', count( $entities ), 'epasscard' ) ),
								(int) $using,
								count( $entities )
							);
							?>
						</p>
					</div>
				</div>
				<p class="epc-starter-actions">
					<button type="button" class="button epc-starter-customize" data-module="<?php echo esc_attr( $slug ); ?>">
						<span class="dashicons dashicons-art" aria-hidden="true"></span>
						<?php esc_html_e( 'Edit design', 'epasscard' ); ?>
					</button>
					<?php if ( $unmapped > 0 ) : ?>
						<button type="button" class="button button-primary epc-starter-action" data-op="apply" data-module="<?php echo esc_attr( $slug ); ?>">
							<?php
							printf(
								/* translators: %d: number of unmapped items */
								esc_html( _n( 'Use it for the %d unmapped item', 'Use it for the %d unmapped items', $unmapped, 'epasscard' ) ),
								(int) $unmapped
							);
							?>
						</button>
					<?php endif; ?>
					<a href="<?php echo esc_url( 'https://app.epasscard.com/pass-templates' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in EpassCard', 'epasscard' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
					<span class="epc-starter-status" aria-live="polite"></span>
				</p>
			<?php endif; ?>
		</div>
		<?php
		self::render_designer_modal( $module, $preset, $design, null !== $saved );
	}

	/**
	 * Preview layout data passed to the designer script.
	 *
	 * @param array<string, mixed> $preset Preset.
	 * @return array<string, mixed>
	 */
	private static function preview_config( array $preset ) {
		$areas = array();
		foreach ( array( 'header', 'primary', 'secondary', 'auxiliary', 'back' ) as $area ) {
			$areas[ $area ] = array();
			foreach ( (array) ( $preset[ $area ] ?? array() ) as $field ) {
				$areas[ $area ][] = array(
					'name'   => (string) $field['name'],
					'sample' => (string) ( $field['sample'] ?? '' ),
				);
			}
		}
		$areas['barcode'] = array(
			array(
				'name'   => (string) $preset['barcode']['name'],
				'sample' => (string) ( $preset['barcode']['sample'] ?? '' ),
			),
		);
		return array(
			'type'     => (string) ( $preset['type'] ?? '' ),
			'cardType' => (string) $preset['card_type'],
			'areas'    => $areas,
		);
	}

	/**
	 * Designer modal (logo, strip, colors, labels, barcode) with a live preview.
	 *
	 * @param EPC_Module           $module Module.
	 * @param array<string, mixed> $preset Preset.
	 * @param array<string, mixed> $design Current design.
	 * @param bool                 $exists Whether the template exists in EpassCard.
	 * @return void
	 */
	private static function render_designer_modal( $module, array $preset, array $design, $exists ) {
		if ( self::$modal_printed ) {
			return;
		}
		self::$modal_printed = true;

		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		$slug   = $module->get_slug();
		$groups = array(
			array(
				'title'  => __( 'Front of the pass: labels', 'epasscard' ),
				'fields' => array_merge( (array) $preset['header'], (array) $preset['primary'], (array) $preset['secondary'], (array) $preset['auxiliary'] ),
			),
			array(
				'title'  => __( 'Back of the pass: labels', 'epasscard' ),
				'fields' => array_merge( (array) $preset['back'], array( $preset['barcode'] ) ),
			),
		);
		$config = array(
			'module'   => $slug,
			'exists'   => (bool) $exists,
			'design'   => $design,
			'defaults' => self::default_design( $module, $preset ),
			'preview'  => self::preview_config( $preset ),
		);
		$media_rows = array(
			'logo'  => array(
				'label' => __( 'Logo', 'epasscard' ),
				'help'  => __( 'Top left of the pass, also used as the icon. A PNG with a transparent background works best (about 480 × 150 px).', 'epasscard' ),
			),
			'strip' => array(
				'label' => __( 'Strip image', 'epasscard' ),
				'help'  => __( 'Wide banner across the pass. Best size 1125 × 432 px. Leave empty for a plain strip.', 'epasscard' ),
			),
		);
		?>
		<div id="epc-starter-designer" class="epc-modal epc-sd" hidden data-config="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">
			<div class="epc-modal__backdrop" data-epc-sd-close></div>
			<div class="epc-modal__dialog epc-sd__dialog" role="dialog" aria-modal="true" aria-labelledby="epc-sd-title">
				<header class="epc-modal__header">
					<h2 id="epc-sd-title">
						<?php echo esc_html( $exists ? __( 'Edit pass design', 'epasscard' ) : __( 'Customize your pass design', 'epasscard' ) ); ?>
					</h2>
					<button type="button" class="epc-modal__close" data-epc-sd-close aria-label="<?php esc_attr_e( 'Close', 'epasscard' ); ?>">&times;</button>
				</header>
				<div class="epc-modal__body epc-sd__body">
					<form class="epc-sd__form" id="epc-sd-form" novalidate>
						<fieldset class="epc-sd__group">
							<legend><?php esc_html_e( 'Brand', 'epasscard' ); ?></legend>
							<div class="epc-sd__row">
								<label for="epc-sd-template-name"><?php esc_html_e( 'Template name', 'epasscard' ); ?></label>
								<input type="text" id="epc-sd-template-name" name="template_name" maxlength="120" />
							</div>
							<div class="epc-sd__row">
								<label for="epc-sd-org-name"><?php esc_html_e( 'Organization name', 'epasscard' ); ?></label>
								<input type="text" id="epc-sd-org-name" name="organization_name" maxlength="120" />
							</div>
							<?php foreach ( $media_rows as $slot => $row ) : ?>
								<div class="epc-sd__row epc-sd__media" data-slot="<?php echo esc_attr( $slot ); ?>">
									<span class="epc-sd__label"><?php echo esc_html( $row['label'] ); ?></span>
									<input type="hidden" name="<?php echo esc_attr( $slot ); ?>_id" value="0" />
									<div class="epc-sd__media-box">
										<img class="epc-sd__thumb epc-sd__thumb--<?php echo esc_attr( $slot ); ?>" alt="" hidden />
										<div class="epc-sd__media-controls">
											<input type="url" name="<?php echo esc_attr( $slot ); ?>_url" placeholder="https://" aria-label="<?php echo esc_attr( $row['label'] ); ?>" />
											<button type="button" class="button epc-sd-media-pick" data-slot="<?php echo esc_attr( $slot ); ?>"><?php esc_html_e( 'Choose image', 'epasscard' ); ?></button>
											<button type="button" class="button-link epc-sd-media-clear" data-slot="<?php echo esc_attr( $slot ); ?>"><?php esc_html_e( 'Remove', 'epasscard' ); ?></button>
										</div>
									</div>
									<p class="description"><?php echo esc_html( $row['help'] ); ?></p>
								</div>
							<?php endforeach; ?>
						</fieldset>

						<fieldset class="epc-sd__group">
							<legend><?php esc_html_e( 'Colors', 'epasscard' ); ?></legend>
							<div class="epc-sd__colors">
								<label><input type="color" name="colors[background]" /> <span><?php esc_html_e( 'Background', 'epasscard' ); ?></span></label>
								<label><input type="color" name="colors[text]" /> <span><?php esc_html_e( 'Values', 'epasscard' ); ?></span></label>
								<label><input type="color" name="colors[label]" /> <span><?php esc_html_e( 'Labels', 'epasscard' ); ?></span></label>
							</div>
							<p class="epc-sd__contrast" hidden><?php esc_html_e( 'Low contrast: text may be hard to read on this background.', 'epasscard' ); ?></p>
						</fieldset>

						<?php foreach ( $groups as $group ) : ?>
							<fieldset class="epc-sd__group">
								<legend><?php echo esc_html( $group['title'] ); ?></legend>
								<div class="epc-sd__labels">
									<?php foreach ( $group['fields'] as $field ) : ?>
										<label>
											<span><?php echo esc_html( (string) $field['label'] ); ?></span>
											<input type="text" maxlength="40" name="labels[<?php echo esc_attr( (string) $field['name'] ); ?>]" data-field="<?php echo esc_attr( (string) $field['name'] ); ?>" />
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						<?php endforeach; ?>

						<fieldset class="epc-sd__group">
							<legend><?php esc_html_e( 'Code', 'epasscard' ); ?></legend>
							<div class="epc-sd__row">
								<label for="epc-sd-barcode"><?php esc_html_e( 'QR / barcode format', 'epasscard' ); ?></label>
								<select id="epc-sd-barcode" name="barcode_format">
									<?php foreach ( self::$barcode_formats as $format ) : ?>
										<option value="<?php echo esc_attr( $format ); ?>"><?php echo esc_html( 'CODE128' === $format ? 'Code 128' : $format ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</fieldset>
						<p><button type="button" class="button-link epc-sd-reset"><?php esc_html_e( 'Reset to the default design', 'epasscard' ); ?></button></p>
					</form>

					<aside class="epc-sd__preview" aria-label="<?php esc_attr_e( 'Pass preview', 'epasscard' ); ?>">
						<div class="epc-sd__tabs" role="tablist">
							<button type="button" role="tab" class="is-active" data-face="front" aria-selected="true"><?php esc_html_e( 'Front', 'epasscard' ); ?></button>
							<button type="button" role="tab" data-face="back" aria-selected="false"><?php esc_html_e( 'Back', 'epasscard' ); ?></button>
						</div>
						<div class="epc-sd-pass" id="epc-sd-pass"></div>
						<p class="description"><?php esc_html_e( 'Sample data. Apple Wallet and Google Wallet lay passes out slightly differently.', 'epasscard' ); ?></p>
					</aside>
				</div>
				<footer class="epc-modal__footer epc-sd__footer">
					<span class="epc-sd-status" aria-live="polite"></span>
					<button type="button" class="button" data-epc-sd-close><?php esc_html_e( 'Cancel', 'epasscard' ); ?></button>
					<button type="button" class="button button-primary epc-sd-save" data-module="<?php echo esc_attr( $slug ); ?>">
						<?php echo esc_html( $exists ? __( 'Save design', 'epasscard' ) : __( 'Create pass design', 'epasscard' ) ); ?>
					</button>
				</footer>
			</div>
		</div>
		<?php
	}
}
