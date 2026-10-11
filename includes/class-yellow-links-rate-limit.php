<?php
/**
 * Fixed-window counters for the Yellow Links routes that write.
 *
 * A route that creates content, counts a view or opens a payment is limited per user and per client
 * IP. The IP is REMOTE_ADDR only: X-Forwarded-For and similar headers are chosen by the caller, so
 * they are never trusted here. Behind a proxy, set REMOTE_ADDR from the proxy at the web server
 * (for example with mod_remoteip or the real_ip module) so each visitor gets their own counter.
 *
 * Counters live in transients, so an object cache makes them cheap. A read-then-write counter can
 * undercount when many requests land in the same instant, so it is a speed bump and not an exact
 * meter. Nothing here stores a plain IP or id: subjects are hashed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Yellow_Links_Rate_Limit {

    const PREFIX = 'yl_rl_';

    /**
     * The caller's address as the web server reports it, or 'unknown'. A site behind a proxy it
     * trusts can map the real visitor address with the yellow_links_client_ip filter, which must
     * return a valid IP.
     *
     * @return string
     */
    public static function client_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $ip = apply_filters( 'yellow_links_client_ip', $ip );

        return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
    }

    /**
     * Counts one hit for a bucket and subject, or refuses it when the window is already full.
     *
     * @param string $bucket  Name of the limit, for example 'link_create_user'.
     * @param string $subject Who is counted: an IP, a user id, or an IP joined to a link id.
     * @param int    $max     Hits allowed per window.
     * @param int    $window  Window length in seconds.
     * @param string $message Text for the 429 error.
     * @return true|WP_Error WP_Error with status 429 once the window is full.
     */
    public static function hit( $bucket, $subject, $max, $window, $message = '' ) {
        $key   = self::key( $bucket, $subject );
        $now   = time();
        $state = self::open_state( $key );

        if ( null === $state ) {
            $state = array(
                'count' => 0,
                'reset' => $now + (int) $window,
            );
        }

        if ( $state['count'] >= $max ) {
            return self::too_many( max( 1, $state['reset'] - $now ), $message );
        }

        $state['count']++;
        set_transient( $key, $state, max( 1, $state['reset'] - $now ) );

        return true;
    }

    /**
     * The 429 error for a full window.
     *
     * @param int    $wait    Seconds until the subject may try again.
     * @param string $message Text for the error.
     * @return WP_Error
     */
    public static function too_many( $wait, $message = '' ) {
        if ( '' === $message ) {
            $message = 'Too many requests. Please try again later.';
        }

        return new WP_Error(
            'rate_limited',
            $message,
            array(
                'status'      => 429,
                'retry_after' => (int) $wait,
                'retryAfter'  => (int) $wait,
            )
        );
    }

    private static function key( $bucket, $subject ) {
        return self::PREFIX . substr( md5( $bucket . '|' . strtolower( trim( (string) $subject ) ) ), 0, 32 );
    }

    /**
     * The stored counter while its window is still open, otherwise null.
     *
     * @param string $key Transient name.
     * @return array|null
     */
    private static function open_state( $key ) {
        $state = get_transient( $key );
        if ( is_array( $state ) && isset( $state['count'], $state['reset'] ) && $state['reset'] > time() ) {
            return $state;
        }

        return null;
    }
}
