<?php
/*
Plugin Name: EA WC - USPS Shipping Updates
Plugin URI: https://github.com/EnhancedAthlete/ea-wc-usps-shipping-updates
Description: Updates order status to show out for delivery and delivered. Fires an action on every USPS status update.
Version: 0.5
Author: BrianHenryIE
Author URI: http://BrianHenry.ie
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 3.0.0
WC tested up to: 3.4.3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, array( 'EA_WC_USPS_Shipping_Updates', 'on_activation' ) );
register_deactivation_hook(__FILE__, array( 'EA_WC_USPS_Shipping_Updates', 'on_deactivation' ) );

class EA_WC_USPS_Shipping_Updates {

	public const LAST_CHECKED_ORDER_DATE_TRANSIENT_KEY = 'ea-wc-usps-shipping-updates-last-checked-order-date';

	public const ORDER_USPS_STATUS_META_KEY = 'ea-wc-usps-shipping-updates-status';
	public const ORDER_USPS_DETAIL_META_KEY = 'ea-wc-usps-shipping-updates-detail';

	public const CRON_JOB_ID = 'ea-wc-usps-shipping-updates';

	public const PACKING_COMPLETE_WC_STATUS = 'wc-ea-packed';
	public const USPS_IN_TRANSIT_WC_STATUS = 'wc-ea-in-transit'; // USPS have scanned the package
	public const USPS_RETURNED_WC_STATUS = 'wc-ea-usps-returned';

	public const COMPLETED_WC_STATUS = 'wc-completed';

	private const MAX_TRACKING_IDS_PER_USPS_API_CALL = 35;

	/** @var EA_WC_USPS_Shipping_Updates_Integration */
	private $settings;

	/** @var WC_Logger */
	private $logger;

	/** @var Slince\ShipmentTracking\USPS\USPSTracker */
	private $tracker;

	/** @var WC_EMail */
	private $email_classes;

	public function __construct() {

		add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'add_plugin_page_links' ) );

		add_action( 'plugins_loaded', array( $this, 'configure_settings' ) );

		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_status_update_actions_filter' ), 25, 1 );

		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_status_update_actions' ), 10, 3 );

		add_action( 'woocommerce_init', array( $this, 'init' ), 21 );

		add_action( self::CRON_JOB_ID, array( $this, 'check_orders' ) );
	}

	/**
	 * Load the existing settings for use and enqueue the Settings admin page in WooCommerce
	 */
	public function configure_settings() {

		if ( class_exists( 'WC_Integration' ) ) {

			include_once 'includes/class-ea-wc-usps-shipping-updates-integration.php';

			add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );

			// TODO: Instantiating the class here and in the UI list will add the action hooks twice
			$this->settings = new EA_WC_USPS_Shipping_Updates_Integration();
		}
	}

	/**
	 * Add the plugin's settings page under WooCommerce Settings / Integrations
	 *
	 * @see https://docs.woocommerce.com/document/implementing-wc-integration/
	 *
	 * @param WC_Integration[] $integrations
	 *
	 * @return array
	 */
	public function add_integration( $integrations ) {

		$integrations[] = 'EA_WC_USPS_Shipping_Updates_Integration';

		return $integrations;
	}

	public function init() {

		if( $this->settings->logging_enabled ) {
			$this->logger = wc_get_logger();
		}

		if ( ! $this->settings->usps_user_id ) {
			return;
		}

		self::register_statuses();

		add_filter( 'wc_order_statuses', array( $this, 'wc_order_statuses_filter' ), 22, 1 );

		add_filter( 'woocommerce_email_classes', array( $this, 'woocommerce_email_classes_filter' ) );

	}

	protected static $_instance = null;

	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Add link on the WordPress plugin list to the plugin's settings page and logs
	 *
	 * @param string[] $links
	 *
	 * @return array
	 */
	function add_plugin_page_links( $links ) {

		$configure_url = admin_url('/admin.php?page=wc-settings&tab=integration&section=ea-wc-usps-shipping-updates-integration');
		$logs_url = admin_url('/admin.php?page=wc-status&tab=logs&source=ea-wc-usps-shipping-updates');

		array_unshift( $links, '<a href="' . $logs_url . '">View Logs</a>' );
		array_unshift( $links, '<a href="' . $configure_url . '">Configure</a>' );

		return $links;
	}

	public static function on_activation() {

		wp_schedule_event( time(), 'hourly', self::CRON_JOB_ID );
	}

	public static function on_deactivation() {

		wp_clear_scheduled_hook( self::CRON_JOB_ID );
	}

	public static function register_statuses() {

		register_post_status( self::PACKING_COMPLETE_WC_STATUS, array(
			'label'                     => 'Packing complete',
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Packed (%s)', 'Packed (%s)', 'ea-wc-usps-shipping-updates' )
		) );

		register_post_status( self::USPS_IN_TRANSIT_WC_STATUS, array(
			'label'                     => 'USPS in transit',
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Dispatched through USPS (%s)', 'Dispatched through USPS (%s)', 'ea-wc-usps-shipping-updates' )
		) );

		register_post_status( self::USPS_RETURNED_WC_STATUS, array(
			'label'                     => 'USPS Returned',
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'USPS Returned (%s)', 'USPS Returned (%s)', 'ea-wc-usps-shipping-updates' )
		) );
	}

	/**
	 * Add our custom order statuses to WooCommerce UI, after the wc-processing status
	 *
	 * @param string[] $order_statuses
	 *
	 * @return string[]
	 */
	public function wc_order_statuses_filter( $order_statuses ) {

		$new_order_statuses = array();

		$insert_after = 'wc-processing';

		foreach ( $order_statuses as $key => $status ) {

			$new_order_statuses[ $key ] = $status;

			if ( $insert_after === $key ) {
				$new_order_statuses[ self::PACKING_COMPLETE_WC_STATUS ] = __( 'Packing complete', 'ea-wc-usps-shipping-updates' );
				$new_order_statuses[ self::USPS_IN_TRANSIT_WC_STATUS ]  = __( 'USPS in transit', 'ea-wc-usps-shipping-updates' );
				$new_order_statuses[ self::USPS_RETURNED_WC_STATUS ]  = __( 'USPS Returned', 'ea-wc-usps-shipping-updates' );
			}
		}

		return $new_order_statuses;
	}

	/**
	 * Define bulk actions for new statuses on order list page
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public function bulk_status_update_actions_filter( $actions ) {

		$new_actions = array();

		foreach( $actions as $key => $value ) {
			$new_actions[ $key ] = $value;

			if( $key == 'mark_processing' ) {

				$new_actions[ 'mark_packing_complete']  = __( 'Change status to packing complete', 'ea-wc-usps-shipping-updates' );

				// There's no huge need for this here, since the whole point in the plugin is to do it automatically.
				$new_actions[ 'mark_usps_in_transit' ]  = __( 'Change status to USPS in transit', 'ea-wc-usps-shipping-updates' );
				$new_actions[ 'mark_usps_returned' ]  = __( 'Change status to USPS Returned', 'ea-wc-usps-shipping-updates' );

			}
		}

		return $new_actions;
	}

	public function handle_bulk_status_update_actions( $redirect_to, $action, $post_ids ) {

		$updates = array(
			'mark_packing_complete' => self::PACKING_COMPLETE_WC_STATUS,
			'mark_usps_in_transit' => self::USPS_IN_TRANSIT_WC_STATUS,
			'mark_usps_returned' => self::USPS_RETURNED_WC_STATUS
		);

		if ( !array_key_exists( $action, $updates ) )
			return $redirect_to; // Exit

		foreach ( $post_ids as $post_id ) {
			$order = wc_get_order( $post_id );

			$order->set_status( $updates[ $action ] );

			$order->save();
		}

		return $redirect_to;
	}

	/**
	 * Adds our custom emails to WooCommerce
	 *
	 * @param WC_Email[] $email_classes
	 *
	 * @return WC_Email[]
	 */
	function woocommerce_email_classes_filter( $email_classes ) {

		// Requiring here avoids the class instantiating before WooCommerce's classes are available to be extended
		require( 'includes/emails/class-ea-wc-dispatched-order-email.php' );
		require( 'includes/emails/class-ea-wc-out-for-delivery-order-email.php' );
		require( 'includes/emails/class-ea-wc-delivered-order-email.php' );

		// To display chronologically on the WooCommerce settings' email tab
		$insert_after = 'WC_Email_Customer_Processing_Order';

		foreach ( $email_classes as $key => $email_class ) {

			$new_email_classes[ $key ] = $email_class;

			if ( $insert_after === $key ) {
				$new_email_classes['EA_WC_Dispatched_Order_Email'] = new EA_WC_Dispatched_Order_Email();
				$new_email_classes['EA_WC_Out_For_Delivery_Order_Email'] = new EA_WC_Out_For_Delivery_Order_Email();
				$new_email_classes['EA_WC_Delivered_Order_Email'] = new EA_WC_Delivered_Order_Email();
			}
		}

		$this->email_classes = $new_email_classes;

		return $new_email_classes;
	}

	/**
	 * Cron job method itself, each time checking orders then scheduling the next event if more orders are to be checked
	 */
	public function check_orders() {

		if ( ! $this->settings->usps_user_id ) {

			// Warnign is in cron so it doesn't pollute the logs every second
			error_log( 'No usps_user_id set for EA WC USPS Shipping Updates plugin' );
			if( $this->logger )
				$this->logger->warning( 'No usps_user_id set. Exiting.' );

			return;
		}

		$orders = $this->get_orders_shipping();

		if ( count( $orders ) == 0 ) {
			return;
		}

		/** @var array $tracking_by_order_id [ order_id : [ tracking_number] ] */
		$tracking_by_order_id = $this->get_order_tracking_numbers( $orders );

		/** @var array $shipments_details [ order_id: [ tracking_number : Shipment ] ] */
		$shipments_details = $this->get_shipments_details( $tracking_by_order_id );

		// Unhook the completed order email. We'll send an Order Delivered email instead
		add_action( 'woocommerce_email', array( $this, 'unhook_order_complete_email' ) );

		//		add_action( 'woocommerce_order_status_' . substr( self::DELIVERED_WC_STATUS, 3 ), array( $this->email_classes['EA_WC_Delivered_Order_Email'], 'trigger' ), 10, 2 );

		$this->update_orders( $shipments_details );

		if ( count( $orders ) < self::MAX_TRACKING_IDS_PER_USPS_API_CALL ) {
			set_transient( self::LAST_CHECKED_ORDER_DATE_TRANSIENT_KEY, 0, 3600 );
		} else {
			// Continue processing orders in a minute
			$last_order_index   = self::MAX_TRACKING_IDS_PER_USPS_API_CALL - 1;
			$last_order         = $orders[ $last_order_index ];
			$date_of_last_order = $last_order->get_date_created()->getTimestamp();
			set_transient( self::LAST_CHECKED_ORDER_DATE_TRANSIENT_KEY, $date_of_last_order );
			wp_schedule_single_event( time() + 60, self::CRON_JOB_ID );
		}
	}

	/**
	 * Unhooks the default WooCommerce Completed Order email
	 * Only gets hooked when run by cron so won't interfere elsewhere
	 *
	 * @param $email_class
	 */
	public function unhook_order_complete_email( $email_class ) {
		remove_action( 'woocommerce_order_status_completed_notification', array( $email_class->emails['WC_Email_Customer_Completed_Order'], 'trigger' ) );
	}

	/**
	 * Searches for orders packing and in transit, up to the max allowed by USPS, newer than the most recently queried
	 * order
	 *
	 * @return WC_Order[]
	 */
	private function get_orders_shipping() {

		/** @var string[] $order_statuses */
		$order_statuses = apply_filters( 'ea_wc_usps_shipping_updates_statuses', array(
			self::PACKING_COMPLETE_WC_STATUS,
			self::USPS_IN_TRANSIT_WC_STATUS
		) );

		$last_order_checked_date = get_transient( self::LAST_CHECKED_ORDER_DATE_TRANSIENT_KEY );

		$last_order_checked_date = $last_order_checked_date == null ? 0 : $last_order_checked_date;

		$args = array(
			'limit'        => self::MAX_TRACKING_IDS_PER_USPS_API_CALL,
			'status'       => $order_statuses,
			'date_created' => '>' . $last_order_checked_date,
			'order'        => 'ASC',
			'orderby'      => 'date',
		);

		/** @var WC_Order[] $orders_pending_payment */
		$orders_shipping = wc_get_orders( $args );

		return $orders_shipping;
	}

	/**
	 * @param WC_Order[] $orders
	 *
	 * @return array [orderid : [trackingnumber]]
	 */
	public function get_order_tracking_numbers( $orders ) {

		/** @var WC_Shipment_Tracking $wc_shipment_tracking */
		$wc_shipment_tracking = wc_shipment_tracking();

		$tracking_by_order_id = array();

		foreach ( $orders as $order ) {

			$order_id = $order->get_id();

			$tracking_items = $wc_shipment_tracking->actions->get_tracking_items( $order_id );

			if ( count( $tracking_items ) == 0 ) {

				if( $this->logger )
					$this->logger->warning( 'No shipping tracking found for order ' . ( method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order->get_id() ) . ' with status ' . $order->get_status() );
				continue;
			}

			$usps_tracking_numbers = array();

			foreach( $tracking_items as $tracking_item ) {

				if ( $tracking_item['tracking_provider'] == 'usps' && $tracking_item['tracking_number'] != '' ) {

					$usps_tracking_numbers[] = $tracking_item['tracking_number'];

				} else {

					if( $this->logger )
						$this->logger->warning( 'Non USPS shipping tracking found for order ' . ( method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order->get_id() ) . ', instead found provider: ' . $tracking_item['tracking_provider'] );
				}
			}

			if ( ! empty( $usps_tracking_numbers ) )
				$tracking_by_order_id[ $order_id ] = $usps_tracking_numbers;
		}

		return $tracking_by_order_id;
	}

	/**
	 * @param array $tracking_by_order_id  // [ order_id : [ tracking_number ] ]
	 *
	 * @return array  // [order_id : [ tracking_number : Slince Shipment] ]
	 */
	public function get_shipments_details( $tracking_by_order_id ) {

		$this->tracker = new Slince\ShipmentTracking\USPS\USPSTracker( $this->settings->usps_user_id );

		$tracking_to_orders = array();

		$shipments_details = array();

		foreach( $tracking_by_order_id as $order_id => $order_tracking_numbers ) {
			foreach ( $order_tracking_numbers as $order_tracking_number ) {
				$tracking_to_orders[ $order_tracking_number ] = $order_id;
			}
			$shipments_details[ $order_id ] = array();
		}

		$tracking_numbers = array_keys( $tracking_to_orders );

		$tracking_numbers_grouped = array_chunk( $tracking_numbers, self::MAX_TRACKING_IDS_PER_USPS_API_CALL );

		foreach( $tracking_numbers_grouped as $tracking_numbers_group ) {

			try {

				/** @var Slince\ShipmentTracking\Foundation\Shipment[] $shipments // [tracking_number : Shipment] */
				$shipments = $this->tracker->trackMulti( $tracking_numbers_group );

				foreach ( $shipments as $tracking_number => $shipment ) {

					$order_id = $tracking_to_orders[ $tracking_number ];

					$shipments_details[ $order_id ][] = $shipment;
				}

			} catch ( Slince\ShipmentTracking\Foundation\Exception\TrackException $exception ) {

				// TODO:

				if( $this->logger )
					$this->logger->error( 'Track error: ' . $exception->getMessage() );
			}

		}
		return $shipments_details;
	}

	/**
	 * @param array $shipments_details [ order_id : [ tracking_number : Slince Shipment ] ]
	 */
	public function update_orders( $shipments_details ) {

		$returned_status = apply_filters( 'ea_wc_usps_shipping_updates_returned_status', self::USPS_RETURNED_WC_STATUS );

		foreach ( $shipments_details as $order_id => $shipments ) {

			/** @var Slince\ShipmentTracking\Foundation\Shipment[] $shipments */

			$order = wc_get_order( $order_id );

			/** @var array $last_status [ tracking_number : last_status ] */
			$last_status = $order->get_meta( self::ORDER_USPS_STATUS_META_KEY );
			if( $last_status == null || $last_status == '' )
				$last_status = array();

			$current_status = array();
			$updated = array();

			foreach( $shipments as $tracking_number => $shipment ) {

				$current_status[ $tracking_number ] = $shipment->getStatus();

				if( !array_key_exists( $tracking_number, $last_status )
				    || $current_status[ $tracking_number ] != $last_status[ $tracking_number ] )
					$updated[ $tracking_number ] = $shipment;
			}

			if ( empty( $updated ) )
				continue;

			$order->add_meta_data( self::ORDER_USPS_DETAIL_META_KEY, $shipments, true );
			$order->add_meta_data( self::ORDER_USPS_STATUS_META_KEY, $current_status, true );

			// Deal with each updated tracking number

			foreach( $updated as $tracking_number => $shipment ) {

				$status = $shipment->getStatus();

				if( $this->not_picked_up( $status ) ) {
					// Don't check anything else

				} else if ( $this->picked_up( $status ) ) {

					$order->set_status( self::USPS_IN_TRANSIT_WC_STATUS );

					// EA_WC_Dispatched_Order_Email has an action to auto-send

				} else if ( 'out for delivery' == strtolower( $status ) ) {

					/** @var EA_WC_Out_For_Delivery_Order_Email $out_for_delivery_email */
					$out_for_delivery_email = $this->email_classes['EA_WC_Out_For_Delivery_Order_Email'];

					$out_for_delivery_email->trigger( $order_id, $order );

				} else if ( $shipment->isDelivered() ) {

					/** @var EA_WC_Delivered_Order_Email $delivered_order_email */
					$delivered_order_email = $this->email_classes['EA_WC_Delivered_Order_Email'];

					$delivered_order_email->trigger( $order_id, $order );

				} else if ( $this->returned_by_usps( $status ) ) {

					$order->set_status( $returned_status );
				}

				if( $this->logger )
					$this->logger->info( 'Order ' . $order_id . ' : ' . $status );

				do_action( 'ea_wc_usps_shipping_updates', $shipment->getStatus(), $shipment, $order );
			}

			// Deal with order as a whole

			$order_complete = true;
			foreach( $shipments as $shipment ) {

				// tracking considered complete if it's delivered, returned or cancelled
				if ( ! $shipment->isDelivered()
				     && ! $this->returned_by_usps( $shipment->getStatus() )
				     && $shipment->getStatus() != 'Label Cancelled' ) {

					$order_complete = false;
				}
			}
			if( $order_complete ) {
				$order->set_status( self::COMPLETED_WC_STATUS );
			}

			// Because not all deliveries can be confirmed (e.g. overseas, new statuses) old orders can be automatically marked complete
			$now = new DateTime();
			$two_weeks_ago = $now->sub( new DateInterval( 'P14D' ) );

			if( $this->settings->mark_overseas_two_weeks_complete
			    && $order->get_shipping_country() != 'US'
			    && $order->get_date_created() < $two_weeks_ago ) {

				// Find the most recently updated time of all tracking numbers associated with this order
				$most_recently_updated_datetime = $now;
				foreach ( $shipments as $shipment ) {

					if( !count( $shipment->getEvents() ) > 0 )
						continue;

					$shipment_tracking_datetime = $shipment->getEvents()[ count( $shipment->getEvents() ) - 1 ]->getTime();

					if ( $shipment_tracking_datetime < $most_recently_updated_datetime ) {
						$most_recently_updated_datetime = $shipment_tracking_datetime;
					}
				}

				// if no update has happened in two weeks, just mark it complete (email is disabled at this point)

				if ( $most_recently_updated_datetime < $two_weeks_ago ) {
					$order->set_status( self::COMPLETED_WC_STATUS );
				}
			}

			$order->save();
		}
	}

	/** */

	// Below here should be moved to USPS class

	public function not_picked_up( $status ) {

		$not_picked_up_statuses = array(
			'Shipping Label Created, USPS Awaiting Item',
			'Pre-Shipment Info Sent to USPS, USPS Awaiting Item',
			'Label Cancelled'
		);

		$not_picked_up = in_array( $status, $not_picked_up_statuses );

		// There should be a filter here

		return $not_picked_up;
	}

	public function picked_up( $status ) {

		// Statuses determined by checking the first and second statuses of 2500 orders

		// Should not include any pending pickup, delivered, or returned statuses

		$picked_up_statuses = array ('Accepted at USPS Origin Facility',
			'USPS in possession of item',
			'Shipment Received, Package Acceptance Pending',
			'Accepted at USPS Origin Facility',
			'Arrived at USPS Regional Origin Facility',
			'Arrived at Post Office',
			'Arrived at USPS Regional Destination Facility',
			'Arrived at Hub',
			'Arrived at USPS Facility',
			'Arrived at USPS Regional Facility',
			'Sorting Complete',
			'Departed Post Office',
			'Dispatched from USPS International Service Center',
			'USPS in possession of item'
		);

		$picked_up = in_array( $status, $picked_up_statuses );

		// There should be a filter here

		return $picked_up;
	}

	public function returned_by_usps( $status ) {

		// There should be a filter here

		return $status == 'Delivered, To Original Sender';
	}
}

$GLOBALS['EA_WC_USPS_Shipping_Updates'] = EA_WC_USPS_Shipping_Updates::get_instance();

// ea-wc-usps-shipping-updates
// ea_wc_usps_shipping_updates
// EA_WC_USPS_Shipping_Updates
