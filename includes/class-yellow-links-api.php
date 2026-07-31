<?php
/**
 * REST API Endpoints for Yellow Links
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Yellow_Links_API {

    public function register_routes() {
        register_rest_route( 'yellow-links/v1', '/gemini/analyze', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'analyze_link' ),
            'permission_callback' => '__return_true', // Open or adjust permission as needed
        ) );

        register_rest_route( 'yellow-links/v1', '/gemini/suggest-ad', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'suggest_ad' ),
            'permission_callback' => '__return_true', // Open or adjust permission as needed
        ) );

        register_rest_route( 'yellow-links/v1', '/network', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_network_links' ),
            'permission_callback' => '__return_true', // Public directory reading
        ) );
    }

    private function get_gemini_key() {
        // Read from the environment where Docker passes it
        return getenv( 'GEMINI_API_KEY' );
    }

    private function call_gemini( $prompt, $schema ) {
        $api_key = $this->get_gemini_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'GEMINI_API_KEY is not configured.', array( 'status' => 500 ) );
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=' . $api_key;

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
        $transient_key = 'yellow_links_network_cache';
        $cached_data = get_transient( $transient_key );

        if ( false !== $cached_data ) {
            return rest_ensure_response( $cached_data );
        }

        $all_links = array();

        // 1. Fetch Local Links
        // We'll create a dummy LinkItem to represent the structure expected by Vue MVP, 
        // normally we would query CPTs. For now, since MVP uses a static array, we just 
        // provide the data format.
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
                
                $tags_meta = get_post_meta( get_the_ID(), 'yl_tags', true );
                $tags = !empty( $tags_meta ) ? json_decode( $tags_meta, true ) : array( 'GENERAL' );
                if ( !is_array( $tags ) ) $tags = array( 'GENERAL' );

                $cats = wp_get_post_terms( get_the_ID(), 'yellow_link_category', array('fields' => 'names') );
                $category = !empty( $cats ) ? $cats[0] : 'Blogs & Personal';

                $local_links[] = array(
                    'id'           => 'link-' . get_the_ID(),
                    'url'          => get_post_meta( get_the_ID(), 'yl_url', true ) ?: get_permalink(),
                    'title'        => get_the_title(),
                    'description'  => get_the_content(),
                    'category'     => $category,
                    'clicks'       => 0,
                    'upvotes'      => 1,
                    'downvotes'    => 0,
                    'tags'         => $tags,
                    'submitter'    => get_the_author(),
                    'timestamp'    => strtotime( get_the_date() ) * 1000,
                    'comments'     => array(),
                    'safetyStatus' => get_post_meta( get_the_ID(), 'yl_safetyStatus', true ) ?: 'safe',
                    'safetyReason' => get_post_meta( get_the_ID(), 'yl_safetyReason', true ) ?: 'Verified local link.',
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
                        // Mark as sister and append
                        foreach( $body as &$remote_link ) {
                            $remote_link['isSister'] = true;
                            $remote_link['sisterUrl'] = $site;
                        }
                        // To avoid infinite loops across sister sites hitting each other's network endpoints, 
                        // we should ideally fetch `/wp/v2/yellow_links` instead, or only merge those where isSister is false.
                        // Let's filter out remote links that are themselves sisters (only accept their local ones).
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
}
