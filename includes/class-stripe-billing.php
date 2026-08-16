<?php
/**
 * Stripe Billing Integration for Yellow Links
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Yellow_Links_Stripe_Billing {

    private static function get_stripe_keys() {
        $secret_key = '';
        $public_key = '';

        if ( function_exists( 'wp_get_connectors' ) ) {
            $connectors = wp_get_connectors();
            if ( ! empty( $connectors['stripe']['authentication']['secret_key'] ) ) {
                $secret_key = get_option( $connectors['stripe']['authentication']['secret_key'], '' );
            }
            if ( ! empty( $connectors['stripe']['authentication']['public_key'] ) ) {
                $public_key = get_option( $connectors['stripe']['authentication']['public_key'], '' );
            }
        }

        if ( empty( $secret_key ) ) {
            $secret_key = getenv( 'STRIPE_SECRET_KEY' );
        }
        if ( empty( $public_key ) ) {
            $public_key = getenv( 'STRIPE_PUBLIC_KEY' );
        }

        return array(
            'secret' => $secret_key,
            'public' => $public_key
        );
    }

    private static function api_request( $endpoint, $method = 'POST', $body = array() ) {
        $keys = self::get_stripe_keys();
        if ( empty( $keys['secret'] ) ) {
            return new WP_Error( 'no_stripe_key', 'Stripe API key is not configured in WP Connectors.', array( 'status' => 500 ) );
        }

        $url = 'https://api.stripe.com/v1/' . $endpoint;
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $keys['secret'],
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

    public static function handle_buy_slot( WP_REST_Request $request ) {
        $link_id = $request->get_param( 'link_id' );
        $buyer_email = sanitize_email( $request->get_param( 'buyer_email' ) );
        $slot_price = (int) $request->get_param( 'price_cents' ); // e.g. 5000 for $50.00

        if ( empty( $link_id ) || empty( $buyer_email ) || empty( $slot_price ) ) {
            return new WP_Error( 'missing_params', 'link_id, buyer_email, and price_cents are required.', array( 'status' => 400 ) );
        }

        $post_id = (int) str_replace( 'link-', '', $link_id );
        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Yellow Link not found.', array( 'status' => 404 ) );
        }

        $body = array(
            'amount'         => $slot_price,
            'currency'       => 'usd',
            'capture_method' => 'manual',
            'receipt_email'  => $buyer_email,
            'metadata'       => array(
                'link_id' => $link_id,
                'post_id' => $post_id
            )
        );

        $intent = self::api_request( 'payment_intents', 'POST', $body );

        if ( is_wp_error( $intent ) ) {
            return $intent;
        }

        update_post_meta( $post_id, 'yl_stripe_pi', $intent['id'] );
        update_post_meta( $post_id, 'yl_buyer_email', $buyer_email );
        
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
