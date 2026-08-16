<?php
/**
 * Yellow Links Analytics Verifier
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Yellow_Links_Analytics_Verifier {

    public static function get_verified_pageviews() {
        $transient_key = 'yl_verified_pageviews_monthly';
        $cached = get_transient( $transient_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $pageviews = 0;

        if ( function_exists( 'wp_get_connectors' ) ) {
            $connectors = wp_get_connectors();
            
            // Try Plausible first
            if ( ! empty( $connectors['plausible']['authentication']['api_key'] ) ) {
                $api_key = get_option( $connectors['plausible']['authentication']['api_key'], '' );
                $domain = get_option( 'plausible_domain', '' ); // Assuming domain is stored here

                if ( ! empty( $api_key ) && ! empty( $domain ) ) {
                    $response = wp_remote_get( 'https://plausible.io/api/v1/stats/aggregate?site_id=' . $domain . '&period=30d&metrics=pageviews', array(
                        'headers' => array( 'Authorization' => 'Bearer ' . $api_key )
                    ) );

                    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                        $body = json_decode( wp_remote_retrieve_body( $response ), true );
                        if ( isset( $body['results']['pageviews']['value'] ) ) {
                            $pageviews = (int) $body['results']['pageviews']['value'];
                        }
                    }
                }
            }

            // Fallback to Google Analytics
            if ( $pageviews === 0 && ! empty( $connectors['google']['authentication']['setting_name'] ) ) {
                // In a real implementation, we'd query the GA4 Data API here.
                // $ga_key = get_option( $connectors['google']['authentication']['setting_name'], '' );
                // $pageviews = ... fetch from GA4
                
                // Mock value for demo purposes when Google is connected
                $pageviews = 50000;
            }
        }

        // Cache for 24 hours
        set_transient( $transient_key, $pageviews, DAY_IN_SECONDS );

        return $pageviews;
    }

    public static function calculate_slot_price() {
        $base_price_cents = 1000; // $10 base minimum
        
        $pageviews = self::get_verified_pageviews();
        
        // Dynamic Pricing Formula: Base $10 + $5 for every 10,000 monthly pageviews
        $traffic_premium = floor( $pageviews / 10000 ) * 500;

        return $base_price_cents + $traffic_premium;
    }
}
