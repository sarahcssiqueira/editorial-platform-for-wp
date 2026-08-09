<?php
/**
 * Plugin Name:       Editorial Workflow
 * Plugin URI:        https://github.com/your-repo/editorial-workflow
 * Description:       Brings Sanity-style editorial UX to WordPress: content approval flow, preview links, review notes, and auto cache-clear on publish.
 * Version:           1.0.0
 * Author:            Sarah
 * License:           GPL-2.0-or-later
 * Text Domain:       editorial
 */

defined( 'ABSPATH' ) || exit;

// ─── CONFIG ────────────────────────────────────────────────────────────────
// Set EDITORIAL_SLACK_WEBHOOK in wp-config.php to enable Slack notifications.
// define( 'EDITORIAL_SLACK_WEBHOOK', 'https://hooks.slack.com/services/XXX/YYY/ZZZ' );
// ───────────────────────────────────────────────────────────────────────────


// ════════════════════════════════════════════════════════════════════════════
// 1. CUSTOM POST STATUSES
// ════════════════════════════════════════════════════════════════════════════

add_action( 'init', 'ew_register_post_statuses' );
function ew_register_post_statuses() {

    register_post_status( 'changes_requested', [
        'label'                     => _x( 'Changes Requested', 'post status', 'editorial' ),
        'label_count'               => _n_noop( 'Changes Requested <span class="count">(%s)</span>', 'Changes Requested <span class="count">(%s)</span>', 'editorial' ),
        'public'                    => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'exclude_from_search'       => true,
    ] );

    register_post_status( 'approved', [
        'label'                     => _x( 'Approved', 'post status', 'editorial' ),
        'label_count'               => _n_noop( 'Approved <span class="count">(%s)</span>', 'Approved <span class="count">(%s)</span>', 'editorial' ),
        'public'                    => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'exclude_from_search'       => true,
    ] );
}


// ════════════════════════════════════════════════════════════════════════════
// 2. ROLE CAPABILITIES
// ════════════════════════════════════════════════════════════════════════════

add_action( 'init', 'ew_set_role_capabilities' );
function ew_set_role_capabilities() {
    $author = get_role( 'author' );
    if ( $author ) {
        $author->add_cap( 'publish_posts' );
    }
    $editor = get_role( 'editor' );
    if ( $editor ) {
        $editor->add_cap( 'publish_posts' );
        $editor->add_cap( 'edit_others_posts' );
    }
}

function ew_user_is_reviewer() {
    $user_roles = (array) wp_get_current_user()->roles;
    return in_array( 'editor', $user_roles, true ) || in_array( 'reviewer', $user_roles, true ) || in_array( 'administrator', $user_roles, true );
}

function ew_user_can_publish_post( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post || ! current_user_can( 'publish_posts' ) ) return false;
    if ( ew_user_is_reviewer() ) return true;

    return get_post_status( $post_id ) === 'approved'
        && (int) $post->post_author === (int) get_current_user_id()
        && current_user_can( 'edit_post', $post_id );
}


// ════════════════════════════════════════════════════════════════════════════
// 3. SUBMIT FOR REVIEW BUTTON (writers)
// ════════════════════════════════════════════════════════════════════════════

add_action( 'post_submitbox_misc_actions', 'ew_submit_for_review_button' );
function ew_submit_for_review_button( $post ) {
    if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return;
    if ( ew_user_is_reviewer() ) return;

    $status = get_post_status( $post->ID );
    if ( in_array( $status, [ 'publish', 'pending', 'approved' ], true ) ) return;

    wp_nonce_field( 'ew_submit_review_' . $post->ID, 'ew_submit_review_nonce' );
    ?>
    <div class="misc-pub-section ew-submit-section">
        <button type="submit" name="ew_submit_for_review" value="1"
                class="button button-primary ew-submit-btn">
            ✦ Submit for Review
        </button>
    </div>
    <?php
}

