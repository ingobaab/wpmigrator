<?php

namespace MigWP\Migrator;

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
            __( 'MigWP Migrator', 'migwp-migrator' ),
            __( 'MigWP Migrator', 'migwp-migrator' ),
            'manage_options',
            'migwp-migrator',
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
            'migwp-migrator-styles',
            plugin_dir_url( __DIR__ ) . 'assets/css/admin.css',
            [],
            MIGWP_MIGRATOR_VERSION
        );
        wp_enqueue_style( 'migwp-migrator-styles' );

        wp_register_script(
            'migwp-migrator-scripts',
            plugin_dir_url( __DIR__ ) . 'assets/js/admin.js',
            ['jquery'],
            MIGWP_MIGRATOR_VERSION,
            true
        );
        wp_enqueue_script( 'migwp-migrator-scripts' );
    }

    /**
     * Get the encoded migration key
     *
     * @return string
     */
    public function get_migration_key() {
        global $wpdb;

        $key          = migwp_migrator()->get_migration_key();
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
            <div class="migwp-card">
                <div class="migwp-header">
                    <div class="migwp-logo">
                        <span class="dashicons dashicons-migrate"></span>
                    </div>
                    <div>
                        <h1 class="migwp-title"><?php esc_html_e( 'MigWP Migrator', 'migwp-migrator' ); ?></h1>
                        <p class="migwp-description"><?php esc_html_e( 'Migrate your site to MigWP', 'migwp-migrator' ); ?></p>
                    </div>
                </div>

                <div class="migwp-instructions">
                    <h2><?php esc_html_e( 'Migration Instructions', 'migwp-migrator' ); ?></h2>
                    <ol>
                        <li><?php esc_html_e( 'Copy the migration key below using the copy button.', 'migwp-migrator' ); ?></li>
                        <li><?php esc_html_e( 'Go to your MigWP dashboard.', 'migwp-migrator' ); ?></li>
                        <li><?php printf( wp_kses_post( __( 'Click the "<strong>Create New Site</strong>" button.', 'migwp-migrator' ) ) ); ?></li>
                        <li><?php printf( wp_kses_post( __( 'Select "<strong>Import Site</strong>" option.', 'migwp-migrator' ) ) ); ?></li>
                        <li><?php esc_html_e( 'Paste your migration key in the provided field.', 'migwp-migrator' ); ?></li>
                        <li><?php esc_html_e( 'Follow the remaining steps in the MigWP migration wizard to complete the process.', 'migwp-migrator' ); ?></li>
                    </ol>
                </div>
                
                <div class="migwp-form-row">
                    <label for="migration_key" class="migwp-label">
                        <?php esc_html_e( 'Migration Key', 'migwp-migrator' ); ?>
                    </label>
                    <div class="migwp-input-wrapper">
                        <input type="password" 
                            id="migwp-migration-key" 
                            name="migration_key" 
                            value="<?php echo esc_attr( $this->get_migration_key() ); ?>" 
                            class="migwp-input"
                            required
                        >

                        <div class="migwp-buttons-wrapper">
                            <button type="button" class="migwp-toggle-password" aria-label="<?php esc_attr_e( 'Toggle password visibility', 'migwp-migrator' ); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>

                            <button type="button" class="migwp-copy-clipboard" aria-label="<?php esc_attr_e( 'Copy migration key to clipboard', 'migwp-migrator' ); ?>">
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
