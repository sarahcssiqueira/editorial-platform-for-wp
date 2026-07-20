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
        plugin_dir_url( __FILE__ ) . 'admin-theme/style.css',
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
        $in_review = get_posts( [ 'post_status' => 'in_review', 'numberposts' => -1 ] );
        $n = count( $in_review );
        if ( $n > 0 ) {
            $wp_admin_bar->add_node( [
                'id'    => 'ew-review-queue',
                'title' => sprintf( '<span class="eat-review-badge">✦ %d to review</span>', $n ),
                'href'  => admin_url( 'edit.php?post_status=in_review&post_type=post' ),
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
    $in_review = get_posts( [ 'post_status' => 'in_review',         'numberposts' => 10, 'orderby' => 'modified', 'order' => 'DESC' ] );
    $changes   = get_posts( [ 'post_status' => 'changes_requested', 'numberposts' => 10, 'orderby' => 'modified', 'order' => 'DESC' ] );
    ?>
    <div class="eat-dashboard-queue">
        <?php if ( ! empty( $in_review ) ) : ?>
        <div class="eat-queue-group">
            <div class="eat-queue-label">Awaiting Review (<?php echo count( $in_review ); ?>)</div>
            <?php foreach ( $in_review as $post ) : ?>
            <div class="eat-queue-item">
                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ); ?>"><?php echo esc_html( $post->post_title ?: '(Untitled)' ); ?></a>
                <span class="eat-queue-meta"><?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?> · <?php echo human_time_diff( strtotime( $post->post_modified ) ); ?> ago</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
        <div class="eat-queue-empty">✓ Nothing pending review</div>
        <?php endif; ?>
        <?php if ( ! empty( $changes ) ) : ?>
        <div class="eat-queue-group eat-queue-changes">
            <div class="eat-queue-label">Changes Requested (<?php echo count( $changes ); ?>)</div>
            <?php foreach ( $changes as $post ) : ?>
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
