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
            'dashicons-clock',
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

        // Général
        $clean['words_per_minute'] = absint( $input['words_per_minute'] ?? 250 ) ?: 250;
        $clean['time_format']      = sanitize_text_field( $input['time_format'] ?? '{time} min de lecture' );
        $clean['show_reading_time'] = empty( $input['show_reading_time'] ) ? '0' : '1';
        $clean['time_position']    = in_array( $input['time_position'] ?? '', [ 'before_content', 'after_title', 'after_content' ], true ) ? $input['time_position'] : 'before_content';
        $clean['show_icon']        = empty( $input['show_icon'] ) ? '0' : '1';
        $clean['show_in_listings'] = empty( $input['show_in_listings'] ) ? '0' : '1';

        // Post types
        $allowed_types = get_post_types( [ 'public' => true ], 'names' );
        $post_types    = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : [ 'post' ];
        $clean['post_types'] = array_intersect( $post_types, $allowed_types );

        $clean['content_selector'] = sanitize_text_field( $input['content_selector'] ?? '.entry-content, .post-content, article' );

        // Barre de progression
        $clean['bar_enabled']  = empty( $input['bar_enabled'] ) ? '0' : '1';
        $clean['bar_color']    = sanitize_hex_color( $input['bar_color'] ?? '#ffc45e' ) ?: '#ffc45e';
        $clean['bar_height']   = max( 1, min( 10, absint( $input['bar_height'] ?? 3 ) ) );
        $clean['bar_position'] = in_array( $input['bar_position'] ?? '', [ 'top', 'bottom' ], true ) ? $input['bar_position'] : 'top';
        $clean['bar_track']    = empty( $input['bar_track'] ) ? '0' : '1';

        // Statistiques
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
            wp_die( 'You do not have sufficient permissions.' );
        }

        $licensed      = rpp_is_licensed();
        $license_key   = get_option( 'rpp_license_key', '' );
        $settings      = get_option( self::OPTION_KEY, [] );
        $defaults      = rpp_settings_defaults();
        $s             = wp_parse_args( $settings, $defaults );
        $tabs = [
            'license'    => [ 'label' => 'Licence',             'icon' => 'dashicons-lock' ],
            'general'    => [ 'label' => 'Général',            'icon' => 'dashicons-admin-settings' ],
            'progress'   => [ 'label' => 'Barre de progression', 'icon' => 'dashicons-minus' ],
            'stats'      => [ 'label' => 'Statistiques',        'icon' => 'dashicons-chart-bar' ],
            'docs'       => [ 'label' => 'Documentation',       'icon' => 'dashicons-book' ],
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
                            <h2>Licence</h2>
                            <div class="rpp-license-card">
                                <?php if ( $licensed ) : ?>
                                    <div style="text-align:center;margin-bottom:20px;">
                                        <span class="rpp-license-active">&#10003; Licence Active</span>
                                    </div>
                                    <table class="form-table" style="margin:0;">
                                        <tr>
                                            <th>Cl&eacute; de licence</th>
                                            <td><?php
$masked = substr($license_key, 0, 4) . '-****-****-' . substr($license_key, -4);
?><code style="font-size:14px;"><?php echo esc_html($masked); ?></code></td>
                                        </tr>
                                        <tr>
                                            <th>Domaine</th>
                                            <td><?php echo esc_html( home_url() ); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Expiration</th>
                                            <td>
                                                <?php
                                                $expires = get_option( 'rpp_license_expires_at', '' );
                                                if ( $expires ) {
                                                    $days = (int) ceil( ( strtotime( $expires ) - time() ) / 86400 );
                                                    $date_formatted = wp_date( 'd F Y', strtotime( $expires ) );
                                                    if ( $days <= 0 ) {
                                                        echo '<span style="color:#dc2626;font-weight:600;">Expir&eacute;e le ' . esc_html( $date_formatted ) . '</span>';
                                                    } elseif ( $days <= 30 ) {
                                                        echo '<span style="color:#d97706;font-weight:600;">' . esc_html( $date_formatted ) . ' (' . $days . ' jour' . ($days > 1 ? 's' : '') . ' restants)</span>';
                                                    } else {
                                                        echo '<span style="color:#16a34a;">' . esc_html( $date_formatted ) . ' (' . $days . ' jours restants)</span>';
                                                    }
                                                } else {
                                                    echo '<span style="color:#16a34a;">Lifetime (pas d\'expiration)</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin-top:20px;">
                                        <button type="button" id="rpp-deactivate-btn" class="button button-secondary" style="color:#d63638;">D&eacute;sactiver la licence</button>
                                    </p>
                                <?php else : ?>
                                    <h2 style="margin-top:0;">Activez votre licence</h2>
                                    <p>Entrez votre cl&eacute; de licence pour activer Reading Time &amp; Progress Pro.</p>
                                    <p>
                                        <input type="text" id="rpp-license-key" placeholder="RPP-XXXX-XXXX-XXXX" style="width:100%;font-size:16px;padding:8px 12px;font-family:monospace;text-transform:uppercase;" maxlength="19">
                                    </p>
                                    <p>
                                        <button type="button" id="rpp-activate-btn" class="button button-primary button-hero" style="width:100%;">Activer la licence</button>
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
                                <h2>Temps de lecture</h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Mots par minute</th>
                                        <td>
                                            <input type="number" name="rpp_settings[words_per_minute]" value="<?php echo esc_attr( $s['words_per_minute'] ); ?>" class="small-text" min="100" max="1000" step="10">
                                            <p class="description">Vitesse de lecture moyenne. 250 est la norme pour le fran&ccedil;ais.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Format d'affichage</th>
                                        <td>
                                            <input type="text" name="rpp_settings[time_format]" value="<?php echo esc_attr( $s['time_format'] ); ?>" class="regular-text">
                                            <p class="description">Utilisez <code>{time}</code> pour le nombre de minutes. Ex: <code>{time} min de lecture</code>, <code>{time} min read</code></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Afficher le temps de lecture</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[show_reading_time]" value="1" <?php checked( $s['show_reading_time'], '1' ); ?>>
                                                Afficher automatiquement le temps de lecture sur les articles
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Position</th>
                                        <td>
                                            <select name="rpp_settings[time_position]">
                                                <option value="before_content" <?php selected( $s['time_position'], 'before_content' ); ?>>Avant le contenu</option>
                                                <option value="after_title" <?php selected( $s['time_position'], 'after_title' ); ?>>Apr&egrave;s le titre</option>
                                                <option value="after_content" <?php selected( $s['time_position'], 'after_content' ); ?>>Apr&egrave;s le contenu</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Ic&ocirc;ne</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[show_icon]" value="1" <?php checked( $s['show_icon'], '1' ); ?>>
                                                Afficher l'ic&ocirc;ne horloge &#128337; avant le temps de lecture
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Listes d'articles</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[show_in_listings]" value="1" <?php checked( $s['show_in_listings'], '1' ); ?>>
                                                Afficher aussi dans les archives, cat&eacute;gories et recherche
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Types de contenu</th>
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
                                        <th scope="row">S&eacute;lecteur CSS du contenu</th>
                                        <td>
                                            <input type="text" name="rpp_settings[content_selector]" value="<?php echo esc_attr( $s['content_selector'] ); ?>" class="regular-text">
                                            <p class="description">S&eacute;lecteur CSS utilis&eacute; pour d&eacute;tecter la zone de contenu (pour la barre de progression). Ex: <code>.entry-content, .post-content, article</code></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="submit">
                                <?php submit_button( 'Enregistrer', 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                        <!-- Progress Bar Tab -->
                        <div id="rpp-tab-progress" class="rpp-tab-content">
                            <div class="rpp-admin-section">
                                <h2>Barre de progression</h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Activer la barre</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" id="rpp-bar-enabled" name="rpp_settings[bar_enabled]" value="1" <?php checked( $s['bar_enabled'], '1' ); ?>>
                                                Afficher une barre de progression de lecture
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Couleur</th>
                                        <td>
                                            <input type="color" id="rpp-bar-color" name="rpp_settings[bar_color]" value="<?php echo esc_attr( $s['bar_color'] ); ?>">
                                            <code id="rpp-bar-color-hex"><?php echo esc_html( $s['bar_color'] ); ?></code>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">&Eacute;paisseur</th>
                                        <td>
                                            <input type="range" id="rpp-bar-height" name="rpp_settings[bar_height]" value="<?php echo esc_attr( $s['bar_height'] ); ?>" min="1" max="10" step="1">
                                            <span id="rpp-bar-height-val"><?php echo esc_html( $s['bar_height'] ); ?>px</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Position</th>
                                        <td>
                                            <label style="display:inline-block;margin-right:20px;">
                                                <input type="radio" id="rpp-bar-pos-top" name="rpp_settings[bar_position]" value="top" <?php checked( $s['bar_position'], 'top' ); ?>>
                                                Haut de page
                                            </label>
                                            <label>
                                                <input type="radio" id="rpp-bar-pos-bottom" name="rpp_settings[bar_position]" value="bottom" <?php checked( $s['bar_position'], 'bottom' ); ?>>
                                                Bas de page
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Fond de piste</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" id="rpp-bar-track" name="rpp_settings[bar_track]" value="1" <?php checked( $s['bar_track'], '1' ); ?>>
                                                Afficher un fond semi-transparent derri&egrave;re la barre
                                            </label>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Live Preview -->
                            <div class="rpp-admin-section">
                                <h2>Aper&ccedil;u</h2>
                                <div class="rpp-bar-preview-container" id="rpp-preview">
                                    <div id="rpp-preview-track" style="position:absolute;left:0;width:100%;background:rgba(0,0,0,0.08);"></div>
                                    <div id="rpp-preview-bar" class="rpp-bar-preview-bar" style="background:<?php echo esc_attr( $s['bar_color'] ); ?>;height:<?php echo esc_attr( $s['bar_height'] ); ?>px;top:0;"></div>
                                    <div class="rpp-bar-preview-content">
                                        <p><strong>Titre de l'article</strong></p>
                                        <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>
                                        <p>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>
                                        <p>Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="submit">
                                <?php submit_button( 'Enregistrer', 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                        <!-- Stats Tab -->
                        <div id="rpp-tab-stats" class="rpp-tab-content">
                            <div class="rpp-admin-section">
                                <h2>Param&egrave;tres de tracking</h2>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Activer le tracking</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[tracking_enabled]" value="1" <?php checked( $s['tracking_enabled'], '1' ); ?>>
                                                Enregistrer les donn&eacute;es de lecture (scroll, temps pass&eacute;)
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Exclure les admins</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[exclude_admins]" value="1" <?php checked( $s['exclude_admins'], '1' ); ?>>
                                                Ne pas tracker les administrateurs
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">R&eacute;tention des donn&eacute;es</th>
                                        <td>
                                            <select name="rpp_settings[retention_days]">
                                                <option value="30" <?php selected( $s['retention_days'], '30' ); ?>>30 jours</option>
                                                <option value="60" <?php selected( $s['retention_days'], '60' ); ?>>60 jours</option>
                                                <option value="90" <?php selected( $s['retention_days'], '90' ); ?>>90 jours</option>
                                                <option value="180" <?php selected( $s['retention_days'], '180' ); ?>>180 jours</option>
                                                <option value="365" <?php selected( $s['retention_days'], '365' ); ?>>1 an</option>
                                            </select>
                                            <p class="description">Dur&eacute;e de conservation des donn&eacute;es de tracking. Les donn&eacute;es plus anciennes sont supprim&eacute;es automatiquement.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Social proof</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="rpp_settings[social_proof]" value="1" <?php checked( $s['social_proof'], '1' ); ?>>
                                                Afficher le nombre de lecteurs en cours
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Seuil minimum</th>
                                        <td>
                                            <input type="number" name="rpp_settings[social_threshold]" value="<?php echo esc_attr( $s['social_threshold'] ); ?>" class="small-text" min="1" max="100">
                                            <p class="description">Nombre minimum de lecteurs actifs pour afficher le social proof.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">CSS personnalis&eacute;</th>
                                        <td>
                                            <textarea id="rpp_custom_css" name="rpp_settings[custom_css]" rows="8" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
                                            <p class="description">CSS personnalis&eacute; appliqu&eacute; sur le front-end.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Stats Dashboard -->
                            <?php $this->render_stats_dashboard(); ?>

                            <div class="submit">
                                <?php submit_button( 'Enregistrer', 'primary', 'submit', false ); ?>
                            </div>
                        </div>

                    </form>

                    <!-- Documentation tab (outside the form, no save needed) -->
                    <div id="rpp-tab-docs" class="rpp-tab-content">

                        <div class="rpp-admin-section">
                            <h2>Premiers pas</h2>
                            <ol style="line-height:2;font-size:14px;color:#374151;">
                                <li>Activez votre <strong>licence</strong> dans l'onglet Licence</li>
                                <li>Configurez la <strong>vitesse de lecture</strong> et le format d'affichage dans G&eacute;n&eacute;ral</li>
                                <li>Personnalisez la <strong>barre de progression</strong> (couleur, &eacute;paisseur, position)</li>
                                <li>Activez le <strong>tracking</strong> pour suivre les statistiques de lecture</li>
                                <li>C'est tout ! Le temps de lecture et la barre s'affichent automatiquement.</li>
                            </ol>
                        </div>

                        <div class="rpp-admin-section">
                            <h2>Temps de lecture</h2>
                            <p style="color:#374151;">Le temps de lecture est calcul&eacute; automatiquement en fonction du nombre de mots et d'images dans votre article :</p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li>Les <strong>mots</strong> sont compt&eacute;s et divis&eacute;s par la vitesse configur&eacute;e (d&eacute;faut : 250 mots/min)</li>
                                <li>Les <strong>images</strong> ajoutent du temps de lecture (12 sec pour la 1&egrave;re, d&eacute;gressif jusqu'&agrave; 3 sec minimum)</li>
                                <li>Le temps minimum est de <strong>1 minute</strong></li>
                                <li>Le temps est mis &agrave; jour automatiquement &agrave; chaque sauvegarde de l'article</li>
                            </ul>
                            <p style="color:#374151;">Le format est personnalisable avec la variable <code>{time}</code>. Exemples :</p>
                            <table class="widefat striped" style="max-width:500px;">
                                <thead><tr><th>Format</th><th>R&eacute;sultat</th></tr></thead>
                                <tbody>
                                    <tr><td><code>{time} min de lecture</code></td><td>5 min de lecture</td></tr>
                                    <tr><td><code>{time} min read</code></td><td>5 min read</td></tr>
                                    <tr><td><code>Lecture : {time} min</code></td><td>Lecture : 5 min</td></tr>
                                    <tr><td><code>{time} minutes</code></td><td>5 minutes</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="rpp-admin-section">
                            <h2>Barre de progression</h2>
                            <p style="color:#374151;">La barre de progression indique visuellement la position du lecteur dans l'article :</p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li>Se base sur le <strong>s&eacute;lecteur CSS</strong> configur&eacute; pour d&eacute;tecter la zone de contenu</li>
                                <li>S'affiche en <strong>haut</strong> ou en <strong>bas</strong> de la fen&ecirc;tre (position fixe)</li>
                                <li>Couleur, &eacute;paisseur et fond personnalisables</li>
                                <li>Utilise du JavaScript vanilla performant (pas de jQuery, scroll passif)</li>
                                <li>S'affiche uniquement sur les <strong>articles singuliers</strong> (pas les archives)</li>
                            </ul>
                        </div>

                        <div class="rpp-admin-section">
                            <h2>Tracking &amp; Statistiques <span style="background:#f0f0f1;color:#787c82;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;">Premium</span></h2>
                            <p style="color:#374151;">Le tracking enregistre des donn&eacute;es de lecture anonymis&eacute;es :</p>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li><strong>Pourcentage de scroll</strong> — jusqu'o&ugrave; le lecteur a d&eacute;fil&eacute;</li>
                                <li><strong>Temps pass&eacute;</strong> — dur&eacute;e totale sur la page</li>
                                <li><strong>Taux de compl&eacute;tion</strong> — pourcentage de lecteurs qui lisent jusqu'&agrave; la fin</li>
                                <li><strong>Top articles</strong> — les articles les plus lus</li>
                            </ul>
                            <p style="color:#374151;">Les donn&eacute;es sont envoy&eacute;es par AJAX toutes les 30 secondes et au d&eacute;part de la page. Aucune donn&eacute;e personnelle n'est stock&eacute;e.</p>
                        </div>

                        <div class="rpp-admin-section">
                            <h2>Social Proof</h2>
                            <p style="color:#374151;">Quand activ&eacute;, un badge s'affiche sous le temps de lecture pour montrer combien de personnes lisent l'article en ce moment :</p>
                            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:16px;margin:12px 0;display:inline-block;">
                                <span style="color:#666;">&#128337; 5 min de lecture</span><br>
                                <span style="color:#16a34a;font-size:12px;">&#9679; 3 personnes lisent cet article</span>
                            </div>
                            <p style="color:#374151;">Le compteur utilise des transients WordPress (cache 2 minutes) pour compter les sessions actives. Vous pouvez d&eacute;finir un seuil minimum pour &eacute;viter d'afficher "1 personne lit cet article".</p>
                        </div>

                        <div class="rpp-admin-section">
                            <h2>Shortcodes</h2>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead><tr><th>Shortcode</th><th>R&eacute;sultat</th></tr></thead>
                                <tbody>
                                    <tr>
                                        <td><code>[reading_time]</code></td>
                                        <td>Affiche le temps de lecture de l'article courant</td>
                                    </tr>
                                    <tr>
                                        <td><code>[reading_time id="123"]</code></td>
                                        <td>Affiche le temps de lecture d'un article sp&eacute;cifique</td>
                                    </tr>
                                    <tr>
                                        <td><code>[reading_time format="{time} minutes"]</code></td>
                                        <td>Format personnalis&eacute; pour cette instance</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="rpp-admin-section">
                            <h2>Personnalisation CSS</h2>
                            <p style="color:#374151;">Classes CSS disponibles pour personnaliser l'affichage :</p>
                            <table class="widefat striped" style="max-width:700px;">
                                <thead><tr><th>Classe</th><th>Description</th></tr></thead>
                                <tbody>
                                    <tr><td><code>.rpp-reading-time</code></td><td>Conteneur du temps de lecture</td></tr>
                                    <tr><td><code>.rpp-icon</code></td><td>Ic&ocirc;ne horloge</td></tr>
                                    <tr><td><code>.rpp-time-text</code></td><td>Texte du temps de lecture</td></tr>
                                    <tr><td><code>.rpp-social-proof</code></td><td>Badge social proof</td></tr>
                                    <tr><td><code>#rpp-progress-track</code></td><td>Piste de la barre de progression</td></tr>
                                    <tr><td><code>#rpp-bar</code></td><td>Barre de progression (remplissage)</td></tr>
                                </tbody>
                            </table>
                            <p style="color:#374151;margin-top:12px;">Vous pouvez aussi ajouter du CSS personnalis&eacute; dans l'onglet Statistiques (champ CSS).</p>
                        </div>

                        <div class="rpp-admin-section">
                            <h2>Licence</h2>
                            <ul style="list-style:disc;padding-left:20px;color:#374151;line-height:2;">
                                <li>Le plugin n&eacute;cessite une <strong>cl&eacute; de licence</strong> au format <code>RPP-XXXX-XXXX-XXXX</code></li>
                                <li>La licence est valid&eacute;e toutes les 72 heures automatiquement</li>
                                <li>Selon votre licence, elle peut &ecirc;tre <strong>mono-domaine</strong> ou <strong>multi-domaines</strong></li>
                                <li>En cas de changement de domaine, d&eacute;sactivez d'abord la licence sur l'ancien domaine</li>
                                <li>Les mises &agrave; jour du plugin sont automatiques via l'admin WordPress</li>
                                <li>Le calcul du temps de lecture fonctionne <strong>sans licence</strong> (gratuit). La barre de progression, le tracking et le social proof n&eacute;cessitent une licence active.</li>
                            </ul>
                        </div>

                        <div class="rpp-admin-section" style="background:#fefce8;border-color:#fde68a;">
                            <h2 style="border-color:#fde68a;">Support</h2>
                            <p style="color:#374151;">Pour toute question ou probl&egrave;me :</p>
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

                btn.prop('disabled', true).text('Activation...');

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
                        btn.prop('disabled', false).text('Activer la licence');
                    }
                }).fail(function() {
                    $('#rpp-license-message').html('<div class="notice notice-error inline"><p>Erreur de connexion.</p></div>').show();
                    btn.prop('disabled', false).text('Activer la licence');
                });
            });

            $('#rpp-deactivate-btn').on('click', function() {
                if (!confirm('D\u00e9sactiver la licence sur ce domaine ?')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('D\u00e9sactivation...');

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
                <p>Taux de compl&eacute;tion (30j)</p>
            </div>
            <div class="rpp-admin-section rpp-stat-card">
                <strong><?php echo esc_html( round( $avg_time ) ); ?>s</strong>
                <p>Temps moyen (30j)</p>
            </div>
            <div class="rpp-admin-section rpp-stat-card">
                <strong><?php echo count( $top ); ?></strong>
                <p>Articles suivis</p>
            </div>
            <div class="rpp-admin-section rpp-stat-card">
                <strong><?php echo absint( array_sum( array_map( function( $d ) { return isset( $d->reads ) ? $d->reads : 0; }, $daily ) ) ); ?></strong>
                <p>Lectures (7j)</p>
            </div>
        </div>

        <!-- Top 10 -->
        <div class="rpp-admin-section">
            <h2>Top 10 articles — 30 derniers jours</h2>
            <?php if ( ! empty( $top ) ) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Article</th>
                            <th style="text-align:right;">Lectures</th>
                            <th style="text-align:right;">Compl&eacute;tion</th>
                            <th style="text-align:right;">Temps moy.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $top as $i => $row ) : ?>
                            <tr>
                                <td><?php echo absint( $i + 1 ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $row->post_id ) . '&action=edit' ) ); ?>">
                                        <?php echo esc_html( get_the_title( $row->post_id ) ?: '(sans titre)' ); ?>
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
                <p style="color:#6b7280;">Aucune donn&eacute;e disponible. Activez le tracking pour commencer &agrave; collecter des statistiques.</p>
            <?php endif; ?>
        </div>

        <!-- Daily chart (7 days) -->
        <div class="rpp-admin-section">
            <h2>Lectures par jour — 7 derniers jours</h2>
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
                <p style="color:#6b7280;">Aucune donn&eacute;e disponible.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}

/* --- Defaults --- */

function rpp_settings_defaults() {
    return [
        'words_per_minute'   => '250',
        'time_format'        => '{time} min de lecture',
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
