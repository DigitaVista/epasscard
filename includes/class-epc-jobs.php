<?php
/**
 * Jobs: what the merchant gives customers (loyalty card, membership card, ticket, gift card).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only map of jobs to the modules that do them, and how far each job is. Adds no storage.
 */
class EPC_Jobs {

	/**
	 * Readiness states, in order.
	 */
	const NOT_INSTALLED = 'not_installed';
	const NEEDS_SETUP   = 'needs_setup';
	const READY         = 'ready';
	const LIVE          = 'live';

	/**
	 * Jobs keyed by slug, each with a label and its module slugs.
	 *
	 * The setup wizard's goals are the single source, so the wizard and the job
	 * reports always agree.
	 *
	 * @return array<string, array{label: string, modules: array<int, string>}>
	 */
	public static function get_jobs() {
		$jobs = array();
		foreach ( EPC_Setup_Wizard::get_goals() as $slug => $goal ) {
			$jobs[ (string) $slug ] = array(
				'label'   => (string) $goal['label'],
				'modules' => array_values( array_map( 'strval', (array) $goal['modules'] ) ),
			);
		}

		/**
		 * Filter the jobs and the modules that belong to each one.
		 *
		 * Integrations added by other plugins can add their module to a job here.
		 *
		 * @param array<string, array{label: string, modules: array<int, string>}> $jobs Jobs.
		 */
		$filtered = apply_filters( 'epc_jobs', $jobs );

		return is_array( $filtered ) ? $filtered : $jobs;
	}

	/**
	 * Words the screens use for a job.
	 *
	 * @param string $job Job slug.
	 * @return array{title: string, pitch: string, goal: string, items: string, no_items: string, needs: string}
	 */
	public static function get_copy( $job ) {
		$copy = array(
			'loyalty'    => array(
				'title'    => __( 'Loyalty card for my store', 'epasscard' ),
				'pitch'    => __( 'Customers earn points on every order and see them on a card in their phone wallet.', 'epasscard' ),
				'goal'     => __( 'Get your loyalty card live', 'epasscard' ),
				'items'    => __( 'loyalty program', 'epasscard' ),
				'no_items' => '',
				'needs'    => __( 'Needs WooCommerce', 'epasscard' ),
			),
			'membership' => array(
				'title'    => __( 'Member card for my members', 'epasscard' ),
				'pitch'    => __( 'A card with name, plan and expiry. It is revoked when the membership ends.', 'epasscard' ),
				'goal'     => __( 'Get your member card live', 'epasscard' ),
				'items'    => __( 'membership plans', 'epasscard' ),
				'no_items' => __( 'No membership plans yet', 'epasscard' ),
				'needs'    => __( 'Needs a membership plugin', 'epasscard' ),
			),
			'events'     => array(
				'title'    => __( 'Tickets for my events', 'epasscard' ),
				'pitch'    => __( 'Every attendee gets a wallet ticket with a QR code.', 'epasscard' ),
				'goal'     => __( 'Get your event tickets live', 'epasscard' ),
				'items'    => __( 'events', 'epasscard' ),
				'no_items' => __( 'No events yet', 'epasscard' ),
				'needs'    => __( 'Needs an events plugin', 'epasscard' ),
			),
			'gift-cards' => array(
				'title'    => __( 'Gift cards with a live balance', 'epasscard' ),
				'pitch'    => __( 'The card in the wallet shows the remaining balance.', 'epasscard' ),
				'goal'     => __( 'Get your gift cards live', 'epasscard' ),
				'items'    => __( 'gift card products', 'epasscard' ),
				'no_items' => __( 'No gift card products yet', 'epasscard' ),
				'needs'    => __( 'Needs a gift card plugin', 'epasscard' ),
			),
			'products'   => array(
				'title'    => __( 'Tickets and vouchers I sell', 'epasscard' ),
				'pitch'    => __( 'Sell a ticket, voucher or pass as a WooCommerce product. Each one bought becomes a card in the buyer\'s wallet.', 'epasscard' ),
				'goal'     => __( 'Get your product cards live', 'epasscard' ),
				'items'    => __( 'products with a wallet card', 'epasscard' ),
				'no_items' => __( 'No product has a wallet card yet', 'epasscard' ),
				'needs'    => __( 'Needs WooCommerce', 'epasscard' ),
			),
			'appointments' => array(
				'title'    => __( 'Cards for my appointments', 'epasscard' ),
				'pitch'    => __( 'Each booked appointment becomes a wallet card with the date, time and place. It expires the day after.', 'epasscard' ),
				'goal'     => __( 'Get your appointment cards live', 'epasscard' ),
				'items'    => __( 'services', 'epasscard' ),
				'no_items' => __( 'No services yet', 'epasscard' ),
				'needs'    => __( 'Needs a booking plugin', 'epasscard' ),
			),
			'forms'      => array(
				'title'    => __( 'Cards from my forms', 'epasscard' ),
				'pitch'    => __( 'Everyone who fills in your form gets a wallet card, linked on the thank-you message and sent by email.', 'epasscard' ),
				'goal'     => __( 'Get your form cards live', 'epasscard' ),
				'items'    => __( 'forms', 'epasscard' ),
				'no_items' => __( 'No published forms yet', 'epasscard' ),
				'needs'    => __( 'Needs a form plugin', 'epasscard' ),
			),
		);

		if ( isset( $copy[ $job ] ) ) {
			return $copy[ $job ];
		}

		$jobs  = self::get_jobs();
		$label = isset( $jobs[ $job ]['label'] ) ? (string) $jobs[ $job ]['label'] : (string) $job;
		return array(
			'title'    => $label,
			'pitch'    => '',
			'goal'     => $label,
			'items'    => __( 'items', 'epasscard' ),
			'no_items' => __( 'Nothing to map yet', 'epasscard' ),
			'needs'    => __( 'Needs a plugin', 'epasscard' ),
		);
	}