add_action( 'save_post', 'ew_handle_submit_for_review', 10, 2 );
function ew_handle_submit_for_review( $post_id, $post ) {
    if ( ! isset( $_POST['ew_submit_for_review'] ) ) return;
    if ( ! isset( $_POST['ew_submit_review_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['ew_submit_review_nonce'], 'ew_submit_review_' . $post_id ) ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

    remove_action( 'save_post', 'ew_handle_submit_for_review', 10 );
    wp_update_post( [ 'ID' => $post_id, 'post_status' => 'pending' ] );
    add_action( 'save_post', 'ew_handle_submit_for_review', 10, 2 );

    ew_notify_editors_review_requested( $post_id );
}

add_action( 'transition_post_status', 'ew_notify_editors_when_pending', 10, 3 );
function ew_notify_editors_when_pending( $new_status, $old_status, $post ) {
    if ( $new_status !== 'pending' || $old_status === 'pending' ) return;
    if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;

    // Prevent duplicate notifications when submit-for-review handler already sent one.
    if ( did_action( 'save_post' ) > 0 && isset( $_POST['ew_submit_for_review'] ) ) return;

    ew_notify_editors_review_requested( $post->ID );
}


// ════════════════════════════════════════════════════════════════════════════
// 4. NOTIFICATIONS
// ════════════════════════════════════════════════════════════════════════════

function ew_notify_editors_review_requested( $post_id ) {
    $post     = get_post( $post_id );
    $author   = get_userdata( $post->post_author );
    $edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
    $preview  = ew_get_preview_url( $post_id );
    $editors  = get_users( [ 'role' => 'editor' ] );
    $to       = array_map( fn( $u ) => $u->user_email, $editors );

    if ( empty( $to ) ) return;

    wp_mail( $to,
        sprintf( '[Review Needed] %s', $post->post_title ),
        sprintf( "Hi,\n\n%s submitted \"%s\" for review.\n\nPreview: %s\nEdit: %s\n\nLog in to approve or send it back for revision.",
            $author->display_name, $post->post_title, $preview, $edit_url )
    );
    ew_slack_notify( sprintf( '✦ *Review needed:* <%s|%s> by %s — <%s|Preview>', $edit_url, $post->post_title, $author->display_name, $preview ) );
}

function ew_notify_author_changes_requested( $post_id, $note ) {
    $post     = get_post( $post_id );
    $author   = get_userdata( $post->post_author );
    $editor   = wp_get_current_user();
    $edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

    wp_mail( $author->user_email,
        sprintf( '[Changes Requested] %s', $post->post_title ),
        sprintf( "Hi %s,\n\n%s reviewed \"%s\" and requested changes:\n\n—\n%s\n—\n\nEdit: %s",
            $author->display_name, $editor->display_name, $post->post_title, $note, $edit_url )
    );
    ew_slack_notify( sprintf( '↩ *Changes requested:* <%s|%s> by %s', $edit_url, $post->post_title, $editor->display_name ) );
}

function ew_slack_notify( $message ) {
    if ( ! defined( 'EDITORIAL_SLACK_WEBHOOK' ) ) return;
    wp_remote_post( EDITORIAL_SLACK_WEBHOOK, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [ 'text' => $message ] ),
    ] );
}


// ════════════════════════════════════════════════════════════════════════════
// 5. EDITOR REVIEW PANEL
// ════════════════════════════════════════════════════════════════════════════

add_action( 'add_meta_boxes', 'ew_add_review_metabox' );
function ew_add_review_metabox() {
    if ( ! ew_user_is_reviewer() ) return;
    add_meta_box( 'ew_review_panel', '✦ Editorial Review', 'ew_render_review_metabox', [ 'post', 'page' ], 'side', 'high' );
}

add_action( 'add_meta_boxes', 'ew_add_feedback_metabox' );
function ew_add_feedback_metabox() {
    global $post;
    if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return;

    add_meta_box( 'ew_feedback_history', 'Editorial Feedback', 'ew_render_feedback_metabox', [ 'post', 'page' ], 'normal', 'default' );
}

function ew_render_feedback_metabox( $post ) {
    $comments = get_comments( [
        'post_id' => $post->ID,
        'type'    => 'editorial_change_request',
        'orderby' => 'comment_date_gmt',
        'order'   => 'DESC',
        'status'  => 'approve',
        'number'  => 20,
    ] );

    if ( empty( $comments ) ) {
        echo '<p>No feedback comments yet.</p>';
        return;
    }

    echo '<div class="ew-feedback-history">';
    foreach ( $comments as $comment ) {
        $author = $comment->comment_author ?: 'Editor';
        $date   = mysql2date( 'M j, Y g:i a', $comment->comment_date );
        echo '<div class="ew-feedback-item" style="margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;">';
        echo '<p style="margin:0 0 6px;"><strong>' . esc_html( $author ) . '</strong> <span style="color:#6b7280;">' . esc_html( $date ) . '</span></p>';
        echo '<p style="margin:0;white-space:pre-wrap;">' . esc_html( $comment->comment_content ) . '</p>';
        echo '</div>';
    }
    echo '</div>';
}

function ew_render_review_metabox( $post ) {
    $status      = get_post_status( $post->ID );
    $preview_url = ew_get_preview_url( $post->ID );
    $note        = ew_get_latest_change_request_note( $post->ID );
    $can_publish = ew_user_can_publish_post( $post->ID );
    wp_nonce_field( 'ew_review_action_' . $post->ID, 'ew_review_nonce' );
    ?>
    <div class="ew-review-panel">
        <div class="ew-status-badge ew-status-<?php echo esc_attr( $status ); ?>">
            <?php echo esc_html( ew_status_label( $status ) ); ?>
        </div>
        <?php if ( $preview_url ) : ?>
        <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="ew-preview-link button button-small">↗ Open Preview</a>
        <?php endif; ?>
        <?php if ( $note ) : ?>
        <div class="ew-previous-note">
            <strong>Last note:</strong>
            <p><?php echo esc_html( $note ); ?></p>
        </div>
        <?php endif; ?>
        <?php if ( in_array( $status, [ 'pending', 'changes_requested' ], true ) ) : ?>
        <textarea name="ew_review_note" id="ew_review_note"
                  placeholder="Leave a note for the writer when sending this back for revision..."
                  rows="4" style="width:100%;margin-top:12px;"></textarea>
        <div class="ew-review-actions">
            <button type="submit" name="ew_action" value="approve" class="button button-primary ew-btn-approve">✓ Approve</button>
            <button type="submit" name="ew_action" value="request_changes" class="button">↩ Request Changes</button>
        </div>
        <p style="margin-top:10px;">Approval keeps this post unpublished. Once approved, the writer can publish it.</p>
        <?php elseif ( $status === 'approved' ) : ?>
        <p style="margin-top:12px;"><?php echo esc_html( $can_publish ? 'This post is approved and can now be published.' : 'This post is approved, but this user cannot publish it.' ); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

add_filter( 'wp_insert_post_data', 'ew_intercept_editor_publish', 10, 2 );
function ew_intercept_editor_publish( $data, $postarr ) {
    if ( ! is_admin() ) return $data;
    if ( ! current_user_can( 'publish_posts' ) ) return $data;
    if ( ! in_array( $data['post_type'] ?? '', [ 'post', 'page' ], true ) ) return $data;

    $publish_clicked = isset( $_POST['publish'] );
    if ( empty( $postarr['ID'] ) ) {
        if ( $publish_clicked && ( $data['post_status'] ?? '' ) === 'publish' && ! ew_user_is_reviewer() ) {
            $data['post_status'] = 'pending';
        }
        return $data;
    }

    $post_id         = (int) $postarr['ID'];
    $current_status  = get_post_status( $post_id );
    $review_action   = sanitize_text_field( $_POST['ew_action'] ?? '' );

    if ( in_array( $review_action, [ 'approve', 'request_changes' ], true ) ) return $data;

    if ( $publish_clicked && ( $data['post_status'] ?? '' ) === 'publish' && ! ew_user_can_publish_post( $post_id ) ) {
        if ( current_user_can( 'edit_post', $post_id ) ) {
            $data['post_status'] = 'pending';
        } else {
            $data['post_status'] = $current_status ?: 'draft';
        }
    }

    return $data;
}

add_action( 'save_post', 'ew_handle_review_action', 10, 2 );
function ew_handle_review_action( $post_id, $post ) {
    if ( ! isset( $_POST['ew_action'] ) ) return;
    if ( ! isset( $_POST['ew_review_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['ew_review_nonce'], 'ew_review_action_' . $post_id ) ) return;
    if ( ! ew_user_is_reviewer() ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

    $action = sanitize_text_field( $_POST['ew_action'] ?? '' );
    $note   = sanitize_textarea_field( $_POST['ew_review_note'] ?? '' );

    remove_action( 'save_post', 'ew_handle_review_action', 10 );
    if ( $action === 'approve' ) {
        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'approved' ] );
        update_post_meta( $post_id, '_ew_approved_by', get_current_user_id() );
        update_post_meta( $post_id, '_ew_approved_at', current_time( 'mysql' ) );
    } elseif ( $action === 'request_changes' ) {
        if ( $note === '' ) {
            add_action( 'save_post', 'ew_handle_review_action', 10, 2 );
            return;
        }
        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'changes_requested' ] );
        update_post_meta( $post_id, '_ew_review_note', $note );
        ew_add_change_request_comment( $post_id, $note );
        ew_notify_author_changes_requested( $post_id, $note );
    }
    add_action( 'save_post', 'ew_handle_review_action', 10, 2 );
}

function ew_add_change_request_comment( $post_id, $note ) {
    $user = wp_get_current_user();
    $comment_id = wp_insert_comment( [
        'comment_post_ID'      => $post_id,
        'comment_content'      => $note,
        'comment_type'         => 'editorial_change_request',
        'comment_approved'     => 1,
        'user_id'              => $user->ID,
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
    ] );

    if ( $comment_id ) {
        add_comment_meta( $comment_id, '_ew_internal', 1, true );
    }
}

function ew_get_latest_change_request_note( $post_id ) {
    $comments = get_comments( [
        'post_id' => $post_id,
        'type'    => 'editorial_change_request',
        'number'  => 1,
        'orderby' => 'comment_date_gmt',
        'order'   => 'DESC',
        'status'  => 'approve',
    ] );

    if ( ! empty( $comments ) ) {
        return $comments[0]->comment_content;
    }

    return get_post_meta( $post_id, '_ew_review_note', true );
}


// ════════════════════════════════════════════════════════════════════════════
// 6. PREVIEW URL HELPER
// ════════════════════════════════════════════════════════════════════════════

function ew_get_preview_url( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) return '';
    return add_query_arg( [
        'preview'        => 'true',
        'preview_id'     => $post_id,
        'preview_nonce'  => wp_create_nonce( 'post_preview_' . $post_id ),
    ], get_permalink( $post_id ) ?: home_url( '/?p=' . $post_id ) );
}


// ════════════════════════════════════════════════════════════════════════════
// 7. CACHE CLEARING ON PUBLISH
// ════════════════════════════════════════════════════════════════════════════

add_action( 'transition_post_status', 'ew_clear_cache_on_publish', 10, 3 );
function ew_clear_cache_on_publish( $new_status, $old_status, $post ) {
    if ( $new_status !== 'publish' || $old_status === 'publish' ) return;
    if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;

    if ( function_exists( 'rocket_clean_post' ) )      rocket_clean_post( $post->ID );
    if ( function_exists( 'w3tc_flush_post' ) )        w3tc_flush_post( $post->ID );
    if ( function_exists( 'wp_cache_post_change' ) )   wp_cache_post_change( $post->ID );
    if ( class_exists( 'LiteSpeed_Cache_API' ) )       LiteSpeed_Cache_API::purge( 'esi.post.' . $post->ID );

    delete_transient( 'ew_preview_' . $post->ID );
    ew_slack_notify( sprintf( '🟢 *Published:* <%s|%s>', get_permalink( $post->ID ), $post->post_title ) );
}


// ════════════════════════════════════════════════════════════════════════════
// 8. ADMIN LIST STATES + STATUS JS
// ════════════════════════════════════════════════════════════════════════════

add_filter( 'display_post_states', 'ew_display_post_states', 10, 2 );
function ew_display_post_states( $states, $post ) {
    $status = get_post_status( $post->ID );
    if ( $status === 'pending' )  $states['pending']  = '<span style="color:#f59e0b">Pending</span>';
    if ( $status === 'changes_requested' ) $states['changes_requested'] = '<span style="color:#ef4444">Changes Requested</span>';
    if ( $status === 'approved' ) $states['approved'] = '<span style="color:#10b981">Approved</span>';
    return $states;
}

add_action( 'admin_footer-post.php',     'ew_inject_status_js' );
add_action( 'admin_footer-post-new.php', 'ew_inject_status_js' );
function ew_inject_status_js() {
    global $post;
    if ( ! $post ) return;
    $status = get_post_status( $post->ID );
    $can_publish = ew_user_can_publish_post( $post->ID );
    $is_reviewer = ew_user_is_reviewer();
    ?>
    <script>
    jQuery(function($){
        var statuses = { 'changes_requested': 'Changes Requested', 'approved': 'Approved' };
        $.each(statuses, function(val, label){ $('#post_status').append($('<option>', { value: val, text: label })); });
        <?php if ( in_array( $status, [ 'changes_requested', 'approved' ], true ) ) : ?>
        $('#post_status').val('<?php echo esc_js( $status ); ?>');
        $('#display-post-states').text('<?php echo esc_js( ew_status_label( $status ) ); ?>');
        <?php endif; ?>

        var $publishButton = $('#publish');

        if ( $publishButton.length && $('input#post_status').length ) {
            function updatePublishButton() {
                var currentStatus = $('#post_status').val() || '';
                var isReviewer = <?php echo wp_json_encode( $is_reviewer ); ?>;
                var canPublish = <?php echo wp_json_encode( $can_publish ); ?>;
                var buttonLabel = (isReviewer || canPublish || currentStatus === 'approved') ? 'Publish' : 'Submit for Review';
                $publishButton.val(buttonLabel).text(buttonLabel);
            }

            updatePublishButton();

            $('#post_status').on('change', updatePublishButton);
        }
    });
    </script>
    <?php
}


// ════════════════════════════════════════════════════════════════════════════
// 9. HELPERS
// ════════════════════════════════════════════════════════════════════════════

function ew_status_label( $status ) {
    return [
        'draft'             => 'Draft',
        'pending'           => 'Pending (In Review)',
        'changes_requested' => 'Changes Requested',
        'approved'          => 'Approved',
        'publish'           => 'Published',
        'future'            => 'Scheduled',
    ][ $status ] ?? ucfirst( $status );
}
