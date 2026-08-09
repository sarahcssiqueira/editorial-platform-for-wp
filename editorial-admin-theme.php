<?php
/**
 * Plugin Name:       Editorial Admin Theme
 * Plugin URI:        https://github.com/your-repo/editorial-workflow
 * Description:       Role-aware admin interface for editorial teams: clean menus for writers, review queue for editors, dark editorial skin.
 * Version:           1.0.0
 * Author:            Sarah
 * License:           GPL-2.0-or-later
 * Text Domain:       editorial
 */

defined( 'ABSPATH' ) || exit;


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

    if ( in_array( 'author', (array) $user->roles, true ) ) {
        foreach ( [ 'index.php', 'edit-comments.php', 'tools.php', 'options-general.php', 'themes.php', 'plugins.php', 'users.php' ] as $page ) {
            remove_menu_page( $page );
        }
    }

    if ( in_array( 'editor', (array) $user->roles, true ) ) {
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
    if ( in_array( 'author', (array) $user->roles, true ) )        $classes .= ' eat-writer';
    elseif ( in_array( 'editor', (array) $user->roles, true ) )    $classes .= ' eat-editor';
    elseif ( in_array( 'administrator', (array) $user->roles, true ) ) $classes .= ' eat-admin';
    return $classes;
}


// ════════════════════════════════════════════════════════════════════════════
// 4. CUSTOM ADMIN BAR
// ════════════════════════════════════════════════════════════════════════════

add_action( 'admin_bar_menu', 'eat_admin_bar', 999 );
function eat_admin_bar( $wp_admin_bar ) {
    $user = wp_get_current_user();

    if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
        foreach ( [ 'wp-logo', 'customize', 'comments', 'new-content' ] as $node ) {
            $wp_admin_bar->remove_node( $node );
        }
    }

    if ( in_array( 'editor', (array) $user->roles, true ) ) {
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
    wp_add_dashboard_widget( 'ew_editorial_queue', '✦ Editorial Queue', 'eat_render_dashboard_widget' );
}

function eat_render_dashboard_widget() {
    $pending  = get_posts( [ 'post_status' => 'pending',  'numberposts' => 10, 'orderby' => 'modified', 'order' => 'DESC' ] );
    $rejected = get_posts( [ 'post_status' => 'rejected', 'numberposts' => 10, 'orderby' => 'modified', 'order' => 'DESC' ] );
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
        <?php if ( ! empty( $rejected ) ) : ?>
        <div class="eat-queue-group eat-queue-changes">
            <div class="eat-queue-label">Rejected / Changes Requested (<?php echo count( $rejected ); ?>)</div>
            <?php foreach ( $rejected as $post ) : ?>
            <div class="eat-queue-item">
                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ); ?>"><?php echo esc_html( $post->post_title ?: '(Untitled)' ); ?></a>
                <span class="eat-queue-meta"><?php echo human_time_diff( strtotime( $post->post_modified ) ); ?> ago</span>
            </div>
            <?php endforeach; ?>
        </div>
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
    if ( ! current_user_can( 'publish_posts' ) ) return;

    global $post;
    if ( ! $post || ! in_array( $post->post_status, [ 'draft', 'pending', 'rejected', 'approved' ], true ) ) return;

    $preview_url = ew_get_preview_url( $post->ID );

    wp_add_inline_style( 'editorial-admin-theme', '
        /* Split view layout */
        body.eat-split #wpbody-content { display: none; }

        #eat-split-root {
            display: flex;
            height: calc(100vh - 32px); /* minus admin bar */
            margin-top: 32px;
            overflow: hidden;
        }

        #eat-split-editor {
            flex: 0 0 55%;
            overflow-y: auto;
            border-right: 1px solid #2a2a2a;
            background: #0f0f0f;
        }

        #eat-split-editor > #wpbody-content {
            display: block !important;
        }

        #eat-split-preview {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #111;
        }

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

        #eat-split-toggle {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            background: #f5f0e8;
            color: #000;
            border: none;
            padding: 8px 16px;
            font-size: 12px;
            letter-spacing: 0.08em;
            border-radius: 3px;
            cursor: pointer;
            font-family: Georgia, serif;
            box-shadow: 0 2px 12px rgba(0,0,0,.4);
            transition: background .15s;
        }

        #eat-split-toggle:hover { background: #c9a84c; }
    ' );

    wp_add_inline_script( 'jquery', '
    jQuery(function($){
        var previewUrl = ' . wp_json_encode( $preview_url ) . ';
        var active = false;

        // Toggle button
        var $btn = $("<button>", {
            id: "eat-split-toggle",
            text: "⊞ Split Preview"
        }).appendTo("body");

        function enableSplit() {
            active = true;
            $btn.text("✕ Close Preview");

            // Move #wpbody-content into the editor pane
            var $content = $("#wpbody-content");
            var $root = $("<div>", { id: "eat-split-root" });
            var $editor = $("<div>", { id: "eat-split-editor" });
            var $preview = $("<div>", { id: "eat-split-preview" });
            var $bar = $("<div>", { id: "eat-split-preview-bar" })
                .append("<span>Live Preview</span>")
                .append($("<button>↺ Refresh</button>").on("click", refreshPreview));
            var $iframe = $("<iframe>", { id: "eat-preview-iframe", src: previewUrl });

            $preview.append($bar).append($iframe);
            $editor.append($content);
            $root.append($editor).append($preview);
            $("#wpbody").append($root);

            $("html, body").css("overflow", "hidden");
        }

        function disableSplit() {
            active = false;
            $btn.text("⊞ Split Preview");

            var $content = $("#eat-split-editor > #wpbody-content");
            $("#wpbody-content-placeholder").replaceWith($content);
            $("#eat-split-root").remove();

            $("html, body").css("overflow", "");
        }

        function refreshPreview() {
            var $iframe = $("#eat-preview-iframe");
            $iframe.attr("src", previewUrl + "&_=" + Date.now());
        }

        $btn.on("click", function(){
            active ? disableSplit() : enableSplit();
        });

        // Auto-enable for editors reviewing pending posts
        var postStatus = $("input#post_status").val() || "";
        if ( postStatus === "pending" ) {
            setTimeout(enableSplit, 400);
        }
    });
    ' );
}