	/**
	 * Title of one integration's card (Design, Cards). Tickets, vouchers and product cards share
	 * a job, so each gets its own title; the others use their job's title.
	 *
	 * @param string $module_slug Module slug.
	 * @return string
	 */
	public static function get_module_title( $module_slug ) {
		$module_slug = sanitize_key( (string) $module_slug );
		$titles      = array(
			'woocommerce-tickets'       => __( 'Tickets I sell', 'epasscard' ),
			'woocommerce-vouchers'      => __( 'Vouchers I sell', 'epasscard' ),
			'woocommerce-product-cards' => __( 'Passes and cards I sell', 'epasscard' ),
		);
		if ( isset( $titles[ $module_slug ] ) ) {
			return $titles[ $module_slug ];
		}
		return (string) self::get_copy( self::get_job_for_module( $module_slug ) )['title'];
	}

	/**
	 * Job a module belongs to.
	 *
	 * @param string $module_slug Module slug.
	 * @return string Job slug, or '' when the module belongs to no job.
	 */
	public static function get_job_for_module( $module_slug ) {
		$module_slug = sanitize_key( (string) $module_slug );
		foreach ( self::get_jobs() as $job => $data ) {
			if ( isset( $data['modules'] ) && in_array( $module_slug, (array) $data['modules'], true ) ) {
				return (string) $job;
			}
		}
		return '';
	}

	/**
	 * Modules of a job whose host plugin is active.
	 *
	 * @param string $job Job slug.
	 * @return array<string, EPC_Module>
	 */
	public static function available_modules( $job ) {
		$jobs = self::get_jobs();
		$out  = array();
		foreach ( (array) ( $jobs[ $job ]['modules'] ?? array() ) as $slug ) {
			$module = EPC_Module_Loader::get_module_instance( (string) $slug );
			if ( $module instanceof EPC_Module && $module->is_available() ) {
				$out[ (string) $slug ] = $module;
			}
		}
		return $out;
	}

