<?php
/**
 * Plugin Name:       Editorial Admin Theme
 * Plugin URI:        https://github.com/your-repo/editorial-workflow
 * Description:       Role-aware admin interface for editorial teams: clean menus for authors, review queue for editors, dark editorial skin.
 * Version:           1.0.0
 * Author:            Sarah
 * License:           GPL-2.0-or-later
 * Text Domain:       editorial
 */

defined( 'ABSPATH' ) || exit;

function eat_get_writer_role_slugs() {
    return [ 'author' ];
}

function eat_get_editor_role_slugs() {
    return [ 'editor' ];
}

function eat_user_has_any_role( $user, $roles ) {
    $user_roles = (array) ( $user->roles ?? [] );
    return (bool) array_intersect( $user_roles, $roles );
}

function eat_user_is_writer( $user = null ) {
    $user = $user ?: wp_get_current_user();
    return eat_user_has_any_role( $user, eat_get_writer_role_slugs() );
}

function eat_user_is_editor( $user = null ) {
    $user = $user ?: wp_get_current_user();
    return eat_user_has_any_role( $user, eat_get_editor_role_slugs() );
}

function eat_user_is_admin( $user = null ) {
    $user = $user ?: wp_get_current_user();
    return eat_user_has_any_role( $user, [ 'administrator' ] );
}


// ════════════════════════════════════════════════════════════════════════════
// 1. ENQUEUE ADMIN STYLES
// ════════════════════════════════════════════════════════════════════════════

add_action( 'admin_enqueue_scripts', 'eat_enqueue_styles' );
function eat_enqueue_styles() {
    wp_enqueue_style(
        'editorial-admin-theme',
        plugins_url( 'admin-theme/style.css', __FILE__ ),
        [ 'wp-admin' ],
        '1.0.0'
    );
}


// ════════════════════════════════════════════════════════════════════════════
// 2. ROLE-AWARE MENU CLEANUP
// ════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', 'eat_cleanup_menu', 999 );
function eat_cleanup_menu() {
    $user = wp_get_current_user();

    if ( eat_user_is_writer( $user ) ) {
        foreach ( [ 'edit-comments.php', 'tools.php', 'options-general.php', 'themes.php', 'plugins.php', 'users.php' ] as $page ) {
            remove_menu_page( $page );
        }
    }

    if ( eat_user_is_editor( $user ) ) {
        foreach ( [ 'tools.php', 'options-general.php', 'themes.php', 'plugins.php' ] as $page ) {
            remove_menu_page( $page );
        }
    }
}


// ════════════════════════════════════════════════════════════════════════════
// 3. ROLE-AWARE BODY CLASS
// ════════════════════════════════════════════════════════════════════════════

add_filter( 'admin_body_class', 'eat_body_class' );
function eat_body_class( $classes ) {
    $user = wp_get_current_user();
    if ( eat_user_is_writer( $user ) )        $classes .= ' eat-writer';
    elseif ( eat_user_is_editor( $user ) )    $classes .= ' eat-editor';
    elseif ( eat_user_is_admin( $user ) )     $classes .= ' eat-admin';
    return $classes;
}

add_action( 'admin_head', 'eat_force_menu_expanded' );
function eat_force_menu_expanded() {
    ?>
    <style>
        #collapse-menu,
        #collapse-button {
            display: none !important;
        }
    </style>
    <script>
        (function() {
            var body = document.body;
            if (!body) {
                return;
            }

            body.classList.remove('folded', 'auto-fold');
        })();
    </script>
    <?php
}


// ════════════════════════════════════════════════════════════════════════════
// 4. CUSTOM ADMIN BAR
// ════════════════════════════════════════════════════════════════════════════

add_action( 'admin_bar_menu', 'eat_admin_bar', 999 );
function eat_admin_bar( $wp_admin_bar ) {
    $user = wp_get_current_user();

    if ( ! eat_user_is_admin( $user ) ) {
        foreach ( [ 'wp-logo', 'customize', 'comments', 'new-content' ] as $node ) {
            $wp_admin_bar->remove_node( $node );
        }
    }

    if ( eat_user_is_editor( $user ) || eat_user_is_admin( $user ) ) {
        $pending = get_posts( [ 'post_status' => 'pending', 'numberposts' => -1 ] );
        $n = count( $pending );
        if ( $n > 0 ) {
            $wp_admin_bar->add_node( [
                'id'    => 'ew-review-queue',
                'title' => sprintf( '<span class="eat-review-badge">✦ %d to review</span>', $n ),
                'href'  => admin_url( 'edit.php?post_status=pending&post_type=post' ),
            ] );
        }
    }
}


