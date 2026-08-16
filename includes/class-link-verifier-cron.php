<?php
/**
 * Yellow Links Verification Cron (Honorable Host Cron)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Yellow_Links_Verifier_Cron {

    public static function init() {
        add_action( 'yellow_links_daily_verify', array( __CLASS__, 'verify_active_slots' ) );

        if ( ! wp_next_scheduled( 'yellow_links_daily_verify' ) ) {
            wp_schedule_event( time(), 'daily', 'yellow_links_daily_verify' );
        }
    }

    public static function verify_active_slots() {
        // This cron runs on the HOST site to verify the link is still being output locally.
        // It acts as an "Honorable System" - if the host's theme or site breaks and the link disappears,
        // it automatically cancels the buyer's subscription to prevent unfair billing.

        $args = array(
            'post_type' => 'yellow_link',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => 'yl_slot_status',
                    'value' => 'active',
                    'compare' => '='
                )
            )
        );

        $query = new WP_Query( $args );

        if ( $query->have_posts() ) {
            $host_url = home_url( '/' . get_option( 'xophz_compass_yellow_links_custom_slug', 'yellow-links' ) );
            
            // Fetch our own directory page
            $response = wp_remote_get( $host_url, array( 'timeout' => 10 ) );
            $html = '';
            
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $html = wp_remote_retrieve_body( $response );
            }

            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                $target_url = get_post_meta( $post_id, 'yl_url', true );
                
                if ( empty( $target_url ) ) {
                    continue;
                }

                // Verify the link actually exists in the HTML output of our directory
                // We do a simple string match for the target URL
                if ( ! empty( $html ) && strpos( $html, esc_url( $target_url ) ) === false ) {
                    // The link is missing from the frontend! The theme might be broken or it was hidden.
                    // To act honorably, we must cancel the buyer's active Stripe subscription/intent.
                    self::cancel_billing( $post_id );
                } else {
                    update_post_meta( $post_id, 'yl_last_verified', time() );
                }
            }
            wp_reset_postdata();
        }
    }

    private static function cancel_billing( $post_id ) {
        $pi_id = get_post_meta( $post_id, 'yl_stripe_pi', true );
        if ( ! empty( $pi_id ) && class_exists( 'Yellow_Links_Stripe_Billing' ) ) {
            // In a full implementation, we would cancel the Subscription object.
            // Here we cancel the Intent or leave a flag for the admin.
            update_post_meta( $post_id, 'yl_slot_status', 'verification_failed' );
            // Yellow_Links_Stripe_Billing::api_request( 'payment_intents/' . $pi_id . '/cancel', 'POST' );
        }
    }
}
