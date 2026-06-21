<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Check if plugin is licensed
 */
function rpp_is_licensed() {
    // Check cached result first
    static $result = null;
    if ( $result !== null ) return $result;

    $status = get_option( 'rpp_license_status', '' );
    if ( $status === 'valid' ) {
        // Check transient for periodic revalidation
        if ( false === get_transient( 'rpp_license_valid' ) ) {
            // Schedule revalidation but don't block
            if ( ! wp_next_scheduled( 'rpp_validate_license_cron' ) ) {
                wp_schedule_single_event( time() + 10, 'rpp_validate_license_cron' );
            }
        }
        $result = true;
        return true;
    }
    $result = false;
    return false;
}

/**
 * Activate license
 */
function rpp_activate_license( $key ) {
    $attempts = (int) get_transient( 'rpp_license_attempts' );
    if ( $attempts >= 5 ) {
        return [ 'success' => false, 'message' => 'Trop de tentatives. Réessayez dans une minute.' ];
    }
    set_transient( 'rpp_license_attempts', $attempts + 1, MINUTE_IN_SECONDS );

    $key = strtoupper( sanitize_text_field( trim( $key ) ) );
    if ( ! preg_match( '/^RPP-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key ) ) {
        return [ 'success' => false, 'message' => 'Format de licence invalide.' ];
    }

    $response = wp_remote_post( RPP_API_URL . '/activate', [
        'timeout' => 15,
        'body'    => json_encode([
            'license_key' => $key,
            'domain'      => home_url(),
            'product'     => 'reading-progress-pro',
        ]),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ]);

    if ( is_wp_error( $response ) ) {
        error_log( '[RPP] License activation error: ' . $response->get_error_message() );
        return [ 'success' => false, 'message' => 'Erreur de connexion: ' . $response->get_error_message() ];
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! empty( $body['success'] ) ) {
        update_option( 'rpp_license_key', $key );
        update_option( 'rpp_license_status', 'valid' );
        update_option( 'rpp_license_domain', home_url() );
        if ( isset( $body['expires_at'] ) ) {
            update_option( 'rpp_license_expires_at', sanitize_text_field( $body['expires_at'] ) );
        }
        set_transient( 'rpp_license_valid', 1, 72 * HOUR_IN_SECONDS );
        return [ 'success' => true, 'message' => $body['message'] ?? 'Licence activée.' ];
    }

    return [ 'success' => false, 'message' => $body['message'] ?? 'Activation échouée.' ];
}

/**
 * Deactivate license
 */
function rpp_deactivate_license() {
    $key = get_option( 'rpp_license_key', '' );
    if ( empty( $key ) ) return;

    wp_remote_post( RPP_API_URL . '/deactivate', [
        'timeout' => 15,
        'body'    => json_encode([
            'license_key' => $key,
            'domain'      => home_url(),
            'product'     => 'reading-progress-pro',
        ]),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ]);

    delete_option( 'rpp_license_key' );
    delete_option( 'rpp_license_status' );
    delete_option( 'rpp_license_domain' );
    delete_option( 'rpp_license_expires_at' );
    delete_transient( 'rpp_license_valid' );
}

/**
 * Validate license (called by cron)
 */
function rpp_validate_license() {
    $key = get_option( 'rpp_license_key', '' );
    if ( empty( $key ) ) return;

    $response = wp_remote_post( RPP_API_URL . '/validate', [
        'timeout' => 15,
        'body'    => json_encode([
            'license_key' => $key,
            'domain'      => home_url(),
            'product'     => 'reading-progress-pro',
        ]),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ]);

    if ( is_wp_error( $response ) ) {
        error_log( '[RPP] License validation error: ' . $response->get_error_message() );
        return;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! empty( $body['valid'] ) ) {
        update_option( 'rpp_license_status', 'valid' );
        if ( isset( $body['expires_at'] ) ) {
            update_option( 'rpp_license_expires_at', sanitize_text_field( $body['expires_at'] ) );
        }
        set_transient( 'rpp_license_valid', 1, 72 * HOUR_IN_SECONDS );
    } else {
        update_option( 'rpp_license_status', 'invalid' );
        delete_transient( 'rpp_license_valid' );
    }
}
add_action( 'rpp_validate_license_cron', 'rpp_validate_license' );

// Schedule cron
function rpp_schedule_validation() {
    if ( ! wp_next_scheduled( 'rpp_validate_license_cron' ) && rpp_is_licensed() ) {
        wp_schedule_event( time(), 'twicedaily', 'rpp_validate_license_cron' );
    }
}
add_action( 'init', 'rpp_schedule_validation' );

// Cleanup cron on deactivation
register_deactivation_hook( RPP_FILE, function() {
    wp_clear_scheduled_hook( 'rpp_validate_license_cron' );
    delete_transient( 'rpp_license_valid' );
});

/**
 * Auto-update via Worker
 */
function rpp_check_plugin_update( $transient ) {
    if ( empty( $transient ) || ! is_object( $transient ) ) return $transient;

    $response = wp_remote_get( RPP_API_URL . '/update-check?product=reading-progress-pro', [
        'timeout' => 10,
    ]);

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return $transient;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $data['version'] ) || ! version_compare( RPP_VERSION, $data['version'], '<' ) ) {
        return $transient;
    }

    $transient->response[ RPP_BASENAME ] = (object) [
        'slug'         => 'reading-progress-pro',
        'plugin'       => RPP_BASENAME,
        'new_version'  => $data['version'],
        'url'          => $data['url'] ?? '',
        'package'      => $data['download_url'] ?? '',
        'tested'       => '7.0',
        'requires'     => '5.0',
        'requires_php' => '7.4',
    ];

    return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'rpp_check_plugin_update' );

