<?php
/*
Plugin Name: WooCommerce TrustPay
Plugin URI: https://github.com/trustpay
Description: Pluging to offer a Trustpay payment option for woocommerce shopping cart
Author: TrustPay
Version: 1.0
Author URI: http://www.trsutpay.biz
License: GPLv2 or later
Text Domain: wctrustpay
Dependency (Plugin): oauth-provider (https://wordpress.org/plugins/oauth2-provider/)
Domain Path: /languages/
*/

/**
* Check if WooCommerce is active
**/
$single_check = in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) );

$network_check = false;
if (is_multisite()) {
  if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
    require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
  }
  $network_check = is_plugin_active_for_network( 'woocommerce/woocommerce.php' );
}

if ( $single_check || $network_check ) {

    // Put your plugin code here.
    add_action( 'plugins_loaded', 'wctrustpay_gateway_load', 0 );

    /**
    * WooCommerce fallback notice.
    */
   function wctrustpay_woocommerce_fallback_notice() {
       $html = '<div class="error">';
           $html .= '<p>' . __( 'WooCommerce TrustPay Gateway depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!', 'wctrustpay' ) . '</p>';
       $html .= '</div>';
       echo $html;
   }

    function wctrustpay_gateway_load() {

	  if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	      add_action( 'admin_notices', 'wctrustpay_woocommerce_fallback_notice' );
	      return;
	  }

	  /**
	  * Load textdomain.
	  */
	  load_plugin_textdomain( 'wctrustpay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	  /**
	  * Add the gateway to WooCommerce.
	  *
	  * @access public
	  * @param array $methods
	  * @return array
	  */

	  function wctrustpay_add_gateway( $methods ) {
	      $methods[] = 'WC_Gateway_Trustpay';
	      return $methods;
	  }
          add_filter( 'woocommerce_payment_gateways', 'wctrustpay_add_gateway' );


	  /**
	  * WC Trustpay Gateway Class.
	  *
	  * Built the Trustpay method.
	  */
	  class WC_Gateway_Trustpay extends WC_Payment_Gateway {
	      /**
	      * Gateway's Constructor.
	      *
	      * @return void
	      */
	      public function __construct() {
		  global $woocommerce;
		  $this->id                  = 'trustpay';
		  $this->icon                = plugins_url( 'images/trustpay.png', __FILE__ );
		  $this->has_fields          = false;
                  $this->url                 = 'https://my.trustpay.biz/TrustPayWebClient/Transact?';
		  $this->defaultsuccessUrl   = str_replace('https:', 'http:', add_query_arg('wc-trustpay', 'trustpay_success_result', home_url('/')));
                  $this->defaultfailureUrl   = str_replace('https:', 'http:', add_query_arg('wc-trustpay', 'trustpay_failure_result', home_url('/')));
		  $this->method_title        = __( 'Trustpay', 'wctrustpay' );
                  $this->response_url        = add_query_arg( 'wc-api', 'WC_Gateway_Trustpay', home_url( '/' ) );

		  // Load the form fields.
		  $this->init_form_fields();

		  // Load the settings.
		  $this->init_settings();

		  // Define user setting variables.
		  $this->title              = $this->settings['title'];
		  $this->description        = $this->settings['description'];
		  $this->app_key            = $this->settings['app_key'];
		  $this->debug              = $this->settings['debug'];
                  $this->successpostbackurl = $this->settings['successpostbackurl'];
		  $this->failurepostbackurl = $this->settings['failurepostbackurl'];
                  //$this->pendingpostbackurl = $this->settings['pendingpostbackurl'];


                  // Actions.
		  add_action( 'woocommerce_api_wc_gateway_trustpay', array( $this, 'check_tpn_response' ) );
		  add_action( 'valid_trustpay_tpn_request', array( $this, 'successful_request' ) );
		  add_action( 'woocommerce_receipt_trustpay', array( $this, 'receipt_page' ) );

		  if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
		      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
		  } else {
		      add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
		  }

		  // Valid for use.
		  if ( ! $this->is_valid_for_use() )
			$this->enabled = false;

		  // Checking if vendor_key/app_key is not empty.
		  if ( empty( $this->app_key ) ) {
		      add_action( 'admin_notices', array( &$this, 'vendor_key_missing_message' ) );
		  }

		  // Active logs.
		  if ( 'yes' == $this->debug ) {
		      $this->log = new WC_Logger();
		  }
	      }

	      /**
	      * Checking if this gateway is enabled and available in the user's country.
	      *
	      * @return bool
	      */
	      public function is_valid_for_use() {
		  $is_available = false;
                  if ($this->enabled == 'yes' && $this->settings['app_key'] != '')
			$is_available = true;
                  return $is_available;
	      }

	      /**
	      * Admin Panel Options
	      * - Options for bits like 'title' and availability on a country-by-country basis.
	      *
	      * @since 1.0.0
	      */
	      public function admin_options() {
		  ?>
		  <h3><?php _e( 'Trustpay', 'wctrustpay' ); ?></h3>
		  <p><?php _e( 'Trustpay Gateway works by sending the user to Trustpay to enter their payment information.', 'wctrustpay' ); ?></p>
		  <table class="form-table">
		      <?php $this->generate_settings_html(); ?>
		  </table> <!-- /.form-table -->
		  <?php
	      }

	      /**
	      * Start Gateway Settings Form Fields.
	      *
	      * @return void
	      */
	      public function init_form_fields() {
		  $this->form_fields = array(
		      'enabled' => array(
			  'title' => __( 'Enable/Disable', 'wctrustpay' ),
                          'label' => __( 'Enable Trustpay Gateway', 'wctrustpay' ),
			  'type' => 'checkbox',
			  'default' => 'yes',
                          'description' => __( 'This controls whether the plugin is active on the checkout page.', 'wctrustpay' )
		      ),
		      'title' => array(
			  'title' => __( 'Title', 'wctrustpay' ),
			  'type' => 'text',
			  'description' => __( 'This controls the title which the user sees during checkout.', 'wctrustpay' ),
			  'default' => __( 'Trustpay', 'wctrustpay' ),
                          'desc_tip' => true
		      ),
		      'description' => array(
			  'title' => __( 'Description', 'wctrustpay' ),
			  'type' => 'textarea',
			  'description' => __( 'This controls the description which the user sees during checkout.', 'wctrustpay' ),
			  'default' => __( 'Pay with TrustPay Methods', 'wctrustpay' ),
                          'desc_tip' => true
		      ),
		      'app_key' => array(
			  'title' => __( 'Vendor Key', 'wctrustpay' ),
			  'type' => 'text',
			  'description' => __( 'Please enter your Trustpay Vendor Key.', 'wctrustpay' ) . ' ' . sprintf( __( 'You can to get this information from your %sTrustPay Account%s.', 'wctrustpay' ), '<a href="https://my.trustpay.biz" target="_blank">', '</a>' ),
			  'default' => ''
		      ),
                      'notificationurl' => array(
                          'title' => __('Notification URL', 'wctrustpay'),
                          'type' => 'text',
                          'description' => __('Please enter the Notification URL.', 'wctrustpay'). ' ' . sprintf( __( 'You can to get this information from your  %sTrustPay Account%s.', 'wctrustpay' ), '<a href="https://my.trustpay.biz" target="_blank">', '</a>' ),
                          'default' => ''
                      ),
                      'sharedsecret' => array(
                          'title' => __('Shared Secret', 'wctrustpay'),
                          'type' => 'text',
                          'description' => __('Please enter the Shared Secret.', 'wctrustpay'). ' ' . sprintf( __( 'You can to get this information from your %sTrustPay Account%s.', 'wctrustpay' ), '<a href="https://my.trustpay.biz" target="_blank">', '</a>' ),
                          'default' => ''
                      ),
                      'successpostbackurl' => array(
                          'title' => __('Success Postback URL', 'wctrustpay'),
                          'type' => 'text',
                          'description' => __('Please enter the Success Postback URL.', 'wctrustpay'),
                          'default' => __( $this->defaultsuccessUrl ),
                          'placeholder' => __('Leave blank for default woocommerce success url'),
                          'desc_tip' => true
                      ),
                      'failurepostbackurl' => array(
                          'title' => __('Failure Postback URL', 'wctrustpay'),
                          'type' => 'text',
                          'description' => __('Please enter the Failure Postback URL.', 'wctrustpay'),
                          'default' => __( $this->defaultfailureUrl ),
                          'placeholder' => __('Leave blank for default woocommerce fail url'),
                          'desc_tip' => true
                      ),
                      'testing' => array(
			  'title' => __( 'Gateway Testing', 'wctrustpay' ),
			  'type' => 'title',
			  'description' => '',
                          'desc_tip' => true
		      ),
                      'istest' => array(
			  'title' => __( 'Test Mode', 'wctrustpay' ),
                          'label' => __( 'Enable Development/Test Mode', 'wctrustpay' ),
			  'type' => 'checkbox',
			  'default' => 'yes',
                          'description' => __( 'This sets the payment gateway in development mode.', 'wctrustpay' ),
                          'desc_tip' => true
		      ),
		      'debug' => array(
			  'title' => __( 'Debug Log', 'wctrustpay' ),
			  'type' => 'checkbox',
			  'label' => __( 'Enable logging', 'wctrustpay' ),
			  'default' => 'no',
			  'description' => __( 'Log Trustpay events, such as API requests, inside <code>woocommerce/logs/trustpay.txt</code>', 'wctrustpay'  )
		      )
		  );
	      }

	      /**
	      * Process the payment and return the result.
	      *
	      * @param int $order_id
	      * @return array
	      */
	      public function process_payment( $order_id ) {
                  //global $woocommerce;
		  $order = new WC_Order( $order_id );
                  return array(
                        'result' 	=> 'success',
                        'redirect'	=> $order->get_checkout_payment_url( true )
                  );
	      }

	      /**
	      * Output for the order received page.
	      *
	      * @return void
	      */
	      public function receipt_page( $order ) {
                  echo $this->generate_truspay_form( $order );
	      }

	      /**
	      * Adds error message when not configured the app_key.
	      *
	      * @return string Error Mensage.
	      */
	      public function vendor_key_missing_message() {
                $html = '<div class="error">';
                $html .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should inform your Vendor Key in Trustpay. %sClick here to configure!%s', 'wctrustpay' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways">', '</a>' ) . '</p>';
                $html .= '</div>';

                echo $html;
	      }

              public function generate_truspay_form( $order_id ) {
                global $woocommerce;
                $order = new WC_Order( $order_id );

                //prepare the success order fallback url
                if (empty($this->settings['successpostbackurl'])){
                    $successUrl = $this->get_return_url( $order );
                }else{
                    $successUrl = $this->settings['successpostbackurl'];
}

                //prepare the fail/cancel order fallback url
                if (empty($this->settings['failurepostbackurl'])){
                    $cancelUrl = $order->get_cancel_order_url();
                }else{
                    $cancelUrl = $this->settings['failurepostbackurl'];
                }

                $this->data_to_send = array(
                    // TrustPay Account related details
                    'vendor_id' => $this->settings['app_key'],
                    'appuser'   => $order->billing_first_name. ' ' .$order->billing_last_name,
                    'currency'  => get_option( 'woocommerce_currency' ),
                    'amount'    => $order->order_total,
                    'txid'      => (string)$order->id,
                    'fail'      => $cancelUrl,
                    'success'   => $successUrl,
                    'message'   => sprintf( __( 'New order from %s', 'wctrustpay' ), get_bloginfo( 'name' ) ),
                    'istest'    => $this->settings['istest']
	   	);
		$trustpay_args_array = array();
		foreach ($this->data_to_send as $key => $value) {
			$trustpay_args_array[] = '<input type="hidden" name="'.$key.'" value="'.$value.'" />';
		}
		return '<form action="' . $this->url . '" method="get" id="trustpay_payment_form">
                            ' . implode('', $trustpay_args_array) . '
                            <input type="submit" class="button-alt" id="submit_trustpay_payment_form" value="' . __( 'Pay via TrustPay', 'wctrustpay' ) . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __( 'Cancel order &amp; restore cart', 'wctrustpay' ) . '</a>
                            <script type="text/javascript">
                                jQuery(function(){
                                    jQuery("body").block(
                                    {
                                        message: "<img src=\"' . $woocommerce->plugin_url() . '/assets/images/ajax-loader.gif\" alt=\"Redirecting...\" />' . __( 'Thank you for your order. We are now redirecting you to TrustPay to make payment.', 'wctrustpay' ) . '",
                                        overlayCSS:
                                        {
                                            background: "#fff",
                                            opacity: 0.6
                                        },
                                        css: {
                                            padding:        20,
                                            textAlign:      "center",
                                            color:          "#555",
                                            border:         "3px solid #aaa",
                                            backgroundColor:"#fff",
                                            cursor:         "wait"
                                        }
                                    });
                                    jQuery( "#submit_trustpay_payment_form" ).click();
                                });
                            </script>
                    </form>';
                }
                /**
                 * check if the TrustPay TPN is valid
                 *  @param array $data
                 */
                function check_tpn_request_is_valid($data){
                    global $woocommerce;

                    if (empty($data['tp_transaction_id'])){
                        $this->log->add( 'trustpay', 'TPN Request is empty.');
                        return FALSE;
                    }
                    $params = array(
                        'amount'=> $data['amount'],
                        'application_id'=> $data['application_id'],
                        'consumermessage'=> $data['consumermessage'],
                        'currency'=> $data['currency'],
                        'description' => $data['description'],
                        'method' => $data['method'],
                        'status' => $data['status'],
                        'tp_transaction_id' => $data['tp_transaction_id'],
                        'transaction_id' => $data['transaction_id'],
                        'transaction_time' => $data['transaction_time'],
                        'user_id' => $data['user_id'],
                        'oauth_consumer_key' => $data['oauth_consumer_key'],
                        'oauth_nonce' => $data['oauth_nonce'],
                        'oauth_signature_method' => $data['oauth_signature_method'],
                        'oauth_timestamp' => $data['oauth_timestamp'],
                        'oauth_version' => $data['oauth_version']
                        );

                    //validate by calculating the signiture and matching it
                    $original_signature = $data['oauth_signature'];

                    // If the payment method specifies full IPN logging, do it now.
                    if (empty($this->settings['sharedsecret']) ||
                        empty($this->settings['notificationurl'])) {
                        $this->log->add( 'trustpay', 'Notification URL and Secret Key Configuration not found'.print_r( $data, true ));
                        return FALSE;
                    }else{
                       $shared_secret = $this->settings['sharedsecret'];
                       $notificationurl = $this->settings['notificationurl'];
                    }

                    $consumer = new OP_OAuthConsumer($data['oauth_consumer_key'], $shared_secret, $notificationurl);
                    $request = new OP_OAuthRequest("GET", $notificationurl, $params);

                    $oauth_signature = urldecode($request->build_signature(new OP_OAuthSignatureMethod_HMAC_SHA1(), $consumer, NULL));

                    if ($original_signature === $oauth_signature){
                        return TRUE;
                    }else{
                        return FALSE;
                    }
                }

                /**
                * Check TrustPay TPN response.
                *
                */
                function check_tpn_response() {
                    @ob_clean();
                    $tpn_response = ! empty( $_GET ) ? $_GET : false;
                    if ( $tpn_response && $this->check_tpn_request_is_valid( $tpn_response ) ) {
                        header( 'HTTP/1.1 200 OK' );
                        do_action( "valid_trustpay_tpn_request", $tpn_response );
                    } else {
                        wp_die( "TrustPay TPN Request Failure", "TrustPay TPN", array( 'response' => 200 ) );
                    }
                } // End check_tpn_response()

                /**
                * Successful Payment!
                *
                * @access public
                * @param array $posted
                * @return void
                */
                function successful_request( $posted ) {
                    $posted = stripslashes_deep( $posted );

                    if ( ! empty( $posted['transaction_id'] )) {
                        $order = $this->get_trustpay_order( $posted['transaction_id']);

                        if ( 'yes' == $this->debug ) {
                            $this->log->add( 'trustpay', 'Found order #' . $order->id );
                        }
                        // Lowercase returned variables
                        $posted['status'] = strtolower( $posted['status'] );

                        if ( 'yes' == $this->debug ) {
                            $this->log->add( 'trustpay', 'Payment status: ' . $posted['status'] );
                        }

                        switch ( $posted['status'] ) {
                            case 'success':
                                // Check order not already completed
                                if ( $order->status == 'completed' ) {
                                    if ( 'yes' == $this->debug ) {
                                       $this->log->add( 'trustpay', 'Aborting, Order #' . $order->id . ' is already complete.' );
                                    }
                                    exit;
                                }

                                // Validate currency
                                if ( $order->get_order_currency() != $posted['currency'] ) {
                                    if ( 'yes' == $this->debug ) {
                                        $this->log->add( 'trustpay', 'Payment error: Currencies do not match (sent "' . $order->get_order_currency() . '" | returned "' . $posted['currency'] . '")' );
                                    }
                                    // Put this order on-hold for manual checking
                                    $order->update_status( 'on-hold', sprintf( __( 'Validation error: TrustPay currencies do not match (code %s).', 'woocommerce' ), $posted['currency'] ) );
                                    exit;
                                }

                                // Validate amount
                                if ( $order->get_total() != $posted['amount'] ) {
                                    if ( 'yes' == $this->debug ) {
                                        $this->log->add( 'trustpay', 'Payment error: Amounts do not match (gross ' . $posted['amount'] . ')' );
                                    }
                                    // Put this order on-hold for manual checking
                                    $order->update_status( 'on-hold', sprintf( __( 'Validation error: TrustPay amounts do not match (gross %s).', 'woocommerce' ), $posted['amount'] ) );
                                    exit;
                                }

                                if ( $posted['status'] == 'success' ) {
                                    $order->add_order_note( __( 'TPN payment completed', 'woocommerce' ) );
                                    $order->payment_complete();
                                }
                                if ( 'yes' == $this->debug ) {
                                    $this->log->add( 'paypal', 'Payment complete.' );
                                }
                            break;
                            case 'denied' :
                            case 'expired' :
                            case 'failed' :
                            case 'voided' :
                                // Order failed
                                $order->update_status( 'failed', sprintf( __( 'Payment %s via TrustPay TPN.', 'woocommerce' ), strtolower( $posted['status'] ) ) );
                            break;
                            default :
                                // No action
                            break;
                        }
                        exit;
                    }
                }

                /**
                 * Get the trustpay order processed by transaction_id
                 *
                 * @param type $transaction_id
                 * @return \WC_Order
                 */
                private function get_trustpay_order( $transaction_id) {
                    $order = new WC_Order( $transaction_id );
                    return $order;
                }
            }
        }
}
?>
