<?php
/**
 * Yellow Links credentials, resolved through the WordPress core Connectors API.
 *
 * Every secret this plugin uses (Gemini, Stripe, Plausible) is read here and nowhere else. Core
 * exposes no public function that returns a key's value, so get_key() follows the same
 * precedence as core's _wp_connectors_get_api_key_source(): environment variable, then PHP
 * constant, then the database option. Every name comes from the connector's own `authentication`
 * definition, so this plugin never hard-codes an option, constant or environment variable name.
 *
 * Values are for server-side use only. Never echo one, log one, return one from a REST route or
 * put one in a URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Yellow_Links_Connectors {

    /** Core AI provider connector for Google (Gemini). */
    const GOOGLE = 'google';

    /** Registered by the Compass core plugin; registered here only when core is not active. */
    const STRIPE_SECRET_KEY = 'stripe_secret_key';

    /** Optional. Read when some plugin registers a connector with this id. */
    const PLAUSIBLE = 'plausible';

    /**
     * Hook connector registration. Priority 20 runs after the Compass core plugin (priority 10),
     * so a connector core already registers keeps core's definition.
     */
    public static function init() {
        add_action( 'wp_connectors_init', array( __CLASS__, 'register' ), 20 );
    }

    /**
     * Register the Stripe secret key connector when no other plugin has.
     *
     * @param object $registry WP_Connector_Registry.
     */
    public static function register( $registry ) {
        if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
            return;
        }
        if ( function_exists( 'wp_is_connector_registered' ) && wp_is_connector_registered( self::STRIPE_SECRET_KEY ) ) {
            return;
        }

        $registry->register(
            self::STRIPE_SECRET_KEY,
            array(
                'name'           => 'Stripe Secret Key',
                'description'    => 'Secret API key for Stripe, used by Yellow Links to authorize and capture ad slot payments.',
                'type'           => 'api_key',
                'authentication' => array(
                    'method'          => 'api_key',
                    'setting_name'    => 'compass_stripe_secret_key',
                    'credentials_url' => 'https://dashboard.stripe.com/apikeys',
                ),
            )
        );
    }

    /**
     * The credential for a registered connector, or '' when it is unregistered or unset.
     *
     * @param string $connector_id Connector id, e.g. 'google' or 'stripe_secret_key'.
     * @return string
     */
    public static function get_key( $connector_id ) {
        $is_available = function_exists( 'wp_get_connector' ) && function_exists( 'wp_is_connector_registered' );
        if ( ! $is_available || ! wp_is_connector_registered( $connector_id ) ) {
            return '';
        }

        $connector = wp_get_connector( $connector_id );
        $auth      = is_array( $connector ) ? ( $connector['authentication'] ?? array() ) : array();

        $from_env = self::from_env( (string) ( $auth['env_var_name'] ?? '' ) );
        if ( '' !== $from_env ) {
            return $from_env;
        }

        $from_constant = self::from_constant( (string) ( $auth['constant_name'] ?? '' ) );
        if ( '' !== $from_constant ) {
            return $from_constant;
        }

        return self::from_option( (string) ( $auth['setting_name'] ?? '' ) );
    }

    private static function from_env( $env_var_name ) {
        return '' === $env_var_name ? '' : self::as_string( getenv( $env_var_name ) );
    }

    private static function from_constant( $constant_name ) {
        $is_defined = '' !== $constant_name && defined( $constant_name );
        return $is_defined ? self::as_string( constant( $constant_name ) ) : '';
    }

    private static function from_option( $setting_name ) {
        return '' === $setting_name ? '' : self::as_string( get_option( $setting_name, '' ) );
    }

    private static function as_string( $value ) {
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }
}