/**
 * Admin notice when not licensed
 */
function rpp_admin_notice_no_license() {
    if ( rpp_is_licensed() ) return;
    $screen = get_current_screen();
    if ( $screen && $screen->id === 'toplevel_page_rpp-settings' ) return;

    echo '<div class="notice notice-warning"><p>';
    echo '<strong>Reading Time &amp; Progress Pro</strong> — ';
    echo 'Veuillez <a href="' . esc_url( admin_url( 'admin.php?page=rpp-settings' ) ) . '">activer votre licence</a> pour utiliser le plugin.';
    echo '</p></div>';
}
add_action( 'admin_notices', 'rpp_admin_notice_no_license' );

function rpp_admin_notice_expiring() {
    if ( ! rpp_is_licensed() ) return;
    $expires = get_option( 'rpp_license_expires_at', '' );
    if ( ! $expires ) return;
    $days = (int) ceil( ( strtotime( $expires ) - time() ) / 86400 );
    if ( $days > 14 ) return;
    $screen = get_current_screen();
    if ( $screen && $screen->id === 'toplevel_page_rpp-settings' ) return;

    if ( $days <= 0 ) {
        echo '<div class="notice notice-error"><p><strong>Reading Time &amp; Progress Pro</strong> — Votre licence a expir&eacute;. <a href="' . esc_url( admin_url( 'admin.php?page=rpp-settings' ) ) . '">Renouveler</a></p></div>';
    } else {
        echo '<div class="notice notice-warning"><p><strong>Reading Time &amp; Progress Pro</strong> — Votre licence expire dans ' . $days . ' jour' . ($days > 1 ? 's' : '') . '. <a href="' . esc_url( admin_url( 'admin.php?page=rpp-settings' ) ) . '">Voir</a></p></div>';
    }
}
add_action( 'admin_notices', 'rpp_admin_notice_expiring' );

/**
 * AJAX handlers
 */
function rpp_ajax_activate_license() {
    check_ajax_referer( 'rpp_license_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission refusée.' );

    $key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
    $result = rpp_activate_license( $key );

    if ( $result['success'] ) {
        wp_send_json_success( $result['message'] );
    } else {
        wp_send_json_error( $result['message'] );
    }
}
add_action( 'wp_ajax_rpp_activate_license', 'rpp_ajax_activate_license' );

function rpp_ajax_deactivate_license() {
    check_ajax_referer( 'rpp_license_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission refusée.' );

    rpp_deactivate_license();
    wp_send_json_success( 'Licence désactivée.' );
}
add_action( 'wp_ajax_rpp_deactivate_license', 'rpp_ajax_deactivate_license' );

/**
 * Derive encryption key from license key.
 */
function rpp_get_encryption_key() {
    $key = get_option( 'rpp_license_key', '' );
    if ( ! $key ) return '';
    $raw = strtoupper( str_replace( '-', '', $key ) );
    return str_pad( substr( $raw, 0, 32 ), 32, '0' );
}

/**
 * Decrypt AES-256-GCM data from Worker.
 */
function rpp_decrypt_aes( $encrypted, $key ) {
    $raw = base64_decode( $encrypted, true );
    if ( ! $raw || strlen( $raw ) < 29 ) return false;

    $iv         = substr( $raw, 0, 12 );
    $ciphertext = substr( $raw, 12 );

    $decrypted = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, substr( $ciphertext, -16 ) );

    if ( $decrypted === false ) {
        $tag  = substr( $raw, -16 );
        $data = substr( $raw, 12, -16 );
        $decrypted = openssl_decrypt( $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
    }

    return $decrypted;
}

/**
 * Download premium PHP files from Worker.
 */
function rpp_download_premium() {
    $key    = get_option( 'rpp_license_key', '' );
    $domain = home_url();

    if ( ! $key ) return false;

    $response = wp_remote_post( RPP_API_URL . '/premium', [
        'timeout' => 30,
        'body'    => wp_json_encode( [
            'license_key' => $key,
            'domain'      => $domain,
            'product'     => 'reading-progress-pro',
        ] ),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[RPP] Premium download error: ' . $response->get_error_message() );
        return false;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $body['files'] ) || ! is_array( $body['files'] ) ) return false;

    update_option( 'rpp_premium_files', $body['files'], false );
    set_transient( 'rpp_premium_fresh', 1, DAY_IN_SECONDS );
    return true;
}

/**
 * Load premium code from stored encrypted files.
 */
function rpp_load_premium_code() {
    if ( ! rpp_is_licensed() ) return;

    // Re-download if stale
    if ( false === get_transient( 'rpp_premium_fresh' ) ) {
        rpp_download_premium();
    }

    $files = get_option( 'rpp_premium_files', [] );
    if ( ! is_array( $files ) || empty( $files ) ) return;

    $enc_key = rpp_get_encryption_key();
    if ( ! $enc_key ) return;

    $load_order = [ 'frontend' ];

    foreach ( $load_order as $name ) {
        if ( ! isset( $files[ $name ] ) ) continue;
        $code = rpp_decrypt_aes( $files[ $name ], $enc_key );
        if ( $code && is_string( $code ) ) {
            eval( $code );
        }
    }
}
