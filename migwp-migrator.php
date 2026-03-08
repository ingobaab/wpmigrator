<?php
/**
 * Plugin Name: MigWP Migrator
 * Plugin URI: https://migwp.com
 * Description: Helps migrate WordPress sites to MigWP platform
 * Version: 1.3.0
 * Author: MigWP
 * Text Domain: migwp-migrator
 * License: GPL-2.0+
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

define( 'MIGWP_MIGRATOR_FILE', __FILE__ );

function migwp_migrator() {
    return \MigWP\Migrator\Plugin::instance();
}

// Run plugin
migwp_migrator();
