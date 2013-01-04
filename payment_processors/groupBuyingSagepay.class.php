<?php

class Group_Buying_SagePay_DC extends Group_Buying_Credit_Card_Processors {
	
	const
		API_ENDPOINT_SIM = 'https://test.sagepay.com/Simulator/VSPDirectGateway.asp',
		API_ENDPOINT_SANDBOX = 'https://test.sagepay.com/gateway/service/vspdirect-register.vsp',
		API_ENDPOINT_LIVE = 'https://live.sagepay.com/gateway/service/vspdirect-register.vsp',
		API_RELEASE_ENDPOINT_SIM = 'https://test.sagepay.com/Simulator/release.asp',
		API_RELEASE_ENDPOINT_SANDBOX = 'https://test.sagepay.com/gateway/service/release.vsp',
		API_RELEASE_ENDPOINT_LIVE = 'https://live.sagepay.com/gateway/service/release.vsp',
		MODE_TEST = 'sandbox',
		MODE_LIVE = 'live',
		MODE_SIM = 'simulator',
		API_USERNAME_OPTION = 'gb_sagepay_username',
		API_PASSWORD_OPTION = 'gb_sagepay_password',
		CURRENCY_CODE_OPTION = 'gb_sagepay_currency',
		API_MODE_OPTION = 'gb_sagepay_mode',
		PAYMENT_METHOD = 'Credit (SagePay)',
		TYPE = 'DEFERRED',	// Transaction type
		PROTOCOL_VERSION = '2.23',	// SagePay protocol vers no
		VENDOR = 'group_buying_site',
		LOGS = TRUE;	// enable logging
		
	public
		$api_mode = self::MODE_TEST,
		$vender_name = '',
		$api_password = '',
		$currency_code = 'GBP',
		$version = '';
		
	protected static $status;
	protected static $instance;
	protected static $errors = array(
	  2000 => 'Your card was declined by the bank.',
	  5013 => 'Your card has expired.',
	  3078 => 'Your e-mail was invalid.',
	  4023 => 'The card issue number is invalid.',
	  4024 => 'The card issue number is required.',
	  2000 => 'Your card was declined by the issuer',
	  2001 => 'Your card was declined by the merchant',
	  5995 => 'Please ensure you have entered the correct digits off the back of your card and your billing address is correct',
	  5027 => 'Card start date is invalid',
	  5028 => 'Card expiry date is invalid',
	  3107 => 'Please ensure you have entered your full name, not just your surname',
	  3069 => 'Your card type is not supported by this vendor. Please try a different card',
	  3057 => 'Your card security number was incorrect. This is normally the last 3 digits on the back of your card',
	  4021 => 'Your card number was incorrect',
	  5018 => "Your card security number was the incorrect length. This is normally the last 3 digits on the back of your card",
	  3130 => "Your state was incorrect. Please use the standard two character state code",
	  3068 => "Your card type is not supported by this vendor. Please try a different card",
	);
	
