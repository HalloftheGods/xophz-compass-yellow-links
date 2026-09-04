<?php
/**
 * Plugin Name:       Xophz Yellow Links
 * Description:       Standalone WordPress backend and router for the Yellow Links web app.
 * Version:           26.9.4-182
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-compass-yellow-links
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_COMPASS_YELLOW_LINKS_VERSION', '26.9.4-182' );
define( 'XOPHZ_COMPASS_YELLOW_LINKS_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_COMPASS_YELLOW_LINKS_URL', plugin_dir_url( __FILE__ ) );

class Xophz_Compass_Yellow_Links {

    /**
     * Dev proxy instance.
     *
     * @var Xophz_Compass_Dev_Proxy|null
     */
    protected $dev_proxy = null;

    public function __construct() {
        require_once XOPHZ_COMPASS_YELLOW_LINKS_PATH . 'includes/class-yellow-links-cpt.php';
        new Yellow_Links_CPT();

        require_once XOPHZ_COMPASS_YELLOW_LINKS_PATH . 'includes/class-link-verifier-cron.php';
        require_once XOPHZ_COMPASS_YELLOW_LINKS_PATH . 'includes/class-analytics-verifier.php';
        Yellow_Links_Verifier_Cron::init();

        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        
        // Flush rewrites when setting is saved
        add_action( 'update_option_xophz_compass_yellow_links_custom_slug', array( $this, 'flush_rewrites_on_save' ), 10, 2 );

        // Consolidated Dev Proxy (Port 8088)
        if ( class_exists( 'Xophz_Compass_Dev_Proxy' ) ) {
            $this->dev_proxy = new Xophz_Compass_Dev_Proxy( array(
                'slug'                 => 'yellow-links',
                'default_slug'         => 'yellow-links',
                'dev_port'             => 8088,
                'query_var'            => 'xophz_compass_yellow_links',
                'plugin_path'          => XOPHZ_COMPASS_YELLOW_LINKS_PATH,
                'plugin_url'           => XOPHZ_COMPASS_YELLOW_LINKS_URL,
                'version'              => XOPHZ_COMPASS_YELLOW_LINKS_VERSION,
                'candidate_dist_paths' => array(
                    XOPHZ_COMPASS_YELLOW_LINKS_PATH . 'public/dist/index.html',
                    dirname( XOPHZ_COMPASS_YELLOW_LINKS_PATH, 3 ) . '/apps/yellow-links/dist/index.html',
                    ABSPATH . 'apps/yellow-links/dist/index.html',
                ),
            ) );

            add_filter( 'xophz_compass_dev_proxy_yellow-links_api_settings', array( $this, 'filter_api_settings' ), 10, 2 );
        }

        // API endpoints
        add_action( 'rest_api_init', array( $this, 'register_api_endpoints' ) );

        // Embed Shortcodes
        add_shortcode( 'yellow_links_directory', array( $this, 'render_directory_shortcode' ) );
        add_shortcode( 'yellow_links_widget', array( $this, 'render_widget_shortcode' ) );
    }

    public function register_api_endpoints() {
        require_once XOPHZ_COMPASS_YELLOW_LINKS_PATH . 'includes/class-yellow-links-api.php';
        $api = new Yellow_Links_API();
        $api->register_routes();
    }

    public function add_plugin_admin_menu() {
        add_options_page(
            'Yellow Links Settings',
            'Yellow Links',
            'manage_options',
            'xophz-compass-yellow-links',
            array( $this, 'display_plugin_setup_page' )
        );
    }

    public function register_settings() {
        register_setting( 'xophz_compass_yellow_links_options', 'xophz_compass_yellow_links_custom_slug' );
        register_setting( 'xophz_compass_yellow_links_options', 'xophz_compass_yellow_links_sister_sites' );
        register_setting( 'xophz_compass_yellow_links_options', 'xophz_compass_yellow_links_auto_publish' );
    }

    public function display_plugin_setup_page() {
        ?>
        <div class="wrap">
            <h2>Yellow Links Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'xophz_compass_yellow_links_options' );
                do_settings_sections( 'xophz_compass_yellow_links_options' );
                $slug = get_option( 'xophz_compass_yellow_links_custom_slug', 'yellow-links' );
                $sisters = get_option( 'xophz_compass_yellow_links_sister_sites', '' );
                $auto_pub = get_option( 'xophz_compass_yellow_links_auto_publish', '1' );
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Deployment Slug</th>
                        <td>
                            <input type="text" name="xophz_compass_yellow_links_custom_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" />
                            <p class="description">The URL slug where the app will be loaded (e.g. <code>yellow-links</code> for <code>/yellow-links</code>). Leave blank to disable standalone rendering.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Submission Moderation Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="xophz_compass_yellow_links_auto_publish" value="1" <?php checked( '1', $auto_pub ); ?> />
                                Automatically publish user submissions (uncheck to require admin review in Pending Queue).
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Sister Sites (Federation)</th>
                        <td>
                            <textarea name="xophz_compass_yellow_links_sister_sites" rows="4" class="large-text code"><?php echo esc_textarea( $sisters ); ?></textarea>
                            <p class="description">Enter one URL per line for other Yellow Links installations to federate and share indices with (e.g. <code>https://sister.domain.com</code>).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_directory_shortcode( $atts ) {
        $slug = get_option( 'xophz_compass_yellow_links_custom_slug', 'yellow-links' );
        $url = home_url( '/' . $slug . '/' );
        return '<div class="yellow-links-embed" style="width:100%;height:700px;border:none;"><iframe src="' . esc_url( $url ) . '" style="width:100%;height:100%;border:none;"></iframe></div>';
    }

    public function render_widget_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'category' => '', 'limit' => 5 ), $atts, 'yellow_links_widget' );
        $args = array(
            'post_type'      => 'yellow_link',
            'posts_per_page' => (int) $atts['limit'],
            'post_status'    => 'publish',
        );
        if ( !empty( $atts['category'] ) ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'yellow_link_category',
                    'field'    => 'name',
                    'terms'    => $atts['category'],
                )
            );
        }
        $query = new WP_Query( $args );
        $output = '<ul class="yellow-links-widget-list">';
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $target_url = get_post_meta( get_the_ID(), 'yl_url', true ) ?: get_permalink();
                $output .= '<li><a href="' . esc_url( $target_url ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title() ) . '</a> - ' . esc_html( get_the_excerpt() ) . '</li>';
            }
            wp_reset_postdata();
        } else {
            $output .= '<li>No community links found.</li>';
        }
        $output .= '</ul>';
        return $output;
    }

    public function flush_rewrites_on_save( $old_value, $new_value ) {
        if ( $old_value !== $new_value ) {
            $this->register_rewrites();
            flush_rewrite_rules();
        }
    }

    public function register_rewrites() {
        if ( $this->dev_proxy ) {
            $this->dev_proxy->register_rewrites();
        }
    }

    public function get_dev_proxy() {
        return $this->dev_proxy;
    }

    public function filter_api_settings( array $payload, string $slug ): array {
        if ( 'yellow-links' === $slug && isset( $payload['currentUser'] ) && is_array( $payload['currentUser'] ) ) {
            $user_id = (int) ( $payload['userId'] ?? 0 );
            if ( $user_id > 0 ) {
                $u = wp_get_current_user();
                $payload['currentUser']['role'] = current_user_can( 'manage_options' ) ? 'moderator' : 'user';
            }
        }
        return $payload;
    }
}

function run_xophz_compass_yellow_links() {
    new Xophz_Compass_Yellow_Links();
}
add_action( 'plugins_loaded', 'run_xophz_compass_yellow_links' );

function xophz_compass_yellow_links_activate() {
    $plugin = new Xophz_Compass_Yellow_Links();
    $plugin->register_rewrites();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'xophz_compass_yellow_links_activate' );

function xophz_compass_yellow_links_action_links( $links ) {
    $settings_link = '<a href="options-general.php?page=xophz-compass-yellow-links">' . __( 'Settings', 'xophz-compass-yellow-links' ) . '</a>';
    $new_links = array( 'settings' => $settings_link );
    foreach ( $links as $key => $value ) {
        $new_links[ $key ] = $value;
    }
    return $new_links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'xophz_compass_yellow_links_action_links' );
