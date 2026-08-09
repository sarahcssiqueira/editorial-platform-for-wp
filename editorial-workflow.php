<?php
/**
 * Plugin Name:       Editorial Workflow
 * Plugin URI:        https://github.com/your-repo/editorial-workflow
 * Description:       Brings a style editorial UX to WordPress: content approval flow, preview links, review notes, and auto cache-clear on publish.
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
        // Writers should not publish directly; publishing is unlocked only after approval.
        $author->remove_cap( 'publish_posts' );
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
    if ( ! $post ) return false;
    if ( ew_user_is_reviewer() ) return current_user_can( 'publish_posts' );

    return get_post_status( $post_id ) === 'approved'
        && (int) $post->post_author === (int) get_current_user_id()
        && current_user_can( 'edit_post', $post_id );
}

/**
 * Finds the current post ID from capability args or the edit screen request.
 */
function ew_get_request_post_id( $args = [] ) {
    if ( isset( $args[2] ) && is_numeric( $args[2] ) ) {
        return (int) $args[2];
    }

    if ( isset( $_POST['post_ID'] ) && is_numeric( $_POST['post_ID'] ) ) {
        return (int) $_POST['post_ID'];
    }

    if ( isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) {
        return (int) $_GET['post'];
    }

    return 0;
}

add_filter( 'user_has_cap', 'ew_grant_publish_cap_for_approved_posts', 10, 4 );
function ew_grant_publish_cap_for_approved_posts( $allcaps, $caps, $args, $user ) {
    if ( ! is_admin() ) return $allcaps;
    if ( ! isset( $user->ID ) || ! $user->ID ) return $allcaps;
    if ( ew_user_is_reviewer() ) return $allcaps;
    if ( ! empty( $allcaps['publish_posts'] ) ) return $allcaps;

    $checking_publish_cap = in_array( 'publish_posts', (array) $caps, true ) || in_array( 'publish_post', (array) $caps, true );
    if ( ! $checking_publish_cap ) return $allcaps;

    $post_id = ew_get_request_post_id( $args );
    if ( ! $post_id ) return $allcaps;

    $post = get_post( $post_id );
    if ( ! $post ) return $allcaps;

    $is_own_approved = (int) $post->post_author === (int) $user->ID
        && get_post_status( $post_id ) === 'approved'
        && in_array( $post->post_type, [ 'post', 'page' ], true )
        && user_can( $user, 'edit_post', $post_id );

    if ( $is_own_approved ) {
        $allcaps['publish_posts'] = true;
    }

    return $allcaps;
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

    $is_resubmission = $status === 'changes_requested';
    $can_resubmit    = ! $is_resubmission || ew_post_is_ready_for_resubmission( $post->ID );

    wp_nonce_field( 'ew_submit_review_' . $post->ID, 'ew_submit_review_nonce' );
    ?>
    <div class="misc-pub-section ew-submit-section">
        <button type="submit" name="ew_submit_for_review" value="1"
                class="button button-primary ew-submit-btn"<?php disabled( $can_resubmit, false ); ?>>
            <?php echo esc_html( $is_resubmission ? '✦ Submit for Review Again' : '✦ Submit for Review' ); ?>
        </button>
        <?php if ( $is_resubmission && ! $can_resubmit ) : ?>
        <p style="margin:8px 0 0;color:#6b7280;">Mark every requested change as done before resubmitting.</p>
        <?php endif; ?>
    </div>
    <?php
}