	public static function get_instance() {
		if ( !(isset(self::$instance) && is_a(self::$instance, __CLASS__)) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_api_url( $release = FALSE ) {
		if ( $release ) {
			if ( $this->api_mode == self::MODE_LIVE ) {
				return self::API_RELEASE_ENDPOINT_LIVE;
			} elseif ( $this->api_mode == self::MODE_SIM ) {
				return self::API_RELEASE_ENDPOINT_SIM;
			} else {
				return self::API_RELEASE_ENDPOINT_SANDBOX;
			}
		}
		else {
			if ( $this->api_mode == self::MODE_LIVE ) {
				return self::API_ENDPOINT_LIVE;
			} elseif ( $this->api_mode == self::MODE_SIM ) {
				return self::API_ENDPOINT_SIM;
			} else {
				return self::API_ENDPOINT_SANDBOX;
			}
		}
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	protected function __construct() {
		parent::__construct();
		$this->vender_name = get_option(self::API_USERNAME_OPTION, '');
		$this->api_password = get_option(self::API_PASSWORD_OPTION, '');
		$this->api_mode = get_option(self::API_MODE_OPTION, self::MODE_TEST);
		$this->currency_code = get_option(self::CURRENCY_CODE_OPTION, 'GBP');

		add_action('admin_init', array($this, 'register_settings'), 10, 0);
		add_action('purchase_completed', array($this, 'capture_purchase'), 10, 1);

		if ( self::DEBUG ) {
			add_action('init', array($this, 'capture_pending_payments'));
		} else {
			add_action(self::CRON_HOOK, array($this, 'capture_pending_payments'));
		}
	}

	public static function register() {
		self::add_payment_processor(__CLASS__, self::__('SagePay (custom)'));
	}

	/**
	 * Process a payment
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( $purchase->get_total($this->get_payment_method()) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase($purchase->get_id());
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance($payment_id);
				return $payment;
			}
		}
	
		
		$post_data = $this->post_data($checkout, $purchase);
		if ( self::DEBUG || self::LOGS ) self::set_error_messages( '--------- POST DATA----------' . print_r( $post_data, true ), FALSE);
		$post_string = $this->format_data($post_data);
		if ( self::DEBUG || self::LOGS ) self::set_error_messages( '--------- POST Format DATA----------' . print_r( $post_string, true ), FALSE );
		
		$raw_response = wp_remote_post( $this->get_api_url(), array(
  			'method' => 'POST',
			'body' => $post_string,
			'timeout' => apply_filters( 'http_request_timeout', 15),
			'sslverify' => false
		));
		if ( is_wp_error($raw_response) ) {
			return FALSE;
		}
		
		if ( self::DEBUG || self::LOGS ) self::set_error_messages( '----------RAW Response Body----------' . print_r($raw_response, TRUE), FALSE);
		$response_body = wp_remote_retrieve_body($raw_response);
		if ( self::DEBUG || self::LOGS ) self::set_error_messages( '----------Respose1---------' . print_r($response_body, TRUE), FALSE);
		
		$response = array();
		// Turn the reponse into an associative array
		$response_array = explode("\r\n", $response_body);
		for ($i=0; $i < sizeof($response_array); $i++) {
		  $key = substr($response_array[$i],0, strpos($response_array[$i], '='));
		  $response[$key] = substr(strstr($response_array[$i], '='), 1);
		}
		
		if ( self::DEBUG || self::LOGS ) self::set_error_messages( '----------Response----------' . print_r($response, TRUE), FALSE);
		
		$this->set_status($response);
		
		if ( self::$status !== 'success' ) {
			return FALSE;
		}
		
		$deal_info = array(); // creating purchased products array for payment below
		foreach ( $purchase->get_products() as $item ) {
			if ( isset($item['payment_method'][$this->get_payment_method()]) ) {
				if ( !isset($deal_info[$item['deal_id']]) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset($checkout->cache['shipping']) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}
		$payment_id = Group_Buying_Payment::new_payment(array(
			'payment_method' => $this->get_payment_method(),
			'purchase' => $purchase->get_id(),
			'amount' => $post_data['x_amount'], // TODO CHANGE to NVP_DATA Match
			'data' => array(
				'api_response' => $response,
				'masked_cc_number' => $this->mask_card_number($this->cc_cache['cc_number']), // save for possible credits later
			),
			'deals' => $deal_info,
			'shipping_address' => $shipping_address,
		), Group_Buying_Payment::STATUS_AUTHORIZED);
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance($payment_id);
		do_action('payment_authorized', $payment);
		return $payment;
	}

	/**
	 * Capture a pre-authorized payment
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function capture_purchase( Group_Buying_Purchase $purchase ) {
		$payments = Group_Buying_Payment::get_payments_for_purchase($purchase->get_id());
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance($payment_id);
			$this->capture_payment($payment);
		}
	}

	/**
	 * Try to capture all pending payments
	 *
	 * @return void
	 */
	public function capture_pending_payments() {
		$payments = Group_Buying_Payment::get_pending_payments();
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance($payment_id);
			$this->capture_payment($payment);
		}
	}

	public  function capture_payment( Group_Buying_Payment $payment ) {
		// is this the right payment processor? does the payment still need processing?
		if ( $payment->get_payment_method() == $this->get_payment_method() && $payment->get_status() != Group_Buying_Payment::STATUS_COMPLETE ) {
			$data = $payment->get_data();
			
			// Do we have a transaction ID to use for the capture?
			if ( isset($data['api_response']) ) {
				
				$items_to_capture = $this->items_to_capture($payment);
				if ( $items_to_capture ) {
			
					$transaction_id = $data['api_response']['VPSTxId'];
					$security_id = $data['api_response']['SecurityKey'];
					$transaction_auth_id = $data['api_response']['TxAuthNo'];
						
					$post_data = $this->capture_nvp_data($transaction_id, $security_id, $transaction_auth_id, $items_to_capture);
					$post_string = $this->format_data($post_data);

					if ( self::DEBUG || self::LOGS) {
						error_log('----------SagePay RELEASE Request----------');
						error_log(print_r($post_data, TRUE));
					}
					$raw_response = wp_remote_post( $this->get_api_url( TRUE ), array(
			  			'method' => 'POST',
						'body' => $post_string,
						'timeout' => apply_filters( 'http_request_timeout', 15),
						'sslverify' => false
					));
					
					if ( !is_wp_error($post_response) && $post_response['response']['code'] == '200' ) {
						
						$response_body = wp_remote_retrieve_body($raw_response);
						
						if ( self::DEBUG|| self::LOGS ) {
							error_log('----------Sagepay RELEASE Response----------');
							error_log(print_r($response_body, TRUE));
						}

						$response = array();
						// Turn the reponse into an associative array
						$response_array = explode("\r\n", $response_body);
						for ($i=0; $i < sizeof($response_array); $i++) {
						  $key = substr($response_array[$i],0, strpos($response_array[$i], '='));
						  $response[$key] = substr(strstr($response_array[$i], '='), 1);
						}

						if ( self::DEBUG || self::LOGS ) self::set_error_messages( '----------Response----------' . print_r($response, TRUE), FALSE);

						$this->set_status($response);
		
						if ( $response['Status'] == 'OK' ) {
							foreach ( $items_to_capture as $deal_id => $amount ) {
								unset($data['uncaptured_deals'][$deal_id]);
							}
							if ( !isset($data['capture_response']) ) {
								$data['capture_response'] = array();
							}
							$data['capture_response'][] = $response;
							$payment->set_data($data);
							do_action('payment_captured', $payment, array_keys($items_to_capture));
							$payment->set_status(Group_Buying_Payment::STATUS_COMPLETE);
							do_action('payment_complete', $payment);
						} else {
							$this->set_error_messages($response, FALSE);
						}

					}
				}
			}
		}
	}

	/**
	 * The the NVP data for submitting a DoCapture request
	 *
	 * @param string $transaction_id
	 * @param array $items
	 * @return array
	 */
	private function capture_nvp_data( $transaction_id, $security_id, $transaction_auth_id, $items ) {
		$total = 0;
		foreach ( $items as $price ) {
			$total += $price;
		}
		$nvpData = array();
		$nvpData['VPSProtocol'] = self::PROTOCOL_VERSION;
		$nvpData['TxType'] = 'RELEASE';
		$nvpData['Vendor'] = $this->vender_name;
		$nvpData['VendorTxCode'] = 'purchase_id_'.$purchase->get_ID();
		$nvpData['VPSTxId'] = $transaction_id;
		$nvpData['SecurityKey'] = $security_id;
		$nvpData['TxAuthNo'] = $transaction_auth_id;
		$nvpData['ReleaseAmount'] = gb_get_number_format( $total );

		$nvpData = apply_filters('gb_asagepay_capture_nvp_data', $nvpData);
		return $nvpData;
	}

	public static function set_status( $response ) {
		if ( self::DEBUG || self::LOGS ) self::set_error_messages( "status: " . print_r( $response, true ), FALSE );
		// Return values. Assign stuff based on the return 'Status' value from SagePay
		switch($response['Status']) {
			case 'OK':
				// Transactino made succssfully
				self::$status = 'success';
				$_SESSION['transaction']['VPSTxId'] = $response['VPSTxId']; // assign the VPSTxId to a session variable for storing if need be
				$_SESSION['transaction']['TxAuthNo'] = $response['TxAuthNo']; // assign the TxAuthNo to a session variable for storing if need be
				
				break;
			case '3DAUTH': // Unused
				// Transaction required 3D Secure authentication
				// The request will return two parameters that need to be passed with the 3D Secure
				$this->acsurl = $response['ACSURL']; // the url to request for 3D Secure
				$this->pareq = $response['PAReq']; // param to pass to 3D Secure
				$this->md = $response['MD']; // param to pass to 3D Secure
				self::$status = '3dAuth'; // set $this->status to '3dAuth' so your controller knows how to handle it
				break;
			case 'REJECTED':
				// errors for if the card is declined
				self::$status = 'declined';
				self::set_error_messages('Your payment was not authorised by your bank or your card details were incorrect.');
				break;
			case 'NOTAUTHED':
				// errors for if their card doesn't authenticate
				self::$status = 'notauthed';
				self::set_error_messages('Your payment was not authorised by your bank or your card details were incorrect.');
				break;
			case 'MALFORMED':
				// errors for if the user provides incorrect card data
				self::$status = 'malformed';
				self::set_error_messages('Purchase could not be authorized, please review all billing information below.');
				break;
			case 'INVALID':
				// errors for if the user provides incorrect card data
				self::$status = 'invalid';
				self::set_error_messages('One or more of your card details were invalid. Please try again.');
				break;
			case 'FAIL':
				// errors for if the transaction fails for any reason
				self::$status = 'fail';
				self::set_error_messages('An unexpected error has occurred. Please try again.');
				break;
			default:
				// default error if none of the above conditions are met
				self::$status = 'error';
				self::set_error_messages('An error has occurred. Please try again.');
				break;
		}
	}
	
	/**
	 * Grabs error messages from a Authorize response and displays them to the user
	 * @param array $response
	 * @param bool $display
	 * @return void
	 */
	private static function set_error_messages( $response, $display = TRUE ) {
		if ( $display ) {
			self::set_message($response, self::MESSAGE_STATUS_ERROR);
		} 
		if ( !$display && self::LOGS ) {
			/*/
			$log_file = dirname( __FILE__ ) . '/logs/error.log';
			$fp = fopen( $log_file , 'a' );
			fwrite( $fp, $response . "\n\n================" . time() . "===================\n" );
			fclose( $fp ); // close file
			// chmod( $log_file , 0600 );
			/**/
			error_log($response);
		}
	}

	/**
	 * Build the NVP data array for submitting the current checkout to Authorize as an Authorization request
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	private function post_data( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		$user = get_userdata($purchase->get_user());
		
		$data = array();
		$data['CardHolder'] = $checkout->cache['billing']['first_name'].' '.$checkout->cache['billing']['last_name'];
		$data['CardNumber'] = $this->cc_cache['cc_number'];
		// Format the StartDate field
		if($this->cache['cc_start_month']){
			$data['StartDate'] = self::expiration_date($this->cc_cache['cc_start_month'], $this->cc_cache['cc_start_year']);
		}
		$data['ExpiryDate'] =  self::expiration_date($this->cc_cache['cc_expiration_month'], $this->cc_cache['cc_expiration_year']);
		$data['CardType'] = strtoupper(self::get_sage_card_type($this->cc_cache['cc_number']));
		$data['CV2'] = $this->cc_cache['cc_cvv'];
		
		$data['BillingFirstnames'] = $checkout->cache['billing']['first_name'];
		$data['BillingSurname'] = $checkout->cache['billing']['last_name'];
		$address_array = explode("\n",$checkout->cache['billing']['street'], 2);
		$data['BillingAddress1'] = $address_array[0];
		if ( isset($address_array[1]) && $address_array[1] != '' ) {
			$data['BillingAddress2'] = $address_array[1];
		}
		$data['BillingCity'] = $checkout->cache['billing']['city'];
		$data['BillingState'] = $checkout->cache['billing']['country'] == 'US' ? $checkout->cache['billing']['zone'] : ''; // Only required for US customers
		$data['BillingPostCode'] = $checkout->cache['billing']['postal_code'];
		$data['BillingCountry'] = $checkout->cache['billing']['country'];

		// Shipping and Tax
		$amount = $purchase->get_subtotal( $this->get_payment_method() );
		$frieght = $purchase->get_shipping_total( $this->get_payment_method() );
		$tax = $purchase->get_tax_total( $this->get_payment_method() );
		$data['Amount'] = gb_get_number_format( $amount + $frieght + $tax );
		
		if ( isset($checkout->cache['shipping']) ) {
			$data['DeliveryFirstnames'] = $checkout->cache['shipping']['first_name'];
			$data['DeliverySurname'] = $checkout->cache['shipping']['last_name'];
			$ship_address_array = explode("\n",$checkout->cache['billing']['street'], 2);
			$data['DeliveryAddress1'] = $ship_address_array[0];
			if ( isset($ship_address_array[1]) && $ship_address_array[1] != '' ) {
				$data['DeliveryAddress2'] = $ship_address_array[1];
			}
			$data['DeliveryCity'] = $checkout->cache['shipping']['city'];
			$data['DeliveryState'] = $checkout->cache['shipping']['country'] == 'US' ? $checkout->cache['shipping']['zone'] : ''; // Only required for US customers
			$data['DeliveryPostCode'] = $checkout->cache['shipping']['postal_code'];
			$data['DeliveryCountry'] = $checkout->cache['shipping']['country'];
		} else { // use the billing fields
			$data['DeliveryFirstnames'] = $checkout->cache['billing']['first_name'];
			$data['DeliverySurname'] = $checkout->cache['billing']['last_name'];
			$data['DeliveryAddress1'] = $address_array[0];
			if ( isset($address_array[1]) && $address_array[1] != '' ) {
				$data['DeliveryAddress2'] = $address_array[1];
			}
			$data['DeliveryCity'] = $checkout->cache['billing']['city'];
			$data['DeliveryState'] = $checkout->cache['billing']['country'] == 'US' ? $checkout->cache['billing']['zone'] : ''; // Only required for US customers
			$data['DeliveryPostCode'] = $checkout->cache['billing']['postal_code'];
			$data['DeliveryCountry'] = $checkout->cache['billing']['country'];
		}

		$data['VPSProtocol'] = self::PROTOCOL_VERSION;
		$data['PaymentType'] = self::TYPE;
		$data['TxType'] = self::TYPE;
		$data['Vendor'] = $this->vender_name;
		$data['VendorTxCode'] = 'purchase_id_'.$purchase->get_ID();
		$data['Currency'] = $this->get_currency_code();
		$data['Description'] = sprintf(self::__('%s Purchase ID: #%s.'),get_bloginfo('name'),$purchase->get_ID());
		$data = apply_filters('gb_sagepay_nvp_data', $data); 
		return $data;
		
	}
	
	private static function get_sage_card_type( $cc_number ) {

		if ( preg_match('/^(6334[5-9][0-9]|6767[0-9]{2})[0-9]{10}([0-9]{2,3}?)?$/', $cc_number) ) {

		  return 'SOLO'; // is also a Maestro product

		} elseif (preg_match('/^(49369[8-9]|490303|6333[0-4][0-9]|6759[0-9]{2}|5[0678][0-9]{4}|6[0-9][02-9][02-9][0-9]{2})[0-9]{6,13}?$/', $cc_number) ) {

		  return 'MAESTRO';

		} elseif (preg_match('/^(49030[2-9]|49033[5-9]|4905[0-9]{2}|49110[1-2]|49117[4-9]|49918[0-2]|4936[0-9]{2}|564182|6333[0-4][0-9])[0-9]{10}([0-9]{2,3}?)?$/', $cc_number) ) {

		  return 'MAESTRO'; // SWITCH is now Maestro

		} elseif (preg_match('/^4[0-9]{12}([0-9]{3})?$/', $cc_number) ) {

		  return 'VISA';

		} elseif (preg_match('/^5[1-5][0-9]{14}$/', $cc_number) ) {

		  return 'MC';

		} elseif (preg_match('/^3[47][0-9]{13}$/', $cc_number) ) {

		  return 'AMEX';

		} elseif (preg_match('/^3(0[0-5]|[68][0-9])[0-9]{11}$/', $cc_number) ) {

		  return 'DINERS';

		} elseif (preg_match('/^(6011[0-9]{12}|622[1-9][0-9]{12}|64[4-9][0-9]{13}|65[0-9]{14})$/', $cc_number) ) {

		  return 'DISCOVER';

		} elseif (preg_match('/^(35(28|29|[3-8][0-9])[0-9]{12}|2131[0-9]{11}|1800[0-9]{11})$/', $cc_number) ) {

		  return 'JCB';

		} else {

		  return 'Unknown';

		}
	}
		
	private function get_currency_code() {
		return apply_filters('gb_paypal_wpp_currency_code', $this->currency_code);
	}
	

	/**
	 * Format the month and year as an expiration date
	 * @static
	 * @param int $month
	 * @param int $year
	 * @return string
	 */
	private static function expiration_date( $month, $year ) {
		return sprintf('%02d%02d', $month, substr($year,2,2));
	}
		
	/**
	 * format_data method
	 * Takes $this->data and converts it to
	 * a url encoded query string
	 * @return void
	 **/
	private function format_data($data)
	{
		// Initialise arr variable
		$str = array();

		// Step through the fields
		foreach($data as $key => $value){
			// Stick them together as key=value pairs (url encoded)
			$str[] = $key . '=' . urlencode($value);
		}

		// Implode the arry using & as the glue and store the data
		$post_string = implode('&', $str);
		return $post_string;
	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_authorizenet_settings';
		add_settings_section($section, self::__('SagePay'), array($this, 'display_settings_section'), $page);
		register_setting($page, self::API_MODE_OPTION);
		register_setting($page, self::API_USERNAME_OPTION);
		register_setting($page, self::API_PASSWORD_OPTION);
		register_setting($page, self::CURRENCY_CODE_OPTION);
		add_settings_field(self::API_MODE_OPTION, self::__('Mode'), array($this, 'display_api_mode_field'), $page, $section);
		add_settings_field(self::API_USERNAME_OPTION, self::__('Vender Name'), array($this, 'display_api_username_field'), $page, $section);
		//add_settings_field(self::API_PASSWORD_OPTION, self::__('Transaction Key (Password)'), array($this, 'display_api_password_field'), $page, $section);
		add_settings_field(self::CURRENCY_CODE_OPTION, self::__('Currency Code'), array($this, 'display_currency_code_field'), $page, $section);
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_USERNAME_OPTION.'" value="'.$this->vender_name.'" size="15" />';
	}
	/*/
	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_PASSWORD_OPTION.'" value="'.$this->api_password.'" size="80" />';
	}
	/**/
	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_LIVE.'" '.checked(self::MODE_LIVE, $this->api_mode, FALSE).'/> '.self::__('Live').'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_TEST.'" '.checked(self::MODE_TEST, $this->api_mode, FALSE).'/> '.self::__('Test').'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_SIM.'" '.checked(self::MODE_SIM, $this->api_mode, FALSE).'/> '.self::__('Simulator').'</label>';
	}
	
	public function display_currency_code_field() {
		echo '<input type="text" name="'.self::CURRENCY_CODE_OPTION.'" value="'.$this->currency_code.'" size="5" />';
	}

}
Group_Buying_SagePay_DC::register();