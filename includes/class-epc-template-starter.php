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
	 * @return array<string, mixed>
	 */
	private static function field( $name, $label, $source, $required = false, $unique = false ) {
		return array(
			'name'     => $name,
			'label'    => $label,
			'source'   => $source,
			'required' => $required,
			'unique'   => $unique,
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
			'header'    => array( self::field( 'Status', __( 'Status', 'epasscard' ), $src['status'] ) ),
			'primary'   => array( self::field( 'Plan', __( 'Plan', 'epasscard' ), $src['plan'] ) ),
			'secondary' => array(
				self::field( 'Name', __( 'Name', 'epasscard' ), 'user_full_name' ),
				self::field( 'Expires', '' !== $expires_label ? $expires_label : __( 'Expires', 'epasscard' ), $src['expires'] ),
			),
			'auxiliary' => array(),
			'back'      => array(
				self::field( 'Email', __( 'Email', 'epasscard' ), 'user_email' ),
			),
			'barcode'   => self::field( 'Member ID', __( 'Member ID', 'epasscard' ), $src['id'], true, true ),
		);
	}

	/**
	 * Event-style preset.
	 *
	 * @param array<string, string> $src  Sources: date, event, ticket, venue, id.
	 * @param array<int, array>     $back Extra back fields.
	 * @param string                $ticket_label Label for the ticket field.
	 * @param string                $id_label     Label for the ticket number.
	 * @return array<string, mixed>
	 */
	private static function event_preset( array $src, array $back, $ticket_label, $id_label ) {
		return array(
			'type'      => 'events',
			'card_type' => 'Event',
			'header'    => array( self::field( 'Date', __( 'Date', 'epasscard' ), $src['date'] ) ),
			'primary'   => array( self::field( 'Event', __( 'Event', 'epasscard' ), $src['event'] ) ),
			'secondary' => array(
				self::field( 'Name', __( 'Name', 'epasscard' ), 'user_full_name' ),
				self::field( 'Ticket', $ticket_label, $src['ticket'] ),
			),
			'auxiliary' => array(
				self::field( 'Venue', __( 'Venue', 'epasscard' ), $src['venue'] ),
			),
			'back'      => array_merge(
				$back,
				array( self::field( 'Email', __( 'Email', 'epasscard' ), 'user_email' ) )
			),
			'barcode'   => self::field( 'Ticket No', $id_label, $src['id'], true, true ),
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
			'header'    => array( self::field( 'Expires', __( 'Expires', 'epasscard' ), 'expire_date' ) ),
			'primary'   => array( self::field( 'Balance', __( 'Balance', 'epasscard' ), 'balance_formatted' ) ),
			'secondary' => array(
				self::field( 'To', __( 'To', 'epasscard' ), $to_source ),
				self::field( 'From', __( 'From', 'epasscard' ), $from_source ),
			),
			'auxiliary' => array(),
			'back'      => array(
				self::field( 'Gift card', __( 'Gift card', 'epasscard' ), 'product_name' ),
				self::field( 'Message', __( 'Message', 'epasscard' ), 'message' ),
			),
			'barcode'   => self::field( 'Card code', __( 'Card code', 'epasscard' ), 'card_number', true, true ),
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
					self::field( 'Address', __( 'Address', 'epasscard' ), 'venue_address' ),
					self::field( 'Organizer', __( 'Organizer', 'epasscard' ), 'organizer_name' ),
					self::field( 'Event page', __( 'Event page', 'epasscard' ), 'event_url' ),
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
					self::field( 'Booking status', __( 'Booking status', 'epasscard' ), 'booking_status' ),
				),
				__( 'Spaces', 'epasscard' ),
				__( 'Booking No', 'epasscard' )
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

	/**
	 * Build the v2 create-pass-template payload.
	 *
	 * @param EPC_Module           $module Module.
	 * @param array<string, mixed> $preset Preset.
	 * @return array<string, mixed>
	 */
	public static function build_payload( $module, array $preset ) {
		$site  = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$org   = '' !== $site ? $site : 'EpassCard';
		$media = EPC_Setup_Wizard::get_default_pass_media();

		$expire = class_exists( 'EPC_Loyalty_Pass_Design_Service' )
			? EPC_Loyalty_Pass_Design_Service::resolve_expire_date( '' )
			: gmdate( 'Y-m-d H:i:s', strtotime( '+99 years' ) );

		$to_pass_fields = static function ( array $fields ) {
			$out = array();
			foreach ( $fields as $field ) {
				$out[] = array(
					'label' => (string) $field['label'],
					'value' => '{' . $field['name'] . '}',
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
			/* translators: 1: site name, 2: integration name */
			'template_name'     => sprintf( __( '%1$s – %2$s', 'epasscard' ), $org, $module->get_label() ),
			'organization_name' => $org,
			'certificate'       => 'pass.com.epasscard.public',
			'card_type'         => (string) $preset['card_type'],
			'logo'              => (string) ( $media['logo_url'] ?? '' ),
			'icon'              => (string) ( $media['logo_url'] ?? '' ),
			'strip_image'       => (string) ( $media['strip_url'] ?? '' ),
			'pass_limit'        => 0,
			'expire_date'       => $expire,
			'colors'            => array(
				'background' => '#1E1B4B',
				'text'       => '#FFFFFF',
				'label'      => '#C7D2FE',
			),
			'header_fields'     => $to_pass_fields( (array) $preset['header'] ),
			'secondary_fields'  => $to_pass_fields( (array) $preset['secondary'] ),
			'barcode'           => array(
				'format' => 'QR',
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
		$back   = (array) $preset['back'];
		$back[] = $preset['barcode'];
		$payload['back_fields'] = $to_pass_fields( $back );

		/**
		 * Filter the create-template payload for a ready-made design.
		 *
		 * @param array      $payload Payload.
		 * @param EPC_Module $module  Module.
		 * @param array      $preset  Preset.
		 */
		return (array) apply_filters( 'epc_starter_template_payload', $payload, $module, $preset );
	}

	/**
	 * Create the module's template (once) and save it.
	 *
	 * @param EPC_Module $module Module.
	 * @return array<string, mixed>|\WP_Error Saved record.
	 */
	public static function create_for_module( $module ) {
		$slug   = $module->get_slug();
		$preset = self::get_preset( $slug );
		if ( null === $preset ) {
			return new WP_Error( 'epc_starter_unsupported', __( 'This integration has no ready-made pass design.', 'epasscard' ) );
		}

		$existing = self::get_saved( $slug );
		if ( null !== $existing ) {
			return $existing;
		}

		// One create at a time per integration (double clicks, two tabs).
		$lock = 'epc_starter_lock_' . $slug;
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'epc_starter_busy', __( 'The pass design is already being created. Wait a moment and reload the page.', 'epasscard' ) );
		}
		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

		$payload = self::build_payload( $module, $preset );
		$result  = EPC_Api_Client::create_pass_template_v2( $payload, $slug . ':create_pass_template' );

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
			'created_at'    => current_time( 'mysql' ),
		);
		update_option( self::OPTION_PREFIX . $slug, $record, false );

		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( $slug . ':get_pass_fields' );
		}
		$fields = EPC_Api_Client::get_pass_fields( $uid );
		if ( ! is_wp_error( $fields ) && ! empty( $fields['passFields'] ) && is_array( $fields['passFields'] ) ) {
			$record['pass_fields'] = $fields['passFields'];
		} elseif ( ! empty( $result['fields'] ) && is_array( $result['fields'] ) ) {
			$record['pass_fields'] = $result['fields'];
		}

		$record['field_mapping'] = self::build_field_mapping( $preset, $record['pass_fields'] );
		update_option( self::OPTION_PREFIX . $slug, $record, false );
		delete_transient( $lock );

		return $record;
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
			$name = strtolower( trim( (string) ( $pass_field['field_name'] ?? $pass_field['name'] ?? '' ), "{} \t" ) );
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

	/**
	 * AJAX: create (op=create) or apply (op=apply) the design.
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

		$created = false;
		if ( 'create' === $op && null === self::get_saved( $slug ) ) {
			$record = self::create_for_module( $module );
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
				$labels[] = (string) $field['label'];
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
						esc_html( _n( 'EpassCard creates a ready-made pass with %1$s and a QR code, and maps it to the %2$d item below that is not mapped yet. You can change the design later in your EpassCard account.', 'EpassCard creates a ready-made pass with %1$s and a QR code, and maps it to the %2$d items below that are not mapped yet. You can change the design later in your EpassCard account.', $unmapped, 'epasscard' ) ),
						esc_html( implode( ', ', $labels ) ),
						(int) $unmapped
					);
					?>
				</p>
				<p class="epc-starter-actions">
					<button type="button" class="button button-primary epc-starter-action" data-op="create" data-module="<?php echo esc_attr( $slug ); ?>">
						<?php esc_html_e( 'Create pass design for me', 'epasscard' ); ?>
					</button>
					<span class="epc-starter-status" aria-live="polite"></span>
				</p>
				<p class="description">
					<?php esc_html_e( 'This uses one template in your EpassCard account. The Free plan includes 3 templates and 50 live passes.', 'epasscard' ); ?>
					<a href="<?php echo esc_url( self::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade your plan', 'epasscard' ); ?></a>
				</p>
			<?php else : ?>
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
						esc_html__( 'Used by %1$d of %2$d items.', 'epasscard' ),
						(int) $using,
						count( $entities )
					);
					?>
					<a href="<?php echo esc_url( 'https://app.epasscard.com/pass-templates' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Change the design in EpassCard', 'epasscard' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
				</p>
				<?php if ( $unmapped > 0 ) : ?>
					<p class="epc-starter-actions">
						<button type="button" class="button button-primary epc-starter-action" data-op="apply" data-module="<?php echo esc_attr( $slug ); ?>">
							<?php
							printf(
								/* translators: %d: number of unmapped items */
								esc_html( _n( 'Use it for the %d unmapped item', 'Use it for the %d unmapped items', $unmapped, 'epasscard' ) ),
								(int) $unmapped
							);
							?>
						</button>
						<span class="epc-starter-status" aria-live="polite"></span>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
