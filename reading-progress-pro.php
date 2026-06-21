<?php
/**
 * Plugin Name: Reading Time & Progress Pro
 * Description: Reading time estimation, progress bar, scroll tracking, and social proof for your articles.
 * Version:     1.0.0
 * Author:      Abderrahim KHALID
 * Text Domain: reading-progress-pro
 * Network:     true
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RPP_VERSION', '1.0.0' );
define( 'RPP_FILE', __FILE__ );
define( 'RPP_BASENAME', plugin_basename( __FILE__ ) );
define( 'RPP_PATH', plugin_dir_path( __FILE__ ) );
define( 'RPP_URL',  plugin_dir_url( __FILE__ ) );
define( 'RPP_CAPABILITY', 'manage_rpp' );
define( 'RPP_API_URL', 'https://dp-starter.khalid.digital' );

// License system FIRST
require_once RPP_PATH . 'inc/license.php';

// Settings page (always loaded — includes license tab)
require_once RPP_PATH . 'admin/class-rpp-settings.php';
new RPP_Settings();

// Only load premium code if licensed
if ( rpp_is_licensed() ) {
    rpp_load_premium_code();
}

// ─── Reading Time Calculation (LOCAL — always loaded) ────────────────────────

/**
 * Calculate reading time for a post.
 */
function rpp_calculate_reading_time( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) return 0;
    $content    = strip_tags( $post->post_content );
    $word_count = str_word_count( $content );
    $speed      = absint( rpp_get_setting( 'words_per_minute' ) ) ?: 250;
    $images     = substr_count( $post->post_content, '<img' );
    $image_time = 0;
    for ( $i = 0; $i < $images; $i++ ) {
        $image_time += max( 3, 12 - $i );
    }
    $minutes = ceil( ( $word_count / $speed ) + ( $image_time / 60 ) );
    return max( 1, $minutes );
}

/**
 * Save reading time on post save.
 */
function rpp_save_reading_time( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    $time = rpp_calculate_reading_time( $post_id );
    update_post_meta( $post_id, '_rpp_reading_time', $time );
}
add_action( 'save_post', 'rpp_save_reading_time' );

// ─── Activation ──────────────────────────────────────────────────────────────

function rpp_add_caps_for_blog() {
    $role = get_role( 'administrator' );
    if ( ! $role ) return;
    $role->add_cap( RPP_CAPABILITY );
}

function rpp_activate( $network_wide = false ) {
    if ( is_multisite() && $network_wide ) {
        $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
        foreach ( $site_ids as $site_id ) {
            switch_to_blog( $site_id );
            rpp_add_caps_for_blog();
            restore_current_blog();
        }
    } else {
        rpp_add_caps_for_blog();
    }

    // Initialize settings defaults if not set
    if ( function_exists( 'rpp_settings_defaults' ) ) {
        $defaults = rpp_settings_defaults();
        $current  = get_option( 'rpp_settings', [] );
        if ( empty( $current ) ) {
            update_option( 'rpp_settings', $defaults );
        }
    }

    // Calculate reading time for all existing posts
    $posts = get_posts( array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );
    foreach ( $posts as $pid ) {
        $time = rpp_calculate_reading_time( $pid );
        update_post_meta( $pid, '_rpp_reading_time', $time );
    }

    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'rpp_activate' );

function rpp_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'rpp_deactivate' );

function rpp_add_caps_on_new_blog( $blog_id ) {
    if ( ! is_multisite() ) return;
    switch_to_blog( $blog_id );
    rpp_add_caps_for_blog();
    restore_current_blog();
}
add_action( 'wpmu_new_blog', 'rpp_add_caps_on_new_blog' );

function rpp_maybe_add_caps() {
    $role = get_role( 'administrator' );
    if ( $role && ! $role->has_cap( RPP_CAPABILITY ) ) {
        $role->add_cap( RPP_CAPABILITY );
    }
}
add_action( 'admin_init', 'rpp_maybe_add_caps' );