// ════════════════════════════════════════════════════════════════════════════
// 5. CUSTOM LOGIN PAGE
// ════════════════════════════════════════════════════════════════════════════

add_action( 'login_enqueue_scripts', 'eat_login_styles' );
function eat_login_styles() { ?>
    <style>
        body.login { background: #0f0f0f; }
        #login h1 a { background-image:none; font-family:'Georgia',serif; font-size:22px; color:#f5f0e8; text-indent:0; width:auto; height:auto; display:block; text-align:center; letter-spacing:.15em; text-transform:uppercase; }
        #login h1 a::before { content:'✦ Editorial'; }
        .login form { background:#1a1a1a; border:1px solid #2a2a2a; box-shadow:none; }
        .login label { color:#888; }
        .login input[type=text], .login input[type=password] { background:#0f0f0f; border-color:#2a2a2a; color:#f5f0e8; }
        .wp-core-ui .button-primary { background:#f5f0e8; border-color:#f5f0e8; color:#0f0f0f; }
        .wp-core-ui .button-primary:hover { background:#fff; border-color:#fff; }
    </style>
<?php }


// ════════════════════════════════════════════════════════════════════════════
// 6. EDITORIAL DASHBOARD WIDGET
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_dashboard_setup', 'eat_setup_dashboard' );
function eat_setup_dashboard() {
    foreach ( [ 'dashboard_activity', 'dashboard_right_now', 'dashboard_site_health' ] as $widget ) {
        remove_meta_box( $widget, 'dashboard', 'normal' );
    }
    foreach ( [ 'dashboard_quick_press', 'dashboard_primary' ] as $widget ) {
        remove_meta_box( $widget, 'dashboard', 'side' );
    }

    if ( eat_user_is_writer() && current_user_can( 'edit_posts' ) ) {
        wp_add_dashboard_widget( 'ew_writer_quick_start', '✦ Start Writing', 'eat_render_writer_quick_start_widget', null, null, 'normal', 'high' );
    }

    if ( current_user_can( 'upload_files' ) ) {
        wp_add_dashboard_widget( 'ew_media_quick_start', '✦ Media Library', 'eat_render_media_quick_start_widget', null, null, 'normal', 'core' );
    }

    if ( current_user_can( 'edit_posts' ) ) {
        wp_add_dashboard_widget( 'ew_open_change_requests', '✦ Open Change Requests', 'eat_render_open_change_requests_widget', null, null, 'normal', 'core' );
    }

    wp_add_dashboard_widget( 'ew_editorial_queue', '✦ Editorial Queue', 'eat_render_dashboard_widget' );
    wp_add_dashboard_widget( 'ew_editorial_notifications', '✦ Editorial Notifications', 'eat_render_notifications_widget', null, null, 'side', 'high' );
}

function eat_render_dashboard_widget() {
    $pending  = get_posts( [ 'post_status' => 'pending', 'numberposts' => 10, 'orderby' => 'modified', 'order' => 'DESC' ] );
    $changes_requested = get_posts( [ 'post_status' => 'changes_requested', 'numberposts' => 10, 'orderby' => 'modified', 'order' => 'DESC' ] );
    $approved = get_posts( [ 'post_status' => 'approved', 'numberposts' => 10, 'orderby' => 'modified', 'order' => 'DESC' ] );
    ?>
    <div class="eat-dashboard-queue">
        <?php if ( ! empty( $pending ) ) : ?>
        <div class="eat-queue-group">
            <div class="eat-queue-label">Pending (<?php echo count( $pending ); ?>)</div>
            <?php foreach ( $pending as $post ) : ?>
            <div class="eat-queue-item">
                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ); ?>"><?php echo esc_html( $post->post_title ?: '(Untitled)' ); ?></a>
                <span class="eat-queue-meta"><?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?> · <?php echo human_time_diff( strtotime( $post->post_modified ) ); ?> ago</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
        <div class="eat-queue-empty">✓ Nothing pending review</div>
        <?php endif; ?>
        <?php if ( ! empty( $changes_requested ) ) : ?>
        <div class="eat-queue-group eat-queue-changes">
            <div class="eat-queue-label">Changes Requested (<?php echo count( $changes_requested ); ?>)</div>
            <?php foreach ( $changes_requested as $post ) : ?>
            <div class="eat-queue-item">
                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ); ?>"><?php echo esc_html( $post->post_title ?: '(Untitled)' ); ?></a>
                <span class="eat-queue-meta"><?php echo human_time_diff( strtotime( $post->post_modified ) ); ?> ago</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ( ! empty( $approved ) ) : ?>
        <div class="eat-queue-group eat-queue-approved">
            <div class="eat-queue-label">Approved (<?php echo count( $approved ); ?>)</div>
            <?php foreach ( $approved as $post ) : ?>
            <div class="eat-queue-item">
                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ); ?>"><?php echo esc_html( $post->post_title ?: '(Untitled)' ); ?></a>
                <span class="eat-queue-meta"><?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?> · ready to publish</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function eat_render_notifications_widget() {
    $counts = wp_count_posts( 'post' );
    $pending_count = isset( $counts->pending ) ? (int) $counts->pending : 0;
    $changes_requested_count = isset( $counts->changes_requested ) ? (int) $counts->changes_requested : 0;
    $approved_count = isset( $counts->approved ) ? (int) $counts->approved : 0;
    $future_count = isset( $counts->future ) ? (int) $counts->future : 0;

    $recent_updates = get_posts( [
        'post_status' => [ 'draft', 'pending', 'changes_requested', 'approved', 'future', 'publish' ],
        'numberposts' => 4,
        'orderby' => 'modified',
        'order' => 'DESC',
    ] );
    ?>
    <div class="eat-notifications-panel">
        <div class="eat-notifications-summary">
            <a class="eat-note-pill is-pending" href="<?php echo esc_url( admin_url( 'edit.php?post_status=pending&post_type=post' ) ); ?>">
                <strong><?php echo esc_html( (string) $pending_count ); ?></strong>
                <span>Pending</span>
            </a>
            <a class="eat-note-pill is-changes" href="<?php echo esc_url( admin_url( 'edit.php?post_status=changes_requested&post_type=post' ) ); ?>">
                <strong><?php echo esc_html( (string) $changes_requested_count ); ?></strong>
                <span>Changes</span>
            </a>
            <a class="eat-note-pill is-approved" href="<?php echo esc_url( admin_url( 'edit.php?post_status=approved&post_type=post' ) ); ?>">
                <strong><?php echo esc_html( (string) $approved_count ); ?></strong>
                <span>Approved</span>
            </a>
            <a class="eat-note-pill is-scheduled" href="<?php echo esc_url( admin_url( 'edit.php?post_status=future&post_type=post' ) ); ?>">
                <strong><?php echo esc_html( (string) $future_count ); ?></strong>
                <span>Scheduled</span>
            </a>
        </div>

        <div class="eat-notifications-block">
            <div class="eat-notifications-title">Recent Updates</div>
            <?php if ( empty( $recent_updates ) ) : ?>
                <p class="eat-notifications-empty">No post activity yet.</p>
            <?php else : ?>
                <ul class="eat-notifications-list">
                    <?php foreach ( $recent_updates as $post ) : ?>
                        <li>
                            <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ); ?>"><?php echo esc_html( $post->post_title ?: '(Untitled)' ); ?></a>
                            <span><?php echo esc_html( human_time_diff( strtotime( $post->post_modified ) ) ); ?> ago</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function eat_render_writer_quick_start_widget() {
    $new_post_url = admin_url( 'post-new.php' );
    ?>
    <div class="eat-writer-quick-start">
        <p class="eat-writer-quick-start-copy">Open a fresh draft and start writing right away.</p>
        <a class="button button-primary eat-writer-quick-start-button" href="<?php echo esc_url( $new_post_url ); ?>">Add New Post</a>
    </div>
    <?php
}

function eat_render_media_quick_start_widget() {
    $media_upload_url  = admin_url( 'media-new.php' );
    $media_library_url = admin_url( 'upload.php' );
    ?>
    <div class="eat-media-quick-start">
        <p class="eat-media-quick-start-copy">Jump straight into uploads or browse the media library.</p>
        <div class="eat-media-quick-start-actions">
            <a class="button button-primary eat-media-quick-start-button" href="<?php echo esc_url( $media_upload_url ); ?>">Upload Media</a>
            <a class="button eat-media-quick-start-secondary" href="<?php echo esc_url( $media_library_url ); ?>">Open Library</a>
        </div>
    </div>
    <?php
}

function eat_get_change_request_age_in_days( $post ) {
    $requested_timestamp = 0;

    if ( function_exists( 'ew_get_active_change_request_comment' ) ) {
        $active_request = ew_get_active_change_request_comment( $post->ID );
        if ( $active_request ) {
            $requested_timestamp = mysql2date( 'U', $active_request->comment_date_gmt ?: $active_request->comment_date, false );
        }
    }

    if ( ! $requested_timestamp ) {
        $requested_timestamp = get_post_modified_time( 'U', true, $post );
    }

    $now = current_time( 'timestamp', true );
    $age = max( 1, (int) ceil( max( 0, $now - $requested_timestamp ) / DAY_IN_SECONDS ) );

    return $age;
}

function eat_render_open_change_requests_widget() {
    $query_args = [
        'post_type'      => 'post',
        'post_status'    => 'changes_requested',
        'posts_per_page' => 6,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ];

    if ( eat_user_is_writer() ) {
        $query_args['author'] = get_current_user_id();
    }

    $changes_requested = get_posts( $query_args );
    $changes_requested_url = admin_url( 'edit.php?post_status=changes_requested&post_type=post' );
    ?>
    <div class="eat-open-requests-panel">
        <?php if ( empty( $changes_requested ) ) : ?>
            <p class="eat-open-requests-empty">No open change requests right now.</p>
        <?php else : ?>
            <div class="eat-open-requests-summary">
                <strong><?php echo esc_html( (string) count( $changes_requested ) ); ?></strong>
                <span><?php echo esc_html( count( $changes_requested ) === 1 ? 'open request needs attention' : 'open requests need attention' ); ?></span>
            </div>
            <ul class="eat-open-requests-list">
                <?php foreach ( $changes_requested as $post ) : ?>
                    <?php $days_open = eat_get_change_request_age_in_days( $post ); ?>
                    <li class="eat-open-requests-item">
                        <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ); ?>"><?php echo esc_html( $post->post_title ?: '(Untitled)' ); ?></a>
                        <span><?php echo esc_html( sprintf( _n( '%d day open', '%d days open', $days_open, 'editorial' ), $days_open ) ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a class="eat-open-requests-link" href="<?php echo esc_url( $changes_requested_url ); ?>">View all open requests</a>
        <?php endif; ?>
    </div>
    <?php
}


// ════════════════════════════════════════════════════════════════════════════
// 7. SPLIT VIEW — inline preview panel (editors only, post edit screen)
// ════════════════════════════════════════════════════════════════════════════

add_action( 'admin_enqueue_scripts', 'eat_enqueue_split_view' );
function eat_enqueue_split_view( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;

    global $post;
    if ( ! $post || ! in_array( $post->post_status, [ 'draft', 'pending', 'changes_requested', 'approved' ], true ) ) return;
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return;

    $preview_url = ew_get_preview_url( $post->ID );

    wp_add_inline_style( 'editorial-admin-theme', '
        body { --eat-split-width: min(48vw, 760px); }

        #eat-split-toggle {
            position: fixed;
            top: 72px;
            right: 24px;
            z-index: 100001;
            background: linear-gradient(135deg, #111827 0%, #374151 100%);
            color: #f9fafb;
            border: 1px solid rgba(255,255,255,.16);
            padding: 9px 14px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border-radius: 8px;
            cursor: pointer;
            font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", sans-serif;
            box-shadow: 0 10px 28px rgba(17,24,39,.28);
            transition: transform .12s ease, box-shadow .15s ease, background .15s ease;
        }

        #eat-split-toggle::before {
            content: "";
            display: inline-block;
            width: 8px;
            height: 8px;
            margin-right: 8px;
            border-radius: 999px;
            background: #34d399;
            box-shadow: 0 0 0 4px rgba(52,211,153,.18);
            vertical-align: -1px;
        }

        #eat-split-toggle:hover {
            background: linear-gradient(135deg, #0f172a 0%, #1f2937 100%);
            box-shadow: 0 12px 34px rgba(15,23,42,.36);
            transform: translateY(-1px);
        }

        #eat-split-toggle.eat-toggle-inline {
            position: static;
            top: auto;
            right: auto;
            margin-left: 8px;
            box-shadow: none;
            transform: none !important;
        }

        #eat-split-dock {
            position: fixed;
            top: 32px;
            right: 0;
            width: var(--eat-split-width);
            height: calc(100vh - 32px);
            background: #111;
            border-left: 1px solid #2a2a2a;
            z-index: 100000;
            display: none;
            flex-direction: column;
        }

        body.eat-split-docked #eat-split-dock { display: flex; }

        #eat-split-preview-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            height: 40px;
            background: #1a1a1a;
            border-bottom: 1px solid #2a2a2a;
            flex-shrink: 0;
        }

        #eat-split-preview-bar span {
            font-family: Georgia, serif;
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #888;
        }

        #eat-split-preview-bar button {
            background: transparent;
            border: 1px solid #2a2a2a;
            color: #888;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 3px;
            cursor: pointer;
            letter-spacing: 0.05em;
            transition: border-color .15s, color .15s;
        }

        #eat-split-preview-bar button:hover {
            border-color: #888;
            color: #f5f0e8;
        }

        #eat-preview-iframe {
            flex: 1;
            width: 100%;
            border: none;
            background: #fff;
        }

        body.eat-split-docked #wpcontent,
        body.eat-split-docked #wpfooter {
            margin-right: var(--eat-split-width) !important;
        }

        body.eat-split-docked.block-editor-page .interface-interface-skeleton {
            margin-right: var(--eat-split-width) !important;
        }

        @media (max-width: 1200px) {
            body { --eat-split-width: 46vw; }
        }

        @media (max-width: 1024px) {
            #eat-split-toggle,
            #eat-split-dock {
                display: none !important;
            }
            body.eat-split-docked #wpcontent,
            body.eat-split-docked #wpfooter,
            body.eat-split-docked.block-editor-page .interface-interface-skeleton {
                margin-right: 0 !important;
            }
        }
    ' );

    wp_add_inline_script( 'jquery', '
    jQuery(function($){
        var previewUrl = ' . wp_json_encode( $preview_url ) . ';
        var active = false;

        var $btn = $("<button>", {
            id: "eat-split-toggle",
            text: "⊞ Split Preview"
        }).appendTo("body");

        function placeToggleButton() {
            var $gutenbergActions = $(".edit-post-header__settings, .editor-header__settings").first();
            if ( $gutenbergActions.length ) {
                $btn.addClass("eat-toggle-inline").appendTo($gutenbergActions);
                return;
            }

            var $classicActions = $("#submitdiv #publishing-action").first();
            if ( $classicActions.length ) {
                $btn.addClass("eat-toggle-inline").appendTo($classicActions);
                return;
            }

            $btn.removeClass("eat-toggle-inline").appendTo("body");
        }

        function ensureDock() {
            var $dock = $("#eat-split-dock");
            if ( $dock.length ) {
                return $dock;
            }

            $dock = $("<div>", { id: "eat-split-dock" });
            var $bar = $("<div>", { id: "eat-split-preview-bar" })
                .append("<span>Live Preview</span>")
                .append($("<button>↺ Refresh</button>").on("click", refreshPreview));
            var $iframe = $("<iframe>", { id: "eat-preview-iframe", src: previewUrl });

            $dock.append($bar).append($iframe).appendTo("body");
            return $dock;
        }

        function enableSplit() {
            active = true;
            $btn.text("✕ Close Preview");
            ensureDock();
            $("body").addClass("eat-split-docked");
            refreshPreview();
        }

        function disableSplit() {
            active = false;
            $btn.text("⊞ Split Preview");
            $("body").removeClass("eat-split-docked");
        }

        function refreshPreview() {
            var $iframe = $("#eat-preview-iframe");
            if ( ! $iframe.length ) {
                return;
            }
            $iframe.attr("src", previewUrl + "&_=" + Date.now());
        }

        $btn.on("click", function(){
            active ? disableSplit() : enableSplit();
        });

        placeToggleButton();
        $(window).on("resize", placeToggleButton);

        var postStatus = $("input#post_status").val() || "";
        if ( postStatus === "pending" && window.matchMedia("(min-width: 1025px)").matches ) {
            setTimeout(enableSplit, 400);
        }
    });
    ' );
}