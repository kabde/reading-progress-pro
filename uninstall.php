<?php
/**
 * Reading Time & Progress Pro — Uninstall
 *
 * Cleans up all plugin data on uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove options
delete_option( 'rpp_settings' );
delete_option( 'rpp_license_key' );
delete_option( 'rpp_license_status' );
delete_option( 'rpp_license_domain' );
delete_option( 'rpp_license_expires_at' );
delete_option( 'rpp_premium_files' );

// Remove transients
delete_transient( 'rpp_license_valid' );
delete_transient( 'rpp_premium_fresh' );
delete_transient( 'rpp_license_attempts' );

// Remove all post meta
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_rpp_reading_time'" );

// Remove active readers transients (pattern: rpp_active_readers_*)
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rpp_active_readers_%' OR option_name LIKE '_transient_timeout_rpp_active_readers_%'" );

// Drop stats table
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rpp_reading_stats" );

// Remove capability
$role = get_role( 'administrator' );
if ( $role ) {
    $role->remove_cap( 'manage_rpp' );
}

// Clear cron
wp_clear_scheduled_hook( 'rpp_validate_license_cron' );
wp_clear_scheduled_hook( 'rpp_cleanup_stats' );
