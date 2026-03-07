<?php
/**
 * Plugin Name: FlyWP Migrator
 * Plugin URI: https://flywp.com
 * Description: Helps migrate WordPress sites to FlyWP platform
 * Version: 1.3.0
 * Author: FlyWP
 * Text Domain: flywp-migrator
 * License: GPL-2.0+
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

define( 'FLYWP_MIGRATOR_FILE', __FILE__ );

function flywp_migrator() {
    return \FlyWP\Migrator\Plugin::instance();
}

// Run plugin
flywp_migrator();
