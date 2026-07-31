<?php
/**
 * Custom Post Type and Taxonomy Registration for Yellow Links
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Yellow_Links_CPT {

    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'init', array( $this, 'register_taxonomy' ) );
        
        // Add meta boxes or register meta for REST API
        add_action( 'init', array( $this, 'register_meta' ) );
    }

    public function register_post_type() {
        $labels = array(
            'name'                  => _x( 'Yellow Links', 'Post type general name', 'xophz-compass-yellow-links' ),
            'singular_name'         => _x( 'Yellow Link', 'Post type singular name', 'xophz-compass-yellow-links' ),
            'menu_name'             => _x( 'Yellow Links', 'Admin Menu text', 'xophz-compass-yellow-links' ),
            'name_admin_bar'        => _x( 'Yellow Link', 'Add New on Toolbar', 'xophz-compass-yellow-links' ),
            'add_new'               => __( 'Add New', 'xophz-compass-yellow-links' ),
            'add_new_item'          => __( 'Add New Yellow Link', 'xophz-compass-yellow-links' ),
            'new_item'              => __( 'New Yellow Link', 'xophz-compass-yellow-links' ),
            'edit_item'             => __( 'Edit Yellow Link', 'xophz-compass-yellow-links' ),
            'view_item'             => __( 'View Yellow Link', 'xophz-compass-yellow-links' ),
            'all_items'             => __( 'All Yellow Links', 'xophz-compass-yellow-links' ),
            'search_items'          => __( 'Search Yellow Links', 'xophz-compass-yellow-links' ),
            'parent_item_colon'     => __( 'Parent Yellow Links:', 'xophz-compass-yellow-links' ),
            'not_found'             => __( 'No yellow links found.', 'xophz-compass-yellow-links' ),
            'not_found_in_trash'    => __( 'No yellow links found in Trash.', 'xophz-compass-yellow-links' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'yellow_link' ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'menu_icon'          => 'dashicons-admin-links',
            'supports'           => array( 'title', 'editor', 'thumbnail', 'comments', 'custom-fields' ),
            'show_in_rest'       => true, // Essential for Gutenberg and REST API
            'rest_base'          => 'yellow_links',
        );

        register_post_type( 'yellow_link', $args );
    }

    public function register_taxonomy() {
        $labels = array(
            'name'              => _x( 'Categories', 'taxonomy general name', 'xophz-compass-yellow-links' ),
            'singular_name'     => _x( 'Category', 'taxonomy singular name', 'xophz-compass-yellow-links' ),
            'search_items'      => __( 'Search Categories', 'xophz-compass-yellow-links' ),
            'all_items'         => __( 'All Categories', 'xophz-compass-yellow-links' ),
            'parent_item'       => __( 'Parent Category', 'xophz-compass-yellow-links' ),
            'parent_item_colon' => __( 'Parent Category:', 'xophz-compass-yellow-links' ),
            'edit_item'         => __( 'Edit Category', 'xophz-compass-yellow-links' ),
            'update_item'       => __( 'Update Category', 'xophz-compass-yellow-links' ),
            'add_new_item'      => __( 'Add New Category', 'xophz-compass-yellow-links' ),
            'new_item_name'     => __( 'New Category Name', 'xophz-compass-yellow-links' ),
            'menu_name'         => __( 'Categories', 'xophz-compass-yellow-links' ),
        );

        $args = array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'yellow_link_category' ),
            'show_in_rest'      => true,
            'rest_base'         => 'yellow_link_categories',
        );

        register_taxonomy( 'yellow_link_category', array( 'yellow_link' ), $args );

        // Location & Ward Taxonomy
        $loc_labels = array(
            'name'          => _x( 'Locations & Wards', 'taxonomy general name', 'xophz-compass-yellow-links' ),
            'singular_name' => _x( 'Location', 'taxonomy singular name', 'xophz-compass-yellow-links' ),
            'search_items'  => __( 'Search Locations', 'xophz-compass-yellow-links' ),
            'all_items'     => __( 'All Locations', 'xophz-compass-yellow-links' ),
            'edit_item'     => __( 'Edit Location', 'xophz-compass-yellow-links' ),
            'update_item'   => __( 'Update Location', 'xophz-compass-yellow-links' ),
            'add_new_item'  => __( 'Add New Location', 'xophz-compass-yellow-links' ),
            'new_item_name' => __( 'New Location Name', 'xophz-compass-yellow-links' ),
            'menu_name'     => __( 'Locations / Wards', 'xophz-compass-yellow-links' ),
        );

        register_taxonomy( 'yellow_link_location', array( 'yellow_link' ), array(
            'hierarchical'      => true,
            'labels'            => $loc_labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'yellow_link_location' ),
            'show_in_rest'      => true,
            'rest_base'         => 'yellow_link_locations',
        ) );

        // Dynamic Trust Badge Taxonomy
        $badge_labels = array(
            'name'          => _x( 'Trust Badges', 'taxonomy general name', 'xophz-compass-yellow-links' ),
            'singular_name' => _x( 'Trust Badge', 'taxonomy singular name', 'xophz-compass-yellow-links' ),
            'search_items'  => __( 'Search Badges', 'xophz-compass-yellow-links' ),
            'all_items'     => __( 'All Badges', 'xophz-compass-yellow-links' ),
            'edit_item'     => __( 'Edit Badge', 'xophz-compass-yellow-links' ),
            'update_item'   => __( 'Update Badge', 'xophz-compass-yellow-links' ),
            'add_new_item'  => __( 'Add New Badge', 'xophz-compass-yellow-links' ),
            'new_item_name' => __( 'New Badge Name', 'xophz-compass-yellow-links' ),
            'menu_name'     => __( 'Trust Badges', 'xophz-compass-yellow-links' ),
        );

        register_taxonomy( 'yellow_link_badge', array( 'yellow_link' ), array(
            'hierarchical'      => false,
            'labels'            => $badge_labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'yellow_link_badge' ),
            'show_in_rest'      => true,
            'rest_base'         => 'yellow_link_badges',
        ) );
    }

    public function register_meta() {
        $meta_keys = array(
            'yl_url'             => array( 'type' => 'string', 'description' => 'Target URL' ),
            'yl_tags'            => array( 'type' => 'string', 'description' => 'JSON string of tags' ),
            'yl_safetyStatus'    => array( 'type' => 'string', 'description' => 'safe, warning, unsafe' ),
            'yl_safetyReason'    => array( 'type' => 'string', 'description' => 'Reason for safety status' ),
            'yl_rating'          => array( 'type' => 'number', 'description' => 'Aggregate rating' ),
            'yl_upvotes'         => array( 'type' => 'integer', 'description' => 'Upvote count' ),
            'yl_downvotes'       => array( 'type' => 'integer', 'description' => 'Downvote count' ),
            'yl_clicks'          => array( 'type' => 'integer', 'description' => 'Outbound visit click count' ),
            'yl_address_street'  => array( 'type' => 'string', 'description' => 'Street Address' ),
            'yl_address_city'    => array( 'type' => 'string', 'description' => 'City' ),
            'yl_address_state'   => array( 'type' => 'string', 'description' => 'State/Province' ),
            'yl_address_zip'     => array( 'type' => 'string', 'description' => 'Postal Code' ),
            'yl_address_country' => array( 'type' => 'string', 'description' => 'Country' ),
            'yl_lat'             => array( 'type' => 'number', 'description' => 'Latitude coordinate' ),
            'yl_lng'             => array( 'type' => 'number', 'description' => 'Longitude coordinate' ),
            'yl_http_status'     => array( 'type' => 'integer', 'description' => 'Last checked HTTP status code' ),
            'yl_last_checked'    => array( 'type' => 'string', 'description' => 'Timestamp of last health check ping' ),
        );

        foreach ( $meta_keys as $key => $details ) {
            register_post_meta( 'yellow_link', $key, array(
                'type'         => $details['type'],
                'description'  => $details['description'],
                'single'       => true,
                'show_in_rest' => true,
            ) );
        }

        // Register Term Meta for Dynamic Badges
        $badge_meta_keys = array(
            'badge_color' => array( 'type' => 'string', 'description' => 'Badge display color (hex or CSS token)' ),
            'badge_icon'  => array( 'type' => 'string', 'description' => 'Lucide icon identifier' ),
            'badge_level' => array( 'type' => 'string', 'description' => 'Verification level priority' ),
        );

        foreach ( $badge_meta_keys as $key => $details ) {
            register_term_meta( 'yellow_link_badge', $key, array(
                'type'         => $details['type'],
                'description'  => $details['description'],
                'single'       => true,
                'show_in_rest' => true,
            ) );
        }
    }
}
