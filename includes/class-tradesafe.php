<?php

class TradeSafe {
	private static $initiated = false;
	public static $enabled = false;

	public static function init() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		if ( ! self::$initiated ) {
			self::init_hooks();
		}
	}

	//Initializes WordPress hooks
	private static function init_hooks() {
		self::$initiated = true;

		// Set test domain
		load_plugin_textdomain( TRADESAFE_PLUGIN_NAME, false, trailingslashit( TRADESAFE_PLUGIN_NAME ) );

		// Payment Gateways
		require_once( TRADESAFE_PLUGIN_DIR . 'includes/class-wc-gateway-tradesafe-base.php' );
		require_once( TRADESAFE_PLUGIN_DIR . 'includes/class-wc-gateway-tradesafe-eftsecure.php' );
		require_once( TRADESAFE_PLUGIN_DIR . 'includes/class-wc-gateway-tradesafe-manualeft.php' );
		require_once( TRADESAFE_PLUGIN_DIR . 'includes/class-wc-gateway-tradesafe-ecentric.php' );

		// Endpoints
		add_rewrite_rule( '^tradesafe/(.*)/(.*)/?$', 'index.php?tradesafe=1&action=$matches[1]&action_id=$matches[2]', 'top' );
		add_rewrite_rule( '^tradesafe/(.*)/?$', 'index.php?tradesafe=1&action=$matches[1]', 'top' );
		add_rewrite_endpoint( 'tradesafe', EP_PERMALINK | EP_PAGES | EP_ROOT );

		// Actions
		add_action( 'admin_init', [ 'TradeSafe', 'settings_init' ] );
		add_action( 'admin_menu', [ 'TradeSafe', 'register_options_page' ] );
		add_action( 'parse_request', [ 'TradeSafe', 'callback_parse_request' ] );
		add_action( 'woocommerce_cart_calculate_fees', [ 'TradeSafe', 'checkout_fee' ] );
		add_action( 'woocommerce_review_order_before_payment', [ 'TradeSafe', 'payment_method_change_checkout' ] );
		add_action( 'woocommerce_pay_order_before_submit', [ 'TradeSafe', 'payment_method_change_order' ] );

		// Filters
		add_filter( 'plugin_action_links_' . TRADESAFE_PLUGIN_BASE_NAME, [ 'TradeSafe', 'plugin_links' ] );
		add_filter( 'query_vars', [ 'TradeSafe', 'query_vars' ] );
		add_filter( 'woocommerce_payment_gateways', [ 'TradeSafe', 'add_payment_methods' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ 'TradeSafe', 'valid_transaction' ] );
	}

	/**
	 * @param $links
	 *
	 * @return array
	 */
	public static function plugin_links( $links ) {
		$settings_url = add_query_arg(
			array(
				'page' => 'tradesafe',
			),
			admin_url( 'options-general.php' )
		);

		$plugin_links = array(
			'<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', TRADESAFE_PLUGIN_NAME ) . '</a>',
			'<a href="https://www.tradesafe.co.za/page/contact">' . __( 'Support', TRADESAFE_PLUGIN_NAME ) . '</a>',
			'<a href="https://www.tradesafe.co.za/page/API">' . __( 'Docs', TRADESAFE_PLUGIN_NAME ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * @param $methods
	 *
	 * @return array
	 */
	public static function add_payment_methods( $methods ) {
		$methods[] = 'WC_Gateway_TradeSafe_Ecentric';
		$methods[] = 'WC_Gateway_TradeSafe_EftSecure';
		$methods[] = 'WC_Gateway_TradeSafe_ManualEft';

		return $methods;
	}

	/**
	 * @param $available_gateways
	 *
	 * @return mixed
	 */
	public static function valid_transaction( $available_gateways ) {
		$user    = wp_get_current_user();
		$user_id = get_user_meta( $user->ID, 'tradesafe_user_id', true );

		if ( ! $user_id && isset( $available_gateways['tradesafe'] ) ) {
			unset( $available_gateways['tradesafe'] );
			if ( isset( $_REQUEST['wc-ajax'] ) ) {
				print "<div style='border: 1px solid #CA170F; padding: 10px; background-color: #f9e7e7'>To complete this action your must first complete <a style='font-weight: bold;' href='" . get_site_url( null, 'my-account/tradesafe/' ) . "'>your account</a></div>";
			}
		}

		return $available_gateways;
	}

	/**
	 * Define Query Variables
	 *
	 * @param $vars
	 *
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'tradesafe';
		$vars[] = 'action';
		$vars[] = 'action_id';

		return $vars;
	}

	/**
	 * Parse requests
	 *
	 * @param $wp
	 */
	public static function callback_parse_request( $wp ) {
		if ( array_key_exists( 'tradesafe', $wp->query_vars )
		     && $wp->query_vars['tradesafe'] == '1' ) {
			switch ( $wp->query_vars['action'] ) {
				case 'auth':
					// run auth check
					TradeSafeProfile::callback_auth();
					break;
				case 'callback':
					TradeSafeOrders::callback();
					break;
				case 'update-order-payment-method':
					TradeSafeOrders::update_order_payment_method( $wp->query_vars['action_id'] );
					break;
				case 'unlink':
					TradeSafeProfile::unlink();
					break;
				case 'accept':
					TradeSafeOrders::accept( $wp->query_vars['action_id'] );
					break;
				case 'extend':
					TradeSafeOrders::extend( $wp->query_vars['action_id'] );
					break;
				case 'decline':
					TradeSafeOrders::decline( $wp->query_vars['action_id'] );
					break;
				default:
					status_header( 404 );
					include( get_query_template( '404' ) );
					die();
			}
		}
	}

	/**
	 * Add checkout fee if Marketplace / Agent is enabled.
	 *
	 * @param null $payment_method
	 */
	public static function checkout_fee() {
		$payment_method = WC()->session->chosen_payment_method;
		$base_value     = WC()->cart->get_cart_contents_total() + WC()->cart->get_shipping_total() + WC()->cart->get_taxes_total( false, false );
		$fee            = self::calculate_fee( $base_value, $payment_method );

		if ( 0 < $fee ) {
			WC()->cart->add_fee( __( 'Processing Fee', TRADESAFE_PLUGIN_NAME ), $fee );
		}
	}

	/**
	 * @param $base_value
	 * @param $payment_method
	 *
	 * @return float|int|mixed|void
	 */
	public static function calculate_fee( $base_value, $payment_method ) {
		$fee = 0;

		if ( 'marketplace' === get_option( 'tradesafe_site_role' ) ) {
			$fee = self::calculate_marketplace_fee( $base_value );
		}

		switch ( $payment_method ) {
			case "tradesafe_manualeft":
			case "tradesafe_eftsecure":
				break;
			case "tradesafe_ecentric":
				$fee += $base_value * 0.015;
				break;
		}

		return $fee;
	}

	/**
	 * Calculate the fee payable by the buyer
	 *
	 * @param $base_value
	 *
	 * @return float|int|mixed|void
	 */
	public static function calculate_marketplace_fee( $base_value ) {
		$fee            = get_option( 'tradesafe_site_fee' );
		$fee_allocation = get_option( 'tradesafe_site_fee_allocation', 1 );

		if ( substr_count( $fee, '%' ) ) {
			$percentage = str_replace( '%', '', $fee );
			$fee_value  = $base_value * ( $percentage / 100 );
		} else {
			$fee_value = $fee;
		}

		switch ( $fee_allocation ) {
			case 1:
				$fee_value = 0;
				break;
			case 2:
				$fee_value /= 2;
				break;
		}

		return $fee_value;
	}

	/**
	 * Update checkout page when changing payment method
	 */
	public static function payment_method_change_checkout() {
		?>
        <script type="text/javascript">
            (function ($) {
                $('form.checkout').on('change', 'input[name^="payment_method"]', function () {
                    $('body').trigger('update_checkout');
                });
            })(jQuery);
        </script>
		<?php
	}

	/**
	 * Update order page when changing payment method
	 */
	public static function payment_method_change_order() {
		$order_id       = get_query_var( 'order-pay' );
		$url            = site_url( '/tradesafe/update-order-payment-method/' . $order_id );
		$order          = new WC_Order( $order_id );
		$payment_method = $order->get_payment_method();
		?>
        <script type="text/javascript">
            (function ($) {
                $('input[name^="payment_method"][value^="<?php esc_attr_e( $payment_method ); ?>"]').prop('checked', true);

                $('form#order_review').on('change', 'input[name^="payment_method"]', function () {
                    var data = {
                        "payment_method": this.value
                    };

                    $('#place_order').prop('disabled', 'disabled');
                    $.post("<?php esc_attr_e( $url ); ?>", data, function () {
                        window.location.reload();
                    });
                });
            })(jQuery);
        </script>
		<?php
	}

	/**
	 * Settings Page
	 */
	public static function settings_init() {
		$configured = false;
		$owner_data = '';

		if ( '' !== get_option( 'tradesafe_api_token', '' ) ) {
			$tradesafe     = new TradeSafeAPIWrapper();
			$owner_details = $tradesafe->owner();
			$industries    = [];

			// Check for error
			if ( is_object( $owner_details ) && 'WP_Error' === get_class( $owner_details ) ) {
				foreach ( $owner_details->errors as $error_code => $messages ) {
					$message = implode( '<br />', $messages );
					print self::notice( 'error', $message );
				}
			} else {
				$configured    = true;
				self::$enabled = true;
				$owner_data    = '<strong>' . __( 'Account Details:', TRADESAFE_PLUGIN_NAME ) . '</strong><br/>'
				                 . "<div><strong>Name: </strong> " . $owner_details['first_name'] . " " . $owner_details['last_name'] . "</div>"
				                 . "<div><strong>Email: </strong>" . $owner_details['email'] . "</div>"
				                 . "<div><strong>Mobile: </strong>" . $owner_details['mobile'] . "</div>"
				                 . "<div><strong>ID Number: </strong>" . $owner_details['id_number'] . "</div>"
				                 . "<div><strong>Bank Details: </strong><br/>" . $owner_details['bank']['name'] . "<br/>" . $owner_details['bank']['account'] . "<br/>" . $owner_details['bank']['type'] . "</div>";
				$industries    = $tradesafe->constant( 'industry-types' );
			}
		} else {
			print self::notice( 'info', 'A valid API token is required to continue setup' );
		}

		// Add the api settings section
		add_settings_section(
			'tradesafe_api_settings',
			'API Details',
			[ 'TradeSafe', 'section_api_settings_intro' ],
			'tradesafe'
		);

		// API Token
		add_settings_field(
			'tradesafe_api_token',
			'Token',
			[ 'TradeSafe', 'settings_field_render' ],
			'tradesafe',
			'tradesafe_api_settings',
			[
				'id'          => 'tradesafe_api_token',
				'type'        => 'textarea',
				'description' => $owner_data,
			]
		);

		register_setting( 'tradesafe', 'tradesafe_api_token', [
			'type'              => 'string',
			'description'       => 'The token to use for API calls.',
			'sanitize_callback' => [ 'TradeSafe', 'settings_field_sanitize' ],
			'default'           => '',
		] );

		// Enable Production
		add_settings_field(
			'tradesafe_api_production',
			'Use Production API',
			[ 'TradeSafe', 'settings_field_render' ],
			'tradesafe',
			'tradesafe_api_settings',
			[
				'id'          => 'tradesafe_api_production',
				'type'        => 'checkbox',
				'description' => 'Enable the live version of the API.',
			]
		);

		register_setting( 'tradesafe', 'tradesafe_api_production', [
			'type'              => 'boolean',
			'description'       => 'Should the production API be used.',
			'sanitize_callback' => [ 'TradeSafe', 'settings_field_sanitize' ],
			'default'           => false,
		] );

		if ( $configured ) {
			// Add the user settings section
			add_settings_section(
				'tradesafe_site_settings',
				'Default Contract Details',
				[ 'TradeSafe', 'section_site_settings_intro' ],
				'tradesafe'
			);

			// Industry
			add_settings_field(
				'tradesafe_site_industry',
				'Industry',
				[ 'TradeSafe', 'settings_field_render' ],
				'tradesafe',
				'tradesafe_site_settings',
				[
					'id'          => 'tradesafe_site_industry',
					'type'        => 'select',
					'options'     => $industries,
					'description' => 'The industry to use for transactions.',
				]
			);

			register_setting( 'tradesafe', 'tradesafe_site_industry', [
				'type'              => 'string',
				'description'       => 'The role that this store will fulfill.',
				'sanitize_callback' => [ 'TradeSafe', 'settings_field_sanitize' ],
				'default'           => 'GENERAL_GOODS_SERVICES',
			] );

			// Store role
			add_settings_field(
				'tradesafe_site_role',
				'Role',
				[ 'TradeSafe', 'settings_field_render' ],
				'tradesafe',
				'tradesafe_site_settings',
				[
					'id'          => 'tradesafe_site_role',
					'type'        => 'select',
					'options'     => [
						'seller'      => __( 'Seller', TRADESAFE_PLUGIN_NAME ),
						'marketplace' => __( 'Marketplace, Agent or Broker', TRADESAFE_PLUGIN_NAME )
					],
					'description' => 'Are you selling products or running a marketplace?',
				]
			);

			register_setting( 'tradesafe', 'tradesafe_site_role', [
				'type'              => 'string',
				'description'       => 'The role that this store will fulfill.',
				'sanitize_callback' => [ 'TradeSafe', 'settings_field_sanitize' ],
				'default'           => '',
			] );

			// Marketplace fee
			add_settings_field(
				'tradesafe_site_fee',
				'Agent / Marketplace Fee',
				[ 'TradeSafe', 'settings_field_render' ],
				'tradesafe',
				'tradesafe_site_settings',
				[
					'id'          => 'tradesafe_site_fee',
					'type'        => 'text',
					'description' => 'This is only applies if the Marketplace / Agent role is selected above.<br />The fee can be a fixed value e.g. 123.99 or a percentage e.g. 5%.',
				]
			);

			register_setting( 'tradesafe', 'tradesafe_site_fee', [
				'type'              => 'string',
				'description'       => 'The role that this store will fulfill.',
				'sanitize_callback' => [ 'TradeSafe', 'settings_field_sanitize' ],
				'default'           => '',
			] );

			// Fee allocation
			add_settings_field(
				'tradesafe_site_fee_allocation',
				'Fee Allocation',
				[ 'TradeSafe', 'settings_field_render' ],
				'tradesafe',
				'tradesafe_site_settings',
				[
					'id'          => 'tradesafe_site_fee_allocation',
					'type'        => 'select',
					'options'     => [
						'0' => __( 'Buyer Pays', TRADESAFE_PLUGIN_NAME ),
						'1' => __( 'Seller Pays', TRADESAFE_PLUGIN_NAME ),
						'2' => __( '50/50 Split', TRADESAFE_PLUGIN_NAME ),
					],
					'description' => 'How will the Marketplace / Agent fee be paid.',
				]
			);

			register_setting( 'tradesafe', 'tradesafe_site_fee_allocation', [
				'type'              => 'string',
				'description'       => 'The role that this store will fulfill.',
				'sanitize_callback' => [ 'TradeSafe', 'settings_field_sanitize' ],
				'default'           => '1',
			] );
		}

		// Add the user settings section
		add_settings_section(
			'tradesafe_site_debugging',
			'Debugging',
			[ 'TradeSafe', 'section_site_debugging_intro' ],
			'tradesafe'
		);

		// Enable Production
		add_settings_field(
			'tradesafe_api_debugging',
			'Enable Debugging',
			[ 'TradeSafe', 'settings_field_render' ],
			'tradesafe',
			'tradesafe_site_debugging',
			[
				'id'          => 'tradesafe_api_debugging',
				'type'        => 'checkbox',
				'description' => 'Turns on logging and error displays.',
			]
		);

		register_setting( 'tradesafe', 'tradesafe_api_debugging', [
			'type'              => 'boolean',
			'description'       => 'Enable debugging.',
			'sanitize_callback' => [ 'TradeSafe', 'settings_field_sanitize' ],
			'default'           => false,
		] );
	}

	// Add the link to the settings menu
	public static function register_options_page() {
		add_options_page( 'TradeSafe Settings', 'TradeSafe', 'manage_options', 'tradesafe', [
			'TradeSafe',
			'settings_page'
		] );
	}

	// Display settings page
	public static function settings_page() {
		$urls = [
			'callback'      => site_url( '/tradesafe/callback/' ),
			'auth_callback' => site_url( '/tradesafe/auth/' ),
		];

		require_once( TRADESAFE_PLUGIN_DIR . 'views/settings.php' );
	}

	// Post notice
	public static function notice( $type, $message ) {
		return sprintf( '<div class="notice-%s notice"><p>%s</p></div>', $type, __( $message, TRADESAFE_PLUGIN_NAME ) );
	}

	/**
	 * Fields for settings page
	 */

	// Intro test for API section
	public static function section_api_settings_intro() {
		echo '<p>' . sprintf( __( 'An API token can be obtained from your <a href="%s" target="_blank">TradeSafe</a> account.', TRADESAFE_PLUGIN_NAME ), 'https://' . TRADESAFE_DOMAIN ) . '</p>';
	}

	// Intro User section
	public static function section_site_settings_intro() {
		echo '<p>' . sprintf( __( 'Set the defaults for each transaction.', TRADESAFE_PLUGIN_NAME ) ) . '</p>';
	}

	// Intro Debugging section
	public static function section_site_debugging_intro() {
		echo '<p>' . sprintf( __( 'Turn on debugging to help diagnose errors.', TRADESAFE_PLUGIN_NAME ) ) . '</p>';
	}

	// Sanitize fields
	public static function settings_field_sanitize( $value ) {
		return trim( filter_var( $value, FILTER_SANITIZE_STRING ) );
	}

	// Render fields
	public static function settings_field_render( $args ) {
		$field = '';

		switch ( $args['type'] ) {
			case 'checkbox':
				$field .= sprintf( '<input name="%1$s" id="%1$s" type="checkbox" value="1" %2$s/>', $args['id'], checked( 1, get_option( $args['id'] ), false ) );
				break;
			case 'text':
				$field .= sprintf( '<input name="%1$s" id="%1$s" type="text" value="%2$s"/>', $args['id'], get_option( $args['id'], '' ) );
				break;
			case 'textarea':
				$field .= sprintf( '<textarea name="%1$s" id="%1$s" cols="60" rows="5"/>%2$s</textarea>', $args['id'], get_option( $args['id'], '' ) );
				break;
			case 'select':
				$option = get_option( $args['id'] );
				$field  .= sprintf( '<select name="%1$s" id="%1$s" cols="60" rows="5"><option value="">' . __( '-- SELECT --', TRADESAFE_PLUGIN_NAME ) . '</option>', $args['id'] );

				foreach ( $args['options'] as $value => $name ) {
					if ( $option === (string) $value ) {
						$field .= sprintf( '<option value="%s" selected="selected">%s</option>', $value, $name );
					} else {
						$field .= sprintf( '<option value="%s">%s</option>', $value, $name );
					}
				}

				$field .= sprintf( '</select>' );
				break;
		}

		if ( isset( $args['description'] ) ) {
			$field .= '<p class="description">' . $args['description'] . '</p>';
		}

		print $field;
	}
}