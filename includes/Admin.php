<?php

namespace FlyWP\Migrator;

class Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', [$this, 'admin_menu'] );
    }

    /**
     * Add admin menu
     *
     * @return void
     */
    public function admin_menu() {
        $hook = add_menu_page(
            __( 'FlyWP Migrator', 'flywp-migrator' ),
            __( 'FlyWP Migrator', 'flywp-migrator' ),
            'manage_options',
            'flywp-migrator',
            [$this, 'plugin_page'],
            'dashicons-migrate',
        );

        add_action( "admin_head-$hook", [$this, 'enqueue_scripts'] );
    }

    /**
     * Enqueue scripts and styles
     *
     * @param string $hook
     *
     * @return void
     */
    public function enqueue_scripts() {
        wp_register_style(
            'flywp-migrator-styles',
            plugin_dir_url( __DIR__ ) . 'assets/css/admin.css',
            [],
            FLYWP_MIGRATOR_VERSION
        );
        wp_enqueue_style( 'flywp-migrator-styles' );

        wp_register_script(
            'flywp-migrator-scripts',
            plugin_dir_url( __DIR__ ) . 'assets/js/admin.js',
            ['jquery'],
            FLYWP_MIGRATOR_VERSION,
            true
        );
        wp_enqueue_script( 'flywp-migrator-scripts' );
    }

    /**
     * Get the encoded migration key
     *
     * @return string
     */
    public function get_migration_key() {
        global $wpdb;

        $key          = flywp_migrator()->get_migration_key();
        $site_url     = home_url();
        $is_multisite = is_multisite() ? 'multisite' : 'single';

        // Encode the key with site URL, multisite status, and database prefix
        $site        = base64_encode( $site_url . '|' . $is_multisite . '|' . $wpdb->prefix );
        $encoded_key = base64_encode( $site . ':' . $key );

        return $encoded_key;
    }

    /**
     * Plugin page callback
     *
     * @return void
     */
    public function plugin_page() {
        ?>
        <div class="wrap">
            <div class="flywp-card">
                <div class="flywp-header">
                    <div class="flywp-logo">
                        <span class="dashicons dashicons-migrate"></span>
                    </div>
                    <div>
                        <h1 class="flywp-title"><?php esc_html_e( 'FlyWP Migrator', 'flywp-migrator' ); ?></h1>
                        <p class="flywp-description"><?php esc_html_e( 'Migrate your site to FlyWP', 'flywp-migrator' ); ?></p>
                    </div>
                </div>

                <div class="flywp-instructions">
                    <h2><?php esc_html_e( 'Migration Instructions', 'flywp-migrator' ); ?></h2>
                    <ol>
                        <li><?php esc_html_e( 'Copy the migration key below using the copy button.', 'flywp-migrator' ); ?></li>
                        <li><?php esc_html_e( 'Go to your FlyWP dashboard.', 'flywp-migrator' ); ?></li>
                        <li><?php printf( wp_kses_post( __( 'Click the "<strong>Create New Site</strong>" button.', 'flywp-migrator' ) ) ); ?></li>
                        <li><?php printf( wp_kses_post( __( 'Select "<strong>Import Site</strong>" option.', 'flywp-migrator' ) ) ); ?></li>
                        <li><?php esc_html_e( 'Paste your migration key in the provided field.', 'flywp-migrator' ); ?></li>
                        <li><?php esc_html_e( 'Follow the remaining steps in the FlyWP migration wizard to complete the process.', 'flywp-migrator' ); ?></li>
                    </ol>
                </div>
                
                <div class="flywp-form-row">
                    <label for="migration_key" class="flywp-label">
                        <?php esc_html_e( 'Migration Key', 'flywp-migrator' ); ?>
                    </label>
                    <div class="flywp-input-wrapper">
                        <input type="password" 
                            id="flywp-migration-key" 
                            name="migration_key" 
                            value="<?php echo esc_attr( $this->get_migration_key() ); ?>" 
                            class="flywp-input"
                            required
                        >

                        <div class="flywp-buttons-wrapper">
                            <button type="button" class="flywp-toggle-password" aria-label="<?php esc_attr_e( 'Toggle password visibility', 'flywp-migrator' ); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>

                            <button type="button" class="flywp-copy-clipboard" aria-label="<?php esc_attr_e( 'Copy migration key to clipboard', 'flywp-migrator' ); ?>">
                                <span class="dashicons dashicons-admin-page"></span>
                                <span>Copy</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
