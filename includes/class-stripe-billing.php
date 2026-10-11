<?php
/**
 * Stripe Billing Integration for Yellow Links
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-yellow-links-connectors.php';
require_once plugin_dir_path( __FILE__ ) . 'class-yellow-links-rate-limit.php';

class Yellow_Links_Stripe_Billing {

    /** Smallest and largest slot price a caller may ask for, in cents ($5 to $5,000). */
    const MIN_SLOT_CENTS = 500;
    const MAX_SLOT_CENTS = 500000;

    private static function get_secret_key() {
        return Yellow_Links_Connectors::get_key( Yellow_Links_Connectors::STRIPE_SECRET_KEY );
    }

    private static function api_request( $endpoint, $method = 'POST', $body = array() ) {
        $secret_key = self::get_secret_key();
        if ( empty( $secret_key ) ) {
            return new WP_Error( 'no_stripe_key', 'Stripe API key is not configured in Settings > Connectors (Stripe Secret Key).', array( 'status' => 500 ) );
        }

        $url = 'https://api.stripe.com/v1/' . $endpoint;
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
            ),
            'method'  => $method,
            'timeout' => 30,
        );

        if ( $method === 'POST' && !empty( $body ) ) {
            $args['body'] = $body; // WP converts array to application/x-www-form-urlencoded
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code >= 400 ) {
            $error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Stripe API Error.';
            return new WP_Error( 'stripe_error', $error_message, array( 'status' => $response_code, 'raw' => $data ) );
        }

        return $data;
    }

    /**
     * Opens a Stripe PaymentIntent for a slot on one link. The caller has to be logged in and has
     * to own the link (or be an admin). Nothing here marks a slot paid: the intent uses manual
     * capture, and a slot only goes active in handle_approve_slot(), after an admin captures the
     * payment and Stripe confirms it. A payment already waiting for approval is never replaced.
     */
    public static function handle_buy_slot( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'rest_forbidden', 'Log in to buy a slot.', array( 'status' => 401 ) );
        }

        $link_id     = $request->get_param( 'link_id' );
        $buyer_email = $request->get_param( 'buyer_email' );
        $buyer_email = is_string( $buyer_email ) ? sanitize_email( $buyer_email ) : '';
        if ( '' === $buyer_email ) {
            $buyer_email = sanitize_email( wp_get_current_user()->user_email );
        }

        if ( ! is_string( $link_id ) || '' === $link_id || ! is_email( $buyer_email ) ) {
            return new WP_Error( 'missing_params', 'link_id and a valid buyer_email are required.', array( 'status' => 400 ) );
        }

        $post_id = (int) str_replace( 'link-', '', $link_id );
        if ( $post_id <= 0 || 'yellow_link' !== get_post_type( $post_id ) || ! in_array( get_post_status( $post_id ), array( 'publish', 'pending' ), true ) ) {
            return new WP_Error( 'not_found', 'Yellow Link not found.', array( 'status' => 404 ) );
        }

        if ( (int) get_post_field( 'post_author', $post_id ) !== $user_id && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You can only buy a slot for your own link.', array( 'status' => 403 ) );
        }

        if ( 'active' === get_post_meta( $post_id, 'yl_slot_status', true ) ) {
            return new WP_Error( 'slot_active', 'This link already has an active slot.', array( 'status' => 409 ) );
        }

        // The site can fix the price (filter or option, in cents); otherwise the caller's price is
        // taken, but only inside a sane range.
        $fixed_price = (int) apply_filters( 'yellow_links_slot_price_cents', (int) get_option( 'xophz_compass_yellow_links_slot_price_cents', 0 ), $post_id );
        $slot_price  = $fixed_price > 0 ? $fixed_price : (int) $request->get_param( 'price_cents' ); // e.g. 5000 for $50.00
        if ( $slot_price < self::MIN_SLOT_CENTS || $slot_price > self::MAX_SLOT_CENTS ) {
            return new WP_Error( 'invalid_price', 'price_cents must be between ' . self::MIN_SLOT_CENTS . ' and ' . self::MAX_SLOT_CENTS . '.', array( 'status' => 400 ) );
        }

        // Each attempt that gets this far costs Stripe calls, so it is limited per user and per
        // address. Requests refused above do not count.
        $limited = Yellow_Links_Rate_Limit::hit( 'buy_slot_user', (string) $user_id, 5, HOUR_IN_SECONDS, 'Too many payment attempts. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }
        $limited = Yellow_Links_Rate_Limit::hit( 'buy_slot_ip', Yellow_Links_Rate_Limit::client_ip(), 10, HOUR_IN_SECONDS, 'Too many payment attempts from this network. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        // A payment that is authorized and waiting for an admin must not be overwritten, or the
        // admin would capture a different intent than the one the buyer approved.
        $earlier = get_post_meta( $post_id, 'yl_stripe_pi', true );
        if ( is_string( $earlier ) && preg_match( '/^pi_[A-Za-z0-9]+$/', $earlier ) ) {
            $existing = self::api_request( 'payment_intents/' . $earlier, 'GET' );
            if ( is_wp_error( $existing ) ) {
                $data = $existing->get_error_data();
                if ( ! is_array( $data ) || 404 !== ( $data['status'] ?? 0 ) ) {
                    return $existing;
                }
            } elseif ( in_array( $existing['status'] ?? '', array( 'requires_capture', 'processing', 'succeeded' ), true ) ) {
                return new WP_Error( 'payment_pending', 'A payment for this link is already waiting for approval.', array( 'status' => 409 ) );
            }
        }

        $body = array(
            'amount'         => $slot_price,
            'currency'       => 'usd',
            'capture_method' => 'manual',
            'receipt_email'  => $buyer_email,
            'metadata'       => array(
                'link_id' => 'link-' . $post_id,
                'post_id' => $post_id,
                'user_id' => $user_id,
            )
        );

        $intent = self::api_request( 'payment_intents', 'POST', $body );

        if ( is_wp_error( $intent ) ) {
            return $intent;
        }

        update_post_meta( $post_id, 'yl_stripe_pi', $intent['id'] );
        update_post_meta( $post_id, 'yl_buyer_email', $buyer_email );
        update_post_meta( $post_id, 'yl_buyer_user', $user_id );
        
        return rest_ensure_response( array(
            'success' => true,
            'client_secret' => $intent['client_secret'],
            'payment_intent_id' => $intent['id']
        ) );
    }

    public static function handle_approve_slot( WP_REST_Request $request ) {
        $link_id = $request->get_param( 'link_id' );
        $post_id = (int) str_replace( 'link-', '', $link_id );

        $pi_id = get_post_meta( $post_id, 'yl_stripe_pi', true );

        if ( empty( $pi_id ) ) {
            return new WP_Error( 'no_intent', 'No pending Stripe transaction found for this link.', array( 'status' => 400 ) );
        }

        $capture = self::api_request( 'payment_intents/' . $pi_id . '/capture', 'POST' );

        if ( is_wp_error( $capture ) ) {
            return $capture;
        }

        // The slot goes active only once Stripe says the money was taken.
        if ( 'succeeded' !== ( $capture['status'] ?? '' ) ) {
            return new WP_Error( 'capture_incomplete', 'Stripe did not confirm the payment, so the slot was not activated.', array( 'status' => 402 ) );
        }

        wp_update_post( array(
            'ID' => $post_id,
            'post_status' => 'publish'
        ) );
        update_post_meta( $post_id, 'yl_slot_status', 'active' );
        update_post_meta( $post_id, 'yl_last_verified', time() );

        return rest_ensure_response( array(
            'success' => true,
            'status'  => 'captured',
            'stripe_id' => $capture['id']
        ) );
    }

    public static function handle_reject_slot( WP_REST_Request $request ) {
        $link_id = $request->get_param( 'link_id' );
        $post_id = (int) str_replace( 'link-', '', $link_id );

        $pi_id = get_post_meta( $post_id, 'yl_stripe_pi', true );

        if ( empty( $pi_id ) ) {
            return new WP_Error( 'no_intent', 'No pending Stripe transaction found for this link.', array( 'status' => 400 ) );
        }

        $cancel = self::api_request( 'payment_intents/' . $pi_id . '/cancel', 'POST' );

        if ( is_wp_error( $cancel ) ) {
            return $cancel;
        }

        wp_trash_post( $post_id );

        return rest_ensure_response( array(
            'success' => true,
            'status'  => 'canceled',
            'stripe_id' => $cancel['id']
        ) );
    }
}
