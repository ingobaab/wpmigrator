<?php

namespace FlyWP\Migrator;

use FlyWP\Migrator\Services\Database\Scheduler;

/**
 * Main plugin class
 */
class Plugin {

    /**
     * Plugin version
     *
     * @var string
     */
    const VERSION = '1.3.0';

    /**
     * Plugin instance
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Get plugin instance
     *
     * @return Plugin
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->define_constants();
        $this->init_hooks();
    }

    /**
     * Define constants
     *
     * @return void
     */
    private function define_constants() {
        define( 'FLYWP_MIGRATOR_VERSION', self::VERSION );
        define( 'FLYWP_MIGRATOR_PATH', dirname( FLYWP_MIGRATOR_FILE ) );
        define( 'FLYWP_MIGRATOR_INCLUDES', FLYWP_MIGRATOR_PATH . '/includes' );
        define( 'FLYWP_MIGRATOR_URL', plugins_url( '', FLYWP_MIGRATOR_FILE ) );
    }

    /**
     * Initialize hooks
     *
     * @return void
     */
    private function init_hooks() {
        register_activation_hook( FLYWP_MIGRATOR_FILE, [$this, 'activate'] );

        // Initialize the plugin
        add_action( 'plugins_loaded', [$this, 'init_plugin'] );

        // Register REST API routes
        add_action( 'rest_api_init', [$this, 'register_rest_routes'] );

        // Add settings link to plugin listing
        add_filter( 'plugin_action_links_' . plugin_basename( FLYWP_MIGRATOR_FILE ), [$this, 'add_plugin_action_links'] );
    }

    /**
     * Plugin activation hook
     *
     * @return void
     */
    public function activate() {
        if ( ! $this->get_migration_key() ) {
            $this->set_migration_key( wp_generate_password( 32, false ) );
        }
    }

    /**
     * Initialize plugin
     *
     * @return void
     */
    public function init_plugin() {
        // Initialize the backup scheduler (registers cron hooks)
        Scheduler::init();

        new Admin();
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_rest_routes() {
        $api = new Api();
        $api->register_routes();
    }

    /**
     * Get migration key
     *
     * @return string
     */
    public function get_migration_key() {
        return get_option( 'flywp_migration_key', '' );
    }

    /**
     * Set migration key
     *
     * @param string $key
     *
     * @return void
     */
    public function set_migration_key( $key ) {
        update_option( 'flywp_migration_key', $key );
    }

    /**
     * Add settings link to plugin action links
     *
     * @param array $links
     *
     * @return array
     */
    public function add_plugin_action_links( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=flywp-migrator' ) . '">' . __( 'Settings', 'flywp-migrator' ) . '</a>';
        array_unshift( $links, $settings_link );

        return $links;
    }
}
