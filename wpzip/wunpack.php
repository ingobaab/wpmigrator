#!/usr/bin/env php
<?php
/**
 * wunpack.php - CLI tool for extracting WordPress archives
 * 
 * Extracts encrypted archives created by wpack.php.
 * Handles AES-256 decryption and selective decompression.
 * 
 * Usage:
 *   wunpack.php [options] [archive_file] [output_dir]
 *   wunpack.php -p password archive.php /var/www/html
 * 
 * Options:
 *   -p, --password=PWD   Decryption password (required)
 *   -o, --offset=NUM     Byte offset to start reading (for SFX archives)
 *   -v                   Verbose output (~20 lines)
 *   -vv                  Very verbose (show every file)
 *   --help               Show this help
 *   --version            Show version
 * 
 * Arguments:
 *   archive_file         Path to archive file (default: script itself for SFX)
 *   output_dir           Output directory (default: current directory)
 * 
 * Examples:
 *   wunpack.php -p secret archive.php /var/www/html
 *   wunpack.php -p secret -v backup.wpack /restore
 *   wunpack.php --password=mypass archive.php .
 */

if (!class_exists('ProgressHandler')) {
    require_once __DIR__ . '/helper.inc.php';
}

// Register AES stream filter
stream_filter_register('aes256', AES256StreamFilter::class);

// Parse command-line options
$shortopts = "p:o:v";
$longopts = [
    "password:",
    "offset:",
    "help",
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
    echo "wunpack.php version 1.6.4\n";
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

// Get parameters
$password = $options['p'] ?? $options['password'] ?? null;
$offset = $options['o'] ?? $options['offset'] ?? 0;
$archive_file = $args[0] ?? __FILE__;  // Default to self for SFX
$output_dir = $args[1] ?? './';

// Prompt for password if not provided
if (empty($password) && PHP_SAPI === 'cli') {
    echo "Password: ";
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";
}

// Validate password
if (empty($password)) {
    fwrite(STDERR, "ERROR: Password is required for decryption!\n");
    fwrite(STDERR, "Usage: wunpack.php -p password [archive_file] [output_dir]\n");
    fwrite(STDERR, "   Or: wunpack.php --help\n");
    exit(1);
}

// Validate archive file
if (!file_exists($archive_file)) {
    fwrite(STDERR, "ERROR: Archive file not found: $archive_file\n");
    exit(1);
}

// For SFX archives, find the __HALT_COMPILER position
if ($archive_file === __FILE__ || basename($archive_file) === basename(__FILE__)) {
    $offset = getOffsetInArchFile($archive_file);
    if ($offset === false) {
        fwrite(STDERR, "ERROR: Could not find archive data in SFX file\n");
        exit(1);
    }
}

// Show configuration if verbose
if ($verbosity >= 1) {
    echo "\nArchive Extraction Configuration:\n";
    echo "  Archive:     $archive_file\n";
    echo "  Output dir:  $output_dir\n";
    echo "  Offset:      $offset bytes\n";
    echo "  Password:    " . str_repeat('*', strlen($password)) . "\n";
    echo "\n";
}

// Create progress handler
$progress = new CliProgressHandler($verbosity);

// Execute extraction
if ($verbosity >= 1) {
    echo "Extracting archive...\n";
}

$result = wunpack($archive_file, $output_dir, $password, $offset, $progress);

// Handle result
if ($result['status'] === 'success') {
    if ($verbosity >= 1) {
        echo "\n";
        echo "Success! Archive extracted:\n";
        echo "  Output dir:  {$result['output_dir']}\n";
        echo "  Files:       {$result['files']}\n";
        echo "  Directories: {$result['dirs']}\n";
        echo "  Time:        " . sprintf('%.2f', $result['time']) . "s\n";
    } elseif ($verbosity === 0) {
        echo "\nExtracted to {$result['output_dir']} ({$result['files']} files, {$result['dirs']} dirs)\n";
    }
    exit(0);
} else {
    fwrite(STDERR, "\nERROR: " . $result['error'] . "\n");
    if ($verbosity >= 2 && isset($result['trace'])) {
        fwrite(STDERR, "\nStack trace:\n" . $result['trace'] . "\n");
    }
    exit(1);
}
