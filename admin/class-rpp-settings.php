<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RPP_Settings {

    const OPTION_KEY = 'rpp_settings';

    /** @var string Settings page hook suffix */
    private $hook = '';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* --- Menu --- */

    public function add_menu() {
        $this->hook = add_menu_page(
            'Reading Time & Progress Pro',
            'Reading Progress',
            'manage_options',
            'rpp-settings',
            [ $this, 'render' ],
            'dashicons-performance',
            24
        );
    }

    /* --- Register --- */

    public function register_settings() {
        register_setting( 'rpp_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [ $this, 'sanitize' ],
        ] );

        add_filter( 'allowed_options', function ( $allowed ) {
            $allowed['rpp_settings_group'] = [ 'rpp_settings' ];
            return $allowed;
        } );
    }

    /* --- Sanitize --- */

    public function sanitize( $input ) {
        $input = is_array( $input ) ? $input : [];
        $clean = [];

        // General
        $clean['words_per_minute'] = absint( $input['words_per_minute'] ?? 250 ) ?: 250;
        $clean['time_format']      = sanitize_text_field( $input['time_format'] ?? '{time} min read' );
        $clean['show_reading_time'] = empty( $input['show_reading_time'] ) ? '0' : '1';
        $clean['time_position']    = in_array( $input['time_position'] ?? '', [ 'before_content', 'after_title', 'after_content' ], true ) ? $input['time_position'] : 'before_content';
        $clean['show_icon']        = empty( $input['show_icon'] ) ? '0' : '1';
        $clean['show_in_listings'] = empty( $input['show_in_listings'] ) ? '0' : '1';

        // Post types
        $allowed_types = get_post_types( [ 'public' => true ], 'names' );
        $post_types    = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : [ 'post' ];
        $clean['post_types'] = array_intersect( $post_types, $allowed_types );

        $clean['content_selector'] = sanitize_text_field( $input['content_selector'] ?? '.entry-content, .post-content, article' );

        // Progress bar
        $clean['bar_enabled']  = empty( $input['bar_enabled'] ) ? '0' : '1';
        $clean['bar_color']    = sanitize_hex_color( $input['bar_color'] ?? '#ffc45e' ) ?: '#ffc45e';
        $clean['bar_height']   = max( 1, min( 10, absint( $input['bar_height'] ?? 3 ) ) );
        $clean['bar_position'] = in_array( $input['bar_position'] ?? '', [ 'top', 'bottom' ], true ) ? $input['bar_position'] : 'top';
        $clean['bar_track']    = empty( $input['bar_track'] ) ? '0' : '1';

        // Statistics
        $clean['tracking_enabled'] = empty( $input['tracking_enabled'] ) ? '0' : '1';
        $clean['exclude_admins']   = empty( $input['exclude_admins'] ) ? '0' : '1';
        $clean['retention_days']   = in_array( $input['retention_days'] ?? '', [ '30', '60', '90', '180', '365' ], true ) ? $input['retention_days'] : '90';
        $clean['social_proof']     = empty( $input['social_proof'] ) ? '0' : '1';
        $clean['social_threshold'] = max( 1, min( 100, absint( $input['social_threshold'] ?? 2 ) ) );
        $clean['custom_css']       = wp_strip_all_tags( $input['custom_css'] ?? '' );

        return $clean;
    }

    /* --- Assets --- */

    public function enqueue_assets( $hook ) {
        if ( $hook !== $this->hook ) {
            return;
        }

        wp_enqueue_style( 'rpp-admin', RPP_URL . 'admin/css/rpp-admin.css', [], RPP_VERSION );
        wp_enqueue_script( 'rpp-admin', RPP_URL . 'admin/js/rpp-admin.js', [ 'jquery' ], RPP_VERSION, true );

        // Code editor for custom CSS
        if ( function_exists( 'wp_enqueue_code_editor' ) ) {
            $editor = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
            if ( false !== $editor ) {
                wp_add_inline_script( 'code-editor', sprintf(
                    'jQuery(function(){if(document.getElementById("rpp_custom_css")){wp.codeEditor.initialize("rpp_custom_css",%s);}});',
                    wp_json_encode( $editor )
                ) );
            }
        }
    }

    /* --- Render --- */

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'reading-progress-pro' ) );
        }

        $licensed      = rpp_is_licensed();
        $license_key   = get_option( 'rpp_license_key', '' );
        $settings      = get_option( self::OPTION_KEY, [] );
        $defaults      = rpp_settings_defaults();
        $s             = wp_parse_args( $settings, $defaults );
        $tabs = [
            'license'    => [ 'label' => __( 'License', 'reading-progress-pro' ),      'icon' => 'dashicons-lock' ],
            'general'    => [ 'label' => __( 'General', 'reading-progress-pro' ),      'icon' => 'dashicons-admin-settings' ],
            'progress'   => [ 'label' => __( 'Progress Bar', 'reading-progress-pro' ), 'icon' => 'dashicons-minus' ],
            'stats'      => [ 'label' => __( 'Statistics', 'reading-progress-pro' ),   'icon' => 'dashicons-chart-bar' ],
            'docs'       => [ 'label' => __( 'Documentation', 'reading-progress-pro' ), 'icon' => 'dashicons-book' ],
        ];

        // Only show non-license tabs when licensed
        if ( ! $licensed ) {
            $tabs = [ 'license' => $tabs['license'] ];
        }

        $nonce = wp_create_nonce( 'rpp_license_nonce' );

        // Get post types for checkboxes
        $public_types = get_post_types( [ 'public' => true ], 'objects' );
        $selected_types = isset( $s['post_types'] ) && is_array( $s['post_types'] ) ? $s['post_types'] : [ 'post' ];
        ?>
        <style>
        /* -- Layout -- */
        #rpp-settings-wrap { max-width: 1140px; margin-top: 20px; }
        .rpp-settings-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
        .rpp-settings-header h1 { margin: 0; font-size: 1.6rem; font-weight: 800; color: #1d2327; }
        .rpp-settings-version { background: #f0f0f1; color: #787c82; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
        .rpp-settings-layout { display: grid; grid-template-columns: 220px 1fr; gap: 0; min-height: 600px; border: 1px solid #c3c4c7; border-radius: 8px; overflow: hidden; background: #f6f7f7; }

        /* -- Sidebar -- */
        .rpp-settings-sidebar { background: #1d2327; padding: 12px 0; display: flex; flex-direction: column; }
        .rpp-sidebar-item { display: flex; align-items: center; gap: 10px; padding: 11px 20px; color: #bbc8d4; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 120ms; border-left: 3px solid transparent; cursor: pointer; }
        .rpp-sidebar-item:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .rpp-sidebar-item:focus { color: #fff; box-shadow: none; outline: none; }
        .rpp-sidebar-item.is-active { color: #fff; background: rgba(255,255,255,0.08); border-left-color: #ffc45e; }
        .rpp-sidebar-item .dashicons { font-size: 16px; width: 16px; height: 16px; opacity: 0.65; }
        .rpp-sidebar-item.is-active .dashicons { opacity: 1; color: #ffc45e; }

        /* -- Panel -- */
        .rpp-settings-panel { background: #fff; padding: 28px 32px; overflow-y: auto; }
        .rpp-tab-content { display: none; }
        .rpp-tab-content.is-active { display: block; animation: rppFadeIn 200ms ease; }
        @keyframes rppFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        /* -- Sections -- */
        .rpp-admin-section { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px 28px; margin: 0 0 20px; }
        .rpp-admin-section h2 { margin: 0 0 16px; padding: 0 0 12px; border-bottom: 1px solid #e5e7eb; font-size: 1.05em; font-weight: 700; color: #1d2327; }
        .rpp-admin-section .form-table th { font-weight: 600; color: #374151; padding-top: 16px; }
        .rpp-admin-section .form-table td { padding-top: 12px; }

        /* -- Submit button -- */
        .rpp-settings-panel .submit { margin-top: 8px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .rpp-settings-panel #submit { background: #1d2327; border-color: #1d2327; color: #fff; border-radius: 6px; padding: 6px 24px; font-weight: 600; transition: background 120ms; }
        .rpp-settings-panel #submit:hover { background: #2c3338; }

        /* -- License card -- */
        .rpp-license-card { max-width: 600px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 30px; }
        .rpp-license-active { display: inline-block; background: #00a32a; color: #fff; padding: 6px 16px; border-radius: 20px; font-weight: 600; }
        .rpp-license-inactive { display: inline-block; background: #dba617; color: #fff; padding: 6px 16px; border-radius: 20px; font-weight: 600; }

        /* -- Stats cards -- */
        .rpp-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .rpp-stat-card { text-align: center; }
        .rpp-stat-card strong { font-size: 2rem; color: #ffc45e; }

        /* -- Chart -- */
        .rpp-chart {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            height: 160px;
            padding: 0 4px;
            margin-bottom: 24px;
        }
        .rpp-chart-bar {
            flex: 1;
            background: linear-gradient(to top, #ffc45e, #ffd47f);
            border-radius: 4px 4px 0 0;
            min-height: 4px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            transition: height 300ms ease;
        }
        .rpp-chart-value {
            font-size: 11px;
            font-weight: 700;
            color: #fff;
            padding-top: 4px;
        }
        .rpp-chart-label {
            position: absolute;
            bottom: -20px;
            font-size: 10px;
            color: #6b7280;
            white-space: nowrap;
        }

        /* -- Progress bar preview -- */
        .rpp-bar-preview-container { background: #f0f0f1; border-radius: 6px; overflow: hidden; margin-top: 16px; position: relative; height: 300px; }
        .rpp-bar-preview-bar { position: absolute; left: 0; width: 65%; transition: all 300ms; }
        .rpp-bar-preview-content { padding: 40px 20px; color: #6b7280; font-size: 13px; line-height: 2; }

        /* -- Responsive -- */
        @media (max-width: 960px) {
            .rpp-settings-layout { grid-template-columns: 1fr; }
            .rpp-settings-sidebar { flex-direction: row; flex-wrap: wrap; padding: 8px; gap: 4px; }
            .rpp-sidebar-item { padding: 8px 12px; border-left: none; border-bottom: 2px solid transparent; font-size: 12px; }
            .rpp-sidebar-item.is-active { border-left: none; border-bottom-color: #ffc45e; }
            .rpp-sidebar-item .dashicons { display: none; }
            .rpp-settings-panel { padding: 20px 16px; }
            .rpp-stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        </style>

        <div id="rpp-settings-wrap" class="wrap">

            <!-- Header -->
            <div class="rpp-settings-header">
                <h1>Reading Time &amp; Progress Pro</h1>
                <span class="rpp-settings-version">v<?php echo esc_html( RPP_VERSION ); ?></span>
            </div>

            <div class="rpp-settings-layout">

                <!-- Sidebar -->
                <nav class="rpp-settings-sidebar">
                    <?php foreach ( $tabs as $slug => $tab ) : ?>
                        <a href="#<?php echo esc_attr( $slug ); ?>" class="rpp-sidebar-item" data-tab="<?php echo esc_attr( $slug ); ?>">
                            <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                            <?php echo esc_html( $tab['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <!-- Panel -->
                <div class="rpp-settings-panel">

                    <!-- License Tab -->
                    <div id="rpp-tab-license" class="rpp-tab-content">
                        <div class="rpp-admin-section">
                            <h2><?php esc_html_e( 'License', 'reading-progress-pro' ); ?></h2>
                            <div class="rpp-license-card">
                                <?php if ( $licensed ) : ?>
                                    <div style="text-align:center;margin-bottom:20px;">
                                        <span class="rpp-license-active">&#10003; <?php esc_html_e( 'License Active', 'reading-progress-pro' ); ?></span>
                                    </div>
                                    <table class="form-table" style="margin:0;">
                                        <tr>
                                            <th><?php esc_html_e( 'License key', 'reading-progress-pro' ); ?></th>
                                            <td><?php
$masked = substr($license_key, 0, 4) . '-****-****-' . substr($license_key, -4);
?><code style="font-size:14px;"><?php echo esc_html($masked); ?></code></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Domain', 'reading-progress-pro' ); ?></th>
                                            <td><?php echo esc_html( home_url() ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Expiration', 'reading-progress-pro' ); ?></th>
                                            <td>
                                                <?php
                                                $expires = get_option( 'rpp_license_expires_at', '' );
                                                if ( $expires ) {
                                                    $days = (int) ceil( ( strtotime( $expires ) - time() ) / 86400 );
                                                    $date_formatted = wp_date( 'd F Y', strtotime( $expires ) );
                                                    if ( $days <= 0 ) {
                                                        /* translators: %s: expiration date */
                                                        echo '<span style="color:#dc2626;font-weight:600;">' . sprintf( esc_html__( 'Expired on %s', 'reading-progress-pro' ), esc_html( $date_formatted ) ) . '</span>';
                                                    } elseif ( $days <= 30 ) {
                                                        /* translators: 1: expiration date, 2: number of days remaining */
                                                        echo '<span style="color:#d97706;font-weight:600;">' . sprintf( esc_html( _n( '%1$s (%2$d day remaining)', '%1$s (%2$d days remaining)', $days, 'reading-progress-pro' ) ), esc_html( $date_formatted ), $days ) . '</span>';
                                                    } else {
                                                        /* translators: 1: expiration date, 2: number of days remaining */
                                                        echo '<span style="color:#16a34a;">' . sprintf( esc_html__( '%1$s (%2$d days remaining)', 'reading-progress-pro' ), esc_html( $date_formatted ), $days ) . '</span>';
                                                    }
                                                } else {
                                                    echo '<span style="color:#16a34a;">' . esc_html__( 'Lifetime (no expiration)', 'reading-progress-pro' ) . '</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin-top:20px;">
                                        <button type="button" id="rpp-deactivate-btn" class="button button-secondary" style="color:#d63638;"><?php esc_html_e( 'Deactivate license', 'reading-progress-pro' ); ?></button>
                                    </p>
                                <?php else : ?>
                                    <h2 style="margin-top:0;"><?php esc_html_e( 'Activate your license', 'reading-progress-pro' ); ?></h2>
                                    <p><?php esc_html_e( 'Enter your license key to activate Reading Time & Progress Pro.', 'reading-progress-pro' ); ?></p>
                                    <p>
                                        <input type="text" id="rpp-license-key" placeholder="RPP-XXXX-XXXX-XXXX" style="width:100%;font-size:16px;padding:8px 12px;font-family:monospace;text-transform:uppercase;" maxlength="19">
                                    </p>
                                    <p>
                                        <button type="button" id="rpp-activate-btn" class="button button-primary button-hero" style="width:100%;"><?php esc_html_e( 'Activate license', 'reading-progress-pro' ); ?></button>
                                    </p>
                                    <div id="rpp-license-message" style="margin-top:15px;display:none;"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ( $licensed ) : ?>

                    <!-- Form wraps General + Progress + Stats -->
                    <form method="post" action="options.php" id="rpp-settings-form">
                        <?php settings_fields( 'rpp_settings_group' ); ?>
                        <input type="hidden" id="rpp_active_tab" name="rpp_active_tab" value="">

                        <!-- General Tab -->
                        <div id="rpp-tab-general" class="rpp-tab-content">
                            <div class="rpp-admin-section">
                                <h2><?php esc_html_e( 'Reading Time', 'reading-progress-pro' ); ?></h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Words per minute', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <input type="number" name="rpp_settings[words_per_minute]" value="<?php echo esc_attr( $s['words_per_minute'] ); ?>" class="small-text" min="100" max="1000" step="10">
                                            <p class="description"><?php esc_html_e( 'Average reading speed. 250 is the standard for most languages.', 'reading-progress-pro' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Display format', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <input type="text" name="rpp_settings[time_format]" value="<?php echo esc_attr( $s['time_format'] ); ?>" class="regular-text">
                                            <p class="description"><?php
                                                /* translators: %s: {time} placeholder */
                                                printf( esc_html__( 'Use %s for the number of minutes. E.g.: %s, %s', 'reading-progress-pro' ), '<code>{time}</code>', '<code>{time} min read</code>', '<code>{time} min de lecture</code>' );
                                            ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Show reading time', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[show_reading_time]" value="1" <?php checked( $s['show_reading_time'], '1' ); ?>>
                                                <?php esc_html_e( 'Automatically display reading time on posts', 'reading-progress-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Position', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <select name="rpp_settings[time_position]">
                                                <option value="before_content" <?php selected( $s['time_position'], 'before_content' ); ?>><?php esc_html_e( 'Before content', 'reading-progress-pro' ); ?></option>
                                                <option value="after_title" <?php selected( $s['time_position'], 'after_title' ); ?>><?php esc_html_e( 'After title', 'reading-progress-pro' ); ?></option>
                                                <option value="after_content" <?php selected( $s['time_position'], 'after_content' ); ?>><?php esc_html_e( 'After content', 'reading-progress-pro' ); ?></option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Icon', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[show_icon]" value="1" <?php checked( $s['show_icon'], '1' ); ?>>
                                                <?php esc_html_e( 'Show clock icon &#128337; before reading time', 'reading-progress-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Post listings', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[show_in_listings]" value="1" <?php checked( $s['show_in_listings'], '1' ); ?>>
                                                <?php esc_html_e( 'Also display in archives, categories, and search results', 'reading-progress-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Content types', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <?php foreach ( $public_types as $type ) : ?>
                                                <label style="display:block;margin-bottom:6px;">
                                                    <input type="checkbox" name="rpp_settings[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $selected_types, true ) ); ?>>
                                                    <?php echo esc_html( $type->labels->name ); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Content CSS selector', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <input type="text" name="rpp_settings[content_selector]" value="<?php echo esc_attr( $s['content_selector'] ); ?>" class="regular-text">
                                            <p class="description"><?php esc_html_e( 'CSS selector used to detect the content area (for the progress bar). E.g.:', 'reading-progress-pro' ); ?> <code>.entry-content, .post-content, article</code></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="submit">
                                <?php submit_button( __( 'Save', 'reading-progress-pro' ), 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                        <!-- Progress Bar Tab -->
                        <div id="rpp-tab-progress" class="rpp-tab-content">
                            <div class="rpp-admin-section">
                                <h2><?php esc_html_e( 'Progress Bar', 'reading-progress-pro' ); ?></h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Enable bar', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" id="rpp-bar-enabled" name="rpp_settings[bar_enabled]" value="1" <?php checked( $s['bar_enabled'], '1' ); ?>>
                                                <?php esc_html_e( 'Show a reading progress bar', 'reading-progress-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Color', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <input type="color" id="rpp-bar-color" name="rpp_settings[bar_color]" value="<?php echo esc_attr( $s['bar_color'] ); ?>">
                                            <code id="rpp-bar-color-hex"><?php echo esc_html( $s['bar_color'] ); ?></code>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Height', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <input type="range" id="rpp-bar-height" name="rpp_settings[bar_height]" value="<?php echo esc_attr( $s['bar_height'] ); ?>" min="1" max="10" step="1">
                                            <span id="rpp-bar-height-val"><?php echo esc_html( $s['bar_height'] ); ?>px</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Position', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <label style="display:inline-block;margin-right:20px;">
                                                <input type="radio" id="rpp-bar-pos-top" name="rpp_settings[bar_position]" value="top" <?php checked( $s['bar_position'], 'top' ); ?>>
                                                <?php esc_html_e( 'Top of page', 'reading-progress-pro' ); ?>
                                            </label>
                                            <label>
                                                <input type="radio" id="rpp-bar-pos-bottom" name="rpp_settings[bar_position]" value="bottom" <?php checked( $s['bar_position'], 'bottom' ); ?>>
                                                <?php esc_html_e( 'Bottom of page', 'reading-progress-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Track background', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" id="rpp-bar-track" name="rpp_settings[bar_track]" value="1" <?php checked( $s['bar_track'], '1' ); ?>>
                                                <?php esc_html_e( 'Show a semi-transparent background behind the bar', 'reading-progress-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Live Preview -->
                            <div class="rpp-admin-section">
                                <h2><?php esc_html_e( 'Preview', 'reading-progress-pro' ); ?></h2>
                                <div class="rpp-bar-preview-container" id="rpp-preview">
                                    <div id="rpp-preview-track" style="position:absolute;left:0;width:100%;background:rgba(0,0,0,0.08);"></div>
                                    <div id="rpp-preview-bar" class="rpp-bar-preview-bar" style="background:<?php echo esc_attr( $s['bar_color'] ); ?>;height:<?php echo esc_attr( $s['bar_height'] ); ?>px;top:0;"></div>
                                    <div class="rpp-bar-preview-content">
                                        <p><strong><?php esc_html_e( 'Article title', 'reading-progress-pro' ); ?></strong></p>
                                        <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>
                                        <p>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>
                                        <p>Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="submit">
                                <?php submit_button( __( 'Save', 'reading-progress-pro' ), 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                        <!-- Stats Tab -->
                        <div id="rpp-tab-stats" class="rpp-tab-content">
                            <div class="rpp-admin-section">
                                <h2><?php esc_html_e( 'Tracking Settings', 'reading-progress-pro' ); ?></h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Enable tracking', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[tracking_enabled]" value="1" <?php checked( $s['tracking_enabled'], '1' ); ?>>
                                                <?php esc_html_e( 'Record reading data (scroll, time spent)', 'reading-progress-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Exclude admins', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[exclude_admins]" value="1" <?php checked( $s['exclude_admins'], '1' ); ?>>
                                                <?php esc_html_e( 'Do not track administrators', 'reading-progress-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Data retention', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <select name="rpp_settings[retention_days]">
                                                <?php
                                                /* translators: %d: number of days */
                                                $day_label = __( '%d days', 'reading-progress-pro' );
                                                ?>
                                                <option value="30" <?php selected( $s['retention_days'], '30' ); ?>><?php printf( $day_label, 30 ); ?></option>
                                                <option value="60" <?php selected( $s['retention_days'], '60' ); ?>><?php printf( $day_label, 60 ); ?></option>
                                                <option value="90" <?php selected( $s['retention_days'], '90' ); ?>><?php printf( $day_label, 90 ); ?></option>
                                                <option value="180" <?php selected( $s['retention_days'], '180' ); ?>><?php printf( $day_label, 180 ); ?></option>
                                                <option value="365" <?php selected( $s['retention_days'], '365' ); ?>><?php esc_html_e( '1 year', 'reading-progress-pro' ); ?></option>
                                            </select>
                                            <p class="description"><?php esc_html_e( 'How long tracking data is kept. Older data is automatically deleted.', 'reading-progress-pro' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Social proof', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[social_proof]" value="1" <?php checked( $s['social_proof'], '1' ); ?>>
                                                <?php esc_html_e( 'Show the number of current readers', 'reading-progress-pro' ); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Minimum threshold', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <input type="number" name="rpp_settings[social_threshold]" value="<?php echo esc_attr( $s['social_threshold'] ); ?>" class="small-text" min="1" max="100">
                                            <p class="description"><?php esc_html_e( 'Minimum number of active readers to display the social proof.', 'reading-progress-pro' ); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Custom CSS', 'reading-progress-pro' ); ?></th>
                                        <td>
                                            <textarea id="rpp_custom_css" name="rpp_settings[custom_css]" rows="8" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
                                            <p class="description"><?php esc_html_e( 'Custom CSS applied on the front-end.', 'reading-progress-pro' ); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Stats Dashboard -->
                            <?php $this->render_stats_dashboard(); ?>

                            <div class="submit">
                                <?php submit_button( __( 'Save', 'reading-progress-pro' ), 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                    </form>

                    <!-- Documentation tab (outside the form, no save needed) -->
                    <div id="rpp-tab-docs" class="rpp-tab-content">

                        <div class="rpp-admin-section">
                            <h2><?php esc_html_e( 'Getting Started', 'reading-progress-pro' ); ?></h2>
                            <ol style="line-height:2;font-size:14px;color:#374151;">
                                <li><?php printf( esc_html__( 'Activate your %slicense%s in the License tab', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( 'Configure the %sreading speed%s and display format in General', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( 'Customize the %sprogress bar%s (color, height, position)', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( 'Enable %stracking%s to monitor reading statistics', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php esc_html_e( "That's it! Reading time and the progress bar display automatically.", 'reading-progress-pro' ); ?></li>
                            </ol>
                        </div>

                        <div class="rpp-admin-section">
                            <h2><?php esc_html_e( 'Reading Time', 'reading-progress-pro' ); ?></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'Reading time is calculated automatically based on the number of words and images in your article:', 'reading-progress-pro' ); ?></p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><?php printf( esc_html__( '%sWords%s are counted and divided by the configured speed (default: 250 words/min)', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( '%sImages%s add reading time (12 sec for the first, decreasing down to 3 sec minimum)', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( 'The minimum time is %s1 minute%s', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php esc_html_e( 'The time is updated automatically each time the article is saved', 'reading-progress-pro' ); ?></li>
                            </ul>
                            <p style="color:#374151;"><?php esc_html_e( 'The format is customizable with the {time} variable. Examples:', 'reading-progress-pro' ); ?></p>
                            <table class="widefat striped" style="max-width:500px;">
                                <thead><tr><th><?php esc_html_e( 'Format', 'reading-progress-pro' ); ?></th><th><?php esc_html_e( 'Result', 'reading-progress-pro' ); ?></th></tr></thead>
                                <tbody>
                                    <tr><td><code>{time} min read</code></td><td>5 min read</td></tr>
                                    <tr><td><code>{time} min de lecture</code></td><td>5 min de lecture</td></tr>
                                    <tr><td><code>Reading time: {time} min</code></td><td>Reading time: 5 min</td></tr>
                                    <tr><td><code>{time} minutes</code></td><td>5 minutes</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="rpp-admin-section">
                            <h2><?php esc_html_e( 'Progress Bar', 'reading-progress-pro' ); ?></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'The progress bar visually indicates the reader\'s position in the article:', 'reading-progress-pro' ); ?></p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><?php printf( esc_html__( 'Based on the configured %sCSS selector%s to detect the content area', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( 'Displayed at the %stop%s or %sbottom%s of the window (fixed position)', 'reading-progress-pro' ), '<strong>', '</strong>', '<strong>', '</strong>' ); ?></li>
                                <li><?php esc_html_e( 'Color, height, and background are customizable', 'reading-progress-pro' ); ?></li>
                                <li><?php esc_html_e( 'Uses performant vanilla JavaScript (no jQuery, passive scroll)', 'reading-progress-pro' ); ?></li>
                                <li><?php printf( esc_html__( 'Only displayed on %ssingular posts%s (not archives)', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                            </ul>
                        </div>

                        <div class="rpp-admin-section">
                            <h2><?php esc_html_e( 'Tracking & Statistics', 'reading-progress-pro' ); ?> <span style="background:#f0f0f1;color:#787c82;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;">Premium</span></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'Tracking records anonymized reading data:', 'reading-progress-pro' ); ?></p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><?php printf( esc_html__( '%sScroll percentage%s — how far the reader scrolled', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( '%sTime spent%s — total time on the page', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( '%sCompletion rate%s — percentage of readers who read to the end', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                                <li><?php printf( esc_html__( '%sTop articles%s — the most read articles', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                            </ul>
                            <p style="color:#374151;"><?php esc_html_e( 'Data is sent via AJAX every 30 seconds and on page exit. No personal data is stored.', 'reading-progress-pro' ); ?></p>
                        </div>

                        <div class="rpp-admin-section">
                            <h2><?php esc_html_e( 'Social Proof', 'reading-progress-pro' ); ?></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'When enabled, a badge is displayed below the reading time to show how many people are currently reading the article:', 'reading-progress-pro' ); ?></p>
                            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:16px;margin:12px 0;display:inline-block;">
                                <span style="color:#666;">&#128337; 5 min read</span><br>
                                <span style="color:#16a34a;font-size:12px;">&#9679; <?php esc_html_e( '3 people are reading this article', 'reading-progress-pro' ); ?></span>
                            </div>
                            <p style="color:#374151;"><?php esc_html_e( 'The counter uses WordPress transients (2-minute cache) to count active sessions. You can set a minimum threshold to avoid displaying "1 person is reading this article".', 'reading-progress-pro' ); ?></p>
                        </div>

                        <div class="rpp-admin-section">
                            <h2><?php esc_html_e( 'Shortcodes', 'reading-progress-pro' ); ?></h2>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead><tr><th><?php esc_html_e( 'Shortcode', 'reading-progress-pro' ); ?></th><th><?php esc_html_e( 'Result', 'reading-progress-pro' ); ?></th></tr></thead>
                                <tbody>
                                    <tr>
                                        <td><code>[reading_time]</code></td>
                                        <td><?php esc_html_e( 'Displays the reading time for the current article', 'reading-progress-pro' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>[reading_time id="123"]</code></td>
                                        <td><?php esc_html_e( 'Displays the reading time for a specific article', 'reading-progress-pro' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>[reading_time format="{time} minutes"]</code></td>
                                        <td><?php esc_html_e( 'Custom format for this instance', 'reading-progress-pro' ); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="rpp-admin-section">
                            <h2><?php esc_html_e( 'CSS Customization', 'reading-progress-pro' ); ?></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'Available CSS classes to customize the display:', 'reading-progress-pro' ); ?></p>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead><tr><th><?php esc_html_e( 'Class', 'reading-progress-pro' ); ?></th><th><?php esc_html_e( 'Description', 'reading-progress-pro' ); ?></th></tr></thead>
                                <tbody>
                                    <tr><td><code>.rpp-reading-time</code></td><td><?php esc_html_e( 'Reading time container', 'reading-progress-pro' ); ?></td></tr>
                                    <tr><td><code>.rpp-icon</code></td><td><?php esc_html_e( 'Clock icon', 'reading-progress-pro' ); ?></td></tr>
                                    <tr><td><code>.rpp-time-text</code></td><td><?php esc_html_e( 'Reading time text', 'reading-progress-pro' ); ?></td></tr>
                                    <tr><td><code>.rpp-social-proof</code></td><td><?php esc_html_e( 'Social proof badge', 'reading-progress-pro' ); ?></td></tr>
                                    <tr><td><code>#rpp-progress-track</code></td><td><?php esc_html_e( 'Progress bar track', 'reading-progress-pro' ); ?></td></tr>
                                    <tr><td><code>#rpp-bar</code></td><td><?php esc_html_e( 'Progress bar (fill)', 'reading-progress-pro' ); ?></td></tr>
                                </tbody>
                            </table>
                            <p style="color:#374151;margin-top:12px;"><?php esc_html_e( 'You can also add custom CSS in the Statistics tab (CSS field).', 'reading-progress-pro' ); ?></p>
                        </div>

                        <div class="rpp-admin-section">
                            <h2><?php esc_html_e( 'License', 'reading-progress-pro' ); ?></h2>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><?php printf( esc_html__( 'The plugin requires a %slicense key%s in the format %s', 'reading-progress-pro' ), '<strong>', '</strong>', '<code>RPP-XXXX-XXXX-XXXX</code>' ); ?></li>
                                <li><?php esc_html_e( 'The license is validated automatically every 72 hours', 'reading-progress-pro' ); ?></li>
                                <li><?php printf( esc_html__( 'Depending on your license, it can be %ssingle-domain%s or %smulti-domain%s', 'reading-progress-pro' ), '<strong>', '</strong>', '<strong>', '</strong>' ); ?></li>
                                <li><?php esc_html_e( 'When changing domains, deactivate the license on the old domain first', 'reading-progress-pro' ); ?></li>
                                <li><?php esc_html_e( 'Plugin updates are automatic via the WordPress admin', 'reading-progress-pro' ); ?></li>
                                <li><?php printf( esc_html__( 'Reading time calculation works %swithout a license%s (free). The progress bar, tracking, and social proof require an active license.', 'reading-progress-pro' ), '<strong>', '</strong>' ); ?></li>
                            </ul>
                        </div>

                        <div class="rpp-admin-section" style="background:#fefce8;border-color:#fde68a;">
                            <h2 style="border-color:#fde68a;"><?php esc_html_e( 'Support', 'reading-progress-pro' ); ?></h2>
                            <p style="color:#374151;"><?php esc_html_e( 'For any questions or issues:', 'reading-progress-pro' ); ?></p>
                            <ul style="list-style:none;padding:0;line-height:2.2;">
                                <li>Email : <a href="mailto:contact@khalid.digital">contact@khalid.digital</a></li>
                            </ul>
                        </div>

                    </div>

                    <?php endif; ?>

                </div><!-- .rpp-settings-panel -->
            </div><!-- .rpp-settings-layout -->
        </div><!-- #rpp-settings-wrap -->

        <script>
        jQuery(function($) {
            /* -- Tab switching -- */
            var $items = $('.rpp-sidebar-item');
            var $tabs  = $('.rpp-tab-content');

            function activateTab(slug) {
                $items.removeClass('is-active');
                $tabs.removeClass('is-active');
                $items.filter('[data-tab="' + slug + '"]').addClass('is-active');
                $('#rpp-tab-' + slug).addClass('is-active');
                $('#rpp_active_tab').val(slug);
                if (history.replaceState) {
                    history.replaceState(null, null, '#' + slug);
                }
            }

            $items.on('click', function(e) {
                e.preventDefault();
                activateTab($(this).data('tab'));
            });

            // Determine initial tab
            var hash = window.location.hash.replace('#', '');
            var validTabs = [];
            $items.each(function() { validTabs.push($(this).data('tab')); });

            if (hash && validTabs.indexOf(hash) !== -1) {
                activateTab(hash);
            } else {
                activateTab(validTabs[0] || 'license');
            }

            /* -- License AJAX -- */
            var licenseNonce = '<?php echo esc_js( $nonce ); ?>';

            $('#rpp-activate-btn').on('click', function() {
                var btn = $(this);
                var key = $('#rpp-license-key').val().trim();
                if (!key) return;

                btn.prop('disabled', true).text('<?php echo esc_js( __( 'Activating...', 'reading-progress-pro' ) ); ?>');

                $.post(ajaxurl, {
                    action: 'rpp_activate_license',
                    nonce: licenseNonce,
                    license_key: key
                }, function(response) {
                    if (response.success) {
                        $('#rpp-license-message').html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>').show();
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        $('#rpp-license-message').html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>').show();
                        btn.prop('disabled', false).text('<?php echo esc_js( __( 'Activate license', 'reading-progress-pro' ) ); ?>');
                    }
                }).fail(function() {
                    $('#rpp-license-message').html('<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Connection error.', 'reading-progress-pro' ) ); ?></p></div>').show();
                    btn.prop('disabled', false).text('<?php echo esc_js( __( 'Activate license', 'reading-progress-pro' ) ); ?>');
                });
            });

            $('#rpp-deactivate-btn').on('click', function() {
                if (!confirm('<?php echo esc_js( __( 'Deactivate the license on this domain?', 'reading-progress-pro' ) ); ?>')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('<?php echo esc_js( __( 'Deactivating...', 'reading-progress-pro' ) ); ?>');

                $.post(ajaxurl, {
                    action: 'rpp_deactivate_license',
                    nonce: licenseNonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    }
                });
            });
        });
        </script>
        <?php
    }

    /* --- Stats Dashboard --- */

    private function render_stats_dashboard() {
        $completion = function_exists( 'rpp_get_completion_rate' ) ? rpp_get_completion_rate( 0, 30 ) : 0;
        $avg_time   = function_exists( 'rpp_get_avg_time' ) ? rpp_get_avg_time( 0, 30 ) : 0;
        $top        = function_exists( 'rpp_get_top_articles' ) ? rpp_get_top_articles( 10, 30 ) : [];
        $daily      = function_exists( 'rpp_get_daily_reads' ) ? rpp_get_daily_reads( 7 ) : [];

        $maxReads = 0;
        if ( ! empty( $daily ) ) {
            foreach ( $daily as $d ) {
                if ( isset( $d->reads ) && $d->reads > $maxReads ) $maxReads = $d->reads;
            }
        }
        ?>
        <!-- Stats cards -->
        <div class="rpp-stats-grid">
            <div class="rpp-admin-section rpp-stat-card">
                <strong><?php echo esc_html( round( $completion ) ); ?>%</strong>
                <p><?php esc_html_e( 'Completion rate (30d)', 'reading-progress-pro' ); ?></p>
            </div>
            <div class="rpp-admin-section rpp-stat-card">
                <strong><?php echo esc_html( round( $avg_time ) ); ?>s</strong>
                <p><?php esc_html_e( 'Avg. time (30d)', 'reading-progress-pro' ); ?></p>
            </div>
            <div class="rpp-admin-section rpp-stat-card">
                <strong><?php echo count( $top ); ?></strong>
                <p><?php esc_html_e( 'Tracked articles', 'reading-progress-pro' ); ?></p>
            </div>
            <div class="rpp-admin-section rpp-stat-card">
                <strong><?php echo absint( array_sum( array_map( function( $d ) { return isset( $d->reads ) ? $d->reads : 0; }, $daily ) ) ); ?></strong>
                <p><?php esc_html_e( 'Reads (7d)', 'reading-progress-pro' ); ?></p>
            </div>
        </div>

        <!-- Top 10 -->
        <div class="rpp-admin-section">
            <h2><?php esc_html_e( 'Top 10 articles — last 30 days', 'reading-progress-pro' ); ?></h2>
            <?php if ( ! empty( $top ) ) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php esc_html_e( 'Article', 'reading-progress-pro' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Reads', 'reading-progress-pro' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Completion', 'reading-progress-pro' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Avg. time', 'reading-progress-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $top as $i => $row ) : ?>
                            <tr>
                                <td><?php echo absint( $i + 1 ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $row->post_id ) . '&action=edit' ) ); ?>">
                                        <?php echo esc_html( get_the_title( $row->post_id ) ?: __( '(no title)', 'reading-progress-pro' ) ); ?>
                                    </a>
                                </td>
                                <td style="text-align:right;font-weight:600;"><?php echo absint( $row->reads ); ?></td>
                                <td style="text-align:right;"><?php echo isset( $row->completion ) ? round( $row->completion ) . '%' : '—'; ?></td>
                                <td style="text-align:right;"><?php echo isset( $row->avg_time ) ? round( $row->avg_time ) . 's' : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color:#6b7280;"><?php esc_html_e( 'No data available. Enable tracking to start collecting statistics.', 'reading-progress-pro' ); ?></p>
            <?php endif; ?>
        </div>

        <!-- Daily chart (7 days) -->
        <div class="rpp-admin-section">
            <h2><?php esc_html_e( 'Reads per day — last 7 days', 'reading-progress-pro' ); ?></h2>
            <?php if ( ! empty( $daily ) ) : ?>
                <div class="rpp-chart">
                    <?php foreach ( $daily as $d ) : ?>
                        <div class="rpp-chart-bar" style="height:<?php echo $maxReads ? round( $d->reads / $maxReads * 100 ) : 0; ?>%">
                            <span class="rpp-chart-value"><?php echo absint( $d->reads ); ?></span>
                            <span class="rpp-chart-label"><?php echo esc_html( date( 'd/m', strtotime( $d->day ) ) ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p style="color:#6b7280;"><?php esc_html_e( 'No data available.', 'reading-progress-pro' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}

/* --- Defaults --- */

function rpp_settings_defaults() {
    return [
        'words_per_minute'   => '250',
        'time_format'        => '{time} min read',
        'show_reading_time'  => '1',
        'time_position'      => 'before_content',
        'show_icon'          => '1',
        'show_in_listings'   => '1',
        'post_types'         => [ 'post' ],
        'content_selector'   => '.entry-content, .post-content, article',
        'bar_enabled'        => '1',
        'bar_color'          => '#ffc45e',
        'bar_height'         => '3',
        'bar_position'       => 'top',
        'bar_track'          => '1',
        'tracking_enabled'   => '1',
        'exclude_admins'     => '1',
        'retention_days'     => '90',
        'social_proof'       => '1',
        'social_threshold'   => '2',
        'custom_css'         => '',
    ];
}

/* --- Helper --- */

function rpp_get_setting( $key ) {
    static $settings = null;
    if ( $settings === null ) {
        $settings = get_option( RPP_Settings::OPTION_KEY, [] );
    }
    $defaults = rpp_settings_defaults();
    return isset( $settings[ $key ] ) && $settings[ $key ] !== '' ? $settings[ $key ] : ( $defaults[ $key ] ?? '' );
}
