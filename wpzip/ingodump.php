#!/usr/bin/env php
<?php


if (!class_exists('MySQLiDump')) { require_once 'helper.inc.php'; }

// Register the filter
stream_filter_register('aes256', AES256StreamFilter::class);

// Parameterverarbeitung
$shortopts = "h:u:p:P:c:ao:vC:N";
$longopts = [
    "host:",
    "user:",
    "password:",
    "port:",
    "charset:",
    "database:",
    "compress:",
    "no-data",
    "help",
    "auto",
    "out:",
    "cryptpass:"
];
$options = getopt($shortopts, $longopts, $rest_index);
$args = array_slice($_SERVER['argv'], $rest_index);

if (isset($options['a']) || isset($options['auto']) ) {

    @ require_once "helper.inc.php";

    $wpConfigPath = find_wp_config();
    if ($wpConfigPath) {
        // echo 'Found wp-config.php at: ' . $wpConfigPath . "\n";
    } else {
        echo 'wp-config.php not found! Exit 1.' . "\n";
        exit(1);
    }

    $dbconfig = getDbConfig($wpConfigPath);
    // print_r($dbconfig);
}

// Parameter auswerten
$host = $options['h'] ?? $options['host'] ?? $dbconfig['host'] ?? null;
$user = $options['u'] ?? $options['user'] ?? $dbconfig['user'] ?? null;
$pass = $options['p'] ?? $options['pass'] ?? $dbconfig['pass'] ?? '';
$port = $options['P'] ?? $options['port'] ?? $dbconfig['port'] ?? 3306;
$socket = $options['S'] ?? $options['socket'] ?? $dbconfig['socket'] ?? '';
$charset = $options['c'] ?? $options['charset'] ?? $dbconfig['charset'] ?? 'utf8mb4';
if (isset($options['v'])) $verb=(is_int($options['v'])) ? $options['v'] : (is_string($options['v']) ? 1+strlen($options['v']) : ((is_bool($options['v'])) ? 1 : count($options['v'])));  // '-v' '-vv'
$secret = $options['C'] ?? $options['cryptpass'] ?? null;

// 'host'    => $host,
// 'name'    => defined('DB_NAME')     ? DB_NAME     : null,
// 'user'    => defined('DB_USER')     ? DB_USER     : null,
// 'pass'    => defined('DB_PASSWORD') ? DB_PASSWORD : null,
// 'port'    => $port,
// 'socket'  => $socket,
// 'charset' => defined('DB_CHARSET')  ? DB_CHARSET  : 'utf8mb4',
// 'collate' => defined('DB_COLLATE')  ? DB_COLLATE  : ''

$database = $args[0] ?? $dbconfig['name'] ?? null;
$compression = $options['compress'] ?? 'none';
$no_data = isset($options['no-data']);

// Show Help
if (isset($options['help']) || isset($options['h']) || empty($host) || empty($user) || empty($database)) {
    die("Usage: " . basename(__FILE__) . " -h host -u user [options] database\n\n" .
        "Required:\n" .
        "  -h, --host=HOST         MySQL host\n" .
        "  -u, --user=USER         MySQL user\n" .
        "  -v                      verbose output, -vv -vvv\n" .
        "  database                Database name\n\n" .

        "Or:\n" .
        "  -a, --auto              Automode, finds wp-config.php and dumps data\n\n" .

        "Options:\n" .
        "  -p, --pass=PWD          MySQL password (will prompt if not provided)\n" .
        "  -P, --port=PORT         MySQL port (default: 3306)\n" .
        "  -S, --socket=...        MySQL socket (default: ???)\n" .
        "  -c, --charset=CHARSET   Character set (default: utf8mb4)\n" .
        "  --compress=TYPE         Compression (none|gzip|bzip2, default: none)\n" .
        "  --no-data               Dump structure only\n" .
        "  -o, --out               Write to output file\n" .
        "  -C, --cryptpass=secret  Crypt the resulting dump with a secret\n" .
        "  --help                  Show this help\n\n" .
        " Example: php ./ingodump.php -a -C secret --compress=none  -o my.sql\n" .
        "          php ./ingodump.php -a -C secret --compress=gzip  -o my.sql.gz\n" .
        "          php ./ingodump.php -a -C secret --compress=bzip2 -o my.sql.bz2\n\n"
    );
}


if ($compression !== 'none') {                                  // compression
    if (!isset($options['o']) AND !isset($options['out'])) {    // and no filename -> ERROR, don't write to STDOUT
        echo "ERROR: Could not output compressed or encrypted dump data to STDOUT. (compress: $compression, crypt: )\n\n";
        exit(1);
    }
}

$out = $options['o'] ?? $options['out'] ?? 'php://output';

//  if (empty($secret)) {
//      echo "Enter password to encrypt this mysqldump: ";
//      system('stty -echo');
//      $secret = trim(fgets(STDIN));
//      system('stty echo');
//      echo "\n";
//  }

#if ($compression == '' )  { $out .= '.gz';  }
if ($compression == 'gzip')  { $out .= '.gz';  }
if ($compression == 'bzip2') { $out .= '.bz2'; }
if ($compression == 'gzip'  && ! function_exists('gzopen')) { echo('ERROR: ' . "function gzopen() s not available in your php. Exit.\n\n" . get_loaded_php_modules() . "\n\n"); exit(1); }
if ($compression == 'bzip2' && ! function_exists('bzopen')) { echo('ERROR: ' . "function bzopen() s not available in your php. Exit.\n\n" . get_loaded_php_modules() . "\n\n"); exit(1); }

stream_filter_register("aes256", AES256StreamFilter::class);
if (!empty($secret)) $out .= '.encrypted';


if ($verb >=1) {
    if ($verb>=2) { echo 'Options: ' . print_r($options, 1) ."\n\n"; }
    echo "\n" . 'database:      ' . $database;
    echo "\n" . 'host:          ' . $host;
    echo "\n" . 'user:          ' . $user;
    echo "\n" . 'pass:          ' . $pass;
    echo "\n" . 'port:          ' . $port;
    echo "\n" . 'socket:        ' . $socket;
    echo "\n" . 'charset:       ' . $charset;
    echo "\n" . 'compression:   ' . $compression;
    echo "\n" . 'secret:        ' . ( is_null($secret) ? '-NULL-' : sq((string)$secret) );
    echo "\n" . 'out:           ' . $out;
    echo "\n\n";
}

if(0):
    try {
        $dump = new MySQLiDump($host, $user, $pass, $database, $port, $socket, $charset);

        $dump->setSettings([
            'compress' => $compression,
            'no_data'  => $no_data,
            'password' => $secret
        ]);

        $dump->setProgressCallback(function($percent) {
            echo sprintf("Export progress: %5.1f%%\r", $percent);
        }, 0.03);

        $dump->export($out);

        if ( $out !== 'php://output' ) {
            echo 'Wrote ' . format_bytes(filesize($out)) . ' to ' . dq($out) . ".\n\n";
        }

    } catch (Exception $e) {
        die("ERROR: " . $e->getMessage() . "\n");
    }
endif;


$infile=$out;
$outfile='extract.sql';

try {
    // Beispiel: Entpacken einer passwortgeschützten, gzip-komprimierten Datei
    MySQLiDump::extract(
        $infile,        // Eingabedatei
        'extract.sql',  // Ausgabedatei
        $secret,        // Passwort
        'gzip'          // Kompressionsmethode
    );

    echo "Dump 'extract.sql' erfolgreich extrahiert!";
} catch (Exception $e) {
    echo "Fehler beim Extrahieren: " . $e->getMessage();
}