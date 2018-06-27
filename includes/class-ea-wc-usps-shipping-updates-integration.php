<?php

class EA_WC_USPS_Shipping_Updates_Integration extends WC_Integration {

	/** @var string  */
	public $usps_user_id;

	/** @var bool */
	public $logging_enabled;

	/** @var bool */
	public $mark_overseas_two_weeks_complete;

	public function __construct() {
		global $woocommerce;
		$this->id                 = 'ea-wc-usps-shipping-updates-integration';
		$this->method_title       = __( 'USPS Shipping Updates', 'ea-wc-usps-shipping-updates' );

		$links = '</p><p style="margin-top:10px;">';
		$links .= '<a target="_blank" href="https://www.usps.com/business/web-tools-apis/welcome.htm">Sign up for USPS API User Id</a> &bull; ';
		$links .= '<a href="admin.php?page=wc-settings&tab=email">Configure WooCommerce emails</a> &bull; ';
		$links .= '<a href="admin.php?page=wc-status&tab=logs&source=ea-wc-usps-shipping-updates">View plugin logs</a> &bull; ';
		$links .= '<a target="_blank" href="https://github.com/EnhancedAthlete/ea-wc-usps-shipping-updates">See Readme/Issues on GitHub</a> &bull; ';
		$links .= '<a href="https://v2.enhancedathlete.com/">Visit EnhancedAthlete.com</a></p>';

		$this->method_description = __( "Settings for querying USPS API for shipment tracking updates.\n".$links, 'ea-wc-usps-shipping-updates' );
		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();
		// Define user set variables.
		$this->usps_user_id     = $this->get_option( 'usps_user_id' );
		$this->logging_enabled  = boolval( $this->get_option( 'logging_enabled' ) );
		$this->mark_overseas_two_weeks_complete = boolval( $this->get_option( 'mark_overseas_two_weeks_complete' ) );
		// Actions.
		add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialize integration settings form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'usps_user_id' => array(
				'title'             => __( 'USPS API User Id', 'ea-wc-usps-shipping-updates' ),
				'type'              => 'text',
				'description'       => __( 'Enter with your API Key. You can find this in "User Profile" drop-down (top right corner) > API Keys.', 'ea-wc-usps-shipping-updates' ),
				'desc_tip'          => true,
				'default'           => ''
			),
			'mark_overseas_two_weeks_complete' => array(
				'title'             => __( 'Auto-completion', 'ea-wc-usps-shipping-updates' ),
				'type'              => 'checkbox',
				'label'             => __( 'Mark overseas orders with no shipping updates for two weeks as complete', 'ea-wc-usps-shipping-updates' ),
				'default'           => 'yes',
				'description'       => __( "The tracking numbers can be manually searched in the local country's postal service's website.", 'ea-wc-usps-shipping-updates' ),
			),
			'logging_enabled' => array(
				'title'             => __( 'Debug Log', 'ea-wc-usps-shipping-updates' ),
				'type'              => 'checkbox',
				'label'             => __( 'Enable logging', 'ea-wc-usps-shipping-updates' ),
				'default'           => 'yes',
				'description'       => __( 'Log updates.', 'ea-wc-usps-shipping-updates' ),
			),
		);
	}

}
