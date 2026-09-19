<?php
/**
 * Loyalty wallet pass design and remote template synchronization.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores a versioned local design snapshot and pushes it through API v2 template endpoints.
 *
 * Local option updates happen only after the remote create/update succeeds so a failed
 * API call keeps the previously saved design and mapped template intact.
 */
class EPC_Loyalty_Pass_Design_Service {

	public const OPTION_KEY = 'epc_loyalty_pass_design';

	public const PROGRAM_ENTITY_ID = 1;

	/**
	 * Placeholder name => loyalty source field slug.
	 *
	 * @return array<string, string>
	 */
	public static function placeholder_source_map() {
		return array(
			'points'           => 'points_balance',
			'name'             => 'user_full_name',
			'member no'        => 'member_id',
			'member id'        => 'member_id',
			'membership id'    => 'member_id',
			'tier'             => 'tier',
			'next tier'        => 'next_tier',
			'next reward'      => 'next_reward',
			'milestone'        => 'milestone',
			'lifetime points'  => 'lifetime_points',
			'email'            => 'user_email',
			'reward summary'   => 'reward_summary',
		);
	}

	/**
	 * Default design used by the WordPress designer/preview.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		$blog = sanitize_text_field( (string) get_bloginfo( 'name' ) );
		if ( '' === $blog ) {
			$blog = 'Loyalty';
		}

		return array(
			'version'            => 1,
			'template_uid'       => '',
			'design_source'      => 'form',
			'template_name'      => sprintf(
				/* translators: %s: site name. */
				__( '%s Loyalty', 'epasscard' ),
				$blog
			),
			'organization_name'  => $blog,
			'certificate'        => 'pass.com.epasscard.public',
			'card_type'          => 'StoreCard',
			'logo_id'            => 0,
			'logo_url'           => '',
			'icon_id'            => 0,
			'icon_url'           => '',
			'strip_id'           => 0,
			'strip_url'          => '',
			'colors'             => array(
				'background' => '#1E1B4B',
				'text'       => '#FFFFFF',
				'label'      => '',
				'strip'      => '',
			),
			'points_label'       => __( 'Points', 'epasscard' ),
			'name_label'         => __( 'Member', 'epasscard' ),
			'secondary_mode'     => 'tier',
			'secondary_label'    => __( 'Tier', 'epasscard' ),
			'barcode_format'     => 'QR',
			'pass_limit'         => 0,
			'expire_date'        => '',
			'description'        => '',
			'field_uids'         => array(),
			'updated_at'         => '',
		);
	}

	/**
	 * Saved design merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_design() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$design = array_merge( self::defaults(), $saved );
		$design = self::sanitize_design( $design, false );

		/**
		 * Filter the stored loyalty pass design.
		 *
		 * @param array<string, mixed> $design Design snapshot.
		 */
		return (array) apply_filters( 'epc_loyalty_pass_design', $design );
	}

	/**
	 * Sanitize a design payload from storage or admin POST.
	 *
	 * @param array<string, mixed> $raw           Raw design.
	 * @param bool                 $require_media Whether logo/strip must resolve to a public URL.
	 * @return array<string, mixed>
	 */
	public static function sanitize_design( array $raw, $require_media = false ) {
		$defaults = self::defaults();
		$design   = $defaults;

		$design['version']           = 1;
		$design['template_uid']      = '';
		$raw_uid                     = isset( $raw['template_uid'] ) ? EPC_Api_Client::sanitize_uid( (string) $raw['template_uid'] ) : false;
		if ( false !== $raw_uid ) {
			$design['template_uid'] = $raw_uid;
		}

		$source = sanitize_key( (string) ( $raw['design_source'] ?? 'form' ) );
		$design['design_source'] = in_array( $source, array( 'form', 'builder' ), true ) ? $source : 'form';

		$design['template_name']     = sanitize_text_field( (string) ( $raw['template_name'] ?? $defaults['template_name'] ) );
		$design['organization_name'] = sanitize_text_field( (string) ( $raw['organization_name'] ?? $defaults['organization_name'] ) );
		$design['certificate']       = sanitize_text_field( (string) ( $raw['certificate'] ?? $defaults['certificate'] ) );
		if ( '' === $design['certificate'] ) {
			$design['certificate'] = 'pass.com.epasscard.public';
		}

		$card_type = sanitize_text_field( (string) ( $raw['card_type'] ?? 'StoreCard' ) );
		$allowed_types = array( 'StoreCard', 'Coupon', 'Event', 'BoardingPass', 'Generic' );
		$design['card_type'] = in_array( $card_type, $allowed_types, true ) ? $card_type : 'StoreCard';

		foreach ( array( 'logo', 'strip' ) as $slot ) {
			$id_key     = $slot . '_id';
			$url_key    = $slot . '_url';
			$posted_id  = absint( $raw[ $id_key ] ?? 0 );
			$posted_url = self::sanitize_image_reference( (string) ( $raw[ $url_key ] ?? '' ) );

			if ( '' !== $posted_url ) {
				$design[ $url_key ] = $posted_url;
				$design[ $id_key ]  = 0;
				if ( $posted_id > 0 ) {
					$from_id = wp_get_attachment_image_url( $posted_id, 'full' );
					if ( is_string( $from_id ) && esc_url_raw( $from_id ) === $posted_url ) {
						$design[ $id_key ] = $posted_id;
					}
				}
			} elseif ( $posted_id > 0 ) {
				$from_id = wp_get_attachment_image_url( $posted_id, 'full' );
				$design[ $id_key ]  = $posted_id;
				$design[ $url_key ] = is_string( $from_id ) ? esc_url_raw( $from_id ) : '';
			} else {
				$design[ $id_key ]  = 0;
				$design[ $url_key ] = '';
			}
		}

		// Icon is derived from logo; clear any legacy separate icon.
		$design['icon_id']  = 0;
		$design['icon_url'] = '';

		$colors = isset( $raw['colors'] ) && is_array( $raw['colors'] ) ? $raw['colors'] : array();
		$design['colors']['background'] = self::sanitize_hex_color_value( (string) ( $colors['background'] ?? $defaults['colors']['background'] ) );
		$design['colors']['text']       = self::sanitize_hex_color_value( (string) ( $colors['text'] ?? $defaults['colors']['text'] ) );
		$design['colors']['label']      = '';
		$design['colors']['strip']      = '';

		$design['points_label']    = sanitize_text_field( (string) ( $raw['points_label'] ?? $defaults['points_label'] ) );
		$design['name_label']      = sanitize_text_field( (string) ( $raw['name_label'] ?? $defaults['name_label'] ) );
		$design['secondary_label'] = sanitize_text_field( (string) ( $raw['secondary_label'] ?? $defaults['secondary_label'] ) );

		$mode = sanitize_key( (string) ( $raw['secondary_mode'] ?? 'tier' ) );
		$design['secondary_mode'] = in_array( $mode, array( 'tier', 'next_reward', 'milestone' ), true ) ? $mode : 'tier';

		$format = strtoupper( sanitize_text_field( (string) ( $raw['barcode_format'] ?? 'QR' ) ) );
		$design['barcode_format'] = in_array( $format, array( 'QR', 'PDF417', 'AZTEC', 'CODE128' ), true ) ? $format : 'QR';

		// 0 = max available on the EpassCard plan / account.
		$design['pass_limit'] = 0;

		$expire = isset( $raw['expire_date'] ) ? sanitize_text_field( (string) $raw['expire_date'] ) : '';
		$design['expire_date'] = self::sanitize_expire_date_for_storage( $expire );
		$design['description'] = sanitize_text_field( (string) ( $raw['description'] ?? '' ) );

		$field_uids = array();
		if ( isset( $raw['field_uids'] ) && is_array( $raw['field_uids'] ) ) {
			foreach ( $raw['field_uids'] as $name => $uid ) {
				$key = sanitize_text_field( (string) $name );
				$san = EPC_Api_Client::sanitize_uid( (string) $uid );
				if ( '' !== $key && false !== $san ) {
					$field_uids[ $key ] = $san;
				}
			}
		}
		$design['field_uids'] = $field_uids;
		$design['updated_at'] = sanitize_text_field( (string) ( $raw['updated_at'] ?? '' ) );

		if ( $require_media ) {
			if ( '' === $design['logo_url'] ) {
				$design['logo_url'] = '';
			}
			if ( '' === $design['strip_url'] ) {
				$design['strip_url'] = '';
			}
		}

		return $design;
	}

	/**
	 * Push the design to EpassCard and persist only after success.
	 *
	 * @param array<string, mixed> $raw Incoming design from admin.
	 * @return array<string, mixed>|\WP_Error Saved design.
	 */
	public static function save_and_sync( array $raw ) {
		$previous = self::get_design();
		foreach ( array( 'logo_url', 'strip_url' ) as $url_key ) {
			$incoming = trim( (string) ( $raw[ $url_key ] ?? '' ) );
			if ( '' === $incoming && self::is_data_image_uri( (string) ( $previous[ $url_key ] ?? '' ) ) ) {
				unset( $raw[ $url_key ] );
			}
		}
		$design = self::sanitize_design( array_merge( $previous, $raw ), true );

		if ( ! EPC_Api_Client::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'Connect EpassCard before saving the loyalty pass design.', 'epasscard' ) );
		}

		if ( 'builder' === $design['design_source'] ) {
			return self::save_from_builder_template( $design );
		}

		if ( '' === $design['logo_url'] || '' === $design['strip_url'] ) {
			return new WP_Error(
				'epc_loyalty_design_media',
				__( 'Logo and strip image URLs are required before the loyalty pass design can be saved.', 'epasscard' )
			);
		}

		$payload = self::build_remote_payload( $design );
		
		$has_uid = '' !== $design['template_uid'];

		if ( $has_uid ) {
			$result = EPC_Api_Client::update_pass_template_v2( $design['template_uid'], $payload );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$remote = $result;
		} else {
			$result = EPC_Api_Client::create_pass_template_v2( $payload );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$remote = $result;
			$design['template_uid'] = (string) $remote['uid'];
		}

		$design['field_uids'] = self::extract_field_uids( $remote );
		if ( empty( $design['field_uids'] ) && '' !== $design['template_uid'] ) {
			$fields = EPC_Api_Client::get_pass_fields( $design['template_uid'] );
			if ( ! is_wp_error( $fields ) && ! empty( $fields['passFields'] ) ) {
				$design['field_uids'] = self::extract_field_uids_from_pass_fields( $fields['passFields'] );
			}
		}

		$design['updated_at'] = gmdate( 'c' );
		$design               = self::sanitize_design( $design, false );

		update_option( self::OPTION_KEY, $design, false );
		self::sync_program_mapping( $design );

		/**
		 * Fires after the loyalty pass design is saved remotely and locally.
		 *
		 * @param array<string, mixed> $design Saved design.
		 * @param array<string, mixed> $remote Remote template payload.
		 */
		do_action( 'epc_loyalty_pass_design_saved', $design, $remote );

		return $design;
	}

	/**
	 * Attach an existing EpassCard dashboard template without overwriting its design.
	 *
	 * @param array<string, mixed> $design Sanitized design.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function save_from_builder_template( array $design ) {
		if ( '' === (string) $design['template_uid'] ) {
			return new WP_Error(
				'epc_loyalty_builder_template',
				__( 'Select a template from the EpassCard template builder before saving.', 'epasscard' )
			);
		}

		$details = EPC_Api_Client::get_template_details( $design['template_uid'] );
		if ( ! is_wp_error( $details ) ) {
			$name = '';
			if ( ! empty( $details['template_name'] ) ) {
				$name = (string) $details['template_name'];
			} elseif ( ! empty( $details['name'] ) ) {
				$name = (string) $details['name'];
			} elseif ( ! empty( $details['template']['template_name'] ) ) {
				$name = (string) $details['template']['template_name'];
			}
			if ( '' !== $name ) {
				$design['template_name'] = sanitize_text_field( $name );
			}
		}

		$fields = EPC_Api_Client::get_pass_fields( $design['template_uid'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$design['field_uids'] = self::extract_field_uids_from_pass_fields( (array) ( $fields['passFields'] ?? array() ) );
		$design['updated_at'] = gmdate( 'c' );
		$design               = self::sanitize_design( $design, false );

		update_option( self::OPTION_KEY, $design, false );
		self::sync_program_mapping( $design );

		do_action( 'epc_loyalty_pass_design_saved', $design, is_wp_error( $details ) ? array() : $details );

		return $design;
	}

	/**
	 * Keep public image URLs and PNG/JPEG data URIs the template API accepts.
	 *
	 * @param string $url Raw image reference.
	 * @return string
	 */
	public static function sanitize_image_reference( $url ) {
		$url = trim( (string) $url );
		if ( self::is_data_image_uri( $url ) ) {
			return $url;
		}

		return esc_url_raw( $url );
	}

	/**
	 * Whether a value is a PNG or JPEG data URI safe to send and print.
	 *
	 * @param string $url Image reference.
	 * @return bool
	 */
	public static function is_data_image_uri( $url ) {
		$url = (string) $url;
		if ( strlen( $url ) > 1500000 ) {
			return false;
		}

		return (bool) preg_match( '#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $url );
	}

	/**
	 * Build the simplified v2 create/update body from a design snapshot.
	 *
	 * @param array<string, mixed> $design Sanitized design.
	 * @return array<string, mixed>
	 */
	public static function build_remote_payload( array $design ) {
		$secondary = self::secondary_placeholder( $design );

		$payload = array(
			'template_name'     => (string) $design['template_name'],
			'organization_name' => (string) $design['organization_name'],
			'certificate'       => (string) $design['certificate'],
			'card_type'         => (string) $design['card_type'],
			'logo'              => (string) $design['logo_url'],
			'icon'              => (string) $design['logo_url'],
			'strip_image'       => (string) $design['strip_url'],
			'pass_limit'        => (int) $design['pass_limit'],
			'expire_date'       => self::resolve_expire_date( (string) ( $design['expire_date'] ?? '' ) ),
			'colors'            => array_filter(
				array(
					'background' => (string) $design['colors']['background'],
					'text'       => (string) $design['colors']['text'],
				),
				static function ( $value ) {
					return '' !== $value;
				}
			),
			'header_fields'     => array(
				array(
					'label' => (string) $design['points_label'],
					'value' => '{Points}',
				),
			),
			'secondary_fields'  => array(
				array(
					'label' => (string) $design['name_label'],
					'value' => '{Name}',
				),
				array(
					'label' => (string) $design['secondary_label'],
					'value' => $secondary['placeholder'],
				),
			),
			'back_fields'       => array(
				array(
					'label' => __( 'Lifetime points', 'epasscard' ),
					'value' => '{Lifetime Points}',
				),
				array(
					'label' => __( 'Email', 'epasscard' ),
					'value' => '{Email}',
				),
				array(
					'label' => __( 'Rewards', 'epasscard' ),
					'value' => '{Reward Summary}',
				),
			),
			'barcode'           => array(
				'format' => (string) $design['barcode_format'],
				'value'  => '{Member No}',
			),
			'fields'            => array(
				array(
					'name'     => 'Points',
					'type'     => 'number',
					'required' => true,
					'unique'   => false,
				),
				array(
					'name'     => 'Name',
					'type'     => 'text',
					'required' => true,
					'unique'   => false,
				),
				array(
					'name'     => $secondary['name'],
					'type'     => 'text',
					'required' => false,
					'unique'   => false,
				),
				array(
					'name'     => 'Member No',
					'type'     => 'text',
					'required' => true,
					'unique'   => true,
				),
				array(
					'name'     => 'Lifetime Points',
					'type'     => 'number',
					'required' => false,
					'unique'   => false,
				),
				array(
					'name'     => 'Email',
					'type'     => 'email',
					'required' => false,
					'unique'   => false,
				),
				array(
					'name'     => 'Reward Summary',
					'type'     => 'text',
					'required' => false,
					'unique'   => false,
				),
			),
		);

		if ( ! empty( $design['description'] ) ) {
			$payload['description'] = (string) $design['description'];
		}

		/**
		 * Filter the loyalty template payload sent to API v2.
		 *
		 * @param array<string, mixed> $payload Remote payload.
		 * @param array<string, mixed> $design  Local design.
		 */
		return (array) apply_filters( 'epc_loyalty_pass_template_payload', $payload, $design );
	}

	/**
	 * Write the loyalty program mapping from template field UIDs.
	 *
	 * @param array<string, mixed> $design Saved design.
	 * @return void
	 */
	public static function sync_program_mapping( array $design ) {
		$module = epc_plugin()->get_module( 'woocommerce-loyalty' );
		if ( ! $module instanceof EPC_Module_WooCommerce_Loyalty ) {
			$module = epc_plugin()->get_all_modules()['woocommerce-loyalty'] ?? null;
		}
		if ( ! $module instanceof EPC_Module ) {
			return;
		}

		$template_uid = (string) ( $design['template_uid'] ?? '' );
		if ( '' === $template_uid ) {
			return;
		}

		$field_mapping = array();
		$field_uids    = isset( $design['field_uids'] ) && is_array( $design['field_uids'] ) ? $design['field_uids'] : array();
		$map           = self::placeholder_source_map();

		foreach ( $field_uids as $name => $uid ) {
			$normalized = strtolower( trim( (string) $name ) );
			if ( ! isset( $map[ $normalized ] ) ) {
				continue;
			}
			$field_mapping[ $uid ] = array(
				'type'   => 'source',
				'source' => $map[ $normalized ],
			);
		}

		if ( empty( $field_mapping ) ) {
			$fields = EPC_Api_Client::get_pass_fields( $template_uid );
			if ( ! is_wp_error( $fields ) ) {
				foreach ( (array) $fields['passFields'] as $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}
					$uid  = isset( $field['uid'] ) ? EPC_Api_Client::sanitize_uid( (string) $field['uid'] ) : false;
					$name = isset( $field['name'] ) ? strtolower( trim( (string) $field['name'] ) ) : '';
					if ( false === $uid || '' === $name || ! isset( $map[ $name ] ) ) {
						continue;
					}
					$field_mapping[ $uid ] = array(
						'type'   => 'source',
						'source' => $map[ $name ],
					);
				}
			}
		}

		$module->save_mapping(
			self::PROGRAM_ENTITY_ID,
			array(
				'template_uid'  => $template_uid,
				'template_name' => (string) $design['template_name'],
				'field_mapping' => $field_mapping,
				'source'        => 'loyalty_designer',
			)
		);
	}

	/**
	 * Issue or refresh a test pass for a WordPress user.
	 *
	 * @param int $user_id User ID.
	 * @return true|\WP_Error
	 */
	public static function issue_test_pass( $user_id ) {
		$user_id = absint( $user_id );
		$design  = self::get_design();
		if ( '' === (string) $design['template_uid'] ) {
			return new WP_Error( 'epc_loyalty_design_missing', __( 'Save the loyalty pass design before issuing a test pass.', 'epasscard' ) );
		}

		self::sync_program_mapping( $design );

		$module = epc_plugin()->get_module( 'woocommerce-loyalty' );
		if ( ! $module instanceof EPC_Module_WooCommerce_Loyalty ) {
			return new WP_Error( 'epc_loyalty_module_inactive', __( 'Enable the WooCommerce Loyalty module to issue a test pass.', 'epasscard' ) );
		}

		return $module->sync_by_source_id( $user_id, 'sync' );
	}

	/**
	 * Representative preview values for the admin designer.
	 *
	 * @return array<string, string>
	 */
	public static function preview_sample_values() {
		return array(
			'points'          => '1250',
			'name'            => 'Alex Member',
			'tier'            => 'Gold',
			'next_reward'     => 'Free Drink',
			'milestone'       => '800 / 1000',
			'member_id'       => 'LYL-DEMO-001',
			'lifetime_points' => '4200',
			'email'           => 'alex@example.com',
			'reward_summary'  => '1 reward available',
		);
	}

	/**
	 * Public image URL for a real QR/barcode preview of sample membership data.
	 *
	 * @param string $format Barcode format (QR, PDF417, AZTEC, CODE128).
	 * @param string $value  Encoded value.
	 * @return string
	 */
	public static function preview_barcode_image_url( $format, $value ) {
		$format = strtoupper( sanitize_text_field( (string) $format ) );
		$value  = (string) $value;
		if ( '' === $value ) {
			$value = 'LYL-DEMO-001';
		}

		$bcid = 'qrcode';
		if ( 'PDF417' === $format ) {
			$bcid = 'pdf417';
		} elseif ( 'AZTEC' === $format ) {
			$bcid = 'azteccode';
		} elseif ( 'CODE128' === $format ) {
			$bcid = 'code128';
		}

		return add_query_arg(
			array(
				'bcid'            => $bcid,
				'text'            => $value,
				'scale'           => 3,
				'backgroundcolor' => 'ffffff',
			),
			'https://bwipjs-api.metafloor.com/'
		);
	}

	/**
	 * Secondary field placeholder for the configured mode.
	 *
	 * @param array<string, mixed> $design Design.
	 * @return array{name: string, placeholder: string, source: string}
	 */
	private static function secondary_placeholder( array $design ) {
		$mode = (string) ( $design['secondary_mode'] ?? 'tier' );
		if ( 'next_reward' === $mode ) {
			return array(
				'name'        => 'Next Reward',
				'placeholder' => '{Next Reward}',
				'source'      => 'next_reward',
			);
		}
		if ( 'milestone' === $mode ) {
			return array(
				'name'        => 'Milestone',
				'placeholder' => '{Milestone}',
				'source'      => 'milestone',
			);
		}

		return array(
			'name'        => 'Tier',
			'placeholder' => '{Tier}',
			'source'      => 'tier',
		);
	}

	/**
	 * @param array<string, mixed> $remote Remote create/update data.
	 * @return array<string, string>
	 */
	private static function extract_field_uids( array $remote ) {
		$out = array();
		if ( empty( $remote['fields'] ) || ! is_array( $remote['fields'] ) ) {
			return $out;
		}

		foreach ( $remote['fields'] as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name = isset( $field['name'] ) ? sanitize_text_field( (string) $field['name'] ) : '';
			$uid  = isset( $field['uid'] ) ? EPC_Api_Client::sanitize_uid( (string) $field['uid'] ) : false;
			if ( '' !== $name && false !== $uid ) {
				$out[ $name ] = $uid;
			}
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $fields Pass field rows.
	 * @return array<string, string>
	 */
	private static function extract_field_uids_from_pass_fields( array $fields ) {
		$out = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name = isset( $field['name'] ) ? sanitize_text_field( (string) $field['name'] ) : '';
			$uid  = isset( $field['uid'] ) ? EPC_Api_Client::sanitize_uid( (string) $field['uid'] ) : false;
			if ( '' !== $name && false !== $uid ) {
				$out[ $name ] = $uid;
			}
		}
		return $out;
	}

	/**
	 * @param string $value Raw color.
	 * @return string
	 */
	private static function sanitize_hex_color_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( function_exists( 'sanitize_hex_color' ) ) {
			$san = sanitize_hex_color( $value );
			return is_string( $san ) ? $san : '';
		}
		return preg_match( '/^#[0-9A-Fa-f]{6}$/', $value ) ? strtoupper( $value ) : '';
	}

	/**
	 * Store blank when unused; placeholders and datetimes otherwise.
	 *
	 * @param string $value Expire input.
	 * @return string
	 */
	private static function sanitize_expire_date_for_storage( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\{.+\}$/', $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}(?:\s+\d{2}:\d{2}:\d{2})?$/', $value ) ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return $value . ' 23:59:00';
			}
			return $value;
		}

		return '';
	}

	/**
	 * Expire date sent to the API (blank → ~99 years for near-lifetime passes).
	 *
	 * @param string $value Stored expire value.
	 * @return string
	 */
	public static function resolve_expire_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '+99 years' ) );
		}
		if ( preg_match( '/^\{.+\}$/', $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}(?:\s+\d{2}:\d{2}:\d{2})?$/', $value ) ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return $value . ' 23:59:00';
			}
			return $value;
		}

		return gmdate( 'Y-m-d H:i:s', strtotime( '+99 years' ) );
	}
}