	/**
	 * Plugin names a job works with (for "Works with …").
	 *
	 * @param string $job Job slug.
	 * @return array<int, string>
	 */
	public static function plugin_names( $job ) {
		$jobs  = self::get_jobs();
		$names = array();
		foreach ( (array) ( $jobs[ $job ]['modules'] ?? array() ) as $slug ) {
			$module = EPC_Module_Loader::get_module_instance( (string) $slug );
			if ( $module instanceof EPC_Module ) {
				$names[] = $module->get_dependency_label();
			}
		}
		return array_values( array_unique( array_filter( $names ) ) );
	}

	/**
	 * Whether a module has a card design mapped to at least one item.
	 *
	 * @param EPC_Module $module Module.
	 * @return bool
	 */
	public static function has_design( $module ) {
		if ( 'woocommerce-loyalty' === $module->get_slug() && class_exists( 'EPC_Loyalty_Pass_Design_Service' ) ) {
			$design = EPC_Loyalty_Pass_Design_Service::get_design();
			return '' !== (string) ( $design['template_uid'] ?? '' );
		}
		foreach ( $module->get_mappings() as $mapping ) {
			if ( is_array( $mapping ) && ! empty( $mapping['template_uid'] ) ) {
				return true;
			}
		}
		return class_exists( 'EPC_Default_Design' ) && null !== EPC_Default_Design::get( $module->get_slug() );
	}

	/**
	 * How far a job is.
	 *
	 * @param string $job       Job slug.
	 * @param string $preferred Module to report on when the job has several (e.g. the one chosen in Setup).
	 * @return array{state: string, module: string, blocker: string, items: int}
	 */
	public static function get_status( $job, $preferred = '' ) {
		$copy      = self::get_copy( $job );
		$available = self::available_modules( $job );
		$status    = array(
			'state'   => self::NOT_INSTALLED,
			'module'  => '',
			'blocker' => $copy['needs'],
			'items'   => 0,
		);
		if ( empty( $available ) ) {
			return $status;
		}

		// The preferred module, else an enabled one with a design, else an enabled one, else the first.
		$slug = isset( $available[ $preferred ] ) ? $preferred : '';
		if ( '' === $slug ) {
			foreach ( $available as $candidate => $module ) {
				if ( EPC_Module_Settings::is_enabled( $candidate ) && self::has_design( $module ) ) {
					$slug = $candidate;
					break;
				}
			}
		}
		if ( '' === $slug ) {
			foreach ( array_keys( $available ) as $candidate ) {
				if ( EPC_Module_Settings::is_enabled( $candidate ) ) {
					$slug = $candidate;
					break;
				}
			}
		}
		if ( '' === $slug ) {
			$slug = (string) array_keys( $available )[0];
		}

		$module            = $available[ $slug ];
		$status['module']  = $slug;
		$status['state']   = self::NEEDS_SETUP;
		$status['items']   = count( $module->get_filtered_mappable_entities() );

		if ( ! EPC_Connection::is_connected() ) {
			$status['blocker'] = __( 'Connect EpassCard first', 'epasscard' );
		} elseif ( 0 === $status['items'] && '' !== $copy['no_items'] ) {
			$status['blocker'] = $copy['no_items'];
		} elseif ( ! EPC_Module_Settings::is_enabled( $slug ) ) {
			$status['blocker'] = __( 'Not turned on yet', 'epasscard' );
		} elseif ( ! self::has_design( $module ) ) {
			$status['blocker'] = __( 'No card design yet', 'epasscard' );
		} elseif ( EPC_DB::count_real_passes( $slug, 'active' ) > 0 ) {
			$status['state']   = self::LIVE;
			$status['blocker'] = '';
		} else {
			$status['state']   = self::READY;
			$status['blocker'] = '';
		}

		return $status;
	}
}