add_action( 'save_post', 'ew_handle_submit_for_review', 10, 2 );
function ew_handle_submit_for_review( $post_id, $post ) {
    if ( ! isset( $_POST['ew_submit_for_review'] ) ) return;
    if ( ! isset( $_POST['ew_submit_review_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['ew_submit_review_nonce'], 'ew_submit_review_' . $post_id ) ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

    ew_process_submit_for_review( $post_id );
}

function ew_process_submit_for_review( $post_id ) {
    if ( get_post_status( $post_id ) === 'changes_requested' && ! ew_post_is_ready_for_resubmission( $post_id ) ) {
        return new WP_Error( 'changes_incomplete', __( 'Mark every requested change as done before resubmitting.', 'editorial' ) );
    }

    remove_action( 'save_post', 'ew_handle_submit_for_review', 10 );
    wp_update_post( [ 'ID' => $post_id, 'post_status' => 'pending' ] );
    add_action( 'save_post', 'ew_handle_submit_for_review', 10, 2 );

    ew_notify_editors_review_requested( $post_id );

    return true;
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

add_action( 'admin_head-post.php', 'ew_output_change_request_styles' );
add_action( 'admin_head-post-new.php', 'ew_output_change_request_styles' );
function ew_output_change_request_styles() {
    global $post;
    if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;
    ?>
    <style>
        .ew-change-requests { display:flex; flex-direction:column; gap:16px; }
        .ew-change-tabs { display:flex; gap:8px; border-bottom:1px solid #e5e7eb; padding-bottom:10px; }
        .ew-change-tab { border:none; background:none; padding:0 0 8px; cursor:pointer; font-weight:600; color:#6b7280; }
        .ew-change-tab.is-active { color:#111827; box-shadow: inset 0 -2px 0 #111827; }
        .ew-change-panel { display:none; }
        .ew-change-panel.is-active { display:block; }
        .ew-change-batch { border:1px solid #e5e7eb; border-radius:8px; background:#fff; padding:14px; }
        .ew-change-batch.is-active { border-color:#d1d5db; background:#fcfcfd; }
        .ew-change-batch-header { display:flex; justify-content:space-between; gap:12px; margin-bottom:10px; align-items:flex-start; }
        .ew-change-batch-title { margin:0; font-size:13px; font-weight:600; color:#111827; }
        .ew-change-batch-meta { margin:4px 0 0; color:#6b7280; font-size:12px; }
        .ew-change-batch-status { display:inline-flex; align-items:center; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:600; background:#eef2ff; color:#4338ca; }
        .ew-change-list { margin:0; padding:0; list-style:none; display:flex; flex-direction:column; gap:10px; }
        .ew-change-item { border:1px solid #e5e7eb; border-radius:8px; padding:12px; background:#fff; }
        .ew-change-item.is-done { background:#f9fafb; border-color:#d1d5db; }
        .ew-change-item-row { display:flex; gap:10px; align-items:flex-start; }
        .ew-change-item-text { margin:0; color:#111827; white-space:pre-wrap; }
        .ew-change-item-meta { margin:6px 0 0 26px; color:#6b7280; font-size:12px; }
        .ew-change-empty { margin:0; color:#6b7280; }
        .ew-change-actions { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:12px; }
        .ew-change-help { margin:0; color:#6b7280; font-size:12px; }
        .ew-change-note { margin:0 0 12px; color:#374151; }
    </style>
    <?php
}

add_action( 'add_meta_boxes', 'ew_add_feedback_metabox' );
function ew_add_feedback_metabox() {
    global $post;
    if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return;

    add_meta_box( 'ew_feedback_history', 'Editorial Workflow', 'ew_render_feedback_metabox', [ 'post', 'page' ], 'normal', 'high' );
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

    $active_request      = ew_get_active_change_request_comment( $post->ID );
    $active_request_data = ew_get_active_change_request_data( $post->ID );
    $active_request_id   = $active_request ? (int) $active_request->comment_ID : 0;
    $can_mark_done       = ew_current_user_can_resolve_change_requests( $post );
    $current_status      = get_post_status( $post->ID );
    $latest_note         = ew_get_latest_change_request_note( $post->ID );
    $preview_url         = ew_get_preview_url( $post->ID );
    $can_publish         = ew_user_can_publish_post( $post->ID );
    $is_reviewer         = ew_user_is_reviewer();

    wp_nonce_field( 'ew_change_resolution_' . $post->ID, 'ew_change_resolution_nonce' );
    if ( $is_reviewer ) {
        wp_nonce_field( 'ew_review_action_' . $post->ID, 'ew_review_nonce' );
    }

    echo '<div class="ew-change-requests">';
    if ( $is_reviewer ) {
        echo '<div class="ew-change-batch is-active">';
        echo '<div class="ew-change-batch-header">';
        echo '<div>';
        echo '<p class="ew-change-batch-title">Review Actions</p>';
        echo '<p class="ew-change-batch-meta">Status: ' . esc_html( ew_status_label( $current_status ) ) . '</p>';
        echo '</div>';
        if ( $preview_url ) {
            echo '<a href="' . esc_url( $preview_url ) . '" target="_blank" class="button button-small">Open Preview</a>';
        }
        echo '</div>';

        if ( $latest_note ) {
            echo '<p class="ew-change-note"><strong>Latest note:</strong> ' . esc_html( $latest_note ) . '</p>';
        }

        if ( in_array( $current_status, [ 'pending', 'changes_requested' ], true ) ) {
            echo '<textarea name="ew_review_note" id="ew_review_note" placeholder="List each requested change on its own line..." rows="4" style="width:100%;margin-top:12px;"></textarea>';
            echo '<div class="ew-change-actions">';
            echo '<p class="ew-change-help">Approval keeps this post unpublished. Once approved, the writer can publish it.</p>';
            echo '<div>';
            echo '<button type="submit" name="ew_action" value="approve" class="button button-primary ew-btn-approve">Approve</button> ';
            echo '<button type="submit" name="ew_action" value="request_changes" class="button">Request Changes</button>';
            echo '</div>';
            echo '</div>';
        } elseif ( $current_status === 'approved' ) {
            echo '<p class="ew-change-note">' . esc_html( $can_publish ? 'This post is approved and can now be published.' : 'This post is approved, but this user cannot publish it.' ) . '</p>';
        }

        echo '</div>';
    }

    echo '<div class="ew-change-batch is-active">';
    echo '<div class="ew-change-batch-header">';
    echo '<div>';
    echo '<p class="ew-change-batch-title">Open Changes</p>';
    echo '<p class="ew-change-batch-meta">Current status: ' . esc_html( ew_status_label( $current_status ) ) . '</p>';
    echo '</div>';
    if ( ! empty( $active_request_data['items'] ) ) {
        $active_items = $active_request_data['items'];
        $active_open_items = array_values( array_filter( $active_items, fn( $item ) => ( $item['status'] ?? 'open' ) !== 'done' ) );
        echo '<span class="ew-change-batch-status">' . esc_html( count( $active_open_items ) ) . ' open</span>';
    }
    echo '</div>';

    if ( ! empty( $active_request_data['items'] ) ) {
        $items           = $active_request_data['items'];
        $open_items      = array_values( array_filter( $items, fn( $item ) => ( $item['status'] ?? 'open' ) !== 'done' ) );
        $requested_by    = $active_request_data['requested_by'];
        $requested_at    = $active_request_data['requested_at'];
        $request_summary = $active_request_data['summary'];

        if ( $requested_at !== '' ) {
            echo '<p class="ew-change-batch-meta">Requested by ' . esc_html( $requested_by ) . ' on ' . esc_html( $requested_at ) . '</p>';
        } else {
            echo '<p class="ew-change-batch-meta">Requested by ' . esc_html( $requested_by ) . '</p>';
        }

        if ( $request_summary !== '' ) {
            echo '<p class="ew-change-note">' . esc_html( $request_summary ) . '</p>';
        }

        echo '<input type="hidden" name="ew_change_request_id" value="' . esc_attr( $active_request_id ) . '">';
        echo '<ul class="ew-change-list">';
        foreach ( $items as $item ) {
            $item_id      = (string) ( $item['id'] ?? '' );
            $is_done      = ( $item['status'] ?? 'open' ) === 'done';
            $resolved_by  = trim( (string) ( $item['resolved_by'] ?? '' ) );
            $resolved_at  = trim( (string) ( $item['resolved_at'] ?? '' ) );
            $resolved_txt = '';

            if ( $resolved_at !== '' ) {
                $resolved_txt = mysql2date( 'M j, Y g:i a', $resolved_at );
            }

            echo '<li class="ew-change-item' . ( $is_done ? ' is-done' : '' ) . '">';
            echo '<div class="ew-change-item-row">';
            if ( $can_mark_done ) {
                echo '<label>'; 
                echo '<input type="checkbox" name="ew_completed_items[]" value="' . esc_attr( $item_id ) . '"' . checked( $is_done, true, false ) . disabled( $is_done, true, false ) . '>';
                echo '</label>';
            } else {
                echo '<input type="checkbox" disabled' . checked( $is_done, true, false ) . '>';
            }
            echo '<div>';
            echo '<p class="ew-change-item-text">' . esc_html( $item['text'] ?? '' ) . '</p>';
            if ( $is_done ) {
                $meta = 'Marked done';
                if ( $resolved_by !== '' ) {
                    $meta .= ' by ' . $resolved_by;
                }
                if ( $resolved_txt !== '' ) {
                    $meta .= ' on ' . $resolved_txt;
                }
                echo '<p class="ew-change-item-meta">' . esc_html( $meta ) . '</p>';
            }
            echo '</div>';
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';

        if ( $can_mark_done && ! empty( $open_items ) && $current_status === 'changes_requested' ) {
            echo '<div class="ew-change-actions">';
            echo '<p class="ew-change-help">Mark the items you addressed. When all items are done, this post goes back to Pending automatically.</p>';
            echo '<button type="submit" name="ew_update_changes" value="1" class="button button-primary">Mark Selected Done</button>';
            echo '</div>';
        }
    } else {
        if ( $latest_note ) {
            echo '<p class="ew-change-note">' . esc_html( $latest_note ) . '</p>';
        } else {
            echo '<p class="ew-change-empty">No open change requests.</p>';
        }
    }
    echo '</div>';

    echo '<div class="ew-change-batch">';
    echo '<div class="ew-change-batch-header">';
    echo '<div>';
    echo '<p class="ew-change-batch-title">History</p>';
    echo '<p class="ew-change-batch-meta">Previous change requests for this post.</p>';
    echo '</div>';
    echo '</div>';
    if ( empty( $comments ) ) {
        echo '<p class="ew-change-empty">No change-request history yet.</p>';
    } else {
        foreach ( $comments as $comment ) {
            $items        = ew_get_change_request_items( $comment );
            $requested_by = $comment->comment_author ?: 'Editor';
            $requested_at = mysql2date( 'M j, Y g:i a', $comment->comment_date );
            $is_active    = (int) $comment->comment_ID === $active_request_id;
            $is_complete  = ew_change_request_items_are_complete( $items );
            $status_label = $is_active ? 'Active' : ( $is_complete ? 'Resolved' : 'Open' );

            echo '<div class="ew-change-batch' . ( $is_active ? ' is-active' : '' ) . '">';
            echo '<div class="ew-change-batch-header">';
            echo '<div>';
            echo '<p class="ew-change-batch-title">Change Request</p>';
            echo '<p class="ew-change-batch-meta">' . esc_html( $requested_by ) . ' on ' . esc_html( $requested_at ) . '</p>';
            echo '</div>';
            echo '<span class="ew-change-batch-status">' . esc_html( $status_label ) . '</span>';
            echo '</div>';

            if ( trim( (string) $comment->comment_content ) !== '' ) {
                echo '<p class="ew-change-note">' . esc_html( $comment->comment_content ) . '</p>';
            }

            if ( empty( $items ) ) {
                echo '<p class="ew-change-empty">No checklist items recorded.</p>';
            } else {
                echo '<ul class="ew-change-list">';
                foreach ( $items as $item ) {
                    $is_done = ( $item['status'] ?? 'open' ) === 'done';
                    echo '<li class="ew-change-item' . ( $is_done ? ' is-done' : '' ) . '">';
                    echo '<div class="ew-change-item-row">';
                    echo '<input type="checkbox" disabled' . checked( $is_done, true, false ) . '>';
                    echo '<div>';
                    echo '<p class="ew-change-item-text">' . esc_html( $item['text'] ?? '' ) . '</p>';
                    if ( $is_done ) {
                        $meta = 'Marked done';
                        if ( ! empty( $item['resolved_by'] ) ) {
                            $meta .= ' by ' . $item['resolved_by'];
                        }
                        if ( ! empty( $item['resolved_at'] ) ) {
                            $meta .= ' on ' . mysql2date( 'M j, Y g:i a', $item['resolved_at'] );
                        }
                        echo '<p class="ew-change-item-meta">' . esc_html( $meta ) . '</p>';
                    }
                    echo '</div>';
                    echo '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }

            echo '</div>';
        }
    }
    echo '</div>';
    echo '</div>';
}

add_filter( 'wp_insert_post_data', 'ew_intercept_editor_publish', 10, 2 );
function ew_intercept_editor_publish( $data, $postarr ) {
    if ( ! is_admin() ) return $data;
    if ( ! in_array( $data['post_type'] ?? '', [ 'post', 'page' ], true ) ) return $data;

    $target_status = $data['post_status'] ?? '';
    if ( ! ew_user_is_reviewer() && in_array( $target_status, [ 'approved', 'changes_requested' ], true ) ) {
        $current_status = ! empty( $postarr['ID'] ) ? get_post_status( (int) $postarr['ID'] ) : '';
        if ( ! in_array( $current_status, [ 'approved', 'changes_requested' ], true ) ) {
            $data['post_status'] = 'pending';
        }
    }

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

    ew_process_review_action( $post_id, $action, $note );
}

function ew_process_review_action( $post_id, $action, $note ) {
    remove_action( 'save_post', 'ew_handle_review_action', 10 );
    if ( $action === 'approve' ) {
        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'approved' ] );
        update_post_meta( $post_id, '_ew_approved_by', get_current_user_id() );
        update_post_meta( $post_id, '_ew_approved_at', current_time( 'mysql' ) );
    } elseif ( $action === 'request_changes' ) {
        $items = ew_parse_change_request_items( $note );

        if ( empty( $items ) ) {
            add_action( 'save_post', 'ew_handle_review_action', 10, 2 );
            return new WP_Error( 'empty_changes', __( 'Add at least one requested change.', 'editorial' ) );
        }

        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'changes_requested' ] );
        update_post_meta( $post_id, '_ew_review_note', $note );
        update_post_meta( $post_id, '_ew_active_change_items', array_values( $items ) );
        $comment_id = ew_add_change_request_comment( $post_id, $note, $items );
        if ( $comment_id ) {
            update_post_meta( $post_id, '_ew_active_change_request_id', $comment_id );
        }
        ew_notify_author_changes_requested( $post_id, $note );
    }
    add_action( 'save_post', 'ew_handle_review_action', 10, 2 );

    return true;
}

function ew_add_change_request_comment( $post_id, $note, $items = [] ) {
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
        if ( ! empty( $items ) ) {
            add_comment_meta( $comment_id, '_ew_items', array_values( $items ), true );
        }
    }

    return $comment_id;
}

function ew_parse_change_request_items( $note ) {
    $lines = preg_split( '/\r\n|\r|\n/', (string) $note );
    $items = [];
    $index = 1;

    foreach ( $lines as $line ) {
        $text = trim( wp_strip_all_tags( $line ) );
        if ( $text === '' ) continue;

        $items[] = [
            'id'     => 'item_' . $index,
            'text'   => $text,
            'status' => 'open',
        ];
        $index++;
    }

    return $items;
}

function ew_normalize_change_request_item( $item ) {
    if ( ! is_array( $item ) ) return null;

    $text = trim( (string) ( $item['text'] ?? '' ) );
    if ( $text === '' ) return null;

    return [
        'id'               => sanitize_key( $item['id'] ?? 'item_' . wp_rand( 1000, 9999 ) ),
        'text'             => $text,
        'status'           => ( $item['status'] ?? 'open' ) === 'done' ? 'done' : 'open',
        'resolved_by'      => sanitize_text_field( $item['resolved_by'] ?? '' ),
        'resolved_at'      => sanitize_text_field( $item['resolved_at'] ?? '' ),
        'resolved_user_id' => absint( $item['resolved_user_id'] ?? 0 ),
    ];
}

function ew_get_change_request_items( $comment ) {
    $comment_obj = $comment instanceof WP_Comment ? $comment : get_comment( $comment );
    if ( ! $comment_obj ) return [];

    $stored_items = get_comment_meta( $comment_obj->comment_ID, '_ew_items', true );
    if ( ! is_array( $stored_items ) || empty( $stored_items ) ) {
        return ew_parse_change_request_items( $comment_obj->comment_content );
    }

    $items = array_map( 'ew_normalize_change_request_item', $stored_items );
    $items = array_values( array_filter( $items ) );

    return $items;
}

function ew_update_change_request_items( $comment_id, $items ) {
    update_comment_meta( $comment_id, '_ew_items', array_values( $items ) );
}

function ew_get_active_change_request_items_meta( $post_id ) {
    $items = get_post_meta( $post_id, '_ew_active_change_items', true );
    if ( ! is_array( $items ) || empty( $items ) ) return [];

    $items = array_map( 'ew_normalize_change_request_item', $items );
    return array_values( array_filter( $items ) );
}

function ew_update_active_change_request_items_meta( $post_id, $items ) {
    update_post_meta( $post_id, '_ew_active_change_items', array_values( $items ) );
}

function ew_change_request_items_are_complete( $items ) {
    if ( empty( $items ) ) return false;

    foreach ( $items as $item ) {
        if ( ( $item['status'] ?? 'open' ) !== 'done' ) {
            return false;
        }
    }

    return true;
}

function ew_get_tracked_change_request_comment( $post_id ) {
    $active_comment_id = absint( get_post_meta( $post_id, '_ew_active_change_request_id', true ) );
    if ( ! $active_comment_id ) return null;

    $active_comment = get_comment( $active_comment_id );
    if ( ! $active_comment ) return null;
    if ( (int) $active_comment->comment_post_ID !== (int) $post_id ) return null;
    if ( $active_comment->comment_type !== 'editorial_change_request' ) return null;

    return $active_comment;
}

function ew_post_is_ready_for_resubmission( $post_id ) {
    $items = ew_get_active_change_request_items_meta( $post_id );
    if ( empty( $items ) ) {
        $active_comment = ew_get_tracked_change_request_comment( $post_id );
        if ( ! $active_comment ) return false;

        $items = ew_get_change_request_items( $active_comment );
    }

    return ew_change_request_items_are_complete( $items );
}

function ew_get_active_change_request_data( $post_id ) {
    $active_comment = ew_get_active_change_request_comment( $post_id );
    if ( $active_comment ) {
        return [
            'items'        => ew_get_change_request_items( $active_comment ),
            'summary'      => trim( (string) $active_comment->comment_content ),
            'requested_by' => $active_comment->comment_author ?: 'Editor',
            'requested_at' => mysql2date( 'M j, Y g:i a', $active_comment->comment_date ),
        ];
    }

    $items = ew_get_active_change_request_items_meta( $post_id );
    $summary = trim( (string) get_post_meta( $post_id, '_ew_review_note', true ) );
    if ( $summary === '' ) {
        $summary = trim( (string) ew_get_latest_change_request_note( $post_id ) );
    }

    if ( empty( $items ) && get_post_status( $post_id ) === 'changes_requested' ) {
        $items = ew_parse_change_request_items( $summary );
        if ( ! empty( $items ) ) {
            ew_update_active_change_request_items_meta( $post_id, $items );
        }
    }

    return [
        'items'        => $items,
        'summary'      => $summary,
        'requested_by' => 'Editor',
        'requested_at' => '',
    ];
}

function ew_recover_active_change_request_comment( $post_id ) {
    if ( get_post_status( $post_id ) !== 'changes_requested' ) return null;

    $latest_note = trim( (string) get_post_meta( $post_id, '_ew_review_note', true ) );
    if ( $latest_note === '' ) {
        $latest_comment_note = ew_get_latest_change_request_note( $post_id );
        $latest_note = trim( (string) $latest_comment_note );
    }

    $items = ew_parse_change_request_items( $latest_note );
    if ( empty( $items ) ) return null;

    $post   = get_post( $post_id );
    $author = $post ? get_userdata( $post->post_author ) : null;

    $comment_id = wp_insert_comment( [
        'comment_post_ID'      => $post_id,
        'comment_content'      => $latest_note,
        'comment_type'         => 'editorial_change_request',
        'comment_approved'     => 1,
        'user_id'              => 0,
        'comment_author'       => $author ? $author->display_name : 'Editorial Workflow',
        'comment_author_email' => $author ? $author->user_email : '',
    ] );

    if ( ! $comment_id ) return null;

    add_comment_meta( $comment_id, '_ew_internal', 1, true );
    add_comment_meta( $comment_id, '_ew_items', array_values( $items ), true );
    update_post_meta( $post_id, '_ew_active_change_request_id', $comment_id );

    return get_comment( $comment_id );
}

function ew_get_active_change_request_comment( $post_id ) {
    $active_comment = ew_get_tracked_change_request_comment( $post_id );
    if ( $active_comment ) {
        $items = ew_get_change_request_items( $active_comment );
        if ( ! empty( $items ) ) {
            if ( ! ew_change_request_items_are_complete( $items ) ) {
                return $active_comment;
            }

            if ( get_post_status( $post_id ) === 'changes_requested' ) {
                return $active_comment;
            }
        }
    }

    $comments = get_comments( [
        'post_id' => $post_id,
        'type'    => 'editorial_change_request',
        'orderby' => 'comment_date_gmt',
        'order'   => 'DESC',
        'status'  => 'approve',
        'number'  => 20,
    ] );

    foreach ( $comments as $comment ) {
        $items = ew_get_change_request_items( $comment );
        if ( ! empty( $items ) && ! ew_change_request_items_are_complete( $items ) ) {
            update_post_meta( $post_id, '_ew_active_change_request_id', $comment->comment_ID );
            return $comment;
        }
    }

    $recovered_comment = ew_recover_active_change_request_comment( $post_id );
    if ( $recovered_comment ) {
        return $recovered_comment;
    }

    if ( get_post_status( $post_id ) !== 'changes_requested' ) {
        delete_post_meta( $post_id, '_ew_active_change_request_id' );
    }
    return null;
}

function ew_current_user_can_resolve_change_requests( $post ) {
    $user_id = get_current_user_id();
    if ( ! $user_id ) return false;
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return false;

    return (int) $post->post_author === (int) $user_id || current_user_can( 'manage_options' );
}

add_action( 'save_post', 'ew_handle_change_resolution', 10, 2 );
function ew_handle_change_resolution( $post_id, $post ) {
    if ( ! isset( $_POST['ew_update_changes'] ) ) return;
    if ( ! isset( $_POST['ew_change_resolution_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['ew_change_resolution_nonce'], 'ew_change_resolution_' . $post_id ) ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
    if ( ! ew_current_user_can_resolve_change_requests( $post ) ) return;

    $submitted_request_id = absint( $_POST['ew_change_request_id'] ?? 0 );
    $completed_ids        = array_map( 'sanitize_key', (array) ( $_POST['ew_completed_items'] ?? [] ) );

    ew_process_change_resolution( $post_id, $submitted_request_id, $completed_ids );
}

function ew_process_change_resolution( $post_id, $submitted_request_id, $completed_ids ) {
    $active_request = ew_get_active_change_request_comment( $post_id );
    $items          = ew_get_active_change_request_items_meta( $post_id );

    if ( empty( $items ) && $active_request ) {
        $items = ew_get_change_request_items( $active_request );
    }

    if ( empty( $items ) ) {
        return new WP_Error( 'missing_request', __( 'No active change request was found.', 'editorial' ) );
    }

    if ( $active_request && $submitted_request_id !== (int) $active_request->comment_ID ) {
        return new WP_Error( 'request_mismatch', __( 'The active change request changed. Reload and try again.', 'editorial' ) );
    }

    $user       = wp_get_current_user();
    $did_change = false;

    foreach ( $items as &$item ) {
        if ( ( $item['status'] ?? 'open' ) === 'done' ) continue;
        if ( ! in_array( $item['id'], $completed_ids, true ) ) continue;

        $item['status']           = 'done';
        $item['resolved_by']      = $user->display_name;
        $item['resolved_at']      = current_time( 'mysql' );
        $item['resolved_user_id'] = $user->ID;
        $did_change               = true;
    }
    unset( $item );

    if ( ! $did_change ) {
        return new WP_Error( 'no_items_selected', __( 'Select at least one change item to mark done.', 'editorial' ) );
    }

    ew_update_active_change_request_items_meta( $post_id, $items );
    if ( $active_request ) {
        ew_update_change_request_items( $active_request->comment_ID, $items );
    }

    return true;
}

add_action( 'wp_ajax_ew_submit_for_review', 'ew_ajax_submit_for_review' );
function ew_ajax_submit_for_review() {
    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id ) {
        wp_send_json_error( [ 'message' => __( 'Missing post ID.', 'editorial' ) ], 400 );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) || ew_user_is_reviewer() ) {
        wp_send_json_error( [ 'message' => __( 'You cannot submit this post for review.', 'editorial' ) ], 403 );
    }

    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ew_submit_review_' . $post_id ) ) {
        wp_send_json_error( [ 'message' => __( 'Security check failed.', 'editorial' ) ], 403 );
    }

    $result = ew_process_submit_for_review( $post_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
    }

    wp_send_json_success( [ 'redirect' => get_edit_post_link( $post_id, 'raw' ) ] );
}

add_action( 'wp_ajax_ew_review_action', 'ew_ajax_review_action' );
function ew_ajax_review_action() {
    $post_id = absint( $_POST['post_id'] ?? 0 );
    $action  = sanitize_text_field( $_POST['ew_action'] ?? '' );
    $note    = sanitize_textarea_field( $_POST['ew_review_note'] ?? '' );

    if ( ! $post_id ) {
        wp_send_json_error( [ 'message' => __( 'Missing post ID.', 'editorial' ) ], 400 );
    }

    if ( ! ew_user_is_reviewer() || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( [ 'message' => __( 'You cannot review this post.', 'editorial' ) ], 403 );
    }

    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ew_review_action_' . $post_id ) ) {
        wp_send_json_error( [ 'message' => __( 'Security check failed.', 'editorial' ) ], 403 );
    }

    $result = ew_process_review_action( $post_id, $action, $note );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
    }

    wp_send_json_success( [ 'redirect' => get_edit_post_link( $post_id, 'raw' ) ] );
}

add_action( 'wp_ajax_ew_update_changes', 'ew_ajax_update_changes' );
function ew_ajax_update_changes() {
    $post_id               = absint( $_POST['post_id'] ?? 0 );
    $submitted_request_id  = absint( $_POST['ew_change_request_id'] ?? 0 );
    $completed_ids         = array_map( 'sanitize_key', (array) ( $_POST['ew_completed_items'] ?? [] ) );
    $post                  = get_post( $post_id );

    if ( ! $post_id || ! $post ) {
        wp_send_json_error( [ 'message' => __( 'Missing post.', 'editorial' ) ], 400 );
    }

    if ( ! ew_current_user_can_resolve_change_requests( $post ) ) {
        wp_send_json_error( [ 'message' => __( 'You cannot resolve these change requests.', 'editorial' ) ], 403 );
    }

    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ew_change_resolution_' . $post_id ) ) {
        wp_send_json_error( [ 'message' => __( 'Security check failed.', 'editorial' ) ], 403 );
    }

    $result = ew_process_change_resolution( $post_id, $submitted_request_id, $completed_ids );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
    }

    wp_send_json_success( [ 'redirect' => get_edit_post_link( $post_id, 'raw' ) ] );
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
                var buttonLabel = (isReviewer || canPublish) ? 'Publish' : 'Submit for Review';
                $publishButton.val(buttonLabel).text(buttonLabel);
            }

            updatePublishButton();

            $('#post_status').on('change', updatePublishButton);
        }
    });
    </script>
    <?php
}

add_action( 'enqueue_block_editor_assets', 'ew_enqueue_block_editor_workflow_actions' );
function ew_enqueue_block_editor_workflow_actions() {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->post_type, [ 'post', 'page' ], true ) ) return;

    wp_add_inline_script( 'jquery', <<<'JS'
jQuery(function($){
    if ( typeof wp === 'undefined' || ! wp.data || ! wp.data.select || ! wp.data.dispatch ) {
        return;
    }

    var editorStore = wp.data.select('core/editor');
    var editorDispatch = wp.data.dispatch('core/editor');
    var noticesDispatch = wp.data.dispatch('core/notices');

    function getPostId() {
        return editorStore.getCurrentPostId() || Number($('#post_ID').val() || 0);
    }

    function waitForSaveToFinish() {
        return new Promise(function(resolve, reject){
            var unsubscribe = wp.data.subscribe(function(){
                if ( editorStore.isSavingPost() ) {
                    return;
                }

                unsubscribe();

                if ( editorStore.didPostSaveRequestSucceed && ! editorStore.didPostSaveRequestSucceed() ) {
                    reject(new Error('WordPress could not save this post.'));
                    return;
                }

                resolve();
            });
        });
    }

    async function ensurePostSaved() {
        if ( editorStore.isSavingPost() ) {
            await waitForSaveToFinish();
            return;
        }

        if ( ! editorStore.isEditedPostDirty() ) {
            return;
        }

        editorDispatch.savePost();
        await waitForSaveToFinish();
    }

    function showError(message) {
        if ( noticesDispatch && noticesDispatch.createErrorNotice ) {
            noticesDispatch.createErrorNotice(message, { type: 'snackbar' });
            return;
        }

        window.alert(message);
    }

    async function performAction(payload) {
        try {
            await ensurePostSaved();
        } catch (error) {
            showError(error.message || 'WordPress could not save this post.');
            return;
        }

        var postId = getPostId();
        if ( ! postId ) {
            showError('Save the post once before using editorial workflow actions.');
            return;
        }

        payload.post_id = postId;

        $.post(ajaxurl, payload)
            .done(function(response){
                if ( response && response.success && response.data && response.data.redirect ) {
                    window.location = response.data.redirect;
                    return;
                }

                window.location.reload();
            })
            .fail(function(xhr){
                var response = xhr.responseJSON || {};
                var message = response.data && response.data.message ? response.data.message : 'Editorial workflow action failed.';
                showError(message);
            });
    }

    $(document).on('click', '.ew-submit-btn', function(event){
        event.preventDefault();

        performAction({
            action: 'ew_submit_for_review',
            nonce: $('input[name="ew_submit_review_nonce"]').val() || ''
        });
    });

    $(document).on('click', 'button[name="ew_action"]', function(event){
        event.preventDefault();

        performAction({
            action: 'ew_review_action',
            ew_action: $(this).val(),
            ew_review_note: $('#ew_review_note').val() || '',
            nonce: $('input[name="ew_review_nonce"]').val() || ''
        });
    });

    $(document).on('click', 'button[name="ew_update_changes"]', function(event){
        event.preventDefault();

        performAction({
            action: 'ew_update_changes',
            ew_change_request_id: $('input[name="ew_change_request_id"]').val() || '',
            ew_completed_items: $('input[name="ew_completed_items[]"]:checked').map(function(){ return $(this).val(); }).get(),
            nonce: $('input[name="ew_change_resolution_nonce"]').val() || ''
        });
    });
});
JS
    );
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
