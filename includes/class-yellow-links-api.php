<?php
/**
 * REST API Endpoints for Yellow Links
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-stripe-billing.php';

class Yellow_Links_API {

    public function register_routes() {
        register_rest_route( 'yellow-links/v1', '/gemini/analyze', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'analyze_link' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'yellow-links/v1', '/gemini/suggest-ad', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'suggest_ad' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'yellow-links/v1', '/network', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_network_links' ),
            'permission_callback' => '__return_true',
        ) );

        // Link Fetch & Submission
        register_rest_route( 'yellow-links/v1', '/links', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_network_links' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_link' ),
                'permission_callback' => '__return_true',
            ),
        ) );

        // Voting & Moderation Signal
        register_rest_route( 'yellow-links/v1', '/links/(?P<id>[a-zA-Z0-9_-]+)/vote', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'vote_link' ),
            'permission_callback' => '__return_true',
        ) );

        // Click Tracking
        register_rest_route( 'yellow-links/v1', '/links/(?P<id>[a-zA-Z0-9_-]+)/click', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'record_click' ),
            'permission_callback' => '__return_true',
        ) );

        // Community Comments
        register_rest_route( 'yellow-links/v1', '/links/(?P<id>[a-zA-Z0-9_-]+)/comment', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'add_comment' ),
            'permission_callback' => '__return_true',
        ) );

        // Comment Voting
        register_rest_route( 'yellow-links/v1', '/comments/(?P<id>[a-zA-Z0-9_-]+)/vote', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'vote_comment' ),
            'permission_callback' => '__return_true',
        ) );

        // GeoJSON Open Data Export
        register_rest_route( 'yellow-links/v1', '/export/geojson', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'export_geojson' ),
            'permission_callback' => '__return_true',
        ) );

        // Current User Info Session Check
        register_rest_route( 'yellow-links/v1', '/me', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_current_user_info' ),
            'permission_callback' => '__return_true',
        ) );

        // Update Link Status
        register_rest_route( 'yellow-links/v1', '/links/(?P<id>[a-zA-Z0-9_-]+)/status', array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array( $this, 'update_link_status' ),
            'permission_callback' => '__return_true',
        ) );

        // Delete Link
        register_rest_route( 'yellow-links/v1', '/links/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => array( $this, 'delete_link' ),
            'permission_callback' => '__return_true',
        ) );

        // Monetization Endpoints
        register_rest_route( 'yellow-links/v1', '/monetization/buy-slot', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'buy_slot' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'yellow-links/v1', '/monetization/approve-slot', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'approve_slot' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );

        register_rest_route( 'yellow-links/v1', '/monetization/reject-slot', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'reject_slot' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );
    }

    public function get_current_user_info( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            $user = wp_get_current_user();
            return rest_ensure_response( array(
                'logged_in' => true,
                'user'      => array(
                    'id'           => 'wp-' . $user_id,
                    'username'     => $user->user_login,
                    'email'        => $user->user_email,
                    'fullName'     => $user->display_name ?: $user->user_login,
                    'avatarUrl'    => get_avatar_url( $user_id ) ?: '👤',
                    'role'         => in_array( 'administrator', (array) $user->roles ) ? 'moderator' : 'user',
                    'registeredAt' => strtotime( $user->user_registered ) * 1000,
                )
            ) );
        }
        return rest_ensure_response( array(
            'logged_in' => false,
            'user'      => null,
        ) );
    }

    private function get_gemini_key() {
        if ( function_exists( 'wp_get_connectors' ) ) {
            $connectors = wp_get_connectors();
            if ( ! empty( $connectors['google']['authentication']['setting_name'] ) ) {
                $api_key = get_option( $connectors['google']['authentication']['setting_name'], '' );
                if ( ! empty( $api_key ) ) {
                    return $api_key;
                }
            }
        }
        // Read from the environment where Docker passes it
        return getenv( 'GEMINI_API_KEY' );
    }

    private function get_gemini_model() {
        if ( function_exists( 'wp_get_connectors' ) ) {
            $connectors = wp_get_connectors();
            if ( ! empty( $connectors['google']['options']['model']['setting_name'] ) ) {
                $model = get_option( $connectors['google']['options']['model']['setting_name'], '' );
                if ( ! empty( $model ) ) {
                    return $model;
                }
            }
        }
        return 'gemini-3.6-flash';
    }

    private function call_gemini( $prompt, $schema ) {
        $api_key = $this->get_gemini_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'GEMINI_API_KEY is not configured.', array( 'status' => 500 ) );
        }

        $model = $this->get_gemini_model();
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => $prompt
                        )
                    )
                )
            ),
            'generationConfig' => array(
                'responseMimeType' => 'application/json',
                'responseSchema'   => $schema
            )
        );

        $response = wp_remote_post( $url, array(
            'headers'     => array(
                'Content-Type' => 'application/json',
                'User-Agent'   => 'aistudio-build'
            ),
            'body'        => wp_json_encode( $body ),
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'gemini_error', $response->get_error_message(), array( 'status' => 500 ) );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code !== 200 ) {
            $error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Failed to call Gemini API.';
            return new WP_Error( 'gemini_error', $error_message, array( 'status' => $response_code ) );
        }

        if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            $result_text = $data['candidates'][0]['content']['parts'][0]['text'];
            return json_decode( $result_text, true );
        }

        return new WP_Error( 'gemini_error', 'Invalid response format from Gemini.', array( 'status' => 500 ) );
    }

    public function analyze_link( WP_REST_Request $request ) {
        $url             = $request->get_param( 'url' );
        $title           = $request->get_param( 'title' );
        $userDescription = $request->get_param( 'userDescription' );

        if ( empty( $url ) ) {
            return new WP_Error( 'missing_url', 'URL is required for analysis.', array( 'status' => 400 ) );
        }

        $prompt = sprintf(
            "Analyze the website link: \"%s\"\nOptional context provided by user:\n- Title: \"%s\"\n- Description: \"%s\"\n\nPerform the following:\n1. Provide a clean, professional, and descriptive 1-2 sentence description summarizing the purpose of this website.\n2. Select the most appropriate category from this exact list: [\"Tech & Dev\", \"Visual Arts\", \"Marketplaces\", \"Open Source\", \"Local Ops\", \"Education\", \"Travel\", \"Blogs & Personal\"].\n3. Suggest 3-4 short uppercase tags/keywords relevant to the link.\n4. Conduct a safety assessment of the link (detecting obvious spam patterns, phishing, low-quality redirects, expired domains, or malicious indicators).\n   - Set safetyStatus to \"safe\", \"warning\", or \"unsafe\".\n   - Provide a concise 1-sentence safetyReason (e.g., \"Verified as a safe public utility\", \"Known domain redirect with tracking code\", \"High-risk spam domain structure\").\n\nReturn the output strictly in the requested JSON structure.",
            $url,
            $title ? $title : 'N/A',
            $userDescription ? $userDescription : 'N/A'
        );

        $schema = array(
            'type' => 'OBJECT',
            'properties' => array(
                'description' => array(
                    'type' => 'STRING',
                    'description' => 'A polished, clear 1-2 sentence summary of the website.',
                ),
                'category' => array(
                    'type' => 'STRING',
                    'description' => 'Appropriate category from the list of 8 valid options.',
                ),
                'tags' => array(
                    'type' => 'ARRAY',
                    'items' => array( 'type' => 'STRING' ),
                    'description' => '3-4 short uppercase tags.',
                ),
                'safetyStatus' => array(
                    'type' => 'STRING',
                    'description' => 'The safety verdict: "safe", "warning", or "unsafe".',
                ),
                'safetyReason' => array(
                    'type' => 'STRING',
                    'description' => 'A brief, objective reason for the safety verdict.',
                ),
            ),
            'required' => array( 'description', 'category', 'tags', 'safetyStatus', 'safetyReason' ),
        );

        $result = $this->call_gemini( $prompt, $schema );
        
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function suggest_ad( WP_REST_Request $request ) {
        $businessName = $request->get_param( 'businessName' );
        $topic        = $request->get_param( 'topic' );

        if ( empty( $businessName ) || empty( $topic ) ) {
            return new WP_Error( 'missing_params', 'Business name and topic/niche are required.', array( 'status' => 400 ) );
        }

        $prompt = sprintf(
            "Generate a bold, uppercase-friendly, high-impact advertisement block for the website directory \"Yellow Links\".\nBusiness/Website Name: \"%s\"\nNiche/Topic: \"%s\"\n\nProvide:\n1. An eye-catching, punchy headline in ALL CAPS (max 25 characters).\n2. A compelling, concise subtitle/pitch statement in ALL CAPS (max 60 characters).\n3. A strong Call To Action button label in ALL CAPS (max 15 characters, e.g., \"VIEW SITE\", \"HIRE US\", \"GET OFFER\").\n\nReturn the output strictly in the requested JSON structure.",
            $businessName,
            $topic
        );

        $schema = array(
            'type' => 'OBJECT',
            'properties' => array(
                'headline' => array(
                    'type' => 'STRING',
                    'description' => 'Punchy uppercase headline, max 25 characters.',
                ),
                'description' => array(
                    'type' => 'STRING',
                    'description' => 'Compelling pitch in ALL CAPS, max 60 characters.',
                ),
                'cta' => array(
                    'type' => 'STRING',
                    'description' => 'Action button text in ALL CAPS, max 15 characters.',
                ),
            ),
            'required' => array( 'headline', 'description', 'cta' ),
        );

        $result = $this->call_gemini( $prompt, $schema );
        
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function get_network_links( WP_REST_Request $request ) {
        $this->ensure_seeded_links();

        $transient_key = 'yellow_links_network_cache';
        $cached_data = get_transient( $transient_key );

        if ( false !== $cached_data ) {
            return rest_ensure_response( $cached_data );
        }

        $all_links = array();

        // 1. Fetch Local Links
        $local_links = array();
        
        $args = array(
            'post_type'      => 'yellow_link',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        );
        $query = new WP_Query( $args );

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                
                $tags_meta = get_post_meta( $post_id, 'yl_tags', true );
                $tags = !empty( $tags_meta ) ? json_decode( $tags_meta, true ) : array( 'GENERAL' );
                if ( !is_array( $tags ) ) $tags = array( 'GENERAL' );

                $cats = wp_get_post_terms( $post_id, 'yellow_link_category', array('fields' => 'names') );
                $category = !empty( $cats ) ? $cats[0] : 'Blogs & Personal';

                $upvotes = (int) get_post_meta( $post_id, 'yl_upvotes', true );
                if ( $upvotes < 1 ) $upvotes = 1;
                $downvotes = (int) get_post_meta( $post_id, 'yl_downvotes', true );
                $clicks = (int) get_post_meta( $post_id, 'yl_clicks', true );

                // Fetch WP Comments
                $wp_comments = get_comments( array( 'post_id' => $post_id, 'status' => 'approve' ) );
                $formatted_comments = array();
                foreach ( $wp_comments as $c ) {
                    $formatted_comments[] = array(
                        'id'        => 'comment-' . $c->comment_ID,
                        'author'    => $c->comment_author ?: 'Anonymous',
                        'text'      => $c->comment_content,
                        'timestamp' => strtotime( $c->comment_date ) * 1000,
                        'votes'     => (int) get_comment_meta( $c->comment_ID, 'yl_comment_votes', true ),
                    );
                }

                $local_links[] = array(
                    'id'           => 'link-' . $post_id,
                    'url'          => get_post_meta( $post_id, 'yl_url', true ) ?: get_permalink(),
                    'title'        => get_the_title(),
                    'description'  => get_the_content(),
                    'category'     => $category,
                    'clicks'       => $clicks,
                    'upvotes'      => $upvotes,
                    'downvotes'    => $downvotes,
                    'tags'         => $tags,
                    'submitter'    => get_the_author() ?: 'Anonymous',
                    'timestamp'    => strtotime( get_the_date() ) * 1000,
                    'comments'     => $formatted_comments,
                    'safetyStatus' => get_post_meta( $post_id, 'yl_safetyStatus', true ) ?: 'safe',
                    'safetyReason' => get_post_meta( $post_id, 'yl_safetyReason', true ) ?: 'Verified local link.',
                    'trustTier'    => get_post_meta( $post_id, 'yl_trust_tier', true ) ?: 'community',
                    'neighborhood' => get_post_meta( $post_id, 'yl_neighborhood', true ) ?: '',
                    'zip'          => get_post_meta( $post_id, 'yl_zip', true ) ?: '',
                    'isSister'     => false
                );
            }
            wp_reset_postdata();
        }
        
        $all_links = array_merge( $all_links, $local_links );

        // 2. Fetch Sister Sites
        $sister_sites_raw = get_option( 'xophz_compass_yellow_links_sister_sites', '' );
        if ( !empty( trim( $sister_sites_raw ) ) ) {
            $sister_sites = explode( "\n", $sister_sites_raw );
            foreach ( $sister_sites as $site ) {
                $site = trim( $site );
                if ( empty( $site ) ) continue;

                $endpoint = trailingslashit( $site ) . 'wp-json/yellow-links/v1/network';
                $response = wp_remote_get( $endpoint, array( 'timeout' => 3 ) );
                
                if ( !is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                    $body = json_decode( wp_remote_retrieve_body( $response ), true );
                    if ( is_array( $body ) ) {
                        $remote_locals = array_filter( $body, function($l) {
                            return !isset($l['isSister']) || $l['isSister'] === false;
                        });

                        foreach( $remote_locals as &$r ) {
                            $r['isSister'] = true;
                            $r['sisterUrl'] = $site;
                        }
                        $all_links = array_merge( $all_links, $remote_locals );
                    }
                }
            }
        }

        // Cache for 5 minutes
        set_transient( $transient_key, $all_links, 5 * MINUTE_IN_SECONDS );

        return rest_ensure_response( $all_links );
    }

    public function create_link( WP_REST_Request $request ) {
        $url          = sanitize_text_field( $request->get_param( 'url' ) );
        $title        = sanitize_text_field( $request->get_param( 'title' ) );
        $description  = sanitize_textarea_field( $request->get_param( 'description' ) );
        $category     = sanitize_text_field( $request->get_param( 'category' ) );
        $submitter    = sanitize_text_field( $request->get_param( 'submitter' ) );
        $safetyStatus = sanitize_text_field( $request->get_param( 'safetyStatus' ) ?: 'safe' );
        $safetyReason = sanitize_text_field( $request->get_param( 'safetyReason' ) ?: 'Community submission.' );
        $tags_input   = $request->get_param( 'tags' );
        $trustTier    = sanitize_text_field( $request->get_param( 'trustTier' ) ?: 'community' );
        $neighborhood = sanitize_text_field( $request->get_param( 'neighborhood' ) );
        $zip          = sanitize_text_field( $request->get_param( 'zip' ) );

        if ( empty( $url ) || empty( $title ) ) {
            return new WP_Error( 'missing_fields', 'URL and Title are required.', array( 'status' => 400 ) );
        }

        // Parse tags
        if ( is_array( $tags_input ) ) {
            $tags_array = array_map( 'sanitize_text_field', $tags_input );
        } elseif ( is_string( $tags_input ) ) {
            $tags_array = array_filter( array_map( 'trim', explode( ',', $tags_input ) ) );
        } else {
            $tags_array = array( 'GENERAL' );
        }

        $auto_publish = get_option( 'xophz_compass_yellow_links_auto_publish', '1' );
        $post_status  = ( $auto_publish === '1' ) ? 'publish' : 'pending';

        $post_id = wp_insert_post( array(
            'post_title'   => $title,
            'post_content' => $description,
            'post_status'  => $post_status,
            'post_type'    => 'yellow_link',
        ) );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Assign Category
        if ( !empty( $category ) ) {
            wp_set_object_terms( $post_id, $category, 'yellow_link_category' );
        }

        // Save Meta
        update_post_meta( $post_id, 'yl_url', $url );
        update_post_meta( $post_id, 'yl_tags', wp_json_encode( $tags_array ) );
        update_post_meta( $post_id, 'yl_safetyStatus', $safetyStatus );
        update_post_meta( $post_id, 'yl_safetyReason', $safetyReason );
        update_post_meta( $post_id, 'yl_upvotes', 1 );
        update_post_meta( $post_id, 'yl_downvotes', 0 );
        update_post_meta( $post_id, 'yl_clicks', 0 );
        update_post_meta( $post_id, 'yl_trust_tier', $trustTier );
        if ( !empty( $neighborhood ) ) update_post_meta( $post_id, 'yl_neighborhood', $neighborhood );
        if ( !empty( $zip ) ) update_post_meta( $post_id, 'yl_zip', $zip );

        // Clear transient
        delete_transient( 'yellow_links_network_cache' );

        return rest_ensure_response( array(
            'success'   => true,
            'id'        => 'link-' . $post_id,
            'status'    => $post_status,
            'message'   => ( $post_status === 'pending' ) ? 'Link submitted for moderation.' : 'Link published successfully.',
        ) );
    }

    public function vote_link( WP_REST_Request $request ) {
        $raw_id = $request->get_param( 'id' );
        $type   = sanitize_text_field( $request->get_param( 'type' ) );
        $post_id = (int) str_replace( 'link-', '', $raw_id );

        if ( !$post_id || get_post_type( $post_id ) !== 'yellow_link' ) {
            return new WP_Error( 'invalid_id', 'Invalid yellow_link ID.', array( 'status' => 404 ) );
        }

        $upvotes   = (int) get_post_meta( $post_id, 'yl_upvotes', true );
        $downvotes = (int) get_post_meta( $post_id, 'yl_downvotes', true );

        if ( $type === 'up' ) {
            $upvotes++;
            update_post_meta( $post_id, 'yl_upvotes', $upvotes );
        } elseif ( $type === 'down' ) {
            $downvotes++;
            update_post_meta( $post_id, 'yl_downvotes', $downvotes );
        }

        // Calculate safety status update
        $safetyStatus = get_post_meta( $post_id, 'yl_safetyStatus', true ) ?: 'safe';
        $safetyReason = get_post_meta( $post_id, 'yl_safetyReason', true ) ?: '';

        if ( $downvotes > 15 && $downvotes > ( $upvotes * 2 ) ) {
            $safetyStatus = 'unsafe';
            $safetyReason = 'Community Flagging: Excessive warning reports submitted by directory visitors.';
            update_post_meta( $post_id, 'yl_safetyStatus', $safetyStatus );
            update_post_meta( $post_id, 'yl_safetyReason', $safetyReason );
        } elseif ( $downvotes > 5 && $downvotes > $upvotes ) {
            $safetyStatus = 'warning';
            $safetyReason = 'Community Warning: Multiple users reported potential safety issues.';
            update_post_meta( $post_id, 'yl_safetyStatus', $safetyStatus );
            update_post_meta( $post_id, 'yl_safetyReason', $safetyReason );
        }

        delete_transient( 'yellow_links_network_cache' );

        return rest_ensure_response( array(
            'id'           => 'link-' . $post_id,
            'upvotes'      => $upvotes,
            'downvotes'    => $downvotes,
            'safetyStatus' => $safetyStatus,
            'safetyReason' => $safetyReason,
        ) );
    }

    public function record_click( WP_REST_Request $request ) {
        $raw_id  = $request->get_param( 'id' );
        $post_id = (int) str_replace( 'link-', '', $raw_id );

        if ( $post_id && get_post_type( $post_id ) === 'yellow_link' ) {
            $clicks = (int) get_post_meta( $post_id, 'yl_clicks', true );
            $clicks++;
            update_post_meta( $post_id, 'yl_clicks', $clicks );
            delete_transient( 'yellow_links_network_cache' );
            return rest_ensure_response( array( 'id' => 'link-' . $post_id, 'clicks' => $clicks ) );
        }

        return new WP_Error( 'invalid_id', 'Invalid link ID.', array( 'status' => 404 ) );
    }

    public function add_comment( WP_REST_Request $request ) {
        $raw_id  = $request->get_param( 'id' );
        $post_id = (int) str_replace( 'link-', '', $raw_id );
        $author  = sanitize_text_field( $request->get_param( 'author' ) ?: 'Anonymous' );
        $text    = sanitize_textarea_field( $request->get_param( 'text' ) );

        if ( !$post_id || empty( $text ) ) {
            return new WP_Error( 'missing_fields', 'Post ID and comment text are required.', array( 'status' => 400 ) );
        }

        $comment_id = wp_insert_comment( array(
            'comment_post_ID'      => $post_id,
            'comment_author'       => $author,
            'comment_content'      => $text,
            'comment_approved'     => 1,
            'comment_type'         => 'comment',
        ) );

        if ( !$comment_id ) {
            return new WP_Error( 'comment_failed', 'Could not save comment.', array( 'status' => 500 ) );
        }

        delete_transient( 'yellow_links_network_cache' );

        return rest_ensure_response( array(
            'id'        => 'comment-' . $comment_id,
            'author'    => $author,
            'text'      => $text,
            'timestamp' => time() * 1000,
            'votes'     => 0,
        ) );
    }

    public function vote_comment( WP_REST_Request $request ) {
        $comment_id_param = $request->get_param( 'id' );
        $type = $request->get_param( 'type' );

        $comment_id = (int) str_replace( 'comment-', '', $comment_id_param );
        $comment = get_comment( $comment_id );
        
        if ( ! $comment ) {
            return new WP_Error( 'not_found', 'Comment not found.', array( 'status' => 404 ) );
        }

        $current_votes = (int) get_comment_meta( $comment_id, 'yl_comment_votes', true );

        if ( $type === 'upvote' ) {
            $current_votes++;
        } elseif ( $type === 'downvote' ) {
            $current_votes--;
        }

        update_comment_meta( $comment_id, 'yl_comment_votes', $current_votes );
        delete_transient( 'yellow_links_network_cache' );

        return rest_ensure_response( array(
            'success' => true,
            'votes'   => $current_votes,
        ) );
    }

    public function export_geojson( WP_REST_Request $request ) {
        $args = array(
            'post_type'      => 'yellow_link',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        );
        $query = new WP_Query( $args );

        $features = array();

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                $lat = get_post_meta( $post_id, 'yl_lat', true );
                $lng = get_post_meta( $post_id, 'yl_lng', true );

                if ( !empty( $lat ) && !empty( $lng ) ) {
                    $features[] = array(
                        'type' => 'Feature',
                        'geometry' => array(
                            'type'        => 'Point',
                            'coordinates' => array( (float) $lng, (float) $lat )
                        ),
                        'properties' => array(
                            'id'           => $post_id,
                            'title'        => get_the_title(),
                            'url'          => get_post_meta( $post_id, 'yl_url', true ),
                            'neighborhood' => get_post_meta( $post_id, 'yl_neighborhood', true ),
                            'zip'          => get_post_meta( $post_id, 'yl_zip', true ),
                            'trustTier'    => get_post_meta( $post_id, 'yl_trust_tier', true ) ?: 'community',
                        )
                    );
                }
            }
            wp_reset_postdata();
        }

        return rest_ensure_response( array(
            'type'     => 'FeatureCollection',
            'features' => $features
        ) );
    }

    public function update_link_status( WP_REST_Request $request ) {
        $raw_id  = $request->get_param( 'id' );
        $status  = sanitize_text_field( $request->get_param( 'status' ) );
        $post_id = (int) str_replace( 'link-', '', $raw_id );

        if ( ! $post_id || ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Link not found.', array( 'status' => 404 ) );
        }

        $wp_status = 'publish';
        if ( $status === 'pending' ) {
            $wp_status = 'pending';
        } elseif ( $status === 'rejected' ) {
            $wp_status = 'draft';
        }

        wp_update_post( array(
            'ID'          => $post_id,
            'post_status' => $wp_status,
        ) );

        return rest_ensure_response( array( 'success' => true, 'id' => $raw_id, 'status' => $status ) );
    }

    public function delete_link( WP_REST_Request $request ) {
        $raw_id  = $request->get_param( 'id' );
        $post_id = (int) str_replace( 'link-', '', $raw_id );

        if ( ! $post_id || ! get_post( $post_id ) ) {
            return new WP_Error( 'not_found', 'Link not found.', array( 'status' => 404 ) );
        }

        wp_trash_post( $post_id );

        return rest_ensure_response( array( 'success' => true, 'id' => $raw_id ) );
    }

    public function check_admin_permission() {
        return current_user_can( 'manage_options' );
    }

    public function buy_slot( WP_REST_Request $request ) {
        // Handled by Yellow_Links_Stripe_Billing class
        if ( class_exists( 'Yellow_Links_Stripe_Billing' ) ) {
            return Yellow_Links_Stripe_Billing::handle_buy_slot( $request );
        }
        return new WP_Error( 'not_implemented', 'Stripe Billing not implemented yet.', array( 'status' => 501 ) );
    }

    public function approve_slot( WP_REST_Request $request ) {
        if ( class_exists( 'Yellow_Links_Stripe_Billing' ) ) {
            return Yellow_Links_Stripe_Billing::handle_approve_slot( $request );
        }
        return new WP_Error( 'not_implemented', 'Stripe Billing not implemented yet.', array( 'status' => 501 ) );
    }

    public function reject_slot( WP_REST_Request $request ) {
        if ( class_exists( 'Yellow_Links_Stripe_Billing' ) ) {
            return Yellow_Links_Stripe_Billing::handle_reject_slot( $request );
        }
        return new WP_Error( 'not_implemented', 'Stripe Billing not implemented yet.', array( 'status' => 501 ) );
    }

    private function ensure_seeded_links() {
        $existing = get_posts( array(
            'post_type'      => 'yellow_link',
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ) );
        if ( ! empty( $existing ) ) {
            return;
        }

        $seeds = array(
            array(
                'title'       => 'Worldwide Webwork (w⁴)',
                'url'         => 'https://www.worldwidewebwork.com',
                'description' => 'The foundational Webwork where digital sovereignty is anchored. Provides the sovereign infrastructure that anchors the entire Hall.',
                'category'    => 'Ecosystem & Portals',
                'clicks'      => 18900,
                'upvotes'     => 620,
                'tags'        => array( 'WEBWORK', 'INFRASTRUCTURE', 'W4', 'SOVEREIGNTY' ),
            ),
            array(
                'title'       => 'Hall of the Gods',
                'url'         => 'https://www.hallofthegods.com',
                'description' => 'Webwork & Protective Umbra. Established MMIV. 20Y+ of Digital Alchemy. A Pantheon of Creators bringing ideas to life.',
                'category'    => 'Ecosystem & Portals',
                'clicks'      => 34200,
                'upvotes'     => 1240,
                'tags'        => array( 'HALLOFTHEGODS', 'WEBWORK', 'UMBRA', 'SOVEREIGN' ),
            ),
            array(
                'title'       => 'Build a BLOX',
                'url'         => 'https://www.buildablox.com',
                'description' => 'Take the hammer. Forge your own realm using the internal guidance of the Compass Suite. You control every pixel, powered by Black BOX’s raw engine.',
                'category'    => 'Tech & Dev',
                'clicks'      => 14200,
                'upvotes'     => 490,
                'tags'        => array( 'BUILDABLOX', 'FORGE', 'COMPASS', 'BLACKBOX' ),
            ),
            array(
                'title'       => 'BlackBOX WhiteGlove',
                'url'         => 'https://www.blackboxwhiteglove.com',
                'description' => 'Industrial-grade power & devoted white-glove engineering stewardship. Shielding you from technical chaos while empowering sovereign architecture.',
                'category'    => 'Professional Services',
                'clicks'      => 11400,
                'upvotes'     => 380,
                'tags'        => array( 'BLACKBOX', 'WHITEGLOVE', 'STEWARDSHIP', 'SERVICES' ),
            ),
            array(
                'title'       => 'My Compass Consulting',
                'url'         => 'https://mycompassconsulting.com',
                'description' => 'Strategic mentorship, navigational clarity, and strategic technology guidance to bring your sovereign digital vision to life.',
                'category'    => 'Professional Services',
                'clicks'      => 9800,
                'upvotes'     => 310,
                'tags'        => array( 'MYCOMPASS', 'CONSULTING', 'MENTORSHIP', 'STRATEGY' ),
            ),
            array(
                'title'       => 'YouMeOS',
                'url'         => 'https://www.youmeos.com',
                'description' => 'Person-to-Person Operating System. The modular vessel designed for the web’s next century, transforming raw Black BOX power into human connection.',
                'category'    => 'Tech & Dev',
                'clicks'      => 12900,
                'upvotes'     => 540,
                'tags'        => array( 'YOUMEOS', 'P2P', 'OS', 'NEXT-CENTURY' ),
            ),
            array(
                'title'       => 'For The XP — Do It',
                'url'         => 'https://doit.forthexp.com',
                'description' => 'Actionable quest engine, gamified experience tracking, and motivation for creators to dream it, build it, and pwn it.',
                'category'    => 'Ecosystem & Portals',
                'clicks'      => 8400,
                'upvotes'     => 290,
                'tags'        => array( 'FORTHEXP', 'DOIT', 'GAMIFIED', 'QUESTS' ),
            ),
            array(
                'title'       => 'GlowiththeFlow',
                'url'         => 'https://glowitheflow.com',
                'description' => 'High-vibe creative alchemy, lifestyle inspiration, and digital artistry binding joy and creation.',
                'category'    => 'Visual Arts',
                'clicks'      => 7600,
                'upvotes'     => 240,
                'tags'        => array( 'GLOWITHEFLOW', 'CREATIVE', 'ART', 'ALCHEMY' ),
            ),
            array(
                'title'       => 'Sacred Realm Foundation',
                'url'         => 'https://sacredrealm.org',
                'description' => 'Protected open archives, digital sanctuary, and sacred knowledge foundations shelter within the Umbra.',
                'category'    => 'Education & Archives',
                'clicks'      => 6900,
                'upvotes'     => 210,
                'tags'        => array( 'SACREDREALM', 'ARCHIVE', 'SANCTUARY', 'FOUNDATION' ),
            ),
            array(
                'title'       => 'Triforce of the Gods',
                'url'         => 'https://www.triforceofthegods.com',
                'description' => 'Power, Wisdom, and Courage. The triune pillar of sovereignty and digital mastery within the Hall of the Gods Webwork.',
                'category'    => 'Ecosystem & Portals',
                'clicks'      => 9100,
                'upvotes'     => 350,
                'tags'        => array( 'TRIFORCE', 'POWER', 'WISDOM', 'COURAGE' ),
            ),
            array(
                'title'       => 'Hall of the Gods Community Discord',
                'url'         => 'https://discord.gg/wFtvcfAtnE',
                'description' => 'Joy, Discord, & Creation. Connect with creators, builders, and nerds wandering the deep code of the Webwork.',
                'category'    => 'Community',
                'clicks'      => 15400,
                'upvotes'     => 720,
                'tags'        => array( 'DISCORD', 'COMMUNITY', 'CREATORS', 'CHAT' ),
            ),
        );

        foreach ( $seeds as $seed ) {
            $post_id = wp_insert_post( array(
                'post_title'   => $seed['title'],
                'post_content' => $seed['description'],
                'post_type'    => 'yellow_link',
                'post_status'  => 'publish',
            ) );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                update_post_meta( $post_id, 'yl_url', $seed['url'] );
                update_post_meta( $post_id, 'yl_clicks', $seed['clicks'] );
                update_post_meta( $post_id, 'yl_upvotes', $seed['upvotes'] );
                update_post_meta( $post_id, 'yl_tags', wp_json_encode( $seed['tags'] ) );
                update_post_meta( $post_id, 'yl_safetyStatus', 'safe' );
                update_post_meta( $post_id, 'yl_safetyReason', 'Official ecosystem link.' );
                wp_set_object_terms( $post_id, $seed['category'], 'yellow_link_category' );
            }
        }
    }
}
