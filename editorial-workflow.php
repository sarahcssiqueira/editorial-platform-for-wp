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

/**
 * Bootstraps the plugin and keeps feature files organized by responsibility.
 */
final class Editorial_Plugin_Bootstrap {

    /**
     * Singleton instance.
     *
     * @var Editorial_Plugin_Bootstrap|null
     */
    private static $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Editorial_Plugin_Bootstrap
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Loads supporting files and initializes the plugin classes.
     *
     * @return void
     */
    public function boot() {
        require_once __DIR__ . '/editorial-admin-theme.php';

        if ( class_exists( 'Editorial_Admin_Theme' ) ) {
            Editorial_Admin_Theme::init();
        }

        Editorial_Workflow::instance();
    }
}

/**
 * Handles editorial workflow features.
 */
final class Editorial_Workflow {

    /**
     * Singleton instance.
     *
     * @var Editorial_Workflow|null
     */
    private static $instance = null;

    /**
     * Returns singleton instance.
     *
     * @return Editorial_Workflow
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->register_hooks();
        }

        return self::$instance;
    }

    /**
     * Registers all plugin hooks.
     *
     * @return void
     */
    private function register_hooks() {
        add_action( 'init', array( $this, 'ew_register_post_statuses' ) );
        add_action( 'init', array( $this, 'ew_set_role_capabilities' ) );
        add_filter( 'user_has_cap', array( $this, 'ew_grant_publish_cap_for_approved_posts' ), 10, 4 );
        add_action( 'post_submitbox_misc_actions', array( $this, 'ew_submit_for_review_button' ) );
        add_action( 'save_post', array( $this, 'ew_handle_submit_for_review' ), 10, 2 );
        add_action( 'transition_post_status', array( $this, 'ew_notify_editors_when_pending' ), 10, 3 );
        add_action( 'admin_head-post.php', array( $this, 'ew_output_change_request_styles' ) );
        add_action( 'admin_head-post-new.php', array( $this, 'ew_output_change_request_styles' ) );
        add_action( 'add_meta_boxes', array( $this, 'ew_add_feedback_metabox' ) );
        add_filter( 'wp_insert_post_data', array( $this, 'ew_intercept_editor_publish' ), 10, 2 );
        add_action( 'save_post', array( $this, 'ew_handle_review_action' ), 10, 2 );
        add_action( 'save_post', array( $this, 'ew_handle_change_resolution' ), 10, 2 );
        add_action( 'wp_ajax_ew_submit_for_review', array( $this, 'ew_ajax_submit_for_review' ) );
        add_action( 'wp_ajax_ew_review_action', array( $this, 'ew_ajax_review_action' ) );
        add_action( 'wp_ajax_ew_update_changes', array( $this, 'ew_ajax_update_changes' ) );
        add_action( 'pre_get_posts', array( $this, 'ew_allow_public_preview_query' ) );
        add_filter( 'posts_results', array( $this, 'ew_filter_public_preview_results' ), 10, 2 );
        add_filter( 'redirect_canonical', array( $this, 'ew_disable_public_preview_canonical' ), 10, 2 );
        add_action( 'wp_head', array( $this, 'ew_public_preview_noindex' ) );
        add_action( 'transition_post_status', array( $this, 'ew_clear_cache_on_publish' ), 10, 3 );
        add_filter( 'display_post_states', array( $this, 'ew_display_post_states' ), 10, 2 );
        add_action( 'admin_footer-post.php', array( $this, 'ew_inject_status_js' ) );
        add_action( 'admin_footer-post-new.php', array( $this, 'ew_inject_status_js' ) );
        add_action( 'enqueue_block_editor_assets', array( $this, 'ew_enqueue_block_editor_workflow_actions' ) );
    }

/**
 * Configuration.
 *
 * Set EDITORIAL_SLACK_WEBHOOK in wp-config.php to enable Slack notifications.
 *
 * Example:
 * define( 'EDITORIAL_SLACK_WEBHOOK', 'https://hooks.slack.com/services/XXX/YYY/ZZZ' );
 */


/**
 * 1. Custom post statuses.
 */

/**
 * Registers custom post statuses used by the editorial workflow.
 *
 * @return void
 */
public function ew_register_post_statuses() {

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


/**
 * 2. Role capabilities.
 */

/**
 * Gets the role slugs treated as content authors.
 *
 * @return string[]
 */
public function ew_get_writer_role_slugs() {
    return [ 'author' ];
}

/**
 * Gets the role slugs treated as editors and reviewers.
 *
 * @return string[]
 */
public function ew_get_editor_role_slugs() {
    return [ 'editor', 'administrator' ];
}

/**
 * Determines whether a user has any of the provided role slugs.
 *
 * @param WP_User|stdClass|null $user User object to check.
 * @param string[]              $roles  Role slugs to match against.
 *
 * @return bool True if the user has at least one matching role.
 */
public function ew_user_has_any_role( $user, $roles ) {
    $user_roles = (array) ( $user->roles ?? [] );
    return (bool) array_intersect( $user_roles, $roles );
}

/**
 * Determines whether a user is a writer-role user.
 *
 * @param WP_User|stdClass|null $user User object to inspect. Defaults to the current user.
 *
 * @return bool True when the user belongs to a writer role.
 */
public function ew_user_is_writer( $user = null ) {
    $user = $user ?: wp_get_current_user();
    return ew_user_has_any_role( $user, ew_get_writer_role_slugs() );
}

/**
 * Adjusts publish capabilities for writer and editor roles during init.
 *
 * @return void
 */
public function ew_set_role_capabilities() {
    foreach ( ew_get_writer_role_slugs() as $writer_role_slug ) {
        $writer_role = get_role( $writer_role_slug );
        if ( $writer_role ) {
            /**
             * Writers should not publish directly; publishing is unlocked only after approval.
             */
            $writer_role->remove_cap( 'publish_posts' );
        }
    }

    foreach ( ew_get_editor_role_slugs() as $editor_role_slug ) {
        $editor_role = get_role( $editor_role_slug );
        if ( $editor_role ) {
            $editor_role->add_cap( 'publish_posts' );
            $editor_role->add_cap( 'edit_others_posts' );
        }
    }
}

/**
 * Determines whether the current user is an editor or administrator.
 *
 * @return bool True if the current user is a reviewer-level user.
 */
public function ew_user_is_reviewer() {
    return ew_user_has_any_role( wp_get_current_user(), ew_get_editor_role_slugs() );
}

/**
 * Determines whether the current user may publish the supplied post.
 *
 * @param int $post_id Post ID to check.
 *
 * @return bool True when the current user is allowed to publish the item.
 */
public function ew_user_can_publish_post( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) return false;
    if ( ew_user_is_reviewer() ) return current_user_can( 'publish_posts' );

    return get_post_status( $post_id ) === 'approved'
        && (int) $post->post_author === (int) get_current_user_id()
        && current_user_can( 'edit_post', $post_id );
}

/**
 * Finds the current post ID from capability arguments or the edit request.
 *
 * @param array $args Capability arguments or request metadata.
 *
 * @return int Post ID if found, otherwise 0.
 */
public function ew_get_request_post_id( $args = [] ) {
    if ( isset( $args[2] ) && is_numeric( $args[2] ) ) {
        return (int) $args[2];
    }

    if ( isset( $_POST['post_ID'] ) && is_numeric( $_POST['post_ID'] ) ) {
        return (int) $_POST['post_ID'];
    }

    if ( isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) {
        return (int) $_GET['post'];
    }

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if ( preg_match( '#/wp/v2/(?:posts|pages)/(\d+)(?:/|\?|$)#', $request_uri, $matches ) ) {
        return (int) $matches[1];
    }

    return 0;
}

/**
 * Grants publish capability to authors whose post has been approved.
 *
 * @param array    $allcaps Capability map for the current user.
 * @param string[] $caps    Requested capabilities being checked.
 * @param array    $args    Capability-check arguments.
 * @param WP_User  $user    User object being evaluated.
 *
 * @return array Updated capability map.
 */
public function ew_grant_publish_cap_for_approved_posts( $allcaps, $caps, $args, $user ) {
    if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return $allcaps;
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

/**
 * Renders the Submit for Review action in the post submit metabox.
 *
 * @param WP_Post $post Post object for the current edit screen.
 *
 * @return void
 */
public function ew_submit_for_review_button( $post ) {
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

/**
 * Handles the submit-for-review action when the post is saved.
 *
 * @param int     $post_id Post ID being saved.
 * @param WP_Post $post    Post object being saved.
 *
 * @return void
 */
public function ew_handle_submit_for_review( $post_id, $post ) {
    if ( ! isset( $_POST['ew_submit_for_review'] ) ) return;
    if ( ! isset( $_POST['ew_submit_review_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['ew_submit_review_nonce'], 'ew_submit_review_' . $post_id ) ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

    ew_process_submit_for_review( $post_id );
}

/**
 * Moves a post to pending and notifies reviewers.
 *
 * @param int $post_id Post ID to submit.
 *
 * @return bool|WP_Error True on success, or WP_Error when resubmission is blocked.
 */
public function ew_process_submit_for_review( $post_id ) {
    if ( get_post_status( $post_id ) === 'changes_requested' && ! ew_post_is_ready_for_resubmission( $post_id ) ) {
        return new WP_Error( 'changes_incomplete', __( 'Mark every requested change as done before resubmitting.', 'editorial' ) );
    }

    remove_action( 'save_post', array( $this, 'ew_handle_submit_for_review' ), 10 );
    wp_update_post( [ 'ID' => $post_id, 'post_status' => 'pending' ] );
    add_action( 'save_post', array( $this, 'ew_handle_submit_for_review' ), 10, 2 );

    ew_notify_editors_review_requested( $post_id );

    return true;
}

/**
 * Notifies editors when a post transitions to pending outside the direct review flow.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object being transitioned.
 *
 * @return void
 */
public function ew_notify_editors_when_pending( $new_status, $old_status, $post ) {
    if ( $new_status !== 'pending' || $old_status === 'pending' ) return;
    if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;

    /**
     * Prevent duplicate notifications when submit-for-review handler already sent one.
     */
    if ( did_action( 'save_post' ) > 0 && isset( $_POST['ew_submit_for_review'] ) ) return;

    ew_notify_editors_review_requested( $post->ID );
}


/**
 * Emails all editors and pings Slack when a post is submitted for review.
 *
 * @param int $post_id Post ID to notify about.
 *
 * @return void
 */
public function ew_notify_editors_review_requested( $post_id ) {
    $post     = get_post( $post_id );
    if ( ! $post ) return;

    $author   = get_userdata( $post->post_author );
    $author_name = $author ? $author->display_name : __( 'An author', 'editorial' );
    $edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
    $preview  = ew_get_public_preview_url( $post_id );
    $reviewers = get_users( [
        'role__in' => ew_get_editor_role_slugs(),
        'fields'   => [ 'user_email' ],
    ] );
    $to = array_values( array_unique( array_filter( array_map( fn( $u ) => $u->user_email, $reviewers ) ) ) );

    if ( ! empty( $to ) ) {
        ew_send_email_notification(
            $to,
            sprintf( '[Review Needed] %s', $post->post_title ),
            sprintf( "Hi,\n\n%s submitted \"%s\" for review.\n\nPreview: %s\nEdit: %s\n\nLog in to approve or send it back for revision.",
                $author_name, $post->post_title, $preview, $edit_url )
        );
    }

    ew_slack_notify( sprintf( '✦ *Review needed:* <%s|%s> by %s — <%s|Preview>', $edit_url, $post->post_title, $author_name, $preview ) );
}

/**
 * Notifies the post author when an editor approves a post.
 *
 * @param int $post_id Post ID that was approved.
 *
 * @return void
 */
public function ew_notify_author_approved( $post_id ) {
    $post   = get_post( $post_id );
    $author = $post ? get_userdata( $post->post_author ) : false;
    if ( ! $post ) return;

    $editor      = wp_get_current_user();
    $edit_url    = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
    $preview     = ew_get_public_preview_url( $post_id );
    $author_name = $author ? $author->display_name : __( 'the author', 'editorial' );
    $editor_name = $editor && $editor->exists() ? $editor->display_name : __( 'An editor', 'editorial' );

    if ( $author && ! empty( $author->user_email ) ) {
        ew_send_email_notification(
            $author->user_email,
            sprintf( '[Approved] %s', $post->post_title ),
            sprintf( "Hi %s,\n\n%s approved \"%s\". You can now publish it.\n\nPreview: %s\nEdit: %s",
                $author_name, $editor_name, $post->post_title, $preview, $edit_url )
        );
    }
    ew_slack_notify( sprintf( '✓ *Approved:* <%s|%s> for %s by %s', $edit_url, $post->post_title, $author_name, $editor_name ) );
}

/**
 * Emails the post author when an editor requests changes.
 *
 * @param int    $post_id Post ID associated with the request.
 * @param string $note    Review note describing requested changes.
 *
 * @return void
 */
public function ew_notify_author_changes_requested( $post_id, $note ) {
    $post     = get_post( $post_id );
    if ( ! $post ) return;

    $author   = get_userdata( $post->post_author );
    $editor   = wp_get_current_user();
    $edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
    $preview  = ew_get_public_preview_url( $post_id );
    $author_name = $author ? $author->display_name : __( 'the author', 'editorial' );

    if ( $author && ! empty( $author->user_email ) ) {
        ew_send_email_notification(
            $author->user_email,
            sprintf( '[Changes Requested] %s', $post->post_title ),
            sprintf( "Hi %s,\n\n%s reviewed \"%s\" and requested changes:\n\n—\n%s\n—\n\nPreview: %s\nEdit: %s",
                $author_name, $editor->display_name, $post->post_title, $note, $preview, $edit_url )
        );
    }
    ew_slack_notify( sprintf( '↩ *Changes requested:* <%s|%s> by %s\n> %s', $edit_url, $post->post_title, $editor->display_name, $note ) );
}

/**
 * Sends an editorial email and logs failures when WordPress debugging is enabled.
 *
 * @param string|array $to      Recipient address or addresses.
 * @param string       $subject Email subject.
 * @param string       $message Plain-text email body.
 *
 * @return bool Whether WordPress accepted the email for delivery.
 */
public function ew_send_email_notification( $to, $subject, $message ) {
    $sent = wp_mail( $to, $subject, $message );

    if ( ! $sent && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Editorial email notification failed: ' . $subject );
    }

    return (bool) $sent;
}

/**
 * Sends a notification message to the configured Slack webhook.
 *
 * @param string $message Message to send.
 *
 * @return void
 */
public function ew_slack_notify( $message ) {
    if ( ! defined( 'EDITORIAL_SLACK_WEBHOOK' ) ) return;

    $response = wp_remote_post( EDITORIAL_SLACK_WEBHOOK, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [ 'text' => $message ] ),
        'timeout' => 4,
    ] );

    /**
     * Keep editorial actions resilient: only log webhook issues in debug mode.
     */
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;

    if ( is_wp_error( $response ) ) {
        error_log( 'Editorial Slack notify failed: ' . $response->get_error_message() );
        return;
    }

    $status_code = (int) wp_remote_retrieve_response_code( $response );
    if ( $status_code < 200 || $status_code >= 300 ) {
        $body = (string) wp_remote_retrieve_body( $response );
        error_log( 'Editorial Slack notify returned HTTP ' . $status_code . '. Body: ' . substr( $body, 0, 300 ) );
    }
}


/**
 * Outputs the admin CSS for the change-request UI on post edit screens.
 *
 * @return void
 */
public function ew_output_change_request_styles() {
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
        .ew-share-preview { border:1px solid #e5e7eb; background:#f8fafc; border-radius:8px; padding:12px; }
        .ew-share-preview-title { margin:0 0 4px; font-size:12px; font-weight:700; color:#111827; }
        .ew-share-preview-help { margin:0 0 8px; font-size:12px; color:#6b7280; }
        .ew-share-preview-row { display:flex; gap:8px; align-items:center; }
        .ew-share-preview-input { flex:1; font-size:12px; padding:6px 8px; border:1px solid #d1d5db; border-radius:6px; background:#fff; }
        .ew-share-preview-copy { white-space:nowrap; }
    </style>
    <?php
}

/**
 * Registers the Editorial Workflow meta box on post and page edit screens.
 *
 * @return void
 */
public function ew_add_feedback_metabox() {
    global $post;
    if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return;

    add_meta_box( 'ew_feedback_history', 'Editorial Workflow', 'ew_render_feedback_metabox', [ 'post', 'page' ], 'normal', 'high' );
}

/**
 * Renders the editorial feedback meta box for the current post.
 *
 * @param WP_Post $post Post object being edited.
 *
 * @return void
 */
public function ew_render_feedback_metabox( $post ) {
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
    $public_preview_url  = ew_get_public_preview_url( $post->ID );
    $can_publish         = ew_user_can_publish_post( $post->ID );
    $is_reviewer         = ew_user_is_reviewer();

    wp_nonce_field( 'ew_change_resolution_' . $post->ID, 'ew_change_resolution_nonce' );
    if ( $is_reviewer ) {
        wp_nonce_field( 'ew_review_action_' . $post->ID, 'ew_review_nonce' );
    }

    echo '<div class="ew-change-requests">';
    if ( $public_preview_url ) {
        echo '<div class="ew-share-preview">';
        echo '<p class="ew-share-preview-title">Share Public Preview</p>';
        echo '<p class="ew-share-preview-help">Anyone with this link can preview the post without logging in.</p>';
        echo '<div class="ew-share-preview-row">';
        echo '<input type="text" class="ew-share-preview-input" readonly value="' . esc_attr( $public_preview_url ) . '">';
        echo '<button type="button" class="button ew-share-preview-copy" data-preview-url="' . esc_attr( $public_preview_url ) . '">Copy Link</button>';
        echo '</div>';
        echo '</div>';
    }

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
            echo '<p class="ew-change-help">Approval keeps this post unpublished. Once approved, the author can publish it.</p>';
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

/**
 * Prevents non-reviewers from bypassing the approval gate when saving.
 *
 * @param array $data    Post data array before insert/update.
 * @param array $postarr Raw post array from the save request.
 *
 * @return array Filtered post data.
 */
public function ew_intercept_editor_publish( $data, $postarr ) {
    if ( ! is_admin() ) return $data;
    if ( ! in_array( $data['post_type'] ?? '', [ 'post', 'page' ], true ) ) return $data;

    $target_status = $data['post_status'] ?? '';
    if ( ! ew_user_is_reviewer() && ! empty( $postarr['ID'] ) ) {
        $post_id         = (int) $postarr['ID'];
        $current_status  = get_post_status( $post_id );

        if ( $target_status === 'approved' ) {
            /**
             * Authors can keep already-approved posts approved while editing,
             * but they must never promote a changes-requested post to approved.
             */
            if ( $current_status !== 'approved' ) {
                $data['post_status'] = $current_status === 'changes_requested' ? 'changes_requested' : 'pending';
            }
        } elseif ( $target_status === 'changes_requested' && $current_status !== 'changes_requested' ) {
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

/**
 * Handles the approve/request-change action on save_post.
 *
 * @param int     $post_id Post ID being saved.
 * @param WP_Post $post    Post object being saved.
 *
 * @return void
 */
public function ew_handle_review_action( $post_id, $post ) {
    if ( ! isset( $_POST['ew_action'] ) ) return;
    if ( ! isset( $_POST['ew_review_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['ew_review_nonce'], 'ew_review_action_' . $post_id ) ) return;
    if ( ! ew_user_is_reviewer() ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

    $action = sanitize_text_field( $_POST['ew_action'] ?? '' );
    $note   = sanitize_textarea_field( $_POST['ew_review_note'] ?? '' );

    ew_process_review_action( $post_id, $action, $note );
}

/**
 * Applies an approval or revision request to the post.
 *
 * @param int    $post_id Post ID to update.
 * @param string $action  Action to apply: approve or request_changes.
 * @param string $note    Reviewer note for revision requests.
 *
 * @return bool|WP_Error True on success, or WP_Error when validation fails.
 */
public function ew_process_review_action( $post_id, $action, $note ) {
    remove_action( 'save_post', array( $this, 'ew_handle_review_action' ), 10 );
    if ( $action === 'approve' ) {
        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'approved' ] );
        update_post_meta( $post_id, '_ew_approved_by', get_current_user_id() );
        update_post_meta( $post_id, '_ew_approved_at', current_time( 'mysql' ) );
        ew_notify_author_approved( $post_id );
    } elseif ( $action === 'request_changes' ) {
        $items = ew_parse_change_request_items( $note );

        if ( empty( $items ) ) {
            add_action( 'save_post', array( $this, 'ew_handle_review_action' ), 10, 2 );
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
    add_action( 'save_post', array( $this, 'ew_handle_review_action' ), 10, 2 );

    return true;
}

/**
 * Stores a change-request comment for the post and attaches item metadata.
 *
 * @param int    $post_id Post ID to attach the comment to.
 * @param string $note    Review summary and requested-change text.
 * @param array  $items   Optional checklist items for the request.
 *
 * @return int|false Comment ID on success, otherwise false.
 */
public function ew_add_change_request_comment( $post_id, $note, $items = [] ) {
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

/**
 * Splits a freeform change-request note into individual checklist items.
 *
 * @param string $note Review note text to parse.
 *
 * @return array<int, array<string, string>> Parsed checklist items.
 */
public function ew_parse_change_request_items( $note ) {
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

/**
 * Sanitizes and normalizes a single change-request item array.
 *
 * @param array $item Change-request item array to normalize.
 *
 * @return array|null Normalized item array, or null when the item is invalid.
 */
public function ew_normalize_change_request_item( $item ) {
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

/**
 * Returns the normalized items stored for a given change-request comment.
 *
 * @param int|WP_Comment $comment Comment ID or WP_Comment object.
 *
 * @return array<int, array<string, mixed>> Normalized checklist items.
 */
public function ew_get_change_request_items( $comment ) {
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

/**
 * Persists updated item data on a change-request comment.
 *
 * @param int   $comment_id Comment ID to update.
 * @param array $items      Checklist items to store.
 *
 * @return void
 */
public function ew_update_change_request_items( $comment_id, $items ) {
    update_comment_meta( $comment_id, '_ew_items', array_values( $items ) );
}

/**
 * Returns the active change-request items stored in post meta.
 *
 * @param int $post_id Post ID to inspect.
 *
 * @return array<int, array<string, mixed>> Active checklist items.
 */
public function ew_get_active_change_request_items_meta( $post_id ) {
    $items = get_post_meta( $post_id, '_ew_active_change_items', true );
    if ( ! is_array( $items ) || empty( $items ) ) return [];

    $items = array_map( 'ew_normalize_change_request_item', $items );
    return array_values( array_filter( $items ) );
}

/**
 * Saves the active change-request items to post meta.
 *
 * @param int   $post_id Post ID to update.
 * @param array $items   Checklist items to persist.
 *
 * @return void
 */
public function ew_update_active_change_request_items_meta( $post_id, $items ) {
    update_post_meta( $post_id, '_ew_active_change_items', array_values( $items ) );
}

/**
 * Checks whether every checklist item in the collection is marked done.
 *
 * @param array $items Checklist items to evaluate.
 *
 * @return bool True when all items are complete.
 */
public function ew_change_request_items_are_complete( $items ) {
    if ( empty( $items ) ) return false;

    foreach ( $items as $item ) {
        if ( ( $item['status'] ?? 'open' ) !== 'done' ) {
            return false;
        }
    }

    return true;
}

/**
 * Returns the tracked active change-request comment for the post, or null.
 *
 * @param int $post_id Post ID to inspect.
 *
 * @return WP_Comment|null Active change request comment or null.
 */
public function ew_get_tracked_change_request_comment( $post_id ) {
    $active_comment_id = absint( get_post_meta( $post_id, '_ew_active_change_request_id', true ) );
    if ( ! $active_comment_id ) return null;

    $active_comment = get_comment( $active_comment_id );
    if ( ! $active_comment ) return null;
    if ( (int) $active_comment->comment_post_ID !== (int) $post_id ) return null;
    if ( $active_comment->comment_type !== 'editorial_change_request' ) return null;

    return $active_comment;
}

/**
 * Returns true when all outstanding change items are done and the post can be resubmitted.
 *
 * @param int $post_id Post ID to inspect.
 *
 * @return bool True if the request is ready for resubmission.
 */
public function ew_post_is_ready_for_resubmission( $post_id ) {
    $items = ew_get_active_change_request_items_meta( $post_id );
    if ( empty( $items ) ) {
        $active_comment = ew_get_tracked_change_request_comment( $post_id );
        if ( ! $active_comment ) return false;

        $items = ew_get_change_request_items( $active_comment );
    }

    return ew_change_request_items_are_complete( $items );
}

/**
 * Returns a structured array of the active change request data for the post.
 */
public function ew_get_active_change_request_data( $post_id ) {
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

/**
 * Creates a change-request comment from legacy meta when no tracked comment exists.
 */
public function ew_recover_active_change_request_comment( $post_id ) {
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

/**
 * Returns the currently active unresolved change-request comment for the post.
 *
 * @param int $post_id Post ID to inspect.
 *
 * @return WP_Comment|null Active change-request comment or null.
 */
public function ew_get_active_change_request_comment( $post_id ) {
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

/**
 * Determines whether the current user may mark change-request items as done.
 *
 * @param WP_Post $post Post object to inspect.
 *
 * @return bool True when the user is allowed to resolve the request.
 */
public function ew_current_user_can_resolve_change_requests( $post ) {
    $user_id = get_current_user_id();
    if ( ! $user_id ) return false;
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return false;

    return (int) $post->post_author === (int) $user_id || current_user_can( 'manage_options' );
}

/**
 * Handles the mark-done form submission on save_post.
 *
 * @param int     $post_id Post ID being saved.
 * @param WP_Post $post    Post object being saved.
 *
 * @return void
 */
public function ew_handle_change_resolution( $post_id, $post ) {
    if ( ! isset( $_POST['ew_update_changes'] ) ) return;
    if ( ! isset( $_POST['ew_change_resolution_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['ew_change_resolution_nonce'], 'ew_change_resolution_' . $post_id ) ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
    if ( ! ew_current_user_can_resolve_change_requests( $post ) ) return;

    $submitted_request_id = absint( $_POST['ew_change_request_id'] ?? 0 );
    $completed_ids        = array_map( 'sanitize_key', (array) ( $_POST['ew_completed_items'] ?? [] ) );

    ew_process_change_resolution( $post_id, $submitted_request_id, $completed_ids );
}

/**
 * Marks the selected change-request items as done and resubmits when complete.
 *
 * @param int   $post_id            Post ID to update.
 * @param int   $submitted_request_id Requested comment ID submitted with the form.
 * @param array $completed_ids      Selected item IDs marked as resolved.
 *
 * @return bool|WP_Error True on success, or WP_Error when validation fails.
 */
public function ew_process_change_resolution( $post_id, $submitted_request_id, $completed_ids ) {
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

/**
 * AJAX handler for authors submitting a post for review.
 *
 * @return void
 */
public function ew_ajax_submit_for_review() {
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

/**
 * AJAX handler for editors approving or requesting changes on a post.
 *
 * @return void
 */
public function ew_ajax_review_action() {
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

/**
 * AJAX handler for authors marking change-request items as done.
 *
 * @return void
 */
public function ew_ajax_update_changes() {
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

/**
 * Returns the most recent change-request note for the post.
 *
 * @param int $post_id Post ID to inspect.
 *
 * @return string Change-request note text, or stored meta value if no comment exists.
 */
public function ew_get_latest_change_request_note( $post_id ) {
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


/**
 * 6. Preview URL helper.
 */

/**
 * Returns the token TTL for public preview links in seconds.
 */
public function ew_get_public_preview_ttl() {
    return (int) apply_filters( 'ew_public_preview_ttl', WEEK_IN_SECONDS * 2 );
}

/**
 * Returns (or creates) a time-limited token for the public preview URL.
 */
public function ew_get_public_preview_token( $post_id ) {
    $token   = (string) get_post_meta( $post_id, '_ew_public_preview_token', true );
    $expires = (int) get_post_meta( $post_id, '_ew_public_preview_expires', true );

    if ( $token !== '' && $expires > time() ) {
        return $token;
    }

    $token = wp_generate_password( 32, false, false );
    update_post_meta( $post_id, '_ew_public_preview_token', $token );
    update_post_meta( $post_id, '_ew_public_preview_expires', time() + max( 3600, ew_get_public_preview_ttl() ) );

    return $token;
}

/**
 * Returns a shareable public preview URL for any logged-out visitor.
 */
public function ew_get_public_preview_url( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) return '';

    $token = ew_get_public_preview_token( $post_id );

    /**
     * Do not use WordPress core preview params here; they trigger auth checks.
     */
    $base_url = home_url( '/?p=' . $post_id );

    return add_query_arg( [
        'ew_public_preview' => '1',
        'preview_id'        => $post_id,
        'ew_preview_token'  => $token,
    ], $base_url );
}

/**
 * Returns true when the current request carries public-preview query params.
 */
public function ew_is_public_preview_request() {
    return isset( $_GET['ew_public_preview'], $_GET['ew_preview_token'], $_GET['preview_id'] );
}

/**
 * Returns the post ID from the public-preview query parameter.
 */
public function ew_public_preview_post_id() {
    return absint( $_GET['preview_id'] ?? 0 );
}

/**
 * Validates the preview token against the stored value and expiry.
 */
public function ew_public_preview_token_is_valid( $post_id, $token ) {
    $expected = (string) get_post_meta( $post_id, '_ew_public_preview_token', true );
    $expires  = (int) get_post_meta( $post_id, '_ew_public_preview_expires', true );

    if ( $expected === '' || $expires <= time() ) {
        return false;
    }

    return hash_equals( $expected, (string) $token );
}

/**
 * Allows unpublished posts to be queried during a valid public-preview request.
 */
public function ew_allow_public_preview_query( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) return;
    if ( ! ew_is_public_preview_request() ) return;

    $post_id = ew_public_preview_post_id();
    if ( ! $post_id ) return;

    $query->set( 'p', $post_id );
    $query->set( 'post_type', [ 'post', 'page' ] );
    $query->set( 'post_status', [ 'draft', 'pending', 'changes_requested', 'approved', 'future', 'publish' ] );
    $query->set( 'posts_per_page', 1 );
}

/**
 * Filters query results to only return the previewed post when the token is valid.
 */
public function ew_filter_public_preview_results( $posts, $query ) {
    if ( is_admin() || ! $query->is_main_query() ) return $posts;
    if ( ! ew_is_public_preview_request() ) return $posts;

    $post_id = ew_public_preview_post_id();
    $token   = sanitize_text_field( wp_unslash( $_GET['ew_preview_token'] ?? '' ) );

    if ( ! $post_id || $token === '' ) {
        return [];
    }

    foreach ( $posts as $post ) {
        if ( (int) $post->ID !== (int) $post_id ) {
            continue;
        }

        if ( ew_public_preview_token_is_valid( $post_id, $token ) ) {
            return $posts;
        }

        break;
    }

    return [];
}

/**
 * Suppresses canonical redirect for public preview URLs.
 */
public function ew_disable_public_preview_canonical( $redirect_url, $requested_url ) {
    if ( ew_is_public_preview_request() ) {
        return false;
    }
    return $redirect_url;
}

/**
 * Outputs a noindex/nofollow meta tag on public preview pages.
 */
public function ew_public_preview_noindex() {
    if ( ! ew_is_public_preview_request() ) return;
    echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
}

/**
 * Returns an authenticated preview URL for logged-in users.
 */
public function ew_get_preview_url( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) return '';
    return add_query_arg( [
        'preview'        => 'true',
        'preview_id'     => $post_id,
        'preview_nonce'  => wp_create_nonce( 'post_preview_' . $post_id ),
    ], get_permalink( $post_id ) ?: home_url( '/?p=' . $post_id ) );
}


/**
 * 7. Cache clearing on publish.
 */

/**
 * Clears known caching-plugin caches and notifies Slack when a post is published.
 */
public function ew_clear_cache_on_publish( $new_status, $old_status, $post ) {
    if ( $new_status !== 'publish' || $old_status === 'publish' ) return;
    if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;

    if ( function_exists( 'rocket_clean_post' ) )      rocket_clean_post( $post->ID );
    if ( function_exists( 'w3tc_flush_post' ) )        w3tc_flush_post( $post->ID );
    if ( function_exists( 'wp_cache_post_change' ) )   wp_cache_post_change( $post->ID );
    if ( class_exists( 'LiteSpeed_Cache_API' ) )       LiteSpeed_Cache_API::purge( 'esi.post.' . $post->ID );

    delete_transient( 'ew_preview_' . $post->ID );
    ew_slack_notify( sprintf( '🟢 *Published:* <%s|%s> by %s', get_permalink( $post->ID ), $post->post_title, get_the_author_meta( 'display_name', $post->post_author ) ) );
}


/**
 * 8. Admin list states and status JS.
 */

/**
 * Adds colored status labels to the post list table.
 */
public function ew_display_post_states( $states, $post ) {
    $status = get_post_status( $post->ID );
    if ( $status === 'pending' )  $states['pending']  = '<span style="color:#f59e0b">Pending</span>';
    if ( $status === 'changes_requested' ) $states['changes_requested'] = '<span style="color:#ef4444">Changes Requested</span>';
    if ( $status === 'approved' ) $states['approved'] = '<span style="color:#10b981">Approved</span>';
    return $states;
}

/**
 * Injects JS that sets the correct status value and Publish button label on the classic editor.
 */
public function ew_inject_status_js() {
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

/**
 * Enqueues inline JS for the block editor's editorial workflow action buttons.
 */
public function ew_enqueue_block_editor_workflow_actions() {
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

    $(document).on('click', '.ew-share-preview-copy', function(event){
        event.preventDefault();

        var $btn = $(this);
        var url = $btn.data('preview-url') || '';
        if ( ! url ) {
            return;
        }

        function markCopied() {
            var original = $btn.text();
            $btn.text('Copied');
            setTimeout(function(){ $btn.text(original); }, 1200);
        }

        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText(url).then(markCopied).catch(function(){
                window.prompt('Copy this preview URL:', url);
            });
            return;
        }

        window.prompt('Copy this preview URL:', url);
    });
});
JS
    );
}


/**
 * 9. Helpers.
 */

/**
 * Returns a human-readable label for a given editorial post status slug.
 */
public function ew_status_label( $status ) {
    return [
        'draft'             => 'Draft',
        'pending'           => 'Pending (In Review)',
        'changes_requested' => 'Changes Requested',
        'approved'          => 'Approved',
        'publish'           => 'Published',
        'future'            => 'Scheduled',
    ][ $status ] ?? ucfirst( $status );
}
}

Editorial_Plugin_Bootstrap::instance()->boot();

if ( ! function_exists( 'ew_register_post_statuses' ) ) { function ew_register_post_statuses( ...$args ) { return Editorial_Workflow::instance()->ew_register_post_statuses( ...$args ); } }
if ( ! function_exists( 'ew_get_writer_role_slugs' ) ) { function ew_get_writer_role_slugs( ...$args ) { return Editorial_Workflow::instance()->ew_get_writer_role_slugs( ...$args ); } }
if ( ! function_exists( 'ew_get_editor_role_slugs' ) ) { function ew_get_editor_role_slugs( ...$args ) { return Editorial_Workflow::instance()->ew_get_editor_role_slugs( ...$args ); } }
if ( ! function_exists( 'ew_user_has_any_role' ) ) { function ew_user_has_any_role( ...$args ) { return Editorial_Workflow::instance()->ew_user_has_any_role( ...$args ); } }
if ( ! function_exists( 'ew_user_is_writer' ) ) { function ew_user_is_writer( ...$args ) { return Editorial_Workflow::instance()->ew_user_is_writer( ...$args ); } }
if ( ! function_exists( 'ew_set_role_capabilities' ) ) { function ew_set_role_capabilities( ...$args ) { return Editorial_Workflow::instance()->ew_set_role_capabilities( ...$args ); } }
if ( ! function_exists( 'ew_user_is_reviewer' ) ) { function ew_user_is_reviewer( ...$args ) { return Editorial_Workflow::instance()->ew_user_is_reviewer( ...$args ); } }
if ( ! function_exists( 'ew_user_can_publish_post' ) ) { function ew_user_can_publish_post( ...$args ) { return Editorial_Workflow::instance()->ew_user_can_publish_post( ...$args ); } }
if ( ! function_exists( 'ew_get_request_post_id' ) ) { function ew_get_request_post_id( ...$args ) { return Editorial_Workflow::instance()->ew_get_request_post_id( ...$args ); } }
if ( ! function_exists( 'ew_grant_publish_cap_for_approved_posts' ) ) { function ew_grant_publish_cap_for_approved_posts( ...$args ) { return Editorial_Workflow::instance()->ew_grant_publish_cap_for_approved_posts( ...$args ); } }
if ( ! function_exists( 'ew_submit_for_review_button' ) ) { function ew_submit_for_review_button( ...$args ) { return Editorial_Workflow::instance()->ew_submit_for_review_button( ...$args ); } }
if ( ! function_exists( 'ew_handle_submit_for_review' ) ) { function ew_handle_submit_for_review( ...$args ) { return Editorial_Workflow::instance()->ew_handle_submit_for_review( ...$args ); } }
if ( ! function_exists( 'ew_process_submit_for_review' ) ) { function ew_process_submit_for_review( ...$args ) { return Editorial_Workflow::instance()->ew_process_submit_for_review( ...$args ); } }
if ( ! function_exists( 'ew_notify_editors_when_pending' ) ) { function ew_notify_editors_when_pending( ...$args ) { return Editorial_Workflow::instance()->ew_notify_editors_when_pending( ...$args ); } }
if ( ! function_exists( 'ew_notify_editors_review_requested' ) ) { function ew_notify_editors_review_requested( ...$args ) { return Editorial_Workflow::instance()->ew_notify_editors_review_requested( ...$args ); } }
if ( ! function_exists( 'ew_notify_author_changes_requested' ) ) { function ew_notify_author_changes_requested( ...$args ) { return Editorial_Workflow::instance()->ew_notify_author_changes_requested( ...$args ); } }
if ( ! function_exists( 'ew_notify_author_approved' ) ) { function ew_notify_author_approved( ...$args ) { return Editorial_Workflow::instance()->ew_notify_author_approved( ...$args ); } }
if ( ! function_exists( 'ew_send_email_notification' ) ) { function ew_send_email_notification( ...$args ) { return Editorial_Workflow::instance()->ew_send_email_notification( ...$args ); } }
if ( ! function_exists( 'ew_slack_notify' ) ) { function ew_slack_notify( ...$args ) { return Editorial_Workflow::instance()->ew_slack_notify( ...$args ); } }
if ( ! function_exists( 'ew_output_change_request_styles' ) ) { function ew_output_change_request_styles( ...$args ) { return Editorial_Workflow::instance()->ew_output_change_request_styles( ...$args ); } }
if ( ! function_exists( 'ew_add_feedback_metabox' ) ) { function ew_add_feedback_metabox( ...$args ) { return Editorial_Workflow::instance()->ew_add_feedback_metabox( ...$args ); } }
if ( ! function_exists( 'ew_render_feedback_metabox' ) ) { function ew_render_feedback_metabox( ...$args ) { return Editorial_Workflow::instance()->ew_render_feedback_metabox( ...$args ); } }
if ( ! function_exists( 'ew_intercept_editor_publish' ) ) { function ew_intercept_editor_publish( ...$args ) { return Editorial_Workflow::instance()->ew_intercept_editor_publish( ...$args ); } }
if ( ! function_exists( 'ew_handle_review_action' ) ) { function ew_handle_review_action( ...$args ) { return Editorial_Workflow::instance()->ew_handle_review_action( ...$args ); } }
if ( ! function_exists( 'ew_process_review_action' ) ) { function ew_process_review_action( ...$args ) { return Editorial_Workflow::instance()->ew_process_review_action( ...$args ); } }
if ( ! function_exists( 'ew_add_change_request_comment' ) ) { function ew_add_change_request_comment( ...$args ) { return Editorial_Workflow::instance()->ew_add_change_request_comment( ...$args ); } }
if ( ! function_exists( 'ew_parse_change_request_items' ) ) { function ew_parse_change_request_items( ...$args ) { return Editorial_Workflow::instance()->ew_parse_change_request_items( ...$args ); } }
if ( ! function_exists( 'ew_normalize_change_request_item' ) ) { function ew_normalize_change_request_item( ...$args ) { return Editorial_Workflow::instance()->ew_normalize_change_request_item( ...$args ); } }
if ( ! function_exists( 'ew_get_change_request_items' ) ) { function ew_get_change_request_items( ...$args ) { return Editorial_Workflow::instance()->ew_get_change_request_items( ...$args ); } }
if ( ! function_exists( 'ew_update_change_request_items' ) ) { function ew_update_change_request_items( ...$args ) { return Editorial_Workflow::instance()->ew_update_change_request_items( ...$args ); } }
if ( ! function_exists( 'ew_get_active_change_request_items_meta' ) ) { function ew_get_active_change_request_items_meta( ...$args ) { return Editorial_Workflow::instance()->ew_get_active_change_request_items_meta( ...$args ); } }
if ( ! function_exists( 'ew_update_active_change_request_items_meta' ) ) { function ew_update_active_change_request_items_meta( ...$args ) { return Editorial_Workflow::instance()->ew_update_active_change_request_items_meta( ...$args ); } }
if ( ! function_exists( 'ew_change_request_items_are_complete' ) ) { function ew_change_request_items_are_complete( ...$args ) { return Editorial_Workflow::instance()->ew_change_request_items_are_complete( ...$args ); } }
if ( ! function_exists( 'ew_get_tracked_change_request_comment' ) ) { function ew_get_tracked_change_request_comment( ...$args ) { return Editorial_Workflow::instance()->ew_get_tracked_change_request_comment( ...$args ); } }
if ( ! function_exists( 'ew_post_is_ready_for_resubmission' ) ) { function ew_post_is_ready_for_resubmission( ...$args ) { return Editorial_Workflow::instance()->ew_post_is_ready_for_resubmission( ...$args ); } }
if ( ! function_exists( 'ew_get_active_change_request_data' ) ) { function ew_get_active_change_request_data( ...$args ) { return Editorial_Workflow::instance()->ew_get_active_change_request_data( ...$args ); } }
if ( ! function_exists( 'ew_recover_active_change_request_comment' ) ) { function ew_recover_active_change_request_comment( ...$args ) { return Editorial_Workflow::instance()->ew_recover_active_change_request_comment( ...$args ); } }
if ( ! function_exists( 'ew_get_active_change_request_comment' ) ) { function ew_get_active_change_request_comment( ...$args ) { return Editorial_Workflow::instance()->ew_get_active_change_request_comment( ...$args ); } }
if ( ! function_exists( 'ew_current_user_can_resolve_change_requests' ) ) { function ew_current_user_can_resolve_change_requests( ...$args ) { return Editorial_Workflow::instance()->ew_current_user_can_resolve_change_requests( ...$args ); } }
if ( ! function_exists( 'ew_handle_change_resolution' ) ) { function ew_handle_change_resolution( ...$args ) { return Editorial_Workflow::instance()->ew_handle_change_resolution( ...$args ); } }
if ( ! function_exists( 'ew_process_change_resolution' ) ) { function ew_process_change_resolution( ...$args ) { return Editorial_Workflow::instance()->ew_process_change_resolution( ...$args ); } }
if ( ! function_exists( 'ew_ajax_submit_for_review' ) ) { function ew_ajax_submit_for_review( ...$args ) { return Editorial_Workflow::instance()->ew_ajax_submit_for_review( ...$args ); } }
if ( ! function_exists( 'ew_ajax_review_action' ) ) { function ew_ajax_review_action( ...$args ) { return Editorial_Workflow::instance()->ew_ajax_review_action( ...$args ); } }
if ( ! function_exists( 'ew_ajax_update_changes' ) ) { function ew_ajax_update_changes( ...$args ) { return Editorial_Workflow::instance()->ew_ajax_update_changes( ...$args ); } }
if ( ! function_exists( 'ew_get_latest_change_request_note' ) ) { function ew_get_latest_change_request_note( ...$args ) { return Editorial_Workflow::instance()->ew_get_latest_change_request_note( ...$args ); } }
if ( ! function_exists( 'ew_get_public_preview_ttl' ) ) { function ew_get_public_preview_ttl( ...$args ) { return Editorial_Workflow::instance()->ew_get_public_preview_ttl( ...$args ); } }
if ( ! function_exists( 'ew_get_public_preview_token' ) ) { function ew_get_public_preview_token( ...$args ) { return Editorial_Workflow::instance()->ew_get_public_preview_token( ...$args ); } }
if ( ! function_exists( 'ew_get_public_preview_url' ) ) { function ew_get_public_preview_url( ...$args ) { return Editorial_Workflow::instance()->ew_get_public_preview_url( ...$args ); } }
if ( ! function_exists( 'ew_is_public_preview_request' ) ) { function ew_is_public_preview_request( ...$args ) { return Editorial_Workflow::instance()->ew_is_public_preview_request( ...$args ); } }
if ( ! function_exists( 'ew_public_preview_post_id' ) ) { function ew_public_preview_post_id( ...$args ) { return Editorial_Workflow::instance()->ew_public_preview_post_id( ...$args ); } }
if ( ! function_exists( 'ew_public_preview_token_is_valid' ) ) { function ew_public_preview_token_is_valid( ...$args ) { return Editorial_Workflow::instance()->ew_public_preview_token_is_valid( ...$args ); } }
if ( ! function_exists( 'ew_allow_public_preview_query' ) ) { function ew_allow_public_preview_query( ...$args ) { return Editorial_Workflow::instance()->ew_allow_public_preview_query( ...$args ); } }
if ( ! function_exists( 'ew_filter_public_preview_results' ) ) { function ew_filter_public_preview_results( ...$args ) { return Editorial_Workflow::instance()->ew_filter_public_preview_results( ...$args ); } }
if ( ! function_exists( 'ew_disable_public_preview_canonical' ) ) { function ew_disable_public_preview_canonical( ...$args ) { return Editorial_Workflow::instance()->ew_disable_public_preview_canonical( ...$args ); } }
if ( ! function_exists( 'ew_public_preview_noindex' ) ) { function ew_public_preview_noindex( ...$args ) { return Editorial_Workflow::instance()->ew_public_preview_noindex( ...$args ); } }
if ( ! function_exists( 'ew_get_preview_url' ) ) { function ew_get_preview_url( ...$args ) { return Editorial_Workflow::instance()->ew_get_preview_url( ...$args ); } }
if ( ! function_exists( 'ew_clear_cache_on_publish' ) ) { function ew_clear_cache_on_publish( ...$args ) { return Editorial_Workflow::instance()->ew_clear_cache_on_publish( ...$args ); } }
if ( ! function_exists( 'ew_display_post_states' ) ) { function ew_display_post_states( ...$args ) { return Editorial_Workflow::instance()->ew_display_post_states( ...$args ); } }
if ( ! function_exists( 'ew_inject_status_js' ) ) { function ew_inject_status_js( ...$args ) { return Editorial_Workflow::instance()->ew_inject_status_js( ...$args ); } }
if ( ! function_exists( 'ew_enqueue_block_editor_workflow_actions' ) ) { function ew_enqueue_block_editor_workflow_actions( ...$args ) { return Editorial_Workflow::instance()->ew_enqueue_block_editor_workflow_actions( ...$args ); } }
if ( ! function_exists( 'ew_status_label' ) ) { function ew_status_label( ...$args ) { return Editorial_Workflow::instance()->ew_status_label( ...$args ); } }
