<?php
/**
 * Plugin Name:       Xophz Yellow Links
 * Description:       Standalone WordPress backend and router for the Yellow Links web app.
 * Version:           26.9.3
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-compass-yellow-links
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_COMPASS_YELLOW_LINKS_VERSION', '26.9.3' );
define( 'XOPHZ_COMPASS_YELLOW_LINKS_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_COMPASS_YELLOW_LINKS_URL', plugin_dir_url( __FILE__ ) );

class Xophz_Compass_Yellow_Links {
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

        // Public rewrite and template
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_action( 'init', array( $this, 'register_rewrites' ) );
        add_action( 'template_redirect', array( $this, 'template_redirect' ) );

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

    public function register_query_vars( $vars ) {
        $vars[] = 'xophz_compass_yellow_links';
        return $vars;
    }

    public function register_rewrites() {
        $slug = get_option( 'xophz_compass_yellow_links_custom_slug', 'yellow-links' );
        
        if ( ! empty( $slug ) ) {
            add_rewrite_rule(
                '^' . $slug . '/?$',
                'index.php?xophz_compass_yellow_links=1',
                'top'
            );
            // Catch-all for frontend routing
            add_rewrite_rule(
                '^' . $slug . '/(.*)?$',
                'index.php?xophz_compass_yellow_links=1',
                'top'
            );
        }
    }

    private function is_dev_mode() {
        return ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
    }

    public function template_redirect() {
        if ( get_query_var( 'xophz_compass_yellow_links' ) ) {
            $is_dev = $this->is_dev_mode();
            $vite_port = '8088';
            if ( isset( $_SERVER['HTTP_HOST'] ) ) {
                $host_parts = explode(':', $_SERVER['HTTP_HOST']);
                $wp_host = $host_parts[0];
            } else {
                $wp_host = wp_parse_url( home_url(), PHP_URL_HOST );
            }
            $vite_url = "//" . $wp_host . ":" . $vite_port;

            if ( $is_dev ) {
                $dev_hosts = array( 'compass', '127.0.0.1', 'localhost' );
                $dev_html  = false;
                foreach ( $dev_hosts as $host ) {
                    $context  = stream_context_create( array( 'http' => array( 'timeout' => 1 ) ) );
                    $dev_html = @file_get_contents( "http://{$host}:{$vite_port}/", false, $context );
                    if ( $dev_html ) {
                        break;
                    }
                }

                if ( $dev_html ) {
                    // Rewrite relative src/href/import/from for dev server
                    $dev_html = str_replace('src="/', 'src="' . $vite_url . '/', $dev_html);
                    $dev_html = str_replace('href="/', 'href="' . $vite_url . '/', $dev_html);
                    $dev_html = str_replace('import("/', 'import("' . $vite_url . '/', $dev_html);
                    $dev_html = str_replace('from "/', 'from="' . $vite_url . '/', $dev_html);
                    $dev_html = str_replace("from '/", "from '" . $vite_url . "/", $dev_html);

                    // Inject Vite client if not present
                    if (strpos($dev_html, '/@vite/client') === false) {
                        $vite_client = '<script type="module" src="' . esc_url($vite_url) . '/@vite/client"></script>';
                        $dev_html = str_replace('</head>', $vite_client . "\n</head>", $dev_html);
                    }

                    $nonce = wp_create_nonce('wp_rest');
                    $user_id = get_current_user_id();
                    $user_data = null;
                    if ( $user_id > 0 ) {
                        $u = wp_get_current_user();
                        $user_data = array(
                            'id'           => 'wp-' . $user_id,
                            'username'     => $u->user_login,
                            'email'        => $u->user_email,
                            'fullName'     => $u->display_name ?: $u->user_login,
                            'avatarUrl'    => get_avatar_url( $user_id ) ?: '👤',
                            'role'         => in_array( 'administrator', (array) $u->roles ) ? 'moderator' : 'user',
                            'registeredAt' => strtotime( $u->user_registered ) * 1000,
                        );
                    }
                    $wp_api_settings = "<script>window.wpApiSettings = { root: '" . esc_url_raw(rest_url()) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw(XOPHZ_COMPASS_YELLOW_LINKS_URL) . "', version: '" . esc_js(XOPHZ_COMPASS_YELLOW_LINKS_VERSION) . "', userId: " . $user_id . ", currentUser: " . wp_json_encode( $user_data ) . " };</script>";
                    $dev_html = str_replace('</head>', $wp_api_settings . "\n</head>", $dev_html);

                    echo $dev_html;
                    exit;
                }
            }

            // Load production build output
            $candidate_paths = array(
                XOPHZ_COMPASS_YELLOW_LINKS_PATH . 'public/dist/index.html',
                dirname( XOPHZ_COMPASS_YELLOW_LINKS_PATH, 3 ) . '/apps/yellow-links/dist/index.html',
                ABSPATH . 'apps/yellow-links/dist/index.html',
            );

            $index_path = false;
            $dist_url   = XOPHZ_COMPASS_YELLOW_LINKS_URL . 'public/dist/';

            foreach ( $candidate_paths as $path ) {
                if ( file_exists( $path ) ) {
                    $index_path = $path;
                    break;
                }
            }

            if ( $index_path ) {
                $content = file_get_contents( $index_path );
                
                // Rewrite absolute paths for production assets
                $content = str_replace( '"/assets/', '"' . $dist_url . 'assets/', $content );
                $content = str_replace( "'/assets/", "'" . $dist_url . "assets/", $content );
                $content = str_replace( '"/vite.svg"', '"' . $dist_url . 'vite.svg"', $content );
                
                // Inject wpApiSettings for production so API requests have the nonce and user info
                $nonce = wp_create_nonce('wp_rest');
                $user_id = get_current_user_id();
                $user_data = null;
                if ( $user_id > 0 ) {
                    $u = wp_get_current_user();
                    $user_data = array(
                        'id'           => 'wp-' . $user_id,
                        'username'     => $u->user_login,
                        'email'        => $u->user_email,
                        'fullName'     => $u->display_name ?: $u->user_login,
                        'avatarUrl'    => get_avatar_url( $user_id ) ?: '👤',
                        'role'         => in_array( 'administrator', (array) $u->roles ) ? 'moderator' : 'user',
                        'registeredAt' => strtotime( $u->user_registered ) * 1000,
                    );
                }
                $wp_api_settings = "<script>window.wpApiSettings = { root: '" . esc_url_raw(rest_url()) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw(XOPHZ_COMPASS_YELLOW_LINKS_URL) . "', version: '" . esc_js(XOPHZ_COMPASS_YELLOW_LINKS_VERSION) . "', userId: " . $user_id . ", currentUser: " . wp_json_encode( $user_data ) . " };</script>";
                $content = str_replace('</head>', $wp_api_settings . "\n</head>", $content);
                
                echo $content;
            } else {
                echo '<h2>Yellow Links is not built yet.</h2><p>Please run <code>pnpm build:yellow-links</code> in the workspace root or <code>npm run build</code> in <code>apps/yellow-links</code>.</p>';
            }
            exit;
        }
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
