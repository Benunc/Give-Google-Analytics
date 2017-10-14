<?php
/**
 * GiveWP - Google Analytics Functions.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper function to check conditions for triggering GA tracking code.
 *
 * @since 1.1
 *
 * @param $payment_id
 *
 * @return bool
 */
function give_should_send_beacon( $payment_id ) {

	$sent_already = get_post_meta( $payment_id, '_give_ga_beacon_sent', true );

	// Check meta beacon flag.
	if ( ! empty( $sent_already ) ) {
		return false;
	}

	// Must be publish status.
	if ( 'publish' !== give_get_payment_status( $payment_id ) ) {
		return false;
	}

	// Don't continue if test mode is enabled and test mode tracking is disabled.
	if ( give_is_test_mode() && ! give_google_analytics_track_testing() ) {
		return false;
	}

	// Passed conditions so return true.
	return apply_filters( 'give_should_send_beacon', true, $payment_id );
}

/**
 * Flag refund beacon after payment updated to refund status.
 */
function give_google_analytics_admin_flag_beacon() {

	// Must be updating payment on the payment details page.
	if ( ! isset( $_GET['page'] ) || 'give-payment-history' !== $_GET['page'] ) {
		return false;
	}

	if ( ! isset( $_GET['give-message'] ) || 'payment-updated' !== $_GET['give-message'] ) {
		return false;
	}

	// Must have page ID.
	if ( ! isset( $_GET['id'] ) ) {
		return false;
	}

	$payment_id = $_GET['id'];

	$status = give_get_payment_status( $payment_id );

	// Bailout.
	if ( 'refunded' !== $status ) {
		return false;
	}

	// Check if the beacon has already been sent.
	$beacon_sent = get_post_meta( $payment_id, '_give_ga_refund_beacon_sent', true );

	if ( ! empty( $beacon_sent ) ) {
		return false;
	}

	// Passed all checks. Now process beacon.
	update_post_meta( $payment_id, '_give_ga_refund_beacon_sent', 'true' );

}

add_action( 'admin_footer', 'give_google_analytics_admin_flag_beacon' );

/**
 * Should track testing?
 *
 * @return bool
 */
function give_google_analytics_track_testing() {
	if ( give_is_setting_enabled( give_get_option( 'google_analytics_test_option' ) ) ) {
		return true;
	}

	return false;
}

/**
 * Track donation completions for offsite gateways.
 *
 * Since donors often don't return from offsite gateways we need to watch for payments updating from "pending" to "completed" statuses.
 * When it does we then check the date of the donation and if a beacon has been sent along with other checks before sending the check.
 *
 * @since  1.1
 *
 * @param $payment_id
 * @param $new_status
 * @param $old_status
 *
 * @return int $do_change
 */
function give_google_analytics_completed_donation( $payment_id, $new_status, $old_status ) {

	// Check conditions.
	$sent_already = get_post_meta( $payment_id, '_give_ga_beacon_sent', true );

	if ( ! empty( $sent_already ) ) {
		return false;
	}

	// Going from "pending" to "Publish" -> like PayPal Standard when receiving a successful payment IPN.
	if ( 
		'pending' === $old_status 
	     && 'publish' === $new_status ) {
		give_google_analytics_send_completed_beacon( $payment_id );
	}

}

add_action( 'give_update_payment_status', 'give_google_analytics_completed_donation', 110, 3 );


/**
 * Triggers when a payment is updated from pending to complete.
 *
 * Uses the Measurement Protocol within GA's API https://developers.google.com/analytics/devguides/collection/protocol/v1/devguide
 *
 * @since 1.1
 *
 * @param string $payment_id
 *
 * @return string
 */
function give_google_analytics_send_completed_beacon( $payment_id ) {

	$ua_code = give_get_option( 'google_analytics_ua_code' );

	// Check if UA code exists.
	if ( empty( $ua_code ) ) {
		give_insert_payment_note( $payment_id, __( 'Google Analytics donation tracking beacon could not send due to missing GA Tracking ID.', 'give-google-analytics' ) );
		return false;
	}

	// Set vars.
	$form_id     = give_get_payment_form_id( $payment_id );
	$form_title  = get_the_title( $form_id );
	$total       = give_get_payment_amount( $payment_id );
	$affiliation = give_get_option( 'google_analytics_affiliate' );

	// Add the categories.
	$ga_categories = give_get_option( 'google_analytics_category' );
	$ga_categories = ! empty( $ga_categories ) ? $ga_categories : 'Donations';
	$ga_list       = give_get_option( 'google_analytics_list' );

	$args = apply_filters( 'give_google_analytics_record_offsite_payment_hit_args', array(
		'v'     => 1,
		'tid'   => $ua_code, // Tracking ID required.
		'cid'   => 555, // Random Client ID. Required.
		't'     => 'event', // Event hit type.
		'ec'    => 'Fundraising', // Event Category. Required.
		'ea'    => 'Donation Success', // Event Action. Required.
		'el'    => $form_title, // Event Label.
		'ti'    => $payment_id, // Transaction ID. Required.
		'tr'    => $total,  // Revenue.
		'ta'    => $affiliation,  // Affiliation.
		'pal'   => $ga_list,   // Product Action List.
		'pa'    => 'purchase',
		'pr1id' => $form_id,  // Product 1 ID. Either ID or name must be set.
		'pr1nm' => $form_title, // Product 1 name. Either ID or name must be set.
		'pr1ca' => $ga_categories, // Product 1 category.
		'pr1br' => 'Fundraising',
		'pr1qt' => 1,   // Product 1 quantity. Required.
	) );

	$args = array_map( 'rawurlencode', $args );

	$url = add_query_arg( $args, 'https://www.google-analytics.com/collect' );

	$request = wp_remote_post( $url );

	// Check if beacon sent successfully.
	if ( ! is_wp_error( $request ) || 200 == wp_remote_retrieve_response_code( $request ) ) {

		add_post_meta( $payment_id, '_give_ga_beacon_sent', true );
		give_insert_payment_note( $payment_id, __( 'Google Analytics ecommerce tracking beacon sent.', 'give-google-analytics' ) );

	}


}
