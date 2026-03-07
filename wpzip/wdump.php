#!/usr/bin/env php
<?php
/**
 * wdump.php - CLI tool for MySQL database dump
 * 
 * Creates MySQL dumps in pure PHP without external tools.
 * Supports compression (gzip, bzip2) and AES-256 encryption.
 * 
 * Usage:
 *   wdump.php [options] [database]
 *   wdump.php -a                    # Auto-detect WordPress config
 *   wdump.php -h host -u user db    # Manual connection
 * 
 * Options:
 *   -h, --host=HOST      MySQL host
 *   -u, --user=USER      MySQL user
 *   -p, --pass=PASS      MySQL password
 *   -P, --port=PORT      MySQL port (default: 3306)
 *   -c, --charset=SET    Character set (default: utf8mb4)
 *   -o, --out=FILE       Output file
 *   -a, --auto           Auto-detect WordPress configuration
 *   --compress=TYPE      Compression: none, gzip, bzip2 (default: none)
 *   --no-data            Structure only, no data
 *   -C, --cryptpass=PWD  Encrypt with password
 *   -v                   Verbose output (~20 lines)
 *   -vv                  Very verbose (show every table)
 *   --help               Show this help
 * 
 * Examples:
 *   wdump.php -a -o backup.sql
 *   wdump.php -a --compress=gzip -o backup.sql.gz
 *   wdump.php -a --compress=gzip -C secret -o backup.sql.gz.aes
 *   wdump.php -h localhost -u root -p password mydb -o dump.sql
 */

if (!class_exists('ProgressHandler')) {
    require_once __DIR__ . '/helper.inc.php';
}

// Parse command-line options
$shortopts = "h:u:p:P:c:ao:vC:";
$longopts = [
    "host:",
    "user:",
    "pass:",
    "password:",
    "port:",
    "charset:",
    "database:",
    "compress:",
    "no-data",
    "help",
    "auto",
    "out:",
    "cryptpass:",
    "version"
];
$options = getopt($shortopts, $longopts, $rest_index);
$args = array_slice($_SERVER['argv'], $rest_index);

// Show help
if (isset($options['help'])) {
    echo file_get_contents(__FILE__);
    exit(0);
}

// Show version
if (isset($options['version'])) {
    echo "wdump.php version 1.6.4\n";
    exit(0);
}

// Determine verbosity level
$verbosity = 0;
if (isset($options['v'])) {
    if (is_array($options['v'])) {
        $verbosity = count($options['v']);
    } elseif (is_string($options['v'])) {
        $verbosity = 1 + strlen($options['v']);
    } else {
        $verbosity = 1;
    }
}

// Auto-detect WordPress configuration
$db_config = [];
if (isset($options['a']) || isset($options['auto'])) {
    $wp_config_path = find_wp_config();
    if ($wp_config_path) {
        $db_config = getDbConfig($wp_config_path);
        if ($verbosity >= 1) {
            echo "Found wp-config.php at: $wp_config_path\n";
        }
    } else {
        fwrite(STDERR, "ERROR: wp-config.php not found!\n");
        exit(1);
    }
}

// Build configuration array
$config = [
    'host' => $options['h'] ?? $options['host'] ?? $db_config['host'] ?? null,
    'user' => $options['u'] ?? $options['user'] ?? $db_config['user'] ?? null,
    'pass' => $options['p'] ?? $options['pass'] ?? $options['password'] ?? $db_config['pass'] ?? '',
    'port' => $options['P'] ?? $options['port'] ?? $db_config['port'] ?? 3306,
    'charset' => $options['c'] ?? $options['charset'] ?? $db_config['charset'] ?? 'utf8mb4',
    'name' => $args[0] ?? $options['database'] ?? $db_config['name'] ?? null,
    'output_file' => $options['o'] ?? $options['out'] ?? null,
    'compression' => $options['compress'] ?? 'none',
    'password' => $options['C'] ?? $options['cryptpass'] ?? null,
    'no_data' => isset($options['no-data'])
];

// Validate required fields
if (empty($config['host']) || empty($config['user']) || empty($config['name'])) {
    fwrite(STDERR, "ERROR: Missing required parameters!\n\n");
    fwrite(STDERR, "Usage: wdump.php -h host -u user [options] database\n");
    fwrite(STDERR, "   Or: wdump.php -a [options]  # Auto-detect WordPress config\n");
    fwrite(STDERR, "   Or: wdump.php --help\n\n");
    exit(1);
}

// Default output file if not specified
if (empty($config['output_file'])) {
    $config['output_file'] = $config['name'] . '_' . date('Y-m-d_H-i-s');
}

// Validate compression type
if (!in_array($config['compression'], ['none', 'gzip', 'bzip2'])) {
    fwrite(STDERR, "ERROR: Invalid compression type: {$config['compression']}\n");
    fwrite(STDERR, "Valid options: none, gzip, bzip2\n");
    exit(1);
}

// Check if compression is available
if ($config['compression'] === 'gzip' && !function_exists('gzopen')) {
    fwrite(STDERR, "ERROR: gzip compression not available in this PHP installation\n");
    exit(1);
}
if ($config['compression'] === 'bzip2' && !function_exists('bzopen')) {
    fwrite(STDERR, "ERROR: bzip2 compression not available in this PHP installation\n");
    exit(1);
}

// Warn if compressed/encrypted output to STDOUT
if (($config['compression'] !== 'none' || !empty($config['password'])) && 
    ($config['output_file'] === 'php://output' || $config['output_file'] === '-')) {
    fwrite(STDERR, "ERROR: Cannot output compressed or encrypted data to STDOUT\n");
    exit(1);
}

// Show configuration if verbose
if ($verbosity >= 1) {
    echo "\nDatabase Dump Configuration:\n";
    echo "  Host:        {$config['host']}\n";
    echo "  Port:        {$config['port']}\n";
    echo "  User:        {$config['user']}\n";
    echo "  Database:    {$config['name']}\n";
    echo "  Charset:     {$config['charset']}\n";
    echo "  Output:      {$config['output_file']}\n";
    echo "  Compression: {$config['compression']}\n";
    echo "  Encryption:  " . (empty($config['password']) ? 'no' : 'yes') . "\n";
    echo "  No data:     " . ($config['no_data'] ? 'yes' : 'no') . "\n";
    echo "\n";
}

// Create progress handler
$progress = new CliProgressHandler($verbosity);

// Execute dump
if ($verbosity >= 1) {
    echo "Starting MySQL dump...\n";
}

$result = wdump($config, $progress);

// Handle result
if ($result['status'] === 'success') {
    if ($verbosity >= 1) {
        echo "\n";
        echo "Success! Dump created:\n";
        echo "  File: {$result['file']}\n";
        echo "  Size: " . format_bytes($result['size']) . "\n";
        echo "  Rows: {$result['rows']}\n";
        echo "  Tables: {$result['tables']}\n";
        echo "  Time: " . sprintf('%.2f', $result['time']) . "s\n";
    } elseif ($verbosity === 0) {
        echo "\nCreated {$result['file']} (" . format_bytes($result['size']) . ")\n";
    }
    exit(0);
} else {
    fwrite(STDERR, "\nERROR: " . $result['error'] . "\n");
    if ($verbosity >= 2 && isset($result['trace'])) {
        fwrite(STDERR, "\nStack trace:\n" . $result['trace'] . "\n");
    }
    exit(1);
}
