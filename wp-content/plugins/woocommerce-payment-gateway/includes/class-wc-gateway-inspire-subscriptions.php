<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Inspire_Subscriptions class.
 *
 * @since 2.0.0
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Inspire_Subscriptions extends WC_Gateway_Inspire {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {

			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );

		}

	}

	/**
	 * Process the payment
	 *
	 * @param  int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		// Processing subscription
		if ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) ) {
			return $this->process_subscription( $order_id );
		} else {
			return parent::process_payment( $order_id );
		}
	}

	/**
	 * Process the subscription payment and set the appropriate recurring flags.
	 *
	 * @param $order_id
	 * @return array
	 */
	public function process_subscription( $order_id ) {

		$new_customer_vault_id = '';
		$order                 = new WC_Order( $order_id );
		$user                  = new WP_User( $order->get_user_id() );
		$processed_vault_id    = '';
		$this->check_payment_method_conversion( $user->user_login, $user->ID );

		// Convert CC expiration date from (M)M-YYYY to MMYY
		$expmonth = parent::get_post( 'expmonth' );
		$expyear  = '';
		if ( $expmonth < 10 ) {
			$expmonth = '0' . $expmonth;
		}
		if ( $this->get_post( 'expyear' ) != null ) {
			$expyear = substr( $this->get_post( 'expyear' ), -2 );
		}

		WC_Gateway_Inspire::update_customer_vault_ids_removing_empty_vault_ids( $user->ID );

		// Create server request using stored or new payment details
		if ( 'yes' == $this->get_post( 'inspire-use-stored-payment-info' ) ) {

			// Short request, use stored billing details
			$customer_vault_ids = get_user_meta( $user->ID, 'customer_vault_ids', true );
			$id                 = $customer_vault_ids[ $this->get_post( 'inspire-payment-method' ) ];
			$processed_vault_id = $id;

			if ( '_' !== substr( $id, 0, 1 ) ) {
				$base_request['customer_vault_id'] = $id;
			} else {
				$base_request['customer_vault_id'] = $user->user_login;
				$base_request['billing_id']        = substr( $id, 1 );
				$base_request['ver']               = 2;
			}
		} else {

			// Full request, new customer or new information
			$base_request = array(
				'ccnumber'  => $this->get_post( 'ccnum' ),
				'cvv'       => $this->get_post( 'cvv' ),
				'ccexp'     => $expmonth . $expyear,
				'firstname' => version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_first_name : $order->get_billing_first_name(),
				'lastname'  => version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_last_name : $order->get_billing_last_name(),
				'address1'  => version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_address_1 : $order->get_billing_address_1(),
				'city'      => version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_city : $order->get_billing_city(),
				'state'     => version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_state : $order->get_billing_state(),
				'zip'       => version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_postcode : $order->get_billing_postcode(),
				'country'   => version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_country : $order->get_billing_country(),
				'phone'     => version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_phone : $order->get_billing_phone(),
				'email'     => version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->billing_email : $order->get_billing_email(),
			);

			$base_request['customer_vault'] = 'add_customer';

			// Generate a new customer vault id for the payment method
			$new_customer_vault_id = $this->random_key();
			$processed_vault_id    = $new_customer_vault_id;

			// Set customer ID for new record
			$base_request['customer_vault_id'] = $new_customer_vault_id;

			// Set 'recurring' flag for subscriptions
			$base_request['billing_method'] = 'recurring';

		}

		// Add transaction-specific details to the request
		$transaction_details = array(
			'username'  => $this->username,
			'password'  => $this->password,
			'amount'    => version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->order_total : $order->get_total(),
			'type'      => $this->salemethod,
			'payment'   => 'creditcard',
			'orderid'   => $order->get_order_number(),
			'ipaddress' => $_SERVER['REMOTE_ADDR'],
		);

		// Send request and get response from server
		$response = $this->post_and_get_response( array_merge( $base_request, $transaction_details ) );

		// Check response
		if ( 1 == $response['response'] ) {

			// Success
			$order->add_order_note( __( 'Inspire Commerce payment completed. Transaction ID: ', 'woocommerce-gateway-inspire' ) . $response['transactionid'] );
			$order->payment_complete();

			// Store the payment method number/customer vault ID translation table in the user's metadata
			$customer_vault_ids         = array();
			$customer_vault_ids_content = get_user_meta( $user->ID, 'customer_vault_ids', true );

			if ( $customer_vault_ids_content ) {
				$customer_vault_ids = $customer_vault_ids_content;
			}

			if ( ! empty( $new_customer_vault_id ) ) {
				$customer_vault_ids[] = $new_customer_vault_id;
			}

			if ( ! empty( $customer_vault_ids ) ) {				
				update_user_meta( $user->ID, 'customer_vault_ids', $customer_vault_ids );
			}

			// Save the correct index for payment card.
			$vault_id_index = 0;
			if ( '' !== $processed_vault_id ) {
				$customer_vault_ids = get_user_meta( $user->ID, 'customer_vault_ids', true );
				$vault_id_index     = array_search( $processed_vault_id, $customer_vault_ids );
			}

			// Store payment method number for future subscription payments
			if ( version_compare( WC_VERSION, '3.0.0', '<' ) ) {
				update_post_meta( $order->id, 'payment_method_number', $vault_id_index );
				update_post_meta( $order->id, 'transactionid', $response['transactionid'] );
			} else {
				$order->update_meta_data( 'payment_method_number', $vault_id_index );
				$order->update_meta_data( 'transactionid', $response['transactionid'] );
				$order->save();
			}

			// Return thank you redirect
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		} elseif ( 2 == $response['response'] ) {

			// Decline
			$order->add_order_note( __( 'Inspire Commerce payment failed. Payment declined.', 'woocommerce-gateway-inspire' ) );
			wc_add_notice( __( 'Sorry, the transaction was declined.', 'woocommerce-gateway-inspire' ), $notice_type = 'error' );

		} elseif ( 3 == $response['response'] ) {

			// Other transaction error
			$order->add_order_note( __( 'Inspire Commerce payment failed. Error: ', 'woocommerce-gateway-inspire' ) . $response['responsetext'] );
			wc_add_notice( __( 'Sorry, there was an error: ', 'woocommerce-gateway-inspire' ) . $response['responsetext'], $notice_type = 'error' );

		} else {

			// No response or unexpected response
			$order->add_order_note( __( "Inspire Commerce payment failed. Couldn't connect to gateway server.", 'woocommerce-gateway-inspire' ) );
			wc_add_notice( __( 'No response from payment gateway server. Try again later or contact the site administrator.', 'woocommerce-gateway-inspire' ), $notice_type = 'error' );

		}

		return array();

	}

	/**
	 * scheduled_subscription_payment function.
	 *
	 * @param float $amount_to_charge  The amount to charge.
	 * @param WC_Order $renewal_order A WC_Order object created to record the renewal payment.
	 * @access public
	 * @return void
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
		$response = $this->process_subscription_payment( $renewal_order, $amount_to_charge );

		if ( is_wp_error( $response ) ) {
			$renewal_order->update_status( 'failed', sprintf( __( 'Inspire Transaction Failed (%s)', 'woocommerce-gateway-inspire' ), $response->get_error_message() ) );
		}
	}

	/**
	 * process_subscription_payment function.
	 *
	 * @access public
	 * @param WC_Order $order
	 * @param int $amount (default: 0)
	 * @return string|WP_Error
	 */
	public function process_subscription_payment( $order, $amount = 0 ) {
		$user     = new WP_User( $order->get_user_id() );
		$order_id = version_compare( WC_VERSION, '3.0.0', '<' ) ? $order->id : $order->get_id();

		$this->check_payment_method_conversion( $user->user_login, $user->ID );
		WC_Gateway_Inspire::update_customer_vault_ids_removing_empty_vault_ids( $user->ID );

		$customer_vault_ids    = get_user_meta( $user->ID, 'customer_vault_ids', true );
		$payment_method_number = get_post_meta( $order_id, 'payment_method_number', true );

		if ( '' == $payment_method_number ) {
			$subscriptions         = wcs_get_subscriptions_for_renewal_order( $order );
			$subscription          = array_pop( $subscriptions );
			$parent_order_id       = $subscription->get_parent_id();

			$payment_method_number = get_post_meta( $parent_order_id, 'payment_method_number', true );
		}

		if ( '' == $payment_method_number ) {
			$payment_method_number = 0;
		}

		$inspire_request = array(
			'username'       => $this->username,
			'password'       => $this->password,
			'amount'         => $amount,
			'type'           => $this->salemethod,
			'billing_method' => 'recurring',
		);

		$id = $customer_vault_ids[ $payment_method_number ];
		if ( substr( $id, 0, 1 ) !== '_' ) {
			$inspire_request['customer_vault_id'] = $id;
		} else {
			$inspire_request['customer_vault_id'] = $user->user_login;
			$inspire_request['billing_id']        = substr( $id, 1 );
			$inspire_request['ver']               = 2;
		}

		$response = $this->post_and_get_response( $inspire_request );

		if ( 1 == $response['response'] ) {
			// Success
			$order->add_order_note( __( 'Inspire Commerce scheduled subscription payment completed. Transaction ID: ', 'woocommerce-gateway-inspire' ) . $response['transactionid'] );
			WC_Subscriptions_Manager::process_subscription_payments_on_order( $order_id );
			$order->payment_complete();

		} elseif ( 2 == $response['response'] ) {
			// Decline
			$order->add_order_note( __( 'Inspire Commerce scheduled subscription payment failed. Payment declined.', 'woocommerce-gateway-inspire' ) );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order_id );

		} elseif ( 3 == $response['response'] ) {
			// Other transaction error
			$order->add_order_note( __( 'Inspire Commerce scheduled subscription payment failed. Error: ', 'woocommerce-gateway-inspire' ) . $response['responsetext'] );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order_id );

		} else {
			// No response or unexpected response
			$order->add_order_note( __( 'Inspire Commerce scheduled subscription payment failed. Couldn\'t connect to gateway server.', 'woocommerce-gateway-inspire' ) );

		}
	}	
}
