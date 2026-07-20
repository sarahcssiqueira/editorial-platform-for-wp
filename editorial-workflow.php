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

    register_post_status( 'in_review', [
        'label'                     => _x( 'In Review', 'post status', 'editorial' ),
        'label_count'               => _n_noop( 'In Review <span class="count">(%s)</span>', 'In Review <span class="count">(%s)</span>', 'editorial' ),
        'public'                    => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'exclude_from_search'       => true,
    ] );

    register_post_status( 'changes_requested', [
        'label'                     => _x( 'Changes Requested', 'post status', 'editorial' ),
        'label_count'               => _n_noop( 'Changes Requested <span class="count">(%s)</span>', 'Changes Requested <span class="count">(%s)</span>', 'editorial' ),
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
        $author->remove_cap( 'publish_posts' );
    }
    $editor = get_role( 'editor' );
    if ( $editor ) {
        $editor->add_cap( 'publish_posts' );
        $editor->add_cap( 'edit_others_posts' );
    }
}


// ════════════════════════════════════════════════════════════════════════════
// 3. SUBMIT FOR REVIEW BUTTON (writers)
// ════════════════════════════════════════════════════════════════════════════

add_action( 'post_submitbox_misc_actions', 'ew_submit_for_review_button' );
function ew_submit_for_review_button( $post ) {
    if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return;
    if ( current_user_can( 'publish_posts' ) ) return;

    $status = get_post_status( $post->ID );
    if ( in_array( $status, [ 'publish', 'in_review' ], true ) ) return;

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
    wp_update_post( [ 'ID' => $post_id, 'post_status' => 'in_review' ] );
    add_action( 'save_post', 'ew_handle_submit_for_review', 10, 2 );

    ew_notify_editors_review_requested( $post_id );
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
        sprintf( "Hi,\n\n%s submitted \"%s\" for review.\n\nPreview: %s\nEdit: %s\n\nLog in to approve or request changes.",
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
    if ( ! current_user_can( 'publish_posts' ) ) return;
    add_meta_box( 'ew_review_panel', '✦ Editorial Review', 'ew_render_review_metabox', [ 'post', 'page' ], 'side', 'high' );
}

function ew_render_review_metabox( $post ) {
    $status      = get_post_status( $post->ID );
    $preview_url = ew_get_preview_url( $post->ID );
    $note        = get_post_meta( $post->ID, '_ew_review_note', true );
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
        <?php if ( $status === 'in_review' ) : ?>
        <textarea name="ew_review_note" id="ew_review_note"
                  placeholder="Leave a note for the writer (required when requesting changes)..."
                  rows="4" style="width:100%;margin-top:12px;"></textarea>
        <div class="ew-review-actions">
            <button type="submit" name="ew_action" value="approve" class="button button-primary ew-btn-approve">✓ Approve & Publish</button>
            <button type="submit" name="ew_action" value="request_changes" class="button ew-btn-changes">↩ Request Changes</button>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

add_action( 'save_post', 'ew_handle_review_action', 10, 2 );
function ew_handle_review_action( $post_id, $post ) {
    if ( ! isset( $_POST['ew_action'] ) ) return;
    if ( ! isset( $_POST['ew_review_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['ew_review_nonce'], 'ew_review_action_' . $post_id ) ) return;
    if ( ! current_user_can( 'publish_posts' ) ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

    $action = sanitize_text_field( $_POST['ew_action'] );
    $note   = sanitize_textarea_field( $_POST['ew_review_note'] ?? '' );

    remove_action( 'save_post', 'ew_handle_review_action', 10 );
    if ( $action === 'approve' ) {
        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
    } elseif ( $action === 'request_changes' ) {
        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'changes_requested' ] );
        update_post_meta( $post_id, '_ew_review_note', $note );
        ew_notify_author_changes_requested( $post_id, $note );
    }
    add_action( 'save_post', 'ew_handle_review_action', 10, 2 );
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
    if ( $status === 'in_review' )         $states['in_review']         = '<span style="color:#f59e0b">✦ In Review</span>';
    if ( $status === 'changes_requested' ) $states['changes_requested'] = '<span style="color:#ef4444">↩ Changes Requested</span>';
    return $states;
}

add_action( 'admin_footer-post.php',     'ew_inject_status_js' );
add_action( 'admin_footer-post-new.php', 'ew_inject_status_js' );
function ew_inject_status_js() {
    global $post;
    if ( ! $post ) return;
    $status = get_post_status( $post->ID );
    ?>
    <script>
    jQuery(function($){
        var statuses = { 'in_review': '✦ In Review', 'changes_requested': '↩ Changes Requested' };
        $.each(statuses, function(val, label){ $('#post_status').append($('<option>', { value: val, text: label })); });
        <?php if ( in_array( $status, [ 'in_review', 'changes_requested' ], true ) ) : ?>
        $('#post_status').val('<?php echo esc_js( $status ); ?>');
        $('#display-post-states').text('<?php echo esc_js( ew_status_label( $status ) ); ?>');
        <?php endif; ?>
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
        'pending'           => 'Pending',
        'in_review'         => '✦ In Review',
        'changes_requested' => '↩ Changes Requested',
        'publish'           => 'Published',
        'future'            => 'Scheduled',
    ][ $status ] ?? ucfirst( $status );
}
