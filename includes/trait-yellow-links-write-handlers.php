<?php
/**
 * Handlers for the Yellow Links routes that write: submit a link, vote on a link or a comment,
 * count a click, and comment.
 *
 * Submitting, voting and commenting need a logged-in user (the route's permission callback), and
 * each is limited per user and per address. A vote counts once per user per link or comment, so
 * one account cannot push a count up. A click is public on purpose, so it is the one write that
 * is limited by address only: one count per address per link per hour.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Yellow_Links_Write_Handlers {

    /**
     * A request value as clean text, cut to $max characters. Anything that is not a string is ''.
     *
     * @param WP_REST_Request $request   The request.
     * @param string          $key       Parameter name.
     * @param int             $max       Longest value kept.
     * @param bool            $multiline True to keep line breaks.
     * @return string
     */
    private function string_param( WP_REST_Request $request, $key, $max, $multiline = false ) {
        $value = $request->get_param( $key );
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

        return mb_substr( $value, 0, $max );
    }

    /** True for a published yellow_link, the only kind of post that takes votes, clicks and comments. */
    private function is_public_link( $post_id ) {
        return $post_id > 0 && 'yellow_link' === get_post_type( $post_id ) && 'publish' === get_post_status( $post_id );
    }

    public function create_link( WP_REST_Request $request ) {
        $user_id = get_current_user_id();

        // Only a web address can be stored: a javascript: or data: URL would run when it is opened.
        $url          = esc_url_raw( $this->string_param( $request, 'url', 2048 ), array( 'http', 'https' ) );
        $title        = $this->string_param( $request, 'title', 200 );
        $description  = $this->string_param( $request, 'description', 2000, true );
        $category     = $this->string_param( $request, 'category', 100 );
        $safetyStatus = $this->string_param( $request, 'safetyStatus', 20 );
        $safetyReason = $this->string_param( $request, 'safetyReason', 300 );
        $tags_input   = $request->get_param( 'tags' );
        $neighborhood = $this->string_param( $request, 'neighborhood', 100 );
        $zip          = $this->string_param( $request, 'zip', 12 );

        if ( '' === $url || '' === $title ) {
            return new WP_Error( 'missing_fields', 'A web address (http or https) and a title are required.', array( 'status' => 400 ) );
        }

        if ( ! in_array( $safetyStatus, array( 'safe', 'warning', 'unsafe' ), true ) ) {
            $safetyStatus = 'safe';
        }
        if ( '' === $safetyReason ) {
            $safetyReason = 'Community submission.';
        }

        // A trust tier is a badge readers rely on ("Official Government"), so only a moderator can
        // award one. Everyone else's link is a community link, whatever the request says.
        $trustTier = 'community';
        if ( current_user_can( 'manage_options' ) ) {
            $requested = sanitize_key( $this->string_param( $request, 'trustTier', 40 ) );
            if ( '' !== $requested ) {
                $trustTier = $requested;
            }
        }

        // Parse tags
        $clean_tag = function ( $tag ) {
            return is_string( $tag ) ? mb_substr( sanitize_text_field( $tag ), 0, 40 ) : '';
        };
        if ( is_array( $tags_input ) ) {
            $tags_array = array_filter( array_map( $clean_tag, $tags_input ) );
        } elseif ( is_string( $tags_input ) ) {
            $tags_array = array_filter( array_map( $clean_tag, explode( ',', $tags_input ) ) );
        } else {
            $tags_array = array();
        }
        $tags_array = array_slice( array_values( $tags_array ), 0, 10 );
        if ( empty( $tags_array ) ) {
            $tags_array = array( 'GENERAL' );
        }

        // Per user and per address, so one account or one network cannot flood the directory.
        // This runs after the input checks, so a mistyped link does not use up the allowance.
        $limited = Yellow_Links_Rate_Limit::hit( 'link_create_user', (string) $user_id, 10, HOUR_IN_SECONDS, 'You have submitted too many links. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }
        $limited = Yellow_Links_Rate_Limit::hit( 'link_create_ip', Yellow_Links_Rate_Limit::client_ip(), 30, HOUR_IN_SECONDS, 'Too many links were submitted from this network. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $auto_publish = get_option( 'xophz_compass_yellow_links_auto_publish', '1' );
        $post_status  = ( $auto_publish === '1' ) ? 'publish' : 'pending';

        $post_id = wp_insert_post( array(
            'post_title'   => $title,
            'post_content' => $description,
            'post_status'  => $post_status,
            'post_type'    => 'yellow_link',
            'post_author'  => $user_id,
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
        $raw_id  = $request->get_param( 'id' );
        $type    = $this->string_param( $request, 'type', 10 );
        $post_id = (int) str_replace( 'link-', '', $raw_id );

        if ( ! $this->is_public_link( $post_id ) ) {
            return new WP_Error( 'invalid_id', 'Invalid yellow_link ID.', array( 'status' => 404 ) );
        }

        if ( 'up' !== $type && 'down' !== $type ) {
            return new WP_Error( 'invalid_type', 'Vote type must be up or down.', array( 'status' => 400 ) );
        }

        $user_id = get_current_user_id();
        $limited = Yellow_Links_Rate_Limit::hit( 'vote_user', (string) $user_id, 120, HOUR_IN_SECONDS, 'You are voting too fast. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $upvotes   = (int) get_post_meta( $post_id, 'yl_upvotes', true );
        $downvotes = (int) get_post_meta( $post_id, 'yl_downvotes', true );

        // One vote per user per link: repeating a vote changes nothing, the opposite vote moves it.
        $cast     = get_user_meta( $user_id, 'yl_link_votes', true );
        $cast     = is_array( $cast ) ? $cast : array();
        $previous = isset( $cast[ $post_id ] ) ? $cast[ $post_id ] : '';

        if ( $previous !== $type ) {
            if ( 'up' === $previous ) {
                $upvotes = max( 0, $upvotes - 1 );
            } elseif ( 'down' === $previous ) {
                $downvotes = max( 0, $downvotes - 1 );
            }

            if ( 'up' === $type ) {
                $upvotes++;
            } else {
                $downvotes++;
            }

            update_post_meta( $post_id, 'yl_upvotes', $upvotes );
            update_post_meta( $post_id, 'yl_downvotes', $downvotes );
            $cast[ $post_id ] = $type;
            update_user_meta( $user_id, 'yl_link_votes', $cast );
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

    /**
     * Public on purpose: counting a visit needs no account. So the count is guarded instead. An
     * address counts once per link per hour (a repeat returns the current count and changes
     * nothing), and one address can add at most 300 counted clicks an hour in all.
     */
    public function record_click( WP_REST_Request $request ) {
        $raw_id  = $request->get_param( 'id' );
        $post_id = (int) str_replace( 'link-', '', $raw_id );

        if ( ! $this->is_public_link( $post_id ) ) {
            return new WP_Error( 'invalid_id', 'Invalid link ID.', array( 'status' => 404 ) );
        }

        $clicks = (int) get_post_meta( $post_id, 'yl_clicks', true );
        $ip     = Yellow_Links_Rate_Limit::client_ip();

        if ( is_wp_error( Yellow_Links_Rate_Limit::hit( 'click_link', $ip . '|' . $post_id, 1, HOUR_IN_SECONDS ) ) ) {
            return rest_ensure_response( array( 'id' => 'link-' . $post_id, 'clicks' => $clicks ) );
        }

        $limited = Yellow_Links_Rate_Limit::hit( 'click_ip', $ip, 300, HOUR_IN_SECONDS, 'Too many clicks from this network. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $clicks++;
        update_post_meta( $post_id, 'yl_clicks', $clicks );
        delete_transient( 'yellow_links_network_cache' );

        return rest_ensure_response( array( 'id' => 'link-' . $post_id, 'clicks' => $clicks ) );
    }

    public function add_comment( WP_REST_Request $request ) {
        $raw_id  = $request->get_param( 'id' );
        $post_id = (int) str_replace( 'link-', '', $raw_id );
        $text    = $this->string_param( $request, 'text', 2000, true );

        if ( !$post_id || '' === $text ) {
            return new WP_Error( 'missing_fields', 'Post ID and comment text are required.', array( 'status' => 400 ) );
        }

        // Comments belong on published links only, never on any other kind of post.
        if ( ! $this->is_public_link( $post_id ) ) {
            return new WP_Error( 'invalid_id', 'Invalid link ID.', array( 'status' => 404 ) );
        }

        $user    = wp_get_current_user();
        $limited = Yellow_Links_Rate_Limit::hit( 'comment_user', (string) $user->ID, 10, HOUR_IN_SECONDS, 'You are commenting too fast. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        // The name is the logged-in user's own, never one sent in the request.
        $author = $user->display_name ?: $user->user_login;

        $comment_id = wp_insert_comment( array(
            'comment_post_ID'      => $post_id,
            'comment_author'       => $author,
            'comment_author_email' => $user->user_email,
            'user_id'              => $user->ID,
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
        $type             = $this->string_param( $request, 'type', 10 );

        $comment_id = (int) str_replace( 'comment-', '', $comment_id_param );
        $comment    = $comment_id ? get_comment( $comment_id ) : null;

        // Only comments on published links take votes, not comments on other posts.
        if ( ! $comment || ! $this->is_public_link( (int) $comment->comment_post_ID ) ) {
            return new WP_Error( 'not_found', 'Comment not found.', array( 'status' => 404 ) );
        }

        if ( 'upvote' !== $type && 'downvote' !== $type ) {
            return new WP_Error( 'invalid_type', 'Vote type must be upvote or downvote.', array( 'status' => 400 ) );
        }

        $user_id = get_current_user_id();
        $limited = Yellow_Links_Rate_Limit::hit( 'comment_vote_user', (string) $user_id, 120, HOUR_IN_SECONDS, 'You are voting too fast. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $current_votes = (int) get_comment_meta( $comment_id, 'yl_comment_votes', true );

        // One vote per user per comment: repeating a vote changes nothing, the opposite vote moves it.
        $cast     = get_user_meta( $user_id, 'yl_comment_votes_cast', true );
        $cast     = is_array( $cast ) ? $cast : array();
        $previous = isset( $cast[ $comment_id ] ) ? $cast[ $comment_id ] : '';

        if ( $previous !== $type ) {
            if ( 'upvote' === $previous ) {
                $current_votes--;
            } elseif ( 'downvote' === $previous ) {
                $current_votes++;
            }

            $current_votes += ( 'upvote' === $type ) ? 1 : -1;

            update_comment_meta( $comment_id, 'yl_comment_votes', $current_votes );
            $cast[ $comment_id ] = $type;
            update_user_meta( $user_id, 'yl_comment_votes_cast', $cast );
            delete_transient( 'yellow_links_network_cache' );
        }

        return rest_ensure_response( array(
            'success' => true,
            'votes'   => $current_votes,
        ) );
    }
}
