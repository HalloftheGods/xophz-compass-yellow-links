<?php
/**
 * Gate for the Yellow Links routes that spend the owner's Gemini key.
 *
 * A caller must be logged in, and each user gets a fixed number of Gemini calls per hour, the same
 * shape as thoth-reader-wp and Kitchen Synk. The Compass page shell hands a logged-in visitor an
 * X-WP-Nonce in window.wpApiSettings, which core's cookie authentication checks before the
 * permission callback runs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Yellow_Links_AI_Gate {

    /** Gemini calls one user may make per window, across both AI routes. */
    const MAX_CALLS_PER_WINDOW = 20;
    const WINDOW_SECONDS       = HOUR_IN_SECONDS;
    const TRANSIENT_PREFIX     = 'yellow_links_ai_rate_';

    /**
     * Permission callback for the AI routes: any logged-in user.
     *
     * @return true|WP_Error WP_Error with status 401 for anonymous callers.
     */
    public static function can_use_ai() {
        if ( is_user_logged_in() ) {
            return true;
        }

        return new WP_Error( 'rest_forbidden', 'Log in to use the AI assistant.', array( 'status' => 401 ) );
    }

    /**
     * Counts one Gemini call against the current user's hourly allowance. Call it right before the
     * request to Gemini, so invalid input costs nothing.
     *
     * @return true|WP_Error WP_Error with status 401 when nobody is logged in, 429 once the allowance is spent.
     */
    public static function consume_allowance() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return self::can_use_ai();
        }

        $key   = self::TRANSIENT_PREFIX . $user_id;
        $now   = time();
        $state = get_transient( $key );

        $is_window_open = is_array( $state ) && isset( $state['count'], $state['reset'] ) && $state['reset'] > $now;
        if ( ! $is_window_open ) {
            $state = array(
                'count' => 0,
                'reset' => $now + self::WINDOW_SECONDS,
            );
        }

        if ( $state['count'] >= self::MAX_CALLS_PER_WINDOW ) {
            return new WP_Error(
                'rate_limited',
                'AI request limit reached. Please try again later.',
                array( 'status' => 429, 'retry_after' => $state['reset'] - $now )
            );
        }

        $state['count']++;
        set_transient( $key, $state, max( 1, $state['reset'] - $now ) );

        return true;
    }
}
