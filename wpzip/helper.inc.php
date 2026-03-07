<?php
/**
 * Define various directory paths and filenames used by the plugin.
 *
 * These variables set up absolute and relative paths for WordPress, uploads,
 * temporary files, dumps, archives, and progress tracking.
 * They also help locate essential configuration files.
 */

// Absolute path to the WordPress root directory
$wp_dir = realpath(dirname(__DIR__ . '/../../../wp-content/'));

// Relative path to the plugin's upload directory
$upload_dir_rel = '/wp-content/uploads/wpmove';

// Absolute path to the plugin's upload directory
$upload_dir_abs = $wp_dir . $upload_dir_rel;

// Relative path to the SQL dump directory
$dump_dir_rel = '/wp-content/uploads/dump';

// Absolute path to the SQL dump directory
$dump_dir_abs = $wp_dir . $dump_dir_rel;

// Filename for the SQL dump (randomized, extension will be added later)
$dump_filename = $dump_dir_abs . '/' . 'sqldump' . '_' . bin2hex(random_bytes(3));

// Base name for the archive file (e.g., "wpmove" or with timestamp)
$wparch_basename = 'wpmove'; // Alternatively: date('Y-m-d-H:i:s')

// Absolute path to the archive file
$arch_filename = $upload_dir_abs . '/' . $wparch_basename;

// URL path to the archive file
$arch_url = $upload_dir_rel . '/' . $wparch_basename;

// Log file path for this plugin
$wpmove_logfile = $upload_dir_abs . '/' . 'wpmove.log';

// Temporary directory for intermediate files (upload_tmp_dir or system temp)
$temp_dir = ini_get('upload_tmp_dir') ? ini_get('upload_tmp_dir') : sys_get_temp_dir();

// Path to wp-config.php
$wpconfig = $wp_dir . '/wp-config.php';

// Length of the WordPress absolute directory path (used for relative calculations)
$length_absdir = strlen(realpath($wp_dir . '/'));

// File path to track progress for dump operation
$progress_dump_fn = $temp_dir . "/" . "progress_dump.json";

// File path to track progress for pack operation
$progress_pack_fn = $temp_dir . "/" . "progress_pack.json";

// Output JSON file to store final results
$json_result_file = $temp_dir . '/' . "results.json";

// Name of the currently executing script (used to detect CLI or admin context)
$my_basename = basename(__FILE__); // Could be admin.php, index.php, wpack.php, etc.


/**
 * Provides formatting helper functions for CLI and web output.
 *
 * Depending on whether the script is run via the CLI or in a web context,
 * these functions either return plain text or wrap strings in appropriate HTML tags.
 */
function sq($s)        { return   "'$s'";            } // single quotes   ' '
function dq($s)        { return '"'.$s.'"';          } // double quotes   " "
function bt($s)        { return   "`$s`";            } // backticks       ` `
function rb($s)        { return   "($s)";            } // round brackets  ( )
function sb($s)        { return   "[$s]";            } // square brackets [ ]
function cb($s)        { return '{'.$s.'}';          } // curly brackets  { }
function ab($s)        { return   "<$s>";            } // angle brackets  < >
if (PHP_SAPI === 'cli'):                               // output plaintext
function b($s, $t='b') { return    ($s);             } // bold   ''       ''
function pre($s)       { return  "\n$s\n";           } // pre    "\n"     "\n"
function i($s)         { return     $s;              } // italic ''       ''
function strong($s)    { return    ($s);             } // strong ''       ''
else:                                                  // output html
function b($s, $t='b') { return  ("<$t>$s</$t>");    } // bold   <b>      </b>
function pre($s)       { return b($s, __FUNCTION__); } // pre    <pre>    </pre>
function i($s)         { return b($s, __FUNCTION__); } // italic <i> </i>
function strong($s)    { return b($s, __FUNCTION__); } // strong <strong> </strong>
endif;


/**
 * Checks if WordPress has been loaded.
 *
 * This function checks if the `ABSPATH` constant is defined, which is an indicator that WordPress has been loaded
 * and is ready to run. It returns `true` if WordPress is loaded, otherwise `false`.
 *
 * @return bool True if WordPress is loaded, otherwise false.
 *
 * @example
 * if (is_wp_loaded()) {
 *     // WordPress is loaded
 * } else {
 *     // WordPress is not loaded
 * }
 */
function is_wp_loaded(): bool {
    return defined('ABSPATH');
}


if(0) {
    echo pre(
        'wp_dir:            ' . b($wp_dir)                                . "\n"
      . 'upload_dir_rel:    ' . dq($upload_dir_rel)                       . "\n"
      . 'upload_dir_abs:    ' . dq($upload_dir_abs)                       . "\n"
      . 'dump_dir_rel:      ' . dq($dump_dir_rel)                         . "\n"
      . 'dump_dir_abs:      ' . dq($dump_dir_abs)                         . "\n"
      . 'SCRIPT_FILENAME:   ' . sb($_SERVER['SCRIPT_FILENAME'])           . "\n"         # set only from webserver, not on cmdline
      . 'rpath of SCRIPT:   ' . cb(realpath($_SERVER['SCRIPT_FILENAME'])) . "\n"
      . '__FILE__:          ' . ab(__FILE__)                              . "\n"
      . 'my_basename:       ' . bt($my_basename)                          . "\n"
      . 'wordpress loaded:  ' . (int)(0+is_wp_loaded())                   . "\n"
    ) . "\n";
    exit(66);
}


/**
 * Script start time for performance measurements.
 * @var float
 */
$time_start_0 = microtime(true);

/**
 * Version, that is shown with '--version'.
 * @var float
 */
$version = '1.6.4';


/**
 * Verbose mode (0 = disabled).
 * @var int
 */
$verb = 0;

/**
 * Minimum file size (in bytes) for compression.
 * @var float
 */
$minSizeForCompression = 2.5 * 1024;

/**
 * gzip compression level must be between 0 and 9.
 * 0 is no compression, 9 is the best compression.
 * @var int
 */
$gzipCompressionLevel = 5;

/**
 * Total size of all processed files in bytes.
 * @var int
 */
$totalSize = 0;

/**
 * Number of processed directories.
 * @var int
 */
$totalDirs = 0;

/**
 * Number of processed files.
 * @var int
 */
$totalFiles = 0;

/**
 * File extensions eligible for compression.
 * @var array
 */
$compressableExtensions = [
    'php',  'html', 'htm', 'txt', 'css', 'js',  '', 'json',
    'xml', 'csv', 'log', 'md', 'sql', 'svg', 'yaml', 'yml',
    'ts',  'jsx', 'tsx', 'scss',  'less',  'config', 'ini',
    'conf', 'template', 'tpl', 'sh'
];

/**
 * Number of files processed after the progress bar is updated.
 * @var int
 */
$progress_update_after_files_processed = 100;  // 1 shows all files

/**
 * Collects unknown file extensions during processing.
 * @var array
 */
$allUnknownExtensions = [];


/**
 * Interface for progress reporting during long-running operations.
 * 
 * This interface allows different implementations for CLI output, 
 * AJAX/WebGUI updates, or silent operation during testing.
 */
interface ProgressHandler {
    /**
     * Update progress with current state.
     * 
     * @param int $current Current progress value (e.g., rows processed, files processed)
     * @param int $total Total expected value
     * @param string $message Optional status message
     * @return void
     */
    public function update(int $current, int $total, string $message = ''): void;
    
    /**
     * Signal that the operation has finished.
     * 
     * @param array $stats Final statistics (e.g., total time, items processed)
     * @return void
     */
    public function finish(array $stats = []): void;
}


/**
 * Progress handler for command-line interface with formatted progress bar.
 * 
 * Supports three verbosity levels:
 * - 0: Single self-overwriting line with progress bar, percentage, and ETA
 * - 1: Progress bar + periodic status updates (~20 lines)
 * - 2+: Detailed output showing every record/file processed
 */
class CliProgressHandler implements ProgressHandler {
    private int $verbosity;
    private float $start_time;
    private int $last_current = 0;
    private float $last_update_time = 0;
    private int $update_counter = 0;
    private bool $finished = false;
    
    /**
     * @param int $verbosity Verbosity level (0=minimal, 1=normal, 2+=verbose)
     */
    public function __construct(int $verbosity = 0) {
        $this->verbosity = $verbosity;
        $this->start_time = microtime(true);
        $this->last_update_time = $this->start_time;
    }
    
    public function update(int $current, int $total, string $message = ''): void {
        if ($this->finished) return;
        
        $now = microtime(true);
        $this->update_counter++;
        
        // Calculate progress
        $percent = ($total > 0) ? ($current / $total * 100) : 0;
        $elapsed = $now - $this->start_time;
        $eta = $this->calculateETA($current, $total, $elapsed);
        
        // Verbosity level 2+: Show every item
        if ($this->verbosity >= 2) {
            if ($message) {
                echo sprintf("[%d/%d] %s\n", $current, $total, $message);
            }
            return;
        }
        
        // Verbosity level 1: Show updates every ~100 items or every 2 seconds
        if ($this->verbosity === 1) {
            if ($this->update_counter % 100 === 0 || ($now - $this->last_update_time) >= 2.0) {
                $bar = $this->renderProgressBar($percent, 30);
                echo sprintf("\r%s %6.1f%% | %s | ETA: %s | Elapsed: %s", 
                    $bar, $percent, $message ?: 'Processing', $eta, $this->formatTime($elapsed));
                $this->last_update_time = $now;
            }
            return;
        }
        
        // Verbosity level 0: Single self-overwriting line, update every 0.5 seconds
        if (($now - $this->last_update_time) >= 0.5 || $current === $total) {
            $bar = $this->renderProgressBar($percent, 40);
            echo sprintf("\r%s %6.1f%% (%d/%d) ETA: %s", 
                $bar, $percent, $current, $total, $eta);
            $this->last_update_time = $now;
        }
        
        $this->last_current = $current;
    }
    
    public function finish(array $stats = []): void {
        if ($this->finished) return;
        $this->finished = true;
        
        $elapsed = microtime(true) - $this->start_time;
        
        if ($this->verbosity === 0) {
            // Complete the progress bar line
            $bar = $this->renderProgressBar(100, 40);
            echo sprintf("\r%s 100.0%% (%s)\n", $bar, $this->formatTime($elapsed));
        } elseif ($this->verbosity === 1) {
            echo sprintf("\n✓ Completed in %s\n", $this->formatTime($elapsed));
        } else {
            echo sprintf("\n✓ Completed in %s", $this->formatTime($elapsed));
            if (!empty($stats)) {
                echo " - Stats: " . json_encode($stats);
            }
            echo "\n";
        }
    }
    
    /**
     * Render a text-based progress bar.
     * 
     * @param float $percent Percentage complete (0-100)
     * @param int $width Width of the progress bar in characters
     * @return string Formatted progress bar
     */
    private function renderProgressBar(float $percent, int $width = 40): string {
        $filled = (int)($width * $percent / 100);
        $empty = $width - $filled;
        return '[' . str_repeat('=', $filled) . str_repeat(' ', $empty) . ']';
    }
    
    /**
     * Calculate estimated time remaining.
     * 
     * @param int $current Current progress
     * @param int $total Total expected
     * @param float $elapsed Time elapsed in seconds
     * @return string Formatted ETA string
     */
    private function calculateETA(int $current, int $total, float $elapsed): string {
        if ($current === 0 || $total === 0) {
            return '--:--';
        }
        
        $rate = $current / $elapsed;
        $remaining = $total - $current;
        $eta_seconds = ($rate > 0) ? ($remaining / $rate) : 0;
        
        return $this->formatTime($eta_seconds);
    }
    
    /**
     * Format time duration in human-readable format.
     * 
     * @param float $seconds Time in seconds
     * @return string Formatted time string
     */
    private function formatTime(float $seconds): string {
        if ($seconds < 60) {
            return sprintf('%ds', (int)$seconds);
        } elseif ($seconds < 3600) {
            return sprintf('%dm %ds', (int)($seconds / 60), (int)($seconds % 60));
        } else {
            $hours = (int)($seconds / 3600);
            $minutes = (int)(($seconds % 3600) / 60);
            return sprintf('%dh %dm', $hours, $minutes);
        }
    }
}


/**
 * Progress handler for AJAX/WebGUI that writes progress to a JSON file.
 * 
 * The WebGUI can poll this file to update a progress bar in the browser.
 */
class AjaxProgressHandler implements ProgressHandler {
    private string $progress_file;
    private float $start_time;
    private int $last_written = 0;
    private float $last_update_time = 0;
    
    /**
     * @param string $progress_file Path to JSON file for progress updates
     */
    public function __construct(string $progress_file) {
        $this->progress_file = $progress_file;
        $this->start_time = microtime(true);
        
        // Initialize progress file
        $this->writeProgress([
            'status' => 'started',
            'percent' => 0,
            'current' => 0,
            'total' => 0,
            'message' => 'Starting...',
            'start_time' => $this->start_time
        ]);
    }
    
    public function update(int $current, int $total, string $message = ''): void {
        $now = microtime(true);
        $elapsed = $now - $this->start_time;
        $percent = ($total > 0) ? ($current / $total * 100) : 0;
        
        // Write update every 100 items or if more than 1 second has passed since last write
        $time_since_last_write = $now - ($this->last_update_time ?: $this->start_time);
        if (($current - $this->last_written) >= 100 || ($current === $total) || $time_since_last_write >= 1.0) {
            $this->writeProgress([
                'status' => 'running',
                'percent' => round($percent, 1),
                'current' => $current,
                'total' => $total,
                'message' => $message ?: 'Processing...',
                'elapsed' => round($elapsed, 1),
                'timestamp' => $now
            ]);
            $this->last_written = $current;
            $this->last_update_time = $now;
        }
    }
    
    public function finish(array $stats = []): void {
        $elapsed = microtime(true) - $this->start_time;
        $this->writeProgress([
            'status' => 'completed',
            'percent' => 100,
            'message' => 'Completed',
            'elapsed' => round($elapsed, 1),
            'stats' => $stats,
            'timestamp' => microtime(true)
        ]);
    }
    
    /**
     * Write progress data to JSON file.
     * 
     * @param array $data Progress data to write
     */
    private function writeProgress(array $data): void {
        $json = json_encode($data, JSON_PRETTY_PRINT) . "\n";
        @file_put_contents($this->progress_file, $json, LOCK_EX);
    }
}


/**
 * Silent progress handler for testing or batch operations.
 * 
 * Does not produce any output.
 */
class SilentProgressHandler implements ProgressHandler {
    public function update(int $current, int $total, string $message = ''): void {
        // Silent - no output
    }
    
    public function finish(array $stats = []): void {
        // Silent - no output
    }
}


class AES256StreamFilter extends php_user_filter
{
    private const SALT_SIZE = 16;
    private const IV_SIZE = 16;
    private const KEY_SIZE = 32;
    private const PBKDF2_ITERATIONS = 100000;

    private string $mode;
    private string $password;
    private bool $compress;
    private string $buffer = '';
    private string $key = '';
    private string $iv = '';
    private string $salt = '';
    private bool $initialized = false;
    private $zlib_context;

    public function onCreate(): bool
    {
        $params = $this->params ?? [];
        $this->mode = $params['mode'] ?? 'encrypt';
        $this->password = $params['password'] ?? '';
        $this->compress = $params['compress'] ?? false;

        if (!$this->password || !in_array($this->mode, ['encrypt', 'decrypt'])) {
            trigger_error("AES256StreamFilter: Missing password or invalid mode", E_USER_WARNING);
            return false;
        }

        return true;
    }

    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->buffer .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        if (!$closing) {
            return PSFS_FEED_ME;
        }

        if (!$this->initialized) {
            if ($this->mode === 'encrypt') {
                $this->salt = random_bytes(self::SALT_SIZE);
                $this->iv = random_bytes(self::IV_SIZE);
                $this->key = $this->deriveKey($this->password, $this->salt);
                $this->initialized = true;

                if ($this->compress) {
                    $this->zlib_context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
                    $this->buffer = deflate_add($this->zlib_context, $this->buffer, ZLIB_FINISH);
                }

                $encrypted = openssl_encrypt($this->buffer, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $this->iv);
                if ($encrypted === false) {
                    trigger_error("AES256StreamFilter: Encryption failed", E_USER_WARNING);
                    return PSFS_ERR_FATAL;
                }

                $bucket = stream_bucket_new($this->stream, $this->salt . $this->iv . $encrypted);
                stream_bucket_append($out, $bucket);
            } else {
                if (strlen($this->buffer) < self::SALT_SIZE + self::IV_SIZE) {
                    trigger_error("AES256StreamFilter: Encrypted data too short", E_USER_WARNING);
                    return PSFS_ERR_FATAL;
                }

                $this->salt = substr($this->buffer, 0, self::SALT_SIZE);
                $this->iv   = substr($this->buffer, self::SALT_SIZE, self::IV_SIZE);
                $ciphertext = substr($this->buffer, self::SALT_SIZE + self::IV_SIZE);
                $this->key = $this->deriveKey($this->password, $this->salt);
                $this->initialized = true;

                $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $this->iv);
                if ($decrypted === false) {
                    trigger_error("AES256StreamFilter: Decryption failed", E_USER_WARNING);
                    return PSFS_ERR_FATAL;
                }

                if ($this->compress) {
                    $this->zlib_context = inflate_init(ZLIB_ENCODING_GZIP);
                    $decrypted = inflate_add($this->zlib_context, $decrypted, ZLIB_FINISH);
                }

                $bucket = stream_bucket_new($this->stream, $decrypted);
                stream_bucket_append($out, $bucket);
            }
        }

        return PSFS_PASS_ON;
    }

    private function deriveKey(string $password, string $salt): string
    {
        return hash_pbkdf2('sha256', $password, $salt, self::PBKDF2_ITERATIONS, self::KEY_SIZE, true);
    }
}



/**
 * Returns the Unicode symbol for Sigma.
 *
 * This function checks if the script is running in CLI (Command Line Interface) mode.
 * If so, it returns the Unicode character for Sigma (Σ). Otherwise, it returns the HTML entity for Sigma (&Sigma;).
 *
 * @return string The Sigma symbol, either as Unicode or HTML entity.
 */
function sigma(): string {
    if (PHP_SAPI === 'cli') {
      return "\u{03A3}";
    } else {
      return "&Sigma;";
    }
}


/**
 * Checks if a shell command is available.
 *
 * @param string $cmd The command to check.
 * @return string|false Path to the command if available, false otherwise.
 */
function is_cmd_available( $cmd ) {
    if ( ! is_shell_exec_enabled() ) {
        return false;
    } else {
        return shell_exec( 'command -v ' . escapeshellarg( $cmd ) . ' 2>/dev/null' );
    }
}


/**
 * Returns the full path to a shell command or a fallback message.
*
* @param string $cmd The command to locate.
* @return string Command path or a "not available" message.
*/
function path_of_cmd( $cmd ): string {

    $path_of_cmd = is_cmd_available( $cmd );

    if ( $path_of_cmd ) {
        return trim( $path_of_cmd );
    } else {
        return __( 'not available', 'wpzip' );
    }
}


/**
 * Check if PDO MySQL driver is available.
 *
 * This function checks if the PDO extension is available and if the MySQL
 * driver is listed among the available PDO drivers.
 *
 * @return bool True if PDO MySQL driver is available, false otherwise.
 */
function is_pdo_mysql_available(): bool {
    return class_exists( 'PDO' ) && in_array( 'mysql', PDO::getAvailableDrivers(), true );
}


/**
 * Custom gettext filter for handling translations in the 'wpzip' domain.
 *
 * This function provides basic translations for the 'wpzip' domain. It maps
 * string translations manually in an array and returns the translated string
 * based on the current locale. This approach enables translation handling
 * without relying on external `.PO` and `.MO` files, although these files
 * can be used for full localization later.
 *
 * Example:
 * - __('Hello, world!', 'wpzip') will return 'Hallo, Welt!' in German (de_DE)
 * - __('Goodbye!', 'wpzip') will return 'Auf Wiedersehen!' in German (de_DE)
 *
 * You can integrate `.PO` and `.MO` files later to replace the hardcoded translations.
 *
 * @param string $translated The translated string.
 * @param string $original The original string before translation.
 * @param string $domain The text domain. Only processes strings in 'wpzip'.
 * @return string The translated string, or the original if no translation is found.
 */
$wpzip_translations = [
    'Hello, WPZip!' => [
        'de_DE' => 'Hallo, WPZip!',
        'fr_FR' => 'Bonjour, WPZip!',
    ],
    'Goodbye!' => [
        'de_DE' => 'Auf Wiedersehen!',
        'fr_FR' => 'À revoir!',
    ],
    'Yes' => [
        'de_DE' => 'Ja',
        'fr_FR' => 'Qui',
        'es_ES' => 'Si',
    ],
    'No' => [
        'de_DE' => 'Nein',
        'fr_FR' => 'Non',
        'es_ED' => 'No',
    ],
    'available' => [
        'de_DE' => 'verfügbar',
        'fr_FR' => 'disponible',
        'es_ES' => 'disponible',
    ],
    'not available' => [
        'de_DE' => 'nicht verfügbar',
        'fr_FR' => 'pas disponible',
        'es_ES' => 'no disponible',
    ]
];

if (is_wp_loaded()) {
    add_filter('gettext', function($translated, $original, $domain) {
        // Check if the domain is 'wpzip' to apply our custom translations
        if ($domain !== 'wpzip') {
            return $translated;
        }

        global $wpzip_translations;

        // Determine the current locale
        $locale = determine_locale();

        // Return the translated string if available for the current locale
        if (isset($wpzip_translations[$original][$locale])) {
            return $wpzip_translations[$original][$locale];
        }

        // If no translation is found, return the original string
        return $translated;
    }, 10, 3);
} else {
    // TODO: Custom Translator-Function for CLI, not needed yet.
}

/**
 * Checks if the `shell_exec` function is enabled and available.
 *
 * This function checks whether `shell_exec` is not disabled in the PHP configuration
 * and whether the function is available for use in the current environment.
 *
 * @return bool Returns `true` if `shell_exec` is enabled and available, otherwise `false`.
 */
function is_shell_exec_enabled(): bool {
    $disabledFunctions = explode(',', ini_get('disable_functions'));

    return function_exists('shell_exec') && !in_array('shell_exec', $disabledFunctions, true);
}


/**
 * Checks if the `mysqldump` command is available.
 *
 * This function first checks if `shell_exec` is enabled. If it is, it attempts to locate
 * the `mysqldump` command by using the `command -v` shell command. If the path to `mysqldump`
 * is found, it returns `true`, indicating that `mysqldump` is available.
 *
 * @return bool Returns `true` if `mysqldump` is available, otherwise `false`.
 */
function is_mysqldump_available(): bool {
    if (!is_shell_exec_enabled()) {
        return false;
    }
    $mysqldumpPath = trim(shell_exec('command -v mysqldump 2>/dev/null'));

    return !empty($mysqldumpPath);
}


/**
 * Returns the total size of a web server root directory in bytes.
 *
 * This function attempts to determine the total disk usage of a given directory, typically the
 * web server's root (default: `/htdocs`). It uses the `du` shell command if available and falls
 * back to a recursive PHP-based approach if shell access is restricted.
 *
 * The process includes:
 * 1. Checking if `shell_exec()` is available and enabled.
 * 2. Verifying that the `du` command exists.
 * 3. Using `du -sb` to obtain the directory size in bytes if possible.
 * 4. Falling back to a pure PHP recursive directory size calculation.
 *
 * This ensures compatibility across different environments, even in restricted hosting setups.
 *
 * @param string $httpdRoot The web root directory to check (default: '/htdocs').
 * @return int The total size of the directory in bytes.
 */
function _1_old_2_getWebserverRootDirectorySize($httpdRoot = '/htdocs') {
    // Step 1: Check if shell_exec() is available
    function shellExecAvailable() {
        if (!function_exists('shell_exec')) return false;
        $disabled = ini_get('disable_functions');
        if (stripos($disabled, 'shell_exec') !== false) return false;
        $test = @shell_exec('echo test');
        return $test !== null;
    }

    // Step 2: Check if 'du' is available
    function duAvailable() {
        $check = @shell_exec('command -v du');
        return !empty($check);
    }

    // Step 3: Use 'du' if available
    if (shellExecAvailable() && duAvailable()) {
        $size = @shell_exec('du -sb ' . escapeshellarg($httpdRoot) . ' | cut -f1');
        if (is_numeric(trim($size))) {
            return (int)trim($size);
        }
    }

    // Step 4: Fallback function (recursive PHP)
    function getDirectorySizePHP($dir) {
        $size = 0;
        if (!is_dir($dir)) return 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    return getDirectorySizePHP($httpdRoot);
}


/**
 * Outputs a rotating cursor with progress information.
 *
 * @param string $str The text to display
 */
function print_next_spinning_cursor($str) {
  static $i = 0, $spinner = ['|', '/', '-', '\\'];
  echo "\r";
  echo $spinner[$i++ % 4].' '.$str;
  fflush(STDOUT);
}


/**
 * Returns the next character in a terminal spinner animation.
 *
 * This function cycles through a predefined array of characters (`|`, `/`, `-`, `\`)
 * to simulate a simple text-based spinner for CLI progress indication. Each call returns
 * the next character in the sequence.
 *
 * @return string The next spinner character in sequence.
 */
function spinner() {
    static $i=0, $spinner = ['|', '/', '-', '\\'];
    return $spinner[$i++ % 4];
}


/**
 * Truncates a string to a maximum length.
 *
 * @param string $str The input string
 * @param int $maxlen Maximum length (default: 100)
 * @return string Truncated string with ".." at the end if shortened
 */
function shorten($str, $maxlen=100, $filled=false) {
    if (strlen($str) > $maxlen) return substr($str,0,$maxlen-2).'..';
    elseif ($filled=false) return $str;
    else return $str . str_repeat(' ', $maxlen-strlen($str));
}


/**
 * Determines whether a file should be compressed.
 *
 * @param string $filename File name
 * @param int $filesize File size in bytes
 * @return bool True if the file should be compressed
 */
function shouldCompressFile($filename, $filesize) {
    global $minSizeForCompression, $compressableExtensions, $allUnknownExtensions;
    if ($filesize <= $minSizeForCompression) return false;
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, $compressableExtensions))
    $allUnknownExtensions[$extension] = isset($allUnknownExtensions[$extension])
      ? $allUnknownExtensions[$extension]=+1
      : 1;
    return in_array($extension, $compressableExtensions);
}


/**
 * List of paths to be excluded from processing.
 * @var array
 */
$excludePaths = [
   'wp-content/uploads/wpmove/'
  ,'wp-content/plugins/wpmove/'
];


/**
 * Checks if a file path should be excluded from processing.
 *
 * @param string $path The file path to check
 * @return bool True if the path matches any of the excluded patterns,
 *              false otherwise
 * @see $excludePaths List of exclusion patterns
 */
function shouldExcludeFilePath($path) {
  global $excludePaths;
  foreach ($excludePaths as $pattern) {
    if (strpos($path, $pattern) !== false) {
        return true;
    }
  }
  return false;
}


/**
 * Cache for paths that have already been excluded to avoid repeated checks.
 *
 * @var array Associative array where keys are paths that have been excluded
 * @since 1.0.0
 * @access private
 */
$excludedPaths = [];


/**
 * Recursively calculates total size, file count and directory count for a given path.
 *
 * Traverses the directory tree starting from the specified path and calculates:
 * - Total size of all files in bytes
 * - Total number of files
 * - Total number of directories
 *
 * The results are stored in the global variables $totalSize, $totalFiles and $totalDirs.
 *
 * @param string $directory Root directory path to scan
 * @return bool Always returns true (consider changing to void if not used)
 * @global int $totalSize Accumulates total file sizes in bytes
 * @global int $totalFiles Counts total processed files
 * @global int $totalDirs Counts total processed directories
 * @throws RuntimeException If directory cannot be read
 */
function calculateTotals($directory, $log_file = null, $log_mode = 'text') {
    global $verb, $totalDirs, $totalFiles, $totalSize, $excludedPaths, $archiveFile;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    // Logging-Callback vorbereiten
    $log = function ($type, $message, $context = []) use ($log_file, $log_mode) {
        $entry = [
            'time' => date('c'),
            'type' => strtoupper($type),
            'message' => $message,
        ];
        if (!empty($context)) {
            $entry['context'] = $context;
        }

        if ($log_mode === 'json') {
            $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            $line = "[" . $entry['time'] . "] [" . $entry['type'] . "] " . $entry['message'] . "\n";
        }

        if ($log_file) {
            file_put_contents($log_file, $line, FILE_APPEND);
        } else {
            echo $line;
        }
    };

    try {
        foreach ($iterator as $file) {
            $current_path = $file->getPathname();

            // Skip excluded paths
            foreach ($excludedPaths as $excludedPath) {
                if (strpos($current_path, $excludedPath) === 0) {
                    continue 2;
                }
            }

            // Skip directories with .zipignore
            if ($file->isDir() && file_exists($current_path . '/.zipignore')) {
                $excludedPaths[] = $current_path . '/';
                if ($verb >= 4) {
                    $log('excluded', "$current_path (found .zipignore)");
                }
                continue;
            }

            // Warn if 'backup' or 'backwpup' is found
            if ($verb >= 0 && (strpos($current_path, 'backup') !== false || strpos($current_path, 'backwpup') !== false)) {
                $log('match', "Found 'backup' in path", ['path' => $current_path]);
            }

            // Optional: skip archive file itself
            /*
            if (realpath($archiveFile) === realpath($current_path)) {
                $log('skip', "Skipping archive file itself", ['path' => $current_path]);
                continue;
            }
            */

            // Add to total size
            $headersize = 1 + 4 + 2 + 16;
            $totalSize += $file->getSize() + strlen($file->getPath()) + $headersize;

            if ($file->isFile()) {
                $totalFiles++;
            } elseif ($file->isDir()) {
                $totalDirs++;
            }

            $i = $totalFiles + $totalDirs;
            if ($i % 1 === 0 && $verb >= 1) {
                $log('progress', 'Progress update', [
                    'index' => $i,
                    'files' => $totalFiles,
                    'dirs'  => $totalDirs,
                    'size'  => $totalSize,
                    'path'  => shorten($current_path, 70)
                ]);
            }
        }
    } catch (\Throwable $e) {
        $log('error', 'Exception in calculateTotals(): ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        return false;
    }

    $log('done', 'Scan completed', [
        'total_files' => $totalFiles,
        'total_dirs'  => $totalDirs,
        'total_size'  => $totalSize
    ]);
    return true;
}


/**
 * Adds a file or directory to the archive.
 *
 * Handles both files and directories, maintaining the relative path structure
 * within the archive. For directories, adds them recursively.
 *
 * @param string $path Absolute filesystem path to the file/directory
 * @param string $relativePath Relative path to use within the archive structure
 * @param resource $archiveStream Open archive resource handle
 * @return void
 * @throws RuntimeException If the file cannot be read or added to the archive
 * @uses ZipArchive::addFile() For actual file addition
 * @uses ZipArchive::addEmptyDir() For directory creation in archive
 */
function addToArchive($path, $relativePath, $archiveStream) {

    global $verb, $totalSize, $processedSize, $totalDirs, $totalFiles
         , $progress_update_after_files_processed, $gzipCompressionLevel;
    static $processedAll = 0, $processedDirs = 0, $processedFiles = 0;

    $type = is_dir($path) ? 0 : 1;
    $content = '';
    $originalSize = 0;
    $compressedSize = 0;
    $compressionRatio = 0;
    $compressed = false;
    $checksum = str_repeat("\0", 16);

    if ($type === 1 OR $type === 2) {
        $originalSize = filesize($path);

        if ($originalSize > 0) {
            $content = file_get_contents($path);
            $checksum = md5($content, true);

            if (shouldCompressFile(basename($path), $originalSize)) {
                $compressedContent = gzencode($content, $gzipCompressionLevel);
                if ($compressedContent !== false) {
                    $compressedSize = strlen($compressedContent);
                    if ($compressedSize < $originalSize) {
                        $type = 2;
                        $content = $compressedContent;
                        $compressed = true;
                        $compressionRatio = 100 - ($compressedSize / $originalSize * 100);
                        $checksum = md5($content, true);
                    }
                }
            }
        } else {
            $checksum = md5('', true);
        }
        $processedFiles++;
    } elseif ($type === 0) {
        $processedDirs++;
    }
    $processedAll++;
    $processedSize += $originalSize + strlen($relativePath) + 23;

    if ($relativePath === 'wp-config.php') {
        $relativePath = '_wp-config_ORIGINAL.php';
    }

    // Fortschrittsanzeige
    $percentage = 100 * $processedSize / $totalSize;
    $compressionMark = $compressed ? '*' : ' ';
    $compressedInfo = $compressed
        ? sprintf("%-7s > %-7s (-%2.0f%%)",
            format_bytes($originalSize),
            format_bytes($compressedSize),
            $compressionRatio)
        : sprintf("%7s", format_bytes($originalSize));

    if ($totalDirs + $totalFiles == $processedDirs + $processedFiles) {
        $percentage = 100.0;
        $processedSize = $totalSize;
    }
    $last_entry = ($processedFiles+$processedDirs === $totalFiles+$totalDirs);
    if (0 === $processedAll % $progress_update_after_files_processed OR $last_entry) {
        printf_pack_progress(
            $processedDirs + $processedFiles,
            $compressionMark,
            $percentage,
            $compressedInfo,
            format_bytes($processedSize) . ' of ' . format_bytes($totalSize), // ' 23.3 MB of 185.56 MB'
            shorten($relativePath, 60, true)
        );
    }

    // write the Header
    fwrite($archiveStream, pack('C', $type));
    fwrite($archiveStream, pack('N', ($type === 0) ? 0 : strlen($content)));
    fwrite($archiveStream, pack('n', strlen($relativePath)));
    fwrite($archiveStream, $checksum);
    fwrite($archiveStream, $relativePath);

    if ($type !== 0 && strlen($content) > 0) { // type 0 is directory
        fwrite($archiveStream, $content);
    }
}


/**
 * Prints the progress of a packing operation to the console.
 *
 * This function outputs a formatted progress line showing details such as the
 * current step, status indicator, percentage completed, and other metadata.
 * A dynamic progress bar is appended to the output.
 *
 * @param int    $a Step counter or item index.
 * @param string $b Status character (e.g. ".", "X", ">").
 * @param float  $c Progress percentage (0.0 to 100.0).
 * @param string $d Name or path of the current file or item being processed.
 * @param string $e Additional info such as timestamps or durations.
 * @param string $f Optional message or identifier.
 *
 * @global int $verb Verbosity level:
 *                   - 0: No output
 *                   - 1: Print formatted progress
 *                   - 2: Print progress plus newline
 * @return void
 */
function printf_pack_progress($a,$b,$c,$d,$e,$f) {
    global $verb;
    if ($verb >= 1)
    printf( "  [%5d] %1s %5.1f%% %29s %23s %s %s\r", $a,$b,$c,$d,$e,$f, progressBar($c, 23));
    if ($verb >= 2) echo "\n";
}


/**
 * Liste der ignorierten Verzeichnisse für Debug-Zwecke.
 * @var array
 */
$ignored_directories = [];


/**
 * Creates .zipignore files in directories of common WordPress backup plugins
 *
 * This function iterates through a list of known backup plugin directories and creates
 * an empty .zipignore file in each existing directory to exclude them from manual ZIP backups.
 * Special handling for BackWPup with dynamic directory names.
 *
 * @return array List of successfully processed directory paths
 *
 * @since 1.0
 * @license MIT
 * @example
 * $ignored = create_zipignore_for_backup_plugins();
 * print_r($ignored); // Shows processed directories
 */
function create_zipignore_for_backup_plugins() {
  global $sourceDirectory, $options, $verb;
  // Liste der gängigen Backup-Plugins mit ihren Verzeichnissen
  $backup_plugins = [
      [ 'name' => 'UpdraftPlus'                 ,  'directory' => $sourceDirectory . '/wp-content/updraft/'                     ],
      [ 'name' => 'Duplicator'                  ,  'directory' => $sourceDirectory . '/wp-snapshots/'                           ],
      [ 'name' => 'BlogVault'                   ,  'directory' => $sourceDirectory . '/wp-content/blogvault-temp/'              ],
      [ 'name' => 'BackWPup'                    ,  'directory' => $sourceDirectory . '/wp-content/uploads/backwpup/'            ],
      [ 'name' => 'BackWPup-X'                  ,  'directory' => $sourceDirectory . '/wp-content/uploads/backwpup-XXXXXX/'     ], // Hinweis: XXXXXX ist ein Platzhalter
      [ 'name' => 'Solid Backups (BackupBuddy)' ,  'directory' => $sourceDirectory . '/wp-content/uploads/backupbuddy_backups/' ],
      [ 'name' => 'All-in-One WP Migration'     ,  'directory' => $sourceDirectory . '/wp-content/ai1wm-backups/'               ],
      [ 'name' => 'WPvivid Backup'              ,  'directory' => $sourceDirectory . '/wp-content/wpvividbackups/'              ],
      [ 'name' => 'Akeeba Backup'               ,  'directory' => $sourceDirectory . '/wp-content/akeeba-backup/'               ],
      [ 'name' => 'Total Upkeep'                ,  'directory' => $sourceDirectory . '/wp-content/boldgrid_backup/'             ],
      [ 'name' => 'WPmove'                      ,  'directory' => $sourceDirectory . '/wp-content/uploads/wpmove/'              ],
      [ 'name' => 'WPmove'                      ,  'directory' => $sourceDirectory . '/wp-content/plugins/wpmove/'              ]
  ];

  $ignored_directories = [];

  if ($verb >= 3) echo "\n\nExclude common Backup-Plugins:\n";
  foreach ($backup_plugins as $plugin) {
      $dir = realpath($plugin['directory']);

      // Ersetze XXXXXX in BackWPup-Verzeichnis durch tatsächliche dynamische Teile (falls nötig)
      if ($plugin['name'] === 'BackWPup-X') {
          $backwpup_dirs = glob($sourceDirectory . '/wp-content/uploads/backwpup-*');
          if (!empty($backwpup_dirs)) {
              $dir = $backwpup_dirs[0]; // Nimm das erste gefundene Verzeichnis
          } else {
              continue; // Überspringen, falls kein BackWPup-Verzeichnis existiert
          }
      }
      if ($verb >= 3) echo sprintf("%30s %s", $plugin['name'] . ': ', $dir) . " "; # . "\n";

      // Prüfe, ob das Verzeichnis existiert
      if (is_dir($dir)) {
          $zipignore_file = $dir . '/.zipignore';
          if ($verb >= 3)  echo $zipignore_file;

          // Versuche, die .zipignore-Datei zu erstellen
          if (touch($zipignore_file)) {
              $ignored_directories[] = $dir;
          }
      }
      if ($verb >= 3) echo "\n";
  }

  return $ignored_directories;
}


/**
 * Creates a ZIP archive from a directory and its subdirectories.
 *
 * Recursively traverses the source directory and adds all files and directories
 * to the archive, taking into account exclusion rules and .zipignore files.
 *
 * @param string $sourceDir Path to the source directory
 * @param string $outputFilename Path to the output file (.zip)
 * @return void
 * @throws Exception If the archive cannot be created
 * @throws RuntimeException If the directory cannot be read
 *
 * @global int $verb Debug level
 * @global int $totalSize Total size of files in bytes
 * @global int $totalDirs Number of directories
 * @global int $totalFiles Number of files
 * @global float $time_start_0 Start time for performance measurement
 * @global array $excludedPaths List of excluded paths
 * @global array $ignored_directories List of ignored directories
 *
 * @uses RecursiveDirectoryIterator For filesystem iteration
 * @uses RecursiveIteratorIterator For recursive traversal
 * @uses calculateTotals() For size calculation
 * @uses format_bytes() For formatting file sizes
 * @uses addToArchive() For the actual archiving process
 *
 * @example
 * // Example call:
 * appendArchive('/path/to/directory', 'archive.zip');
 */
function appendArchive($sourceDir, $outputFilename) {
    global $verb, $totalSize, $totalDirs, $totalFiles, $time_start_0, $excludedPaths, $ignored_directories, $password;

    $archiveStream = fopen($outputFilename, 'ab');
    if (!$archiveStream) {
        throw new Exception("Can't create archive file.");
    }

    if ( ! stream_filter_append($archiveStream, 'aes256', STREAM_FILTER_WRITE, [
        'mode' => 'encrypt',
        'password' => $password
        ])) {
        throw new Exception("Can't append aes256 filter.");
    }

    $sourceDir = realpath($sourceDir);
    $dirIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $processedSize = 0;
    $processedFiles = 0;
    $processedDirs = 0;

    foreach ($dirIterator as $item) {
        $currentPath = $item->getPathname();

        // Überspringe wenn in excludedPaths oder .zipignore vorhanden
        $shouldExclude = false;
        foreach ($excludedPaths as $excludedPath) {
            if (strpos($currentPath, $excludedPath) === 0) {
                $shouldExclude = true;
                break;
            }
        }

        // Prüfe auf .zipignore in aktuellen Verzeichnis
        if ($item->isDir() && !$shouldExclude && file_exists($item->getPathname().'/.zipignore')) {
            $excludedPaths[] = $item->getPathname().'/';
            $ignored_directories[] = "[EXCLUDED] ".$item->getPathname();
            $shouldExclude = true;
        }

        $relativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);

        if (realpath($relativePath) === realpath($outputFilename)) {  // skip the archive itself, if it is created in webspace
            if( $verb >= 2) echo "\n[skipping the archive itself] " . $relativePath . "\n";
            continue;
        }

        if ($shouldExclude) {
            continue;
        }

        if ($item->isFile()) {
            $processedFiles++;
            $processedSize += $item->getSize() + strlen($item->getPathname()) + 1 + 4 + 2 + 16;
        } elseif ($item->isDir()) {
            $processedDirs++;
            $processedSize += 0 + strlen($item->getPathname()) + 1 + 4 + 2 + 16;
        }

        addToArchive($item->getPathname(), $relativePath, $archiveStream);

    }

    fclose($archiveStream);

    echo sprintf("\nProcessed %d items (%d dirs + %d files). ", ($processedDirs + $processedFiles), $processedDirs, $processedFiles);
    echo "\nArchive was successfully created: '{$outputFilename}', size " . format_bytes(filesize($outputFilename)) . ". ";
    echo sprintf_needed_time_memory_peak() . "\n";
}


/**
 * Decrypts a binary file using a stream filter and returns a formatted hex dump.
 *
 * This function reads and decrypts a segment of a binary file using a custom
 * AES-256 stream filter. It outputs a hexadecimal dump with ASCII representation,
 * similar to classic hexdump tools.
 *
 * Each line of the dump displays:
 *  - Byte offset (hex)
 *  - Hexadecimal values (grouped by 8 bytes)
 *  - ASCII characters (non-printable replaced with dots)
 *
 * @param string  $filename Path to the encrypted file.
 * @param string  $password Password used for decryption.
 * @param int     $filepos  Byte offset to start reading from (default: 0).
 * @param int     $length   Number of bytes to read and display (default: 256).
 *
 * @throws Exception If the AES decryption stream filter cannot be applied.
 *
 * @return string The formatted hexdump output, or an error message if file is not readable or cannot be opened.
 */
function hexdump_decrypt($filename, $password, $filepos = 0, $length = 256): string {
    if (!is_readable($filename)) {
        return "Datei nicht lesbar oder existiert nicht.";
    }

    $handle = fopen($filename, "rb");
    if (!$handle) {
        return "Fehler beim Öffnen der Datei.";
    }

    if (!stream_filter_append($handle, 'aes256', STREAM_FILTER_READ, [
        'mode' => 'decrypt',
        'password' => $password
      ])) {
        throw new Exception("Can't append aes256 filter.");
    }

    fseek($handle, $filepos);
    $data = fread($handle, $length);
    fclose($handle);

    $output = '';
    $offset = $filepos;
    $lines = str_split($data, 16);

    foreach ($lines as $line) {
        $hex = str_split($line, 1);
        $hexstr = '';
        $ascstr = '';

        foreach ($hex as $i => $char) {
            $hexstr .= sprintf("%02x", ord($char)) . ' ';
            $ascstr .= (ord($char) >= 32 && ord($char) <= 126) ? $char : '.';
            if ($i == 7) $hexstr .= ' '; // zusätzliche Trennung nach 8 Bytes
        }

        // Padding falls weniger als 16 Bytes
        $padding = 16 - strlen($line);
        if ($padding > 0) {
            $hexstr .= str_repeat('   ', $padding);
            if ($padding > 8) {
                $hexstr .= ' ';
            }
        }

        $output .= sprintf("%08x: %-49s |%s|\n", $offset, $hexstr, $ascstr);
        $offset += 16;
    }

    return $output;
}


/**
 * Produces a formatted hexadecimal dump of a section of a binary file.
 *
 * This function reads a portion of a file starting from a specified byte offset,
 * and returns a hexdump formatted similarly to the Unix `hexdump` or `xxd` command.
 *
 * Each line includes:
 * - The byte offset (in hex)
 * - Hexadecimal byte values, grouped in 8-byte halves
 * - Printable ASCII characters, with non-printable bytes replaced by dots
 *
 * @param string $filename Path to the file to be read.
 * @param int    $filepos  Starting byte offset in the file (default: 0).
 * @param int    $length   Number of bytes to read (default: 256).
 *
 * @return string A formatted hexdump string or an error message if the file
 *                cannot be read or opened.
 */
function hexdump($filename, $filepos = 0, $length = 256): string {
    if (!is_readable($filename)) {
        return "Datei nicht lesbar oder existiert nicht.";
    }

    $handle = fopen($filename, "rb");
    if (!$handle) {
        return "Fehler beim Öffnen der Datei.";
    }

    fseek($handle, $filepos);
    $data = fread($handle, $length);
    fclose($handle);

    $output = '';
    $offset = $filepos;
    $lines = str_split($data, 16);

    foreach ($lines as $line) {
        $hex = str_split($line, 1);
        $hexstr = '';
        $ascstr = '';

        foreach ($hex as $i => $char) {
            $hexstr .= sprintf("%02x", ord($char)) . ' ';
            $ascstr .= (ord($char) >= 32 && ord($char) <= 126) ? $char : '.';
            if ($i == 7) $hexstr .= ' '; // zusätzliche Trennung nach 8 Bytes
        }

        // Padding falls weniger als 16 Bytes
        $padding = 16 - strlen($line);
        if ($padding > 0) {
            $hexstr .= str_repeat('   ', $padding);
            if ($padding > 8) {
                $hexstr .= ' ';
            }
        }

        $output .= sprintf("%08x: %-49s |%s|\n", $offset, $hexstr, $ascstr);
        $offset += 16;
    }

    return $output;
}


/**
 * Inflates an archive into an output directory.
 *
 * @param string $archiveFile archivefile
 * @param string $outputDir Destination directory
 * @param int $offset Byte offset in archive (for SFX)
 * @throws Exception on errors
 */
function extractArchive($archiveFile, $outputDir, $offset = 0) {

    global $verb, $processedSize, $totalDirs, $totalFiles, $time_start_0
         , $progress_update_after_files_processed, $password, $dump_dir_abs;

    $totalSize = filesize($archiveFile) - $offset;
    echo "Decompressing dataset " . format_bytes($totalSize) . " from '" . realpath($archiveFile)
       . "', starting from position " . $offset . " " . "with password (" . str_repeat('*', strlen($password)) . "). "
       ."\n";

    if ($totalSize === 0) {
        echo 'There is no data to extract! Exit 1.' . "\n\n";
        exit(1);
    }

    $stream = fopen($archiveFile, 'rb');

    if (! stream_filter_append($stream, 'aes256', STREAM_FILTER_READ, [
          'mode' => 'decrypt',
          'password' => $password
       ])) {
        throw new Exception("Can't append aes256 filter.");
    }

    fseek($stream, $offset);
    if (!$stream) throw new Exception("Can't read archive.");

    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0777, true)) {
            die("Fehler: konnte Verzeichnis nicht erstellen: $outputDir");
        }
    }
    if (!is_dir($outputDir)) die("\nFATAL: '$outputDir' is not a directory! Exit.\n\n");

    $processedFiles = 0;
    $processedDirs = 0;

    // echo hexdump_decrypt($archiveFile, $password, $offset, 512);

    while (!feof($stream)) {
        $i = 1+ $processedFiles + $processedDirs;
        $header = fread($stream, 1 + 4 + 2 + 16);
        if (strlen($header) < 23) break;

        $unpacked = unpack('Ctype/Ndata_length/npath_length', $header);
        extract($unpacked);
        $md5sum = substr($header, 7, 16);
        $relativePath = fread($stream, $unpacked['path_length']);

        if ($verb >= 5) sprintf ('[% 5d] %1d %2d %16s %70s %5d' . "\n", $i, $type, $path_length, strtoupper(bin2hex($md5sum)), shorten($relativePath, 70, true), $data_length);

        $fullPath = $outputDir . DIRECTORY_SEPARATOR . $relativePath;

        if ($unpacked['type'] === 0) {
            if (!is_dir($fullPath)) mkdir($fullPath, 0777, true);
            $processedDirs += 1;
            continue;
        }

        $content = $unpacked['data_length'] > 0 ? fread($stream, $unpacked['data_length']) : '';
        $processedSize += $unpacked['data_length'] + 1 + 4 + 2 + 16;
        $processedFiles += 1;

        if ($unpacked['data_length'] > 0 && md5($content, true) !== $md5sum)
            throw new Exception("Checksum was wrong for :" . $relativePath);

        $compressed = ($unpacked['type'] === 2);
        if ($compressed) {
            $content = gzdecode($content);
            if ($content === false)
                throw new Exception("Could not decompress: " . $relativePath);
        }

        $dir = dirname($fullPath);
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        if ($unpacked['data_length'] === 0) {
            touch($fullPath);
        } else {
            file_put_contents($fullPath, $content);
        }

        $percentage = sprintf("%5.1f%%", ($processedSize / $totalSize) * 100);
        $compressionMark = $compressed ? '*' : ' ';
        $compressionRatio = ($unpacked['data_length'] > 0)
            ? (strlen($content) / $unpacked['data_length'] * 100)
            : 0;
        $sizeInfo = $compressed
            ? sprintf("%8s > %8s (+%2.0f%%)",
                format_bytes($unpacked['data_length']),
                format_bytes(strlen($content)),
                $compressionRatio)
            : sprintf("%7s", format_bytes($unpacked['data_length']));

        $displayPath = shorten($relativePath, 70);
        $displayPath .= str_repeat(' ', 70-strlen($displayPath));

        if ( $totalDirs + $totalFiles === $processedDirs + $processedFiles) $percentage=100;
        $processedAll = $processedDirs + $processedFiles;
        if (0 === $processedAll % $progress_update_after_files_processed) {
          print_next_spinning_cursor(sprintf("[%6d] %7s %s %27s %60s",
            $processedAll,
            $percentage,
            $compressionMark,
            $sizeInfo,
            $displayPath));
          if ($verb >= 3) echo "\n";
        }
    }
    if ($processedFiles>0) {
      print_next_spinning_cursor(sprintf("[%6d] %1s %7s %27s %s",
          $processedAll,
          $compressionMark,
          "100.0%",
          $sizeInfo,
          $displayPath));
      if ($verb >= 2) echo "\n";
      fclose($stream);
      echo "\rArchive successfully decompressed to: '" . realpath($outputDir) . "'. ";
    }
    echo sprintf_needed_time_memory_peak() . "\n";

    $dump_dir_abs = realpath($outputDir . '/wp-content/uploads/dump/');
    echo "dump_dir_abs: " . $dump_dir_abs . "\n";
    print_r(glob($dump_dir_abs . "/*"));

    $mysql_dump_dir_files = glob($dump_dir_abs . "/*");
    if (count($mysql_dump_dir_files) === 1) {
        $mysql_dump_file = $mysql_dump_dir_files[0];
    } else {
        echo "Found " . count($mysql_dump_dir_files) . " sql dumps under " . $dump_dir_abs;
        $mysql_dump_file = $mysql_dump_dir_files[0];
    }

    echo "Using the mysql dump: " . $mysql_dump_file . "\n";
    if( ! file_exists($mysql_dump_file) ) {
        throw new Exception("Could not find mysqldump file " . dq($mysql_dump_file) . ". \n");
    }

    $in = fopen($mysql_dump_file, 'rb');

    if (! stream_filter_append($in, 'aes256', STREAM_FILTER_READ, [
        'mode' => 'decrypt',
        'password' => $password
     ])) {
      throw new Exception("Can't append aes256 filter.");
    }

    $out = fopen($mysql_dump_file . '.decrypted', 'wb');

    while(!feof($in)) {
        fwrite($out, fgets($in, 8192));
    }
    fclose($in);
    fclose($out);
    echo "Decrypted mysql dump to " . $mysql_dump_file . ".decrypted. \n";
}


/**
 * Returns a formatted string showing the elapsed time and peak memory usage.
 *
 * If no start time is provided, it falls back to the global `$time_start_0` variable.
 * This function is typically used to measure the runtime and memory consumption of a script or task.
 *
 * @param float|null $time_start Optional custom start time (e.g., from `microtime(true)`). Defaults to global `$time_start_0`.
 *
 * @return string A formatted string indicating elapsed time and memory usage.
 *
 * @uses format_bytes()
 */
function sprintf_needed_time_memory_peak($time_start = null) {
  global $time_start_0;
  if (is_null($time_start))
    return sprintf("Needed time %' 3.2fs, memory peak %s. ", microtime(true)-$time_start_0, format_bytes(memory_get_peak_usage()));
  else
    return sprintf("Needed time %' 3.2fs, memory peak %s. ", microtime(true)-$time_start  , format_bytes(memory_get_peak_usage()));
}


/**
 * Converts a human-readable size string into a byte value.
 *
 * Accepts size strings such as "100", "10k", or "2M", and returns the corresponding number of bytes.
 * The input is case-insensitive and supports both kilobytes (K/k) and megabytes (M/m).
 *
 * If the input is invalid or an array, it returns false and optionally exits with an error.
 *
 * @param string|mixed $size Size value as a string (e.g., "5M", "512k") or other input to validate.
 *
 * @return int|false Size in bytes, or false if the input is invalid.
 */
function parse_size($size) {
  // Regulärer Ausdruck zum Extrahieren der Zahl (auch Dezimalzahlen) und des optionalen Suffixes
  if (is_array($size)) { echo "\nDid you specify any option twice? Error parsing your values. Exit.\n\n"; exit (2); }
  if (preg_match('/^(\d+(?:\.\d+)?)([kKmM]?)$/', $size, $matches)) {
      $num = (float) $matches[1];
      $suffix = strtolower($matches[2]);

      // Multiplizieren basierend auf dem Suffix
      switch ($suffix) {
          case 'k':
              return (int) round($num * 1024);
          case 'm':
              return (int) round($num * 1024 * 1024);
          default:
              return (int) round($num);
      }
  }
  // return false if there was not found anything.
  return false;
}


/**
 * Prompts the user for input from the command line with the input being visible.
 *
 * This function displays a prompt and reads a line of input from STDIN (the terminal).
 * The input is not masked or hidden, making it suitable for general input (not secure passwords).
 *
 * @param string $prompt The prompt message to display before reading input. Default is 'Passwort: '.
 *
 * @return string The trimmed user input without the trailing newline character.
 *
 * @example
 * $username = prompt_visible_input("Username: ");
 */
function prompt_visible_input($prompt = 'Passwort: ') {
    echo $prompt;
    $password = rtrim(fgets(STDIN), "\n");
    return $password;
}




/**
 * Searches for the next wp-config.php file upwards in the directory tree.
 *
 * This function starts in the current directory and moves upwards in the directory
 * structure until it finds a wp-config.php file or reaches the root directory.
 *
 * @param string $startDir The directory to start the search from. Defaults to the current directory.
 *
 * @return string|null The path to the wp-config.php file if found, or null if not found.
 *
 * @example
 * $wpConfigPath = find_wp_config();
 * if ($wpConfigPath) {
 *     echo "Found wp-config.php at: $wpConfigPath";
 * } else {
 *     echo "wp-config.php not found!";
 * }
 */
function find_wp_config(string $startDir = __DIR__): ?string {
    // Start the search from the given directory
    $dir = realpath($startDir);

    // Traverse upwards in the directory tree
    while ($dir !== false) {
        $configPath = $dir . DIRECTORY_SEPARATOR . 'wp-config.php';

        // Check if the wp-config.php file exists in the current directory
        if (file_exists($configPath)) {
            return $configPath; // Return the full path if found
        }

        // Move up one level in the directory tree
        $dir = dirname($dir);

        // Stop if we've reached the root directory
        if ($dir === '/') {
            break;
        }
    }

    return null; // Return null if wp-config.php was not found
}


/**
 * Includes a sanitized version of the given wp-config.php file.
 *
 * This function creates a temporary copy of the provided wp-config.php file,
 * excluding all lines that contain `require`, `require_once`, `include`, or `include_once`,
 * and stops copying when encountering specific WordPress bootstrap comment markers.
 * The sanitized file is then included to define constants and variables like DB credentials.
 * After inclusion, the temporary file is deleted.
 *
 * @param string $wp_config_path Absolute path to the wp-config.php file.
 *
 * @throws Exception If the file does not exist or cannot be opened.
 *
 * @return void
 */
function include_sanitized_wp_config($wp_config_path) {

    global $table_prefix;

    if (!file_exists($wp_config_path)) {
        throw new Exception("Datei nicht gefunden: $wp_config_path");
    }

    $stop_patterns = [
        "/* That's all, stop editing! Happy publishing.",
        "/** Absolute path to the WordPress directory.",
        "/** Sets up WordPress vars and included files."
    ];

    $skip_keywords = ['require', 'require_once', 'include', 'include_once'];

    $tmp_file = tempnam(sys_get_temp_dir(), 'wpconfig_');
    $in = fopen($wp_config_path, 'r');
    $out = fopen($tmp_file, 'w');

    if (!$in || !$out) {
        throw new Exception("Fehler beim Öffnen von Dateien.");
    }

    while (($line = fgets($in)) !== false) {
        // Prüfen, ob eine der Stop-Zeilen erreicht wurde
        foreach ($stop_patterns as $pattern) {
            if (strpos($line, $pattern) !== false) {
                break 2; // beide Schleifen abbrechen
            }
        }

        // Zeilen mit bestimmten Schlüsselwörtern überspringen
        foreach ($skip_keywords as $keyword) {
            if (stripos($line, $keyword) !== false) {
                continue 2; // zur nächsten Zeile
            }
        }

        fwrite($out, $line);
    }

    fclose($in);
    fclose($out);

    // temporäre Datei inkludieren
    // echo "include_once $tmp_file\n";
    @ include_once $tmp_file;    // Don't show WARNINGS, multiple defines of WP_DEBUG or other garbage

    // temporäre Datei löschen
    // unlink($tmp_file);
}


/**
 * Retrieves the WordPress database configuration from a given wp-config.php path.
 *
 * This function attempts to load the database settings either from the currently loaded
 * WordPress context (if `$table_prefix` is already set), or by including a sanitized copy
 * of the provided `wp-config.php` file. The sanitized copy excludes any include/require
 * statements to avoid unintended side effects or full WordPress loading.
 *
 * If the database constants (DB_NAME, etc.) cannot be retrieved, the script will write an
 * error message to STDERR and exit with code 1.
 *
 * @param string $wpConfigPath Absolute path to the wp-config.php file.
 *
 * @return array{
 *     DB_NAME: string,
 *     DB_USER: string,
 *     DB_PASSWORD: string,
 *     DB_HOST: string,
 *     DB_CHARSET: string,
 *     DB_COLLATE: string,
 *     table_prefix: string
 * } Associative array with WordPress database configuration values.
 */
function getDbConfig($wpConfigPath) {

    global $table_prefix;
    static $config = null;

    if (is_array($config)) return $config;

    #if (! isset($table_prefix)) {                      // if global $table_prefix is set, we are in WP-Context. WordPress is loaded.
    if (!is_wp_loaded()) {                              // if NOT is_wp_loaded(), otherwise everything is defined already.
        include_sanitized_wp_config($wpConfigPath);     // if not set, wie 'include stanitized' a tmp-wp-config.php copy without loading WP.
    }

    if (!defined('DB_HOST')) {
        throw new RuntimeException('DB_HOST is not defined.');
    }

    $host   = 'localhost';
    $port   = null;
    $socket = null;

    $db_host = DB_HOST;

    if (strpos($db_host, ':') !== false) {
        [$host_part, $second_part] = explode(':', $db_host, 2);

        if (is_numeric($second_part)) {
            $host = $host_part;
            $port = (int)$second_part;
        } elseif (str_starts_with($second_part, '/')) {
            $host   = $host_part;
            $socket = $second_part;
        } else {
            $host = $db_host; // fallback
        }
    } else {
        $host = $db_host;
    }

    // and return this results - array
    $config = [
        'host'    => $host,
        'port'    => $port,
        'socket'  => $socket,
        'user'    => defined('DB_USER')     ? DB_USER     : null,
        'pass'    => defined('DB_PASSWORD') ? DB_PASSWORD : null,
        'name'    => defined('DB_NAME')     ? DB_NAME     : null,
        'charset' => defined('DB_CHARSET')  ? DB_CHARSET  : 'utf8mb4',
        'collate' => defined('DB_COLLATE')  ? DB_COLLATE  : ''
    ];

    return $config;

}



function _old_getDbConfig($wpConfigPath) {
    $config = [];

    // Überprüfen, ob die wp-config.php existiert
    if (!file_exists($wpConfigPath)) {
        die("Die Datei wp-config.php konnte nicht gefunden werden: $wpConfigPath");
    }

    // Datei einlesen
    $wpconfig_content = file($wpConfigPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($wpconfig_content as $configline) {
        $configline = trim($configline);

        // Kommentare überspringen
        if (empty($configline) || str_starts_with($configline, '//') || str_starts_with($configline, '#')) {
            continue;
        }

        // Defines mit DB_ Konstante parsen
        if (strpos($configline, 'define') !== false && strpos($configline, 'DB_') !== false) {
            if (preg_match("/define\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*(['\"])(.*?)\\2\s*\)\s*;/", $configline, $matches)) {
                $config[$matches[1]] = $matches[3];
            } else {
                echo "Ungültige Zeile in wp-config.php (define DB_): $configline\n";
            }
        }

        // $table_prefix parsen, example: $table_prefix = 'joJ_';

        if (strpos($configline, '$table_prefix') !== false) {
            #echo "$configline";
            if (preg_match('/\s*\$table_prefix\s*=\s*([\'"])(.*?)\1\s*;/', $configline, $matches)) {
                $config['table_prefix'] = $matches[2];
            } else {
                echo "Kein table_prefix gefunden.\n";
            }
        }
    }
    return $config;
}


/**
 * Reads and processes the command-line options for creating a
 * self-extracting WordPress archive.
 *
 * Recognizes options such as archive file, password, compression level, minimum size for compression, etc.
 * Also performs a script update if necessary.
 *
 * @global int    $verb
 * @global array  $argv
 * @global string $sourceDirectory
 * @global int    $progress_update_after_files_processed
 * @global string $version
 * @global int    $minSizeForCompression
 * @global string $archiveFile
 * @global int    $gzipCompressionLevel
 * @global int    $totalDirs
 * @global int    $totalFiles
 * @global int    $totalSize
 * @global array  $excludedPaths
 * @global array  $options
 * @global string $wp_config_path
 * @global string $password
 * @return void
 */
function getOptions() {

    global $verb, $argv, $sourceDirectory, $progress_update_after_files_processed, $version
         , $minSizeForCompression, $archiveFile,$gzipCompressionLevel, $totalDirs
         , $totalFiles, $totalSize, $excludedPaths, $options, $wp_config_path, $password;

    $options = getopt("a:m:c:v::hiVujsp:n:", ['version', 'info', 'help', 'update', 'size'], $optind);
    $args = array_slice($argv, $optind);

    if (isset($options['v'])) $verb=(is_int($options['v'])) ? $options['v'] : (is_string($options['v']) ? 1+strlen($options['v']) : ((is_bool($options['v'])) ? 1 : count($options['v'])));  // '-v' '-v3' or '-vv'
    if ($verb >= 5) echo   "options:\n" . print_r($options, 1) . "verb: " . $verb . "argv:\n" . print_r($argv, 1) . "\n\n";

    $sourceDirectory = $args[0] ?? './';

    // Standardwerte setzen
    if (isset($options['m'])) $minSizeForCompression = parse_size($options['m']);
    if (isset($options["a"])) $archiveFile = $options["a"];
    if (isset($options["p"])) $password = $options["p"];
    if (isset($options["n"])) $progress_update_after_files_processed = $options["n"];
    if (isset($options["c"])) $gzipCompressionLevel = (int)$options["c"];
    if (isset($options["V"]) or isset($options["version"])) die('Version: ' . $version . "\n\n");
    if (isset($options["u"]) or isset($options["update"])) {
        echo "Updating " . basename(__FILE__) . " Version " . $version . ' ';
        $cmd='wget -q https://pre.a.wpexpress.de/wp-content/uploads/wpmove/src/wpack.php -O' . __FILE__;
        if ($verb >= 2) echo "updating with cmd: $cmd\n";
        shell_exec($cmd);
        echo " (updated to " . trim(shell_exec(__FILE__ . ' --version')) . ")\n";
        exit(0);
    }
    if (!isset($archiveFile)) $archiveFile = "wpsfx-" . date('Y-m-d') . ".php";

    $myname = basename(__FILE__);
    $______ = str_repeat(' ', strlen($myname));

    if (isset($options['h'])) {
    echo <<<END
                                                                                                                                    $version
    Syntax: $myname -h                                       # show this help
            $myname -m=1024 /htdocs                          # set minimal size for compression, smaller files will not be compressed
            $myname -a=my_wordpress.php                      # set output name of self-extracting-archive-file, it must be end with '.php'
            $______ -h   this help                           # this help text
            $______ -v   -vv more verbosity                  # -vvv shows also debug informations
            $______ -a   archivefile                         # output to custom archive file
            $______ -p   password for encryption             # sets the password to protect the archive by aes encryption with password
            $______ -c   set the compression level           # 0 is no compression, 1 is the least, 9 is the most complex compression
            $______ -m   set the min size for compression    # 2.5k is default, increase (-m 6k) or decrease it (-m 1024)
            $______ -V | --version                           # shows the version ($version)
            $______ -u | --update                            # downloads via wget the latest version of this program
            $______ -i | --info                              # shows (only) all parsed settings and the total size of this installation
            $______ -n   1 shows every file                  # progress_update_after_files_processed (default is $progress_update_after_files_processed)
            $______ -j
    Examples:
            $myname -a=arch.php /htdocs                      # create the sfx-archive as "arch.php" and compress /htdocs
            $myname -i -v /htdocs                            # show parsed settings and some more debug about what is excluded
            $myname -ivvv /htdocs                            # show parsed settings and some more more more debug..
            $myname -a=wp.php -c5 ./                         # write an archive wp.php use gzip compression of 5, keep the min size default
            $myname -vv -m=3.5k -c1 .                        # show more infos, dont compress smaller than 3.5kB, compress with only 1
            $myname -vvv -m=10k /htdocs                      # show more more debug,, dont compress smaller 10k, wordpress is in /htdocs
            $myname -v -p password -a pre.wparch /htdocs     # set verbosity, password and archfile
    \n
    END;
    exit(0);
    }

    // Check required parameters
    // Password must be provided so we can use it for encryption.
    if (empty($password)) {
        # echo "Password must be provided so we can use it for encryption.\n";
        echo "A password must be provided as the archive will be encrypted with it. ";
        $password = prompt_visible_input("Please enter password: ");
    }

    if (empty($sourceDirectory)) {
        $sourceDirectory = prompt_visible_input("Please enter WordPress directory: ");
    }

    // Source directory must be specified and it must exist so we can create the archive from it.
    if (empty($sourceDirectory) OR ! is_dir($sourceDirectory)) {
        echo("\nFATAL: Directory is missing. \n\n"
        . "Example: $myname -m=4k -o=backup_wordpress.php /var/www/htdocs      # $myname [options] [wordpress-directory] \n");
        exit(1);
    }
}


/**
 * Reads and processes the command-line options for extracting
 * a self-extracting archive and, if necessary, importing an SQL dump.
 *
 * @global int    $verb
 * @global array  $argv
 * @global string $outputDirectory
 * @global string $archiveFile
 * @global string $password
 * @global string $version
 * @global string $my_basename
 * @return void
 */
function getOptionsSFX() {

    global $verb, $argv, $outputDirectory, $archiveFile, $password, $version, $my_basename;

    $shortopts  = "v::hia:lp:m:";
    $longopts = ["verbose::", "help"];
    $options = getopt($shortopts, $longopts, $optind);
    $args = array_slice($argv, $optind);

    if (isset($options['v'])) $verb=(is_int($options['v'])) ? $options['v'] : (is_string($options['v']) ? 1+strlen($options['v']) : ((is_bool($options['v'])) ? 1 : count($options['v'])));  // '-v' '-v3' or '-vv'
    if (isset($options['p'])) $password = $options['p'];
    if (isset($options['m'])) $dumpfile = $options['m'];

    $myname=basename(__FILE__);

    if (isset($options['a'])) {
        $archiveFile = $options['a'];
        if (!file_exists($archiveFile)) {
            echo "file '$archiveFile' was not found.\n\n";
            exit(1);
        }
    } else {
        $archiveFile = __FILE__;
    }

    if (isset($options['h'])) {
        echo <<<END
                                                                                                                                     $version
        Syntax: $myname [directory]
                -h             # this help
                -v             # verbose, --vv more, -v3 more more

                -a [archfile]  # use archive-file
                -i             # info
                -l             # only list all contents
                -p [password]  # password to decrypt
                -m [dumpfile]  # import mysqldump

        Example: $myname /htdocs         # Attention: if dir is not given, the archive will be decompressed to the current working directory.

        END;
        exit (0);
    }

    if (empty($password) && PHP_SAPI === 'cli') {
        $password = prompt_visible_input($prompt = 'This archive (' . $my_basename . ') is compressed and encrypted.' . "\n" . 'Please provide the correct passwort: ');
    }

    $outputDirectory=$args[0] ?? './';

    if (isset($options['i'])) {
        echo "options:\n".print_r($options,1)."\nverb:\n".$verb."\n"."args:\n".print_r($args,1)."\n";
        echo "archiveFile:     " . $archiveFile . "\n";
        echo "outputDirectory: " . $outputDirectory . "\n";
        echo "password:        " . str_repeat('*', strlen($password)) . "\n";
        echo "dumpfile:        " . $dumpfile . "\n";
    }

    if (isset($dumpfile)) {
        if (file_exists($dumpfile)) {
            echo "Using $dumpfile for mysql-import. \n";
            exit(11);
        } else {
            echo "Mysqldump '$dumpfile' for mysql-import was not found. Exit.\n";
            exit(12);
        }
    }
}


/**
 * Imports a MySQL dump file step by step into the database,
 * to avoid memory issues.
 *
 * @param string  $dumpFile   Pfad zur SQL-Dump-Datei
 * @param string  $host       Datenbank-Host
 * @param string  $username   Datenbank-Benutzername
 * @param string  $password   Datenbank-Passwort
 * @param string  $database   Datenbankname
 * @param int     $chunkSize  Anzahl der Bytes, die pro Schritt gelesen werden (Standard: 8192)
 * @return void
 */
function importMysqlDumpInChunks($dumpFile, $host, $username, $password, $database, $chunkSize = 8192)
{
    // Verbindung zur Datenbank aufbauen
    $conn = new mysqli($host, $username, $password, $database);

    if ($conn->connect_error) {
        die("Verbindung fehlgeschlagen: " . $conn->connect_error);
    }

    // Datei zum Lesen öffnen
    $file = fopen($dumpFile, 'r');
    if (!$file) {
        die("Fehler beim Öffnen der Dump-Datei.");
    }

    $sql = '';
    $isComment = false;

    // Die Datei in Chunks einlesen und verarbeiten
    while (!feof($file)) {
        $chunk = fread($file, $chunkSize);
        $sql .= $chunk;

        // Zeilenumbrüche oder Semikolons als Trennzeichen nutzen, um SQL-Befehle zu extrahieren
        $queries = explode(";\n", $sql);

        foreach ($queries as $query) {
            $query = trim($query);

            // Kommentarzeilen überspringen
            if (empty($query) || strpos($query, '--') === 0 || strpos($query, '#') === 0) {
                continue;
            }

            // SQL-Abfrage ausführen
            if ($conn->query($query) === false) {
                echo "Fehler bei der Ausführung des Befehls: " . $conn->error . "\n";
            }
        }

        // Den restlichen SQL-Teil behalten, falls er unvollständig ist
        $sql = end($queries);
    }

    // Verbindung schließen
    fclose($file);
    $conn->close();

    echo "Import abgeschlossen!";
}


/**
 * Formats bytes into a human-readable size (B/KB/MB/GB/TB).
 *
 * @param int $bytes File size in bytes
 * @param int $precision Number of decimal places for non-B units
 * @return string Formatted size (e.g., "1.5 MB" or "512 B")
 */
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $value = $bytes / pow(1024, $pow);

    if ($bytes < 1024) {
        // For bytes, return integer without decimals
        return sprintf('%d B', (int)$value);
    }

    return sprintf('%.'. $precision .'f %s', $value, $units[$pow]);
}


/**
 * Establishes a connection to the MySQL database based on the provided configuration values.
 *
 * Expects an associative array with the following keys:
 *
 * - DB_HOST
 * - DB_USER
 * - DB_PASSWORD
 * - DB_NAME
 *
 * @param array $config Assoziatives Array mit den Datenbankverbindungsdaten.
 * @return mysqli Erfolgreich verbundene MySQLi-Verbindung.
 * @throws void Beendet das Skript mit einer Fehlermeldung, falls die Verbindung fehlschlägt.
 */
function createDbConnection($config) {
    // Verbindung zur Datenbank herstellen
    $conn = new mysqli($config['DB_HOST'], $config['DB_USER'], $config['DB_PASSWORD'], $config['DB_NAME']);

    if ($conn->connect_error) {
        die("Verbindung fehlgeschlagen: " . $conn->connect_error);
    }

    return $conn;
}


/**
 * Creates a MySQL database dump with optional compression and encryption.
 *
 * This function performs the following tasks:
 * - Retrieves MySQL database configuration from the provided WordPress config.
 * - Estimates the file size of the dump based on the database size and selected compression method.
 * - Optionally encrypts the dump using AES-256 if a password is provided.
 * - Deletes any existing files or directories related to the dump if they already exist.
 * - Creates the directory for the dump if it does not exist.
 * - Performs the database dump and tracks the progress, either printing the progress in a readable format or as JSON.
 * - After the dump is completed, outputs the final file size and time taken to create the dump.
 *
 * @global int $verb Verbosity level for progress output.
 * @global int $compression The compression method to use (0 = none, 1 = gzip, 2 = bzip2).
 * @global array $options Command-line options.
 * @global string $dump_dir_abs Absolute path to the directory where the dump will be stored.
 * @global array $dump_settings Settings related to the dump, including compression and encryption settings.
 * @global string $dump_filename The filename for the dump.
 * @global int $dump_filesize_guess Estimated size of the dump file.
 * @global float $time_start_0 Timestamp when the dump process starts.
 * @global string $progress_dump_fn Filename for the progress log.
 * @global int $db_size_done The total size of the database that has been dumped so far.
 * @global string $wpconfig WordPress configuration array.
 * @global int $info_all_rows_count Total number of rows dumped so far.
 * @global string $password The password used for AES-256 encryption, if applicable.
 *
 * @throws \Exception If an error occurs during the MySQL dump process.
 *
 * @return void
 */
function mysql_dump() {
    global $verb, $compression, $options, $dump_dir_abs, $dump_settings, $dump_filename, $dump_filesize_guess
         , $time_start_0, $progress_dump_fn, $db_size_done, $dump_filesize_guess, $wpconfig, $info_all_rows_count, $password;

    $db_size_done = 0;                  # [name] => pre_a_wpexpress
    $dump_filesize_guess = 0;           # [user] => pre_a_f1AKE6A6
    $info_all_rows_count = 0;           # [pass] => aRLOQtKb
                                        # [host] => 127.0.0.1
    $dbc = getDbConfig($wpconfig);      # [charst] => utf8
                                        # [collate] =>

    $db_stats = mysql_get_stats($dbc['host'], $dbc['user'], $dbc['pass'], $dbc['name']);  # gives 'tables' 'rows' and 'size'

    try {

        if ($compression == 2 && function_exists("bzopen")) {
            $dump_settings = ['compress' => Mysqldump::BZIP2];
            $dump_filename .= '.bz2';
            $dump_filesize_guess = (int)($db_stats['size'] * 0.027 );
        } elseif ($compression == 1 && function_exists("gzopen")) {
            $dump_settings = ['compress' => Mysqldump::GZIP];
            $dump_filename .= '.gz';
            $dump_filesize_guess = (int)($db_stats['size'] * 0.052 );
        } else {
            $dump_settings = ['compress' => Mysqldump::NONE];
            $dump_filename .= '.txt';
            $dump_filesize_guess = (int)($db_stats['size'] * 0.5 );
        }

        if (!empty($password)) {
            echo 'Using AES 256 Encryption (with given password) for the mysqldump. ' . "\n";
            $dump_settings = ['compress' => Mysqldump::AES256ENCRYPT];
            $dump_filename .= '.aes';
        } else {
            echo 'Using no encryption (no password was set) for the mysqldump. ' . "\n";
        }

        if (isset($options['s'])) {
            echo_fs_mysql();
            exit(0);
        }

        if (file_exists($progress_dump_fn) && unlink($progress_dump_fn)) {
            $msg = 'Removed ' . $progress_dump_fn . ".";
            echoj (0, $msg);
            append_to_locked_file($progress_dump_fn, json_encode(['message'=>$msg])."\n");
        }

        if (deleteDirectory($dump_dir_abs)) {
            $msg = 'Removed ' . $dump_dir_abs . ".";
            echoj (0, $msg);
            append_to_locked_file($progress_dump_fn, json_encode(['message'=>$msg])."\n");
        }


        if (strpos($dbc['DB_HOST'],':')) {
            list($dbhost, $port) = explode(':', $dbc['DB_HOST']);
        } else {
            $dbhost=$dbc['DB_HOST'];
        }

        $user_info = posix_getpwuid(posix_geteuid());
        if ( !is_dir($dump_dir_abs) ) { if( !mkdir($dump_dir_abs, 0777, true) ) { die( "Could not create " . sq($dump_dir_abs) . ". Exit.\n" . 'PHP läuft als Benutzer: ' . $user_info['name'] . "\n" ); } }

        // old variant with PDO
        $mysql_dump = new Mysqldump('mysql:host='.$dbhost.';dbname='.$dbc['DB_NAME'], $dbc['DB_USER'], $dbc['DB_PASSWORD'], $dump_settings);

        // new varian with mysqli
        // $mysqli = new mysqli($dbhost, $dbc['DB_USER'], $dbc['DB_PASSWORD'], $dbc['DB_NAME']);
        // if ($mysqli->connect_error) {
        //     die("Verbindung fehlgeschlagen: " . $mysqli->connect_error);
        // }
        // $mysql_dump = new Mysqldump($mysqli, '', '', $dump_settings);


        // set the info hook updates our progress bar
        $mysql_dump->setInfoHook(function($table, $info) {
            global $verb, $dump_filename, $db_stats, $dump_filesize_guess, $options, $progress_dump_fn, $info_all_rows_count;
            $filesize_current = filesize($dump_filename);
            $percent_done = 100 * $filesize_current / $dump_filesize_guess;    # $db_stats['size']
            $info_all_rows_count += $info['rowCount'];
            clearstatcache();
            if (isset($options['j'])) { # json output
                $json=json_encode([
                'percent_done'    => (float)sprintf("%.1f", $percent_done)
                ,'table_name'      => $info['name']
                ,'row_count'       => $info['rowCount']
                ,'filesize_curent' => format_bytes($filesize_current)
                ])."\n";
                echo $json;
                append_to_locked_file($progress_dump_fn, $json);
            } else {
                printf_mysql_progress( $percent_done, $info['name'], $info['rowCount'], format_bytes($filesize_current) );
            }
        });

        // only for debugging, waste time in every iteration
        $mysql_dump->wastedMicroseconds=0;
        $t = microtime(1);

        // Start the dump
        $mysql_dump->start( $dump_filename );


        clearstatcache();
        $dt=microtime(true)-$t;
        $percent_done = 100.0;
        $message='Created ' . ($dump_filename) . ' with '.format_bytes(filesize($dump_filename)) . ' in ~' . sprintf("%' 3.2f",$dt) . 's. ';

        if( isset($options['j']) ) {
            $json=json_encode([
            'percent_done'     => (float)sprintf("%.1f", $percent_done)
            , 'table_name'       => '' #$info['name']
            , 'row_count'        => '' #$info['rowCount']
            , 'filesize_curent'  => format_bytes(filesize($dump_filename))
            , 'message'          => $message
            , 'filename_created' => $dump_filename
            , 'time_elapsed'     => sprintf("%' 3.1f",$dt).'s'
            ])."\n";
            echo $json;
            append_to_locked_file($progress_dump_fn, $json);
        } else {
            printf_mysql_progress($percent_done, 'All tables have been processed. ', $info_all_rows_count, format_bytes(filesize($dump_filename)));
            if ( $verb >1 ) echo "\n";
            echo "\r$message";
        }
    } catch (\Throwable $e) {
        echo( 'mysqldump-php error: ' . $e->getMessage() );
    }
}


/**
 * Outputs the progress of a MySQL dump in the terminal.
 *
 * Displays a formatted progress bar and information about the current table.
 * The output is determined by the global verbosity level ($verb).
 *
 * @param float  $percent_done Progress in percentage (0.0 to 100.0).
 * @param string $table_name   Name of the currently processed table.
 * @param int    $rows_count   Number of rows already processed.
 * @param string $file_size_mb Size of the processed file (as a formatted string, e.g., "12.3 MB").
 * @return void
 */
function printf_mysql_progress($percent_done, $table_name, $rows_count, $file_size_mb) {
    global $verb;
    if ($verb >= 1)
    printf( "  %5.1f%% -- %-50s %6s %15s %s\r", $percent_done, $table_name, $rows_count, $file_size_mb, progressBar($percent_done, 42));
    if ($verb >= 2) echo "\n";
}


/**
 * Main function for MySQL database dump with progress reporting.
 *
 * Creates a MySQL dump in pure PHP without external tools. Supports:
 * - Compression (none, gzip, bzip2)
 * - AES-256 encryption with password
 * - Progress reporting via ProgressHandler
 * - Automatic WordPress configuration detection
 *
 * @param array $config Configuration array with keys:
 *   - 'host' (string): MySQL host
 *   - 'user' (string): MySQL user
 *   - 'pass' (string): MySQL password
 *   - 'name' (string): Database name
 *   - 'port' (int): MySQL port (default: 3306)
 *   - 'socket' (string): MySQL socket (optional)
 *   - 'charset' (string): Character set (default: utf8mb4)
 *   - 'output_file' (string): Output file path
 *   - 'compression' (string): 'none', 'gzip', or 'bzip2'
 *   - 'password' (string): Encryption password (optional)
 *   - 'no_data' (bool): Structure only, no data (optional)
 * @param ProgressHandler|null $progress Progress handler for updates
 * @return array Result array with keys:
 *   - 'status' (string): 'success' or 'error'
 *   - 'file' (string): Path to created dump file
 *   - 'size' (int): File size in bytes
 *   - 'rows' (int): Total rows exported
 *   - 'tables' (int): Number of tables
 *   - 'time' (float): Execution time in seconds
 *   - 'error' (string): Error message if status is 'error'
 */
function wdump(array $config, ?ProgressHandler $progress = null): array {
    $start_time = microtime(true);
    
    // Set defaults
    $config = array_merge([
        'port' => 3306,
        'socket' => null,
        'charset' => 'utf8mb4',
        'compression' => 'none',
        'password' => null,
        'no_data' => false
    ], $config);
    
    // Validate required fields
    $required = ['host', 'user', 'pass', 'name', 'output_file'];
    foreach ($required as $field) {
        if (empty($config[$field]) && $field !== 'pass') {
            return [
                'status' => 'error',
                'error' => "Missing required configuration field: $field"
            ];
        }
    }
    
    try {
        // Get database statistics
        $db_stats = mysql_get_stats($config['host'], $config['user'], $config['pass'], $config['name']);
        
        // Determine output filename and settings based on compression
        $output_file = $config['output_file'];
        $dump_settings = [];
        
        if ($config['compression'] === 'bzip2' && function_exists("bzopen")) {
            $dump_settings['compress'] = Mysqldump::BZIP2;
            if (!str_ends_with($output_file, '.bz2')) {
                $output_file .= '.bz2';
            }
        } elseif ($config['compression'] === 'gzip' && function_exists("gzopen")) {
            $dump_settings['compress'] = Mysqldump::GZIP;
            if (!str_ends_with($output_file, '.gz')) {
                $output_file .= '.gz';
            }
        } else {
            $dump_settings['compress'] = Mysqldump::NONE;
            if (!str_ends_with($output_file, '.sql') && !str_ends_with($output_file, '.txt')) {
                $output_file .= '.sql';
            }
        }
        
        // Add encryption if password provided
        if (!empty($config['password'])) {
            $dump_settings['compress'] = Mysqldump::AES256ENCRYPT;
            $dump_settings['password'] = $config['password'];
            if (!str_ends_with($output_file, '.aes')) {
                $output_file .= '.aes';
            }
        }
        
        if ($config['no_data']) {
            $dump_settings['no-data'] = true;
        }
        
        // Ensure output directory exists
        $output_dir = dirname($output_file);
        if (!is_dir($output_dir)) {
            if (!mkdir($output_dir, 0777, true)) {
                return [
                    'status' => 'error',
                    'error' => "Could not create output directory: $output_dir"
                ];
            }
        }
        
        // Parse host:port if needed
        if (strpos($config['host'], ':')) {
            list($host, $port) = explode(':', $config['host'], 2);
        } else {
            $host = $config['host'];
            $port = $config['port'];
        }
        
        // Create mysqldump instance
        $mysql_dump = new Mysqldump(
            'mysql:host=' . $host . ';dbname=' . $config['name'],
            $config['user'],
            $config['pass'],
            $dump_settings
        );
        
        // Set up progress tracking
        $total_rows = $db_stats['rows'] ?? 0;
        $rows_processed = 0;
        
        if ($progress) {
            $mysql_dump->setInfoHook(function($table, $info) use ($progress, &$rows_processed, $total_rows, $output_file) {
                $rows_processed += $info['rowCount'];
                $message = sprintf("Table: %s (%d rows)", $info['name'], $info['rowCount']);
                
                // Update progress
                if (file_exists($output_file)) {
                    clearstatcache();
                }
                
                $progress->update($rows_processed, max($total_rows, 1), $message);
            });
        }
        
        // Execute the dump
        $mysql_dump->start($output_file);
        
        // Get final statistics
        clearstatcache();
        $file_size = file_exists($output_file) ? filesize($output_file) : 0;
        $elapsed = microtime(true) - $start_time;
        
        if ($progress) {
            $progress->finish([
                'file' => $output_file,
                'size' => format_bytes($file_size),
                'rows' => $rows_processed,
                'tables' => $db_stats['tables'] ?? 0,
                'time' => sprintf('%.2fs', $elapsed)
            ]);
        }
        
        return [
            'status' => 'success',
            'file' => $output_file,
            'size' => $file_size,
            'rows' => $rows_processed,
            'tables' => $db_stats['tables'] ?? 0,
            'time' => $elapsed
        ];
        
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];
    }
}


/**
 * Checks if the info option is set.
 *
 * This function checks whether either `-i` or `--info` has been passed as an option.
 *
 * @global array $options Options parsed by getopt().
 * @return bool True if the info option is set, otherwise false.
 */
function option_i() {
    global $options;
    return isset($options["i"]) or isset($options["info"]);
}


/**
 * Checks if the info option is set.
 *
 * This function checks whether either `-s` or `--size` has been passed as an option.
 *
 * @global array $options Options parsed by getopt().
 * @return bool True if the info option is set, otherwise false.
 */
function option_s() {
    global $options;
    return isset($options["s"]) or isset($options["size"]);
}


/**
 * Main function for creating an archive with custom format and selective compression.
 *
 * Creates an encrypted archive with file-by-file headers. Each file has:
 * - Type byte (0=directory, 1=uncompressed file, 2=compressed file)
 * - Data length (4 bytes)
 * - Path length (2 bytes)
 * - MD5 checksum (16 bytes)
 * - Relative path
 * - File content (compressed if applicable)
 *
 * @param string $source_dir WordPress root directory to archive
 * @param string $archive_file Output archive file path
 * @param string $password Encryption password (required)
 * @param array $options Additional options:
 *   - 'excluded_paths' (array): Paths to exclude from archive
 *   - 'compression_level' (int): Gzip compression level (0-9, default: 5)
 *   - 'min_size_for_compression' (int): Minimum file size to compress (default: 2560 bytes)
 * @param ProgressHandler|null $progress Progress handler for updates
 * @return array Result array with keys:
 *   - 'status' (string): 'success' or 'error'
 *   - 'archive' (string): Path to created archive
 *   - 'size' (int): Archive size in bytes
 *   - 'files' (int): Number of files archived
 *   - 'dirs' (int): Number of directories archived
 *   - 'time' (float): Execution time in seconds
 *   - 'error' (string): Error message if status is 'error'
 */
function wpack(string $source_dir, string $archive_file, string $password, array $options = [], ?ProgressHandler $progress = null): array {
    $start_time = microtime(true);
    
    // Set defaults
    $excluded_paths = $options['excluded_paths'] ?? [];
    $compression_level = $options['compression_level'] ?? 5;
    $min_size = $options['min_size_for_compression'] ?? 2560;
    
    // Validate inputs
    if (empty($password)) {
        return [
            'status' => 'error',
            'error' => 'Password is required for archive encryption'
        ];
    }
    
    if (!is_dir($source_dir)) {
        return [
            'status' => 'error',
            'error' => "Source directory does not exist: $source_dir"
        ];
    }
    
    try {
        // Calculate total size for progress tracking
        $source_dir = realpath($source_dir);
        $total_size = 0;
        $total_files = 0;
        $total_dirs = 0;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $should_exclude = false;
            foreach ($excluded_paths as $excluded) {
                if (strpos($item->getPathname(), $excluded) === 0) {
                    $should_exclude = true;
                    break;
                }
            }
            if ($should_exclude) continue;
            
            if ($item->isFile()) {
                $total_files++;
                $total_size += $item->getSize();
            } elseif ($item->isDir()) {
                $total_dirs++;
            }
        }
        
        // Open archive file for writing
        $archive_stream = fopen($archive_file, 'wb');
        if (!$archive_stream) {
            return [
                'status' => 'error',
                'error' => "Cannot create archive file: $archive_file"
            ];
        }
        
        // Apply encryption filter
        if (!stream_filter_append($archive_stream, 'aes256', STREAM_FILTER_WRITE, [
            'mode' => 'encrypt',
            'password' => $password
        ])) {
            fclose($archive_stream);
            return [
                'status' => 'error',
                'error' => 'Cannot apply AES-256 encryption filter'
            ];
        }
        
        // Archive files
        $processed_size = 0;
        $processed_files = 0;
        $processed_dirs = 0;
        $processed_total = 0;
        $total_items = $total_files + $total_dirs;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $current_path = $item->getPathname();
            
            // Check exclusions
            $should_exclude = false;
            foreach ($excluded_paths as $excluded) {
                if (strpos($current_path, $excluded) === 0) {
                    $should_exclude = true;
                    break;
                }
            }
            
            // Check .zipignore
            if ($item->isDir() && !$should_exclude && file_exists($item->getPathname() . '/.zipignore')) {
                $excluded_paths[] = $item->getPathname() . '/';
                $should_exclude = true;
            }
            
            if ($should_exclude) continue;
            
            // Skip the archive itself
            if (realpath($current_path) === realpath($archive_file)) {
                continue;
            }
            
            $relative_path = substr($current_path, strlen($source_dir) + 1);
            $relative_path = str_replace('\\', '/', $relative_path);
            
            // Rename wp-config.php to avoid overwriting on extraction
            if ($relative_path === 'wp-config.php') {
                $relative_path = '_wp-config_ORIGINAL.php';
            }
            
            // Determine file type and process content
            $type = $item->isDir() ? 0 : 1;
            $content = '';
            $original_size = 0;
            $compressed = false;
            
            if ($type === 1) {
                $original_size = $item->getSize();
                
                if ($original_size > 0) {
                    $content = file_get_contents($current_path);
                    $checksum = md5($content, true);
                    
                    // Try compression for eligible files
                    if ($original_size >= $min_size && shouldCompressFile(basename($current_path), $original_size)) {
                        $compressed_content = gzencode($content, $compression_level);
                        if ($compressed_content !== false && strlen($compressed_content) < $original_size) {
                            $type = 2;  // Compressed file
                            $content = $compressed_content;
                            $compressed = true;
                            $checksum = md5($content, true);
                        }
                    }
                } else {
                    $checksum = md5('', true);
                }
                
                $processed_files++;
                $processed_size += $original_size;
            } else {
                $checksum = str_repeat("\0", 16);
                $processed_dirs++;
            }
            
            $processed_total++;
            
            // Write header and content
            fwrite($archive_stream, pack('C', $type));
            fwrite($archive_stream, pack('N', strlen($content)));
            fwrite($archive_stream, pack('n', strlen($relative_path)));
            fwrite($archive_stream, $checksum);
            fwrite($archive_stream, $relative_path);
            
            if ($type !== 0 && strlen($content) > 0) {
                fwrite($archive_stream, $content);
            }
            
            // Update progress
            if ($progress && ($processed_total % 100 === 0 || $processed_total === $total_items)) {
                $message = sprintf("%s (%s)", 
                    $compressed ? "Compressed: $relative_path" : $relative_path,
                    format_bytes($original_size)
                );
                $progress->update($processed_total, $total_items, $message);
            }
        }
        
        fclose($archive_stream);
        
        $elapsed = microtime(true) - $start_time;
        $archive_size = filesize($archive_file);
        
        if ($progress) {
            $progress->finish([
                'archive' => $archive_file,
                'size' => format_bytes($archive_size),
                'files' => $processed_files,
                'dirs' => $processed_dirs,
                'time' => sprintf('%.2fs', $elapsed)
            ]);
        }
        
        return [
            'status' => 'success',
            'archive' => $archive_file,
            'size' => $archive_size,
            'files' => $processed_files,
            'dirs' => $processed_dirs,
            'time' => $elapsed
        ];
        
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];
    }
}


/**
 * Main function for extracting a custom-format encrypted archive.
 *
 * Extracts files from an archive created by wpack(). Handles:
 * - AES-256 decryption
 * - Decompression of compressed files
 * - MD5 checksum verification
 * - Directory structure recreation
 *
 * @param string $archive_file Path to archive file
 * @param string $output_dir Output directory for extracted files
 * @param string $password Decryption password
 * @param int $offset Byte offset to start reading (for SFX archives)
 * @param ProgressHandler|null $progress Progress handler for updates
 * @return array Result array with keys:
 *   - 'status' (string): 'success' or 'error'
 *   - 'output_dir' (string): Path to extraction directory
 *   - 'files' (int): Number of files extracted
 *   - 'dirs' (int): Number of directories created
 *   - 'time' (float): Execution time in seconds
 *   - 'error' (string): Error message if status is 'error'
 */
function wunpack(string $archive_file, string $output_dir, string $password, int $offset = 0, ?ProgressHandler $progress = null): array {
    $start_time = microtime(true);
    
    // Validate inputs
    if (!file_exists($archive_file)) {
        return [
            'status' => 'error',
            'error' => "Archive file does not exist: $archive_file"
        ];
    }
    
    if (empty($password)) {
        return [
            'status' => 'error',
            'error' => 'Password is required for decryption'
        ];
    }
    
    try {
        // Open archive
        $stream = fopen($archive_file, 'rb');
        if (!$stream) {
            return [
                'status' => 'error',
                'error' => "Cannot open archive file: $archive_file"
            ];
        }
        
        // Apply decryption filter
        if (!stream_filter_append($stream, 'aes256', STREAM_FILTER_READ, [
            'mode' => 'decrypt',
            'password' => $password
        ])) {
            fclose($stream);
            return [
                'status' => 'error',
                'error' => 'Cannot apply AES-256 decryption filter'
            ];
        }
        
        // Seek to offset if specified (for SFX archives)
        if ($offset > 0) {
            fseek($stream, $offset);
        }
        
        // Create output directory
        if (!is_dir($output_dir)) {
            if (!mkdir($output_dir, 0777, true)) {
                fclose($stream);
                return [
                    'status' => 'error',
                    'error' => "Cannot create output directory: $output_dir"
                ];
            }
        }
        
        // Calculate total size for progress
        $total_size = filesize($archive_file) - $offset;
        $processed_size = 0;
        $processed_files = 0;
        $processed_dirs = 0;
        
        // Extract files
        while (!feof($stream)) {
            // Read header
            $header = fread($stream, 23);  // 1 + 4 + 2 + 16 bytes
            if (strlen($header) < 23) break;
            
            $unpacked = unpack('Ctype/Ndata_length/npath_length', $header);
            $md5sum = substr($header, 7, 16);
            $relative_path = fread($stream, $unpacked['path_length']);
            
            if ($unpacked['type'] === 0) {
                // Directory
                $full_path = $output_dir . DIRECTORY_SEPARATOR . $relative_path;
                if (!is_dir($full_path)) {
                    mkdir($full_path, 0777, true);
                }
                $processed_dirs++;
                $processed_size += 23 + $unpacked['path_length'];
                
                if ($progress) {
                    $progress->update($processed_files + $processed_dirs, 
                                     max($total_size / 1000, 1), 
                                     "Dir: $relative_path");
                }
                continue;
            }
            
            // Read file content
            $content = $unpacked['data_length'] > 0 ? fread($stream, $unpacked['data_length']) : '';
            $processed_size += 23 + $unpacked['path_length'] + $unpacked['data_length'];
            
            // Verify checksum
            if ($unpacked['data_length'] > 0 && md5($content, true) !== $md5sum) {
                fclose($stream);
                return [
                    'status' => 'error',
                    'error' => "Checksum verification failed for: $relative_path"
                ];
            }
            
            // Decompress if needed
            $compressed = ($unpacked['type'] === 2);
            if ($compressed) {
                $content = gzdecode($content);
                if ($content === false) {
                    fclose($stream);
                    return [
                        'status' => 'error',
                        'error' => "Decompression failed for: $relative_path"
                    ];
                }
            }
            
            // Write file
            $full_path = $output_dir . DIRECTORY_SEPARATOR . $relative_path;
            $dir = dirname($full_path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            
            if ($unpacked['data_length'] === 0) {
                touch($full_path);
            } else {
                file_put_contents($full_path, $content);
            }
            
            $processed_files++;
            
            // Update progress
            if ($progress && ($processed_files % 100 === 0 || feof($stream))) {
                $message = sprintf("%s (%s)", 
                    $compressed ? "Decompressed: $relative_path" : $relative_path,
                    format_bytes($unpacked['data_length'])
                );
                $progress->update($processed_files + $processed_dirs, 
                                 max($total_size / 1000, 1), 
                                 $message);
            }
        }
        
        fclose($stream);
        
        $elapsed = microtime(true) - $start_time;
        
        if ($progress) {
            $progress->finish([
                'output_dir' => $output_dir,
                'files' => $processed_files,
                'dirs' => $processed_dirs,
                'time' => sprintf('%.2fs', $elapsed)
            ]);
        }
        
        return [
            'status' => 'success',
            'output_dir' => $output_dir,
            'files' => $processed_files,
            'dirs' => $processed_dirs,
            'time' => $elapsed
        ];
        
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];
    }
}


/**
 * Self-extracting archive (SFX) implementation logic.
 *
 * This script contains the automatic extraction routine that executes when
 * the archive file is run directly. Handles:
 * - Embedded payload detection
 * - Temporary directory creation
 * - Automated extraction process
 * - Cleanup operations
 *
 * @package SFX_Archive
 * @category Compression
 *
 * @uses extractArchive() For the core extraction functionality
 * @uses format_bytes() For human-readable progress reporting
 *
 * @notice Execution requires PHP CLI environment
 */
function __HALT_SFX() {

    global $verb, $totalSize, $sourceDirectory, $archiveFile, $outputDirectory,  $options, $gzipCompressionLevel, $totalDirs,
           $totalFiles, $excludedPaths, $ignored_dirs, $minSizeForCompression, $wp_config_path, $dump_filename, $archiveFile,
           $wpconfig, $my_basename;

    if ( is_directly_executed() && $my_basename === 'wpack.php') {

        getOptions();

        if ($verb >= 2) echo "Running with:          PHP " . PHP_VERSION;

        if (!file_exists($sourceDirectory . '/wp-config.php') AND !file_exists($sourceDirectory . '/../wp-config.php')) {
          echo "\nCould not find file 'wp-config.php' in $sourceDirectory. \n";

          $wpConfigPath = find_wp_config();
          if ($wpConfigPath) {
              echo "Found wp-config.php at: $wpConfigPath. \n";
              $sourceDirectory=dirname($wpConfigPath);
              echo "Found WordPress Installation in " . dq($sourceDirectory) . ".\n";
          } else {
              echo "wp-config.php not found! \n";
              echo "Exit 1.\n";
              exit(1);
          }
        }

        if (file_exists($sourceDirectory . '/wp-config.php'))    $wp_config_path = realpath($sourceDirectory . '/wp-config.php');
        if (file_exists($sourceDirectory . '/../wp-config.php')) $wp_config_path = realpath($sourceDirectory . '/../wp-config.php');

        echo "Calculating sizes for directory '" . $sourceDirectory . "'. Please wait.. ";

        $ignored_dirs = create_zipignore_for_backup_plugins();
        calculateTotals($sourceDirectory);  // '/htdocs
        $freeSpace = disk_free_space($sourceDirectory);

        $dbc = getDbConfig($wpconfig);            // $dbc['host'],
                                                  // $dbc['user'],
                                                  // $dbc['pass'],
                                                  // $dbc['name'],
                                                  // $dbc['port'] ?? ini_get("mysqli.default_port"),
                                                  // $dbc['socket'] ?? ini_get("mysqli.default_socket")

        $db_stats = mysql_get_stats($dbc['host'], $dbc['user'], $dbc['pass'], $dbc['name']);  # gives 'tables' 'rows' and 'size'
                                                  // [size] => 11272192
                                                  // [rows] => 1526
                                                  // [tables] => 52
        if ($verb >= 4) echo "\ndb_stats: " . print_r($db_stats, 1);

        $db_size = $db_stats['size'];

        echo "\nFilesystem is " . format_bytes($totalSize) . '. Found ' . ($totalDirs+$totalFiles) . " items, " . $totalDirs . " directories and " . $totalFiles . " files."
        . "\nCompressing files greater " . format_bytes($GLOBALS['minSizeForCompression']) . ". Creating the archive" . "..."
        . "\n";

        if (option_i() or option_s()) {
            echo "\nminSizeForCompression: " . $minSizeForCompression;
            echo "\ngzipCompressionLevel:  " . $gzipCompressionLevel;
            echo "\narchiveFile:           " . $archiveFile;
            echo "\nsourceDirectory:       " . $sourceDirectory;
            echo "\nwp_config_path:        " . $wp_config_path;
            echo "\ndb_size:               " . format_bytes($db_size) . '(' . $db_size . ')' . ' compressed estimated ' . format_bytes($db_size*0.4) ;
            echo "\n";
            echo "\ntotalSize:             " . format_bytes($totalSize) . ' (' . $totalSize. ')';
            echo "\nFree disc space:       " . format_bytes($freeSpace) . ' (' . $freeSpace. ')';;
            echo "\ntotalItems:            " . ( $totalDirs + $totalFiles ) . ' (totalDirs ' . $totalDirs . ' + totalFiles ' . $totalFiles . ')';
            echo "\n";
            echo "\nexcludedPaths:         " . implode(', '."\n                       ", $excludedPaths) . "\n";
            #if ($verb >= 2) echo "\nignored_dirs:          " . implode(', ', $ignored_dirs);
            if (option_s()) {
                echo("\nOnly showing size estimation and parsed infos. Exit.");
                echo "\n" . sprintf_needed_time_memory_peak() . "\n";
                exit(0);
            }
        }

        if ($totalSize >= $freeSpace) {
          echo "\nNot enough free disc space " . format_bytes($totalSize) . ' (' . $totalSize. ')' . " to create archive of "
             . format_bytes($totalSize) . ' (' . $totalSize. ')' . ". Exit.\n\n";
          exit(1);
        }
        if (!is_dir($sourceDirectory)) {
            echo "\nFATAL: Could not find directory '" . $sourceDirectory . "'. Exit 1.\n\n";
            exit(1);
        }

        echo sprintf_needed_time_memory_peak();
        $t=microtime(1);
        mysql_dump();
        echo sprintf_needed_time_memory_peak($t) . "                        \n";

        $fp__FILE__ = fopen(__FILE__, 'rb');
        // search the __HALT_COMPILER position, the ending here not existing semicolon saves us from finding this comment - as position.
        $posHaltCompiler = false;
        while (($line = fgets($fp__FILE__)) !== false) {
            if (strpos($line, '__HALT_'.'COMPILER();') !== false) {  // the conacatination saves us to match this - as position as well.
                $posHaltCompiler = ftell($fp__FILE__);
                break;
            }
        }
        if (false === $posHaltCompiler) {
            echo "\nFATAL: Could not find the compressed raw data in " . __FILE__ .". Exit 1.\n\n";
            exit(1);
        }

        if (!rewind($fp__FILE__)) {
            echo "\nFATAL: Could not rewind file pointer from " . __FILE__ .". Exit 1.\n\n";
            exit(1);
        }

        $archiveStream = fopen($archiveFile, 'wb');
        if (!$archiveStream) {
            echo("\nFATAL: Could not create '$archiveFile'. Exit 1.\n\n");
            exit(1);
        }
        stream_copy_to_stream( $fp__FILE__, $archiveStream, $posHaltCompiler );
        fclose($archiveStream);
        appendArchive($sourceDirectory, $archiveFile);
        chmod($archiveFile, 0744);
    }
    else
    {
        getOptionsSFX();
        $posHaltCompiler = getOffsetInArchFile($archiveFile);
        if (false === $posHaltCompiler) die("FATAL: Could not find raw-data. Exit.");
        echo "Extracting archive '" . $archiveFile . "' to '" . realpath($outputDirectory) . "'". ($verb >= 2 ? ", reading from position: " . $posHaltCompiler : "") . ".\n";
        $processedSize = $posHaltCompiler;
        // extract archiv from position after $posHaltCompiler
        extractArchive($archiveFile, $outputDirectory, $posHaltCompiler);

        $dbc = getDbConfig($wpconfig);
        print_r($dbc);
        importMysqlDumpInChunks($dumpfile, $dbc['host'], $dbc['user'], $dbc['pass'], $dbc['name']);  # gives 'tables' 'rows' and 'size'

        mysql_import($dumpfile);

    }
}


/**
 * Reads the offset position of the `__HALT_COMPILER()` command in an archive file.
 *
 * This function opens the specified archive file and searches for the position of the
 * `__HALT_COMPILER()` command, which is found in PHP archive files (e.g., SFX archives)
 * to mark the end of the executable PHP code.
 *
 * @param string $archiveFile The path to the archive file in which the `__HALT_COMPILER()` command is searched.
 * @return int|false Returns the position (offset) in the file where the `__HALT_COMPILER()` command was found.
 *                   If the command is not found, `false` is returned.
 */
function getOffsetInArchFile($archiveFile) {
    $fp_in = fopen($archiveFile, 'rb');
    $posHaltCompiler = false;
    while (($line = fgets($fp_in)) !== false) {
        if (strpos($line, '__HALT_'.'COMPILER();') !== false) {
            $posHaltCompiler = ftell($fp_in);
            break;
        }
    }
    return $posHaltCompiler;
}


/**
 * mysql_get_stats connects to a given database and returns some
 * stats about the total size, total number of rows and total
 * number of tables of the database.
 *
 * @param string $DB_HOST contains the name of the databse host,
 *    can also contain a with column seperated port number.
 * @param string $DB_USER contains the username of the database.
 * @param string $DB_PASSWPORD contains the password of the data-
 *    base.
 * @param string $DB_NAME contains the name of the database.
 *
 * @return an array of total size, total number of rows and
 *    total number of tables of the database.
 *
 * @since 0.1.0
 */
function mysql_get_stats($DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME) {
    global $verb;

    if (strpos($DB_HOST, ':')) {
      list($dbhost,$port) = explode(':', DB_HOST);
    } else {
      $dbhost = $DB_HOST;
      $port = 3306;
    }

    $link = mysqli_connect($dbhost, $DB_USER, $DB_PASSWORD, $DB_NAME, $port);
    if (mysqli_connect_errno()) {
      printf("Connect failed: %s\n", mysqli_connect_error());
      exit(1);
    }
    // $query = "SHOW TABLE STATUS FROM `".DB_NAME."`";
    $query = "SHOW TABLE STATUS";

    if ( $result = mysqli_query($link, $query) ) {
      $db_rows   = 0;
      $db_size   = 0;
      $db_tables = 0;
      while ( $row = mysqli_fetch_array($result, MYSQLI_ASSOC) ) {
        $db_rows += $row["Rows"];
        $db_size += $row["Data_length"];
        $db_size += $row["Index_length"];
        $db_tables++;
      }
    }

    $db_size_megabytes = $db_size / 1024 / 1024;

    return
      array(
        'size'   => $db_size,
        'rows'   => $db_rows,
        'tables' => $db_tables
      );
}


/**
 * Deletes a directory along with all its contained files and subdirectories.
 *
 * This function checks if the specified directory exists. If it does, all the files and subdirectories inside
 * are recursively deleted before the directory itself is removed.
 *
 * @param string $dir The path to the directory that should be deleted.
 * @return bool Returns `true` if the directory and all its contents were successfully deleted.
 *              Returns `false` if the specified directory does not exist or if the deletion failed.
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    return rmdir($dir);
}


/**
 * Outputs a message either as a JSON-encoded response or as normal output, based on the options.
 *
 * If the `-j` option is set, the message is output as a JSON object. Otherwise,
 * the message is output using the `echon` function.
 *
 * @param int $verb_level The verbosity level used to control the amount of output.
 * @param string $string The message to be output.
 */

function echoj($verb_level, $string) {
    global $options;
    if (isset($options['j'])) {
        echo json_encode(['message' => $string]) . "\n";
    } else {
        echon($verb_level, $string);
    }
}


/**
 * Outputs a message based on the current verbosity level.
 *
 * If the specified `verb_level` is less than or equal to the global verbosity level (`$verb`),
 * the message is output. Otherwise, the message is not output.
 *
 * @param int $verb_level The verbosity level used to control the amount of output.
 * @param string $string The message to be output.
 */

function echon($verb_level, $string) {
    if ($verb_level <= $GLOBALS['verb']) {
        echo $string . "\n";
    }
}


/**
 * Appends a string to a file that is secured with an exclusive lock.
 *
 * This function opens the specified file in append mode and attempts to acquire an exclusive lock
 * to ensure that the file is not modified by other processes simultaneously.
 * If the lock is successfully acquired, the string is written to the file. Afterward, the
 * lock is released, and the file is closed.
 *
 * @param string $fn The path to the file to which the string should be appended.
 * @param string $string The string to be written to the file.
 * @return void Does not return any values. However, an error message is displayed if the lock cannot be acquired.
 */
function append_to_locked_file($fn, $string) {
    // TODO: error-handling with "try..catch exception"
    $fp = fopen($fn, 'a');
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, $string);
        flock($fp, LOCK_UN);
    } else {
        echo "Couldn't get the lock for '$fn'!";
        // TODO: sleep, and retry..
    }
    fclose($fp);
}


/**
 * Class MySQLiDump
 *
 * A simple MySQL database dump utility using mysqli and optional compression.
 *
 * Features:
 * - Dumps structure and/or data from MySQL tables
 * - Supports gzip and bzip2 compression
 * - Allows table inclusion/exclusion
 * - Supports extended inserts, disabling keys, and locking tables
 * - Optional single transaction dump
 * - Output to file or stdout (php://output)
 *
 * Settings:
 * - include_tables (array): Tables to include (empty = all)
 * - exclude_tables (array): Tables to exclude
 * - compress (string): 'none', 'gzip', or 'bzip2'
 * - no_data (bool): If true, only structure is dumped
 * - add_drop_table (bool): If true, adds DROP TABLE statements
 * - single_transaction (bool): If true, wraps dump in a transaction
 * - lock_tables (bool): If true, locks tables (recommended when no transaction is used)
 * - add_locks (bool): If true, adds LOCK/UNLOCK around inserts
 * - extended_insert (bool): If true, uses extended INSERT format
 * - disable_keys (bool): If true, disables keys during inserts
 * - where (string): Optional WHERE clause applied to all SELECTs
 * - max_query_size (int): Max byte size per extended INSERT statement
 * - charset (string): Connection charset (default: utf8mb4)
 *
 * Usage:
 *   $dumper = new MySQLiDump('localhost', 'user', 'pass', 'dbname');
 *   $dumper->setSettings(['compress' => 'gzip']);
 *   $dumper->export('dump.sql.gz');
 *
 * @package MySQLiDump
 * @author   Ingo Baab <ingo@baab.de>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     https://baab.de/mysqlidump/
 */
class MySQLiDump {
    private $conn;
    private $settings = [
        'include_tables' => [],
        'exclude_tables' => [],
        'compress' => 'none', // 'none', 'gzip', 'bzip2'
        'no_data' => false,
        'add_drop_table' => true,
        'single_transaction' => false,
        'lock_tables' => false,
        'add_locks' => true,
        'extended_insert' => true,
        'disable_keys' => true,
        'where' => '',
        'max_query_size' => 1000000,
        'charset' => 'utf8mb4',
        'password' => ''
    ];

    private $progress_callback = null;
    private $progress_callback_delay = 1.0;
    private $last_progress_time = 0;
    private $total_rows = 0;
    private $exported_rows = 0;

    public function __construct($host, $username, $password, $dbname, $port = 3306, $socket = null, $charset = 'utf8mb4') {
        $this->conn = new mysqli($host, $username, $password, $dbname, $port, $socket);

        if ($this->conn->connect_error) {
            throw new Exception('Connect Error (' . $this->conn->connect_errno . ') ' . $this->conn->connect_error);
        }

        if (!empty($charset)) {
            if (!$this->conn->set_charset($charset)) {
                throw new Exception("Invalid character set '$charset' provided. Supported sets: " .
                    implode(', ', $this->getSupportedCharsets()));
            }
            $this->settings['charset'] = $charset;
        }
    }

    public function setProgressCallback(callable $callback, $delay = 0.25) {
        $this->progress_callback = $callback;
        $this->progress_callback_delay = $delay;
    }

    private function getSupportedCharsets() {
        $result = $this->conn->query("SHOW CHARACTER SET");
        $charsets = [];
        while ($row = $result->fetch_assoc()) {
            $charsets[] = $row['Charset'];
        }
        return $charsets;
    }

    public function setSettings($settings) {
        $this->settings = array_merge($this->settings, $settings);
    }

    public function export($filename = 'php://output') {
        $handle = $this->openFile($filename);

        try {
            $this->calculateTotalRows();
            $this->exported_rows = 0;
            $this->last_progress_time = microtime(true);

            $this->writeHeader($handle);
            $this->exportTables($handle);
            $this->writeFooter($handle);

            if ($this->progress_callback) {
                call_user_func($this->progress_callback, 100);
            }
        } finally {
            fclose($handle);
        }
    }

    private function calculateTotalRows() {
        $this->total_rows = 0;
        foreach ($this->getTables() as $table) {
            if ($this->settings['no_data']) {
                continue;
            }
            $where = $this->settings['where'] ? " WHERE " . $this->settings['where'] : '';
            $result = $this->conn->query("SELECT COUNT(*) AS cnt FROM `$table`" . $where);
            if ($result) {
                $row = $result->fetch_assoc();
                $this->total_rows += (int)$row['cnt'];
            }
        }
    }

    private function openFile($filename) {
        $handle = fopen($filename, 'wb');
        if (!$handle) {
            throw new Exception("Unable to open file: $filename");
        }

        if ($this->settings['compress'] === 'gzip') {
            if (!function_exists('gzencode')) {
                throw new Exception("GZIP compression not available: missing zlib.");
            }
            stream_filter_append($handle, 'zlib.deflate', STREAM_FILTER_WRITE);
        } elseif ($this->settings['compress'] === 'bzip2') {
            if (!function_exists('bzcompress')) {
                throw new Exception("BZIP2 compression not available: missing bz2.");
            }
            stream_filter_append($handle, 'bzip2.compress', STREAM_FILTER_WRITE);
        }

        if (!empty($this->settings['password'])) {
            if (!stream_filter_append($handle, 'aes256', STREAM_FILTER_WRITE, [
                'mode' => 'encrypt',
                'password' => $this->settings['password']
            ])) {
                throw new Exception("Can't append aes256 stream filter.");
            }
        }

        return $handle;
    }

    private function write($handle, $string) {
        fwrite($handle, $string);
    }

    private function writeHeader($handle) {
        $this->write($handle, "-- MySQL dump created by MySQLiDump\n");
        $this->write($handle, "-- Host: {$this->conn->host_info}\n");
        $this->write($handle, "-- Generation Time: " . date('r') . "\n");
        $this->write($handle, "-- Server Version: " . $this->conn->server_info . "\n\n");
        $this->write($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        $this->write($handle, "SET AUTOCOMMIT = 0;\n");
        if ($this->settings['single_transaction']) $this->write($handle, "START TRANSACTION;\n");
        $this->write($handle, "SET time_zone = \"+00:00\";\n\n");
    }

    private function writeFooter($handle) {
        if ($this->settings['single_transaction']) $this->write($handle, "COMMIT;\n");
    }

    private function getTables() {
        $result = $this->conn->query("SHOW TABLES");
        $tables = [];
        while ($row = $result->fetch_row()) {
            $table = $row[0];
            if (!empty($this->settings['include_tables']) && !in_array($table, $this->settings['include_tables'])) {
                continue;
            }
            if (in_array($table, $this->settings['exclude_tables'])) {
                continue;
            }
            $tables[] = $table;
        }
        return $tables;
    }

    private function exportTables($handle) {
        foreach ($this->getTables() as $table) {
            $this->exportTableStructure($handle, $table);
            if (!$this->settings['no_data']) {
                $this->exportTableData($handle, $table);
            }
        }
    }

    private function exportTableStructure($handle, $table) {
        if ($this->settings['add_drop_table']) {
            $this->write($handle, "DROP TABLE IF EXISTS `$table`;\n\n");
        }

        $result = $this->conn->query("SHOW CREATE TABLE `$table`");
        $row = $result->fetch_row();
        $this->write($handle, $row[1] . ";\n\n");
    }

    private function exportTableData($handle, $table) {
        $where = $this->settings['where'] ? " WHERE " . $this->settings['where'] : '';
        $result = $this->conn->query("SELECT * FROM `$table`" . $where);

        if ($result->num_rows === 0) return;

        $this->write($handle, "--\n-- Dumping data for `$table`\n--\n\n");

        if ($this->settings['disable_keys']) {
            $this->write($handle, "ALTER TABLE `$table` DISABLE KEYS;\n");
        }

        if ($this->settings['add_locks']) {
            $this->write($handle, "LOCK TABLES `$table` WRITE;\n");
        }

        $insert_prefix = "INSERT INTO `$table` VALUES ";
        $line_size = 0;
        $first_row = true;

        while ($row = $result->fetch_assoc()) {
            $values = array_map(function ($v) {
                return is_null($v) ? 'NULL' : "'" . addslashes($v) . "'";
            }, array_values($row));

            $row_data = "(" . implode(",", $values) . ")";

            if ($this->settings['extended_insert']) {
                if ($first_row) {
                    $this->write($handle, $insert_prefix . $row_data);
                } else {
                    $this->write($handle, "," . $row_data);
                }

                $line_size += strlen($row_data);
                if ($line_size > $this->settings['max_query_size']) {
                    $this->write($handle, ";\n" . $insert_prefix . $row_data);
                    $line_size = strlen($row_data);
                }
            } else {
                $this->write($handle, $insert_prefix . $row_data . ";\n");
            }

            $first_row = false;

            $this->exported_rows++;
            if ($this->progress_callback) {
                $now = microtime(true);
                if (($now - $this->last_progress_time) >= $this->progress_callback_delay) {
                    $percent = ($this->total_rows > 0) ? ($this->exported_rows / $this->total_rows) * 100 : 100;
                    call_user_func($this->progress_callback, (float)$percent);
                    $this->last_progress_time = $now;
                }
            }
        }

        if ($this->settings['extended_insert']) $this->write($handle, ";\n");
        if ($this->settings['add_locks']) $this->write($handle, "UNLOCK TABLES;\n");
        if ($this->settings['disable_keys']) $this->write($handle, "ALTER TABLE `$table` ENABLE KEYS;\n");

        $this->write($handle, "\n");
    }

    /**
     * Extrahiert eine verschlüsselte/komprimierte Dump-Datei
     *
     * @param string $inputFile Eingabedatei (verschlüsselt/komprimiert)
     * @param string $outputFile Ausgabedatei (unverschlüsselt)
     * @param string $password Passwort für die Entschlüsselung
     * @param string $compression Kompressionsmethode ('none', 'gzip', 'bzip2')
     * @throws Exception Bei Fehlern
     */
    public static function extract($inputFile, $outputFile, $password = null, $compression = 'none') {
        if (!file_exists($inputFile)) {
            throw new Exception("Input file does not exist: $inputFile");
        }

        $input = fopen($inputFile, 'rb');
        if (!$input) {
            throw new Exception("Cannot open input file: $inputFile");
        }

        $output = fopen($outputFile, 'wb');
        if (!$output) {
            fclose($input);
            throw new Exception("Cannot create output file: $outputFile");
        }

        try {
            // Entschlüsselungsfilter anwenden
            if ($password) {
                if (!stream_filter_append($input, 'aes256', STREAM_FILTER_READ, [
                    'mode' => 'decrypt',
                    'password' => $password
                ])) {
                    throw new Exception("Failed to apply decryption filter");
                }
            }

            // Dekompressionsfilter anwenden
            switch ($compression) {
                case 'gzip':
                    if (!stream_filter_append($input, 'zlib.inflate', STREAM_FILTER_READ)) {
                        throw new Exception("Failed to apply gzip decompression");
                    }
                    break;
                case 'bzip2':
                    if (!stream_filter_append($input, 'bzip2.decompress', STREAM_FILTER_READ)) {
                        throw new Exception("Failed to apply bzip2 decompression");
                    }
                    break;
                case 'none':
                    break;
                default:
                    throw new Exception("Unsupported compression method: $compression");
            }

            // Daten kopieren
            $bufferSize = 8192;
            while (!feof($input)) {
                $data = fread($input, $bufferSize);
                if ($data === false) {
                    throw new Exception("Error reading from input file");
                }
                if (fwrite($output, $data) === false) {
                    throw new Exception("Error writing to output file");
                }
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

}


class MySQLiDumpExtractor {
    private $password;
    private $compress; // 'none', 'gzip', 'bzip2'

    public function __construct($password = '', $compress = 'none') {
        $this->password = $password;
        $this->compress = $compress;
    }

    /**
     * Extract encrypted & compressed dump file to plain SQL file.
     *
     * @param string $inputFile Encrypted & compressed dump filename (e.g. .sql.enc)
     * @param string $outputFile Output plain SQL filename
     * @throws Exception on errors
     */
    public function extract(string $inputFile, string $outputFile): void {
        // Check if compression method is supported by PHP environment
        if ($this->compress === 'gzip' && !function_exists('inflate_init')) {
            throw new Exception("gzip decompression not supported by this PHP installation.");
        }
        if ($this->compress === 'bzip2' && !function_exists('bzopen')) {
            throw new Exception("bzip2 decompression not supported by this PHP installation.");
        }

        $in = fopen($inputFile, 'rb');
        if (!$in) {
            throw new Exception("Cannot open input file '$inputFile' for reading.");
        }

        $out = fopen($outputFile, 'wb');
        if (!$out) {
            fclose($in);
            throw new Exception("Cannot open output file '$outputFile' for writing.");
        }

        try {
            // Append decrypt filter if password is set
            if (!empty($this->password)) {
                if (!stream_filter_append($in, 'aes256', STREAM_FILTER_READ, [
                    'mode' => 'decrypt',
                    'password' => $this->password
                ])) {
                    throw new Exception("Failed to append aes256 decrypt filter. Possibly wrong filter name or implementation missing.");
                }
            }

            // Append decompression filter
            if ($this->compress === 'gzip') {
                if (!stream_filter_append($in, 'zlib.inflate', STREAM_FILTER_READ)) {
                    throw new Exception("Failed to append gzip inflate filter.");
                }
            } elseif ($this->compress === 'bzip2') {
                if (!stream_filter_append($in, 'bzip2.decompress', STREAM_FILTER_READ)) {
                    throw new Exception("Failed to append bzip2 decompress filter.");
                }
            } elseif ($this->compress !== 'none') {
                throw new Exception("Unsupported compression method: {$this->compress}");
            }

            $total_bytes = 0;
            while (!feof($in)) {
                $data = fread($in, 8192);
                if ($data === false) {
                    throw new Exception("Error reading input file " . $inputFile . " during extraction.");
                }
                $bytes_written = fwrite($out, $data);
                if ($bytes_written === false || $bytes_written !== strlen($data)) {
                    throw new Exception("Error writing to output file during extraction.");
                }
                $total_bytes += $bytes_written;
            }

            // Simple heuristic: if output file is empty or very small, maybe password wrong
            if ($total_bytes === 0) {
                throw new Exception("Extraction resulted in empty output file. Possibly wrong password or corrupted input.");
            }

        } finally {
            fclose($in);
            fclose($out);
        }
    }
  }

/**
 * PHP version of mysqldump cli that comes with MySQL.
 *
 * Tags: mysql mysqldump pdo php7 php5 database php sql hhvm mariadb mysql-backup.
 *
 * Class Mysqldump.
 *
 * @category Library
 * @author   Diego Torres <ifsnop@github.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     https://github.com/ifsnop/mysqldump-php
 *
 */
class Mysqldump
{

    // Same as mysqldump.
    const MAXLINESIZE = 1000000;

    // List of available compression methods as constants.
    const GZIP  = 'Gzip';
    const BZIP2 = 'Bzip2';
    const NONE  = 'None';
    const GZIPSTREAM = 'Gzipstream';
    const AES256ENCRYPT = 'Aes256encrypt';

    // List of available connection strings.
    const UTF8    = 'utf8';
    const UTF8MB4 = 'utf8mb4';
    const BINARY = 'binary';

    /**
     * Database username.
     * @var string
     */
    public $user;

    /**
     * Database password.
     * @var string
     */
    public $pass;

    /**
     * Connection string for PDO.
     * @var string
     */
    public $dsn;

    /**
     * Destination filename, defaults to stdout.
     * @var string
     */
    public $fileName = 'php://stdout';

    /**
     * Wasted Microseconds, defaults to 0.
     * @var Integer
     */
    public $wastedMicroseconds=0;

    // Internal stuff.
    private $tables = array();
    private $views = array();
    private $triggers = array();
    private $procedures = array();
    private $functions = array();
    private $events = array();
    protected $dbHandler = null;
    private $dbType = "";
    private $compressManager;
    private $typeAdapter;
    protected $dumpSettings = array();
    protected $pdoSettings = array();
    private $version;
    private $tableColumnTypes = array();
    private $transformTableRowCallable;
    private $transformColumnValueCallable;
    private $infoCallable;

    /**
     * Database name, parsed from dsn.
     * @var string
     */
    private $dbName;

    /**
     * Host name, parsed from dsn.
     * @var string
     */
    private $host;

    /**
     * Dsn string parsed as an array.
     * @var array
     */
    private $dsnArray = array();

    /**
     * Keyed on table name, with the value as the conditions.
     * e.g. - 'users' => 'date_registered > NOW() - INTERVAL 6 MONTH'
     *
     * @var array
     */
    private $tableWheres = array();
    private $tableLimits = array();

    protected $dumpSettingsDefault = array(
        'include-tables' => array(),
        'exclude-tables' => array(),
        'include-views' => array(),
        'compress' => Mysqldump::NONE,
        'init_commands' => array(),
        'no-data' => array(),
        'if-not-exists' => false,
        'reset-auto-increment' => false,
        'add-drop-database' => false,
        'add-drop-table' => false,
        'add-drop-trigger' => true,
        'add-locks' => true,
        'complete-insert' => false,
        'databases' => false,
        'default-character-set' => Mysqldump::UTF8,
        'disable-keys' => true,
        'extended-insert' => true,
        'events' => false,
        'hex-blob' => true, /* faster than escaped content */
        'insert-ignore' => false,
        'net_buffer_length' => self::MAXLINESIZE,
        'no-autocommit' => true,
        'no-create-db' => false,
        'no-create-info' => false,
        'lock-tables' => true,
        'routines' => false,
        'single-transaction' => true,
        'skip-triggers' => false,
        'skip-tz-utc' => false,
        'skip-comments' => false,
        'skip-dump-date' => false,
        'skip-definer' => false,
        'where' => '',
        /* deprecated */
        'disable-foreign-keys-check' => true
    );

    protected $pdoSettingsDefault = array(
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        );

    /**
     * Constructor of Mysqldump. Note that in the case of an SQLite database
     * connection, the filename must be in the $db parameter.
     *
     * @param string $dsn        PDO DSN connection string
     * @param string $user       SQL account username
     * @param string $pass       SQL account password
     * @param array  $dumpSettings SQL database settings
     * @param array  $pdoSettings  PDO configured attributes
     */
    public function __construct(
        $dsn = '',
        $user = '',
        $pass = '',
        $dumpSettings = array(),
        $pdoSettings = array()
    ) {

        $this->user = $user;
        $this->pass = $pass;
        $this->parseDsn($dsn);

        // This drops MYSQL dependency, only use the constant if it's defined.
        if ("mysql" === $this->dbType) {
            $this->pdoSettingsDefault[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }

        $this->pdoSettings = array_replace_recursive($this->pdoSettingsDefault, $pdoSettings);
        $this->dumpSettings = array_replace_recursive($this->dumpSettingsDefault, $dumpSettings);
        $this->dumpSettings['init_commands'][] = "SET NAMES ".$this->dumpSettings['default-character-set'];

        if (false === $this->dumpSettings['skip-tz-utc']) {
            $this->dumpSettings['init_commands'][] = "SET TIME_ZONE='+00:00'";
        }

        $diff = array_diff(array_keys($this->dumpSettings), array_keys($this->dumpSettingsDefault));
        if (count($diff) > 0) {
            throw new Exception("Unexpected value in dumpSettings: (".implode(",", $diff).")");
        }

        if (!is_array($this->dumpSettings['include-tables']) ||
            !is_array($this->dumpSettings['exclude-tables'])) {
            throw new Exception("Include-tables and exclude-tables should be arrays");
        }

        // If no include-views is passed in, dump the same views as tables, mimic mysqldump behaviour.
        if (!isset($dumpSettings['include-views'])) {
            $this->dumpSettings['include-views'] = $this->dumpSettings['include-tables'];
        }

        // Create a new compressManager to manage compressed output
        $this->compressManager = CompressManagerFactory::create($this->dumpSettings['compress']);
    }

    /**
     * Destructor of Mysqldump. Unsets dbHandlers and database objects.
     */
    public function __destruct()
    {
        $this->dbHandler = null;
    }

    /**
     * Keyed by table name, with the value as the conditions:
     * e.g. 'users' => 'date_registered > NOW() - INTERVAL 6 MONTH AND deleted=0'
     *
     * @param array $tableWheres
     */
    public function setTableWheres(array $tableWheres)
    {
        $this->tableWheres = $tableWheres;
    }

    /**
     * @param $tableName
     *
     * @return boolean|mixed
     */
    public function getTableWhere($tableName)
    {
        if (!empty($this->tableWheres[$tableName])) {
            return $this->tableWheres[$tableName];
        } elseif ($this->dumpSettings['where']) {
            return $this->dumpSettings['where'];
        }

        return false;
    }

    /**
     * Keyed by table name, with the value as the numeric limit:
     * e.g. 'users' => 3000
     *
     * @param array $tableLimits
     */
    public function setTableLimits(array $tableLimits)
    {
        $this->tableLimits = $tableLimits;
    }

    /**
     * Returns the LIMIT for the table.  Must be numeric to be returned.
     * @param $tableName
     * @return boolean
     */
    public function getTableLimit($tableName)
    {
        if (!isset($this->tableLimits[$tableName])) {
            return false;
        }

        $limit = $this->tableLimits[$tableName];
        if (!is_numeric($limit)) {
            return false;
        }

        return $limit;
    }

    /**
    * Import supplied SQL file
    * @param string $path Absolute path to imported *.sql file
    */
    public function restore($path)
    {
        if(!$path || !is_file($path)){
            throw new Exception("File {$path} does not exist.");
        }

        $handle = fopen($path , 'rb');

        if(!$handle){
            throw new Exception("Failed reading file {$path}. Check access permissions.");
        }

        if(!$this->dbHandler){
            $this->connect();
        }

        $buffer = '';
        while ( !feof($handle) ) {
            $line = fgets($handle);

            if (substr($line, 0, 2) == '--' || !$line) {
                continue; // skip comments
            }

            $buffer .= $line;

            // if it has a semicolon at the end, it's the end of the query
            if (';' == substr(rtrim($line), -1, 1)) {
                $this->dbHandler->exec($buffer);
                $buffer = '';
            }
        }

        fclose($handle);
    }

    /**
     * Parse DSN string and extract dbname value
     * Several examples of a DSN string
     *   mysql:host=localhost;dbname=testdb
     *   mysql:host=localhost;port=3307;dbname=testdb
     *   mysql:unix_socket=/tmp/mysql.sock;dbname=testdb
     *
     * @param string $dsn dsn string to parse
     * @return boolean
     */
    private function parseDsn($dsn)
    {
        if (empty($dsn) || (false === ($pos = strpos($dsn, ":")))) {
            throw new Exception("Empty DSN string");
        }

        $this->dsn = $dsn;
        $this->dbType = strtolower(substr($dsn, 0, $pos)); // always returns a string

        if (empty($this->dbType)) {
            throw new Exception("Missing database type from DSN string");
        }

        $dsn = substr($dsn, $pos + 1);

        foreach (explode(";", $dsn) as $kvp) {
            $kvpArr = explode("=", $kvp);
            $this->dsnArray[strtolower($kvpArr[0])] = $kvpArr[1];
        }

        if (empty($this->dsnArray['host']) &&
            empty($this->dsnArray['unix_socket'])) {
            throw new Exception("Missing host from DSN string");
        }
        $this->host = (!empty($this->dsnArray['host'])) ?
            $this->dsnArray['host'] : $this->dsnArray['unix_socket'];

        if (empty($this->dsnArray['dbname'])) {
            throw new Exception("Missing database name from DSN string");
        }

        $this->dbName = $this->dsnArray['dbname'];

        return true;
    }

    /**
     * Connect with PDO.
     *
     * @return null
     */
    protected function connect()
    {
        // Connecting with PDO.
        try {
            switch ($this->dbType) {
                case 'sqlite':
                    $this->dbHandler = @new PDO("sqlite:".$this->dbName, null, null, $this->pdoSettings);
                    break;
                case 'mysql':
                case 'pgsql':
                case 'dblib':
                    $this->dbHandler = @new PDO(
                        $this->dsn,
                        $this->user,
                        $this->pass,
                        $this->pdoSettings
                    );
                    // Execute init commands once connected
                    foreach ($this->dumpSettings['init_commands'] as $stmt) {
                        $this->dbHandler->exec($stmt);
                    }
                    // Store server version
                    $this->version = $this->dbHandler->getAttribute(PDO::ATTR_SERVER_VERSION);
                    break;
                default:
                    throw new Exception("Unsupported database type (".$this->dbType.")");
            }
        } catch (PDOException $e) {
            throw new Exception(
                "Connection to ".$this->dbType." failed with message: ".
                $e->getMessage()
            );
        }

        if (is_null($this->dbHandler)) {
            throw new Exception("Connection to ".$this->dbType."failed");
        }

        $this->dbHandler->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
        $this->typeAdapter = TypeAdapterFactory::create($this->dbType, $this->dbHandler, $this->dumpSettings);
    }

    /**
     * Primary function, triggers dumping.
     *
     * @param string $filename  Name of file to write sql dump to
     * @return null
     * @throws \Exception
     */
    public function start($filename = '')
    {
        // Output file can be redefined here
        if (!empty($filename)) {
            $this->fileName = $filename;
        }

        // Connect to database
        $this->connect();

        // Create output file
        $this->compressManager->open($this->fileName);

        // Write some basic info to output file
        $this->compressManager->write($this->getDumpFileHeader());

        // initiate a transaction at global level to create a consistent snapshot
        if ($this->dumpSettings['single-transaction']) {
            $this->dbHandler->exec($this->typeAdapter->setup_transaction());
            $this->dbHandler->exec($this->typeAdapter->start_transaction());
        }

        // Store server settings and use sanner defaults to dump
        $this->compressManager->write(
            $this->typeAdapter->backup_parameters()
        );

        if ($this->dumpSettings['databases']) {
            $this->compressManager->write(
                $this->typeAdapter->getDatabaseHeader($this->dbName)
            );
            if ($this->dumpSettings['add-drop-database']) {
                $this->compressManager->write(
                    $this->typeAdapter->add_drop_database($this->dbName)
                );
            }
        }

        // Get table, view, trigger, procedures, functions and events structures from
        // database.
        $this->getDatabaseStructureTables();
        $this->getDatabaseStructureViews();
        $this->getDatabaseStructureTriggers();
        $this->getDatabaseStructureProcedures();
        $this->getDatabaseStructureFunctions();
        $this->getDatabaseStructureEvents();

        if ($this->dumpSettings['databases']) {
            $this->compressManager->write(
                $this->typeAdapter->databases($this->dbName)
            );
        }

        // If there still are some tables/views in include-tables array,
        // that means that some tables or views weren't found.
        // Give proper error and exit.
        // This check will be removed once include-tables supports regexps.
        if (0 < count($this->dumpSettings['include-tables'])) {
            $name = implode(",", $this->dumpSettings['include-tables']);
            throw new Exception("Table (".$name.") not found in database");
        }

        $this->exportTables();
        $this->exportTriggers();
        $this->exportFunctions();
        $this->exportProcedures();
        $this->exportViews();
        $this->exportEvents();

        // Restore saved parameters.
        $this->compressManager->write(
            $this->typeAdapter->restore_parameters()
        );

        // end transaction
        if ($this->dumpSettings['single-transaction']) {
            $this->dbHandler->exec($this->typeAdapter->commit_transaction());
        }

        // Write some stats to output file.
        $this->compressManager->write($this->getDumpFileFooter());
        // Close output file.
        $this->compressManager->close();

        return;
    }

    /**
     * Returns header for dump file.
     *
     * @return string
     */
    private function getDumpFileHeader()
    {
        $header = '';
        if (!$this->dumpSettings['skip-comments']) {
            // Some info about software, source and time
            $header = "-- mysqldump-php https://github.com/ifsnop/mysqldump-php".PHP_EOL.
                    "--".PHP_EOL.
                    "-- Host: {$this->host}\tDatabase: {$this->dbName}".PHP_EOL.
                    "-- ------------------------------------------------------".PHP_EOL;

            if (!empty($this->version)) {
                $header .= "-- Server version \t".$this->version.PHP_EOL;
            }

            if (!$this->dumpSettings['skip-dump-date']) {
                $header .= "-- Date: ".date('r').PHP_EOL.PHP_EOL;
            }
        }
        return $header;
    }

    /**
     * Returns footer for dump file.
     *
     * @return string
     */
    private function getDumpFileFooter()
    {
        $footer = '';
        if (!$this->dumpSettings['skip-comments']) {
            $footer .= '-- Dump completed';
            if (!$this->dumpSettings['skip-dump-date']) {
                $footer .= ' on: '.date('r');
            }
            $footer .= PHP_EOL;
        }

        return $footer;
    }

    /**
     * Reads table names from database.
     * Fills $this->tables array so they will be dumped later.
     *
     * @return null
     */
    private function getDatabaseStructureTables()
    {
        // Listing all tables from database
        if (empty($this->dumpSettings['include-tables'])) {
            // include all tables for now, blacklisting happens later
            foreach ($this->dbHandler->query($this->typeAdapter->show_tables($this->dbName)) as $row) {
                array_push($this->tables, current($row));
            }
        } else {
            // include only the tables mentioned in include-tables
            foreach ($this->dbHandler->query($this->typeAdapter->show_tables($this->dbName)) as $row) {
                if (in_array(current($row), $this->dumpSettings['include-tables'], true)) {
                    array_push($this->tables, current($row));
                    $elem = array_search(
                        current($row),
                        $this->dumpSettings['include-tables']
                    );
                    unset($this->dumpSettings['include-tables'][$elem]);
                }
            }
        }
        return;
    }

    /**
     * Reads view names from database.
     * Fills $this->tables array so they will be dumped later.
     *
     * @return null
     */
    private function getDatabaseStructureViews()
    {
        // Listing all views from database
        if (empty($this->dumpSettings['include-views'])) {
            // include all views for now, blacklisting happens later
            foreach ($this->dbHandler->query($this->typeAdapter->show_views($this->dbName)) as $row) {
                array_push($this->views, current($row));
            }
        } else {
            // include only the tables mentioned in include-tables
            foreach ($this->dbHandler->query($this->typeAdapter->show_views($this->dbName)) as $row) {
                if (in_array(current($row), $this->dumpSettings['include-views'], true)) {
                    array_push($this->views, current($row));
                    $elem = array_search(
                        current($row),
                        $this->dumpSettings['include-views']
                    );
                    unset($this->dumpSettings['include-views'][$elem]);
                }
            }
        }
        return;
    }

    /**
     * Reads trigger names from database.
     * Fills $this->tables array so they will be dumped later.
     *
     * @return null
     */
    private function getDatabaseStructureTriggers()
    {
        // Listing all triggers from database
        if (false === $this->dumpSettings['skip-triggers']) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_triggers($this->dbName)) as $row) {
                array_push($this->triggers, $row['Trigger']);
            }
        }
        return;
    }

    /**
     * Reads procedure names from database.
     * Fills $this->tables array so they will be dumped later.
     *
     * @return null
     */
    private function getDatabaseStructureProcedures()
    {
        // Listing all procedures from database
        if ($this->dumpSettings['routines']) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_procedures($this->dbName)) as $row) {
                array_push($this->procedures, $row['procedure_name']);
            }
        }
        return;
    }

    /**
     * Reads functions names from database.
     * Fills $this->tables array so they will be dumped later.
     *
     * @return null
     */
    private function getDatabaseStructureFunctions()
    {
        // Listing all functions from database
        if ($this->dumpSettings['routines']) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_functions($this->dbName)) as $row) {
                array_push($this->functions, $row['function_name']);
            }
        }
        return;
    }

    /**
     * Reads event names from database.
     * Fills $this->tables array so they will be dumped later.
     *
     * @return null
     */
    private function getDatabaseStructureEvents()
    {
        // Listing all events from database
        if ($this->dumpSettings['events']) {
            foreach ($this->dbHandler->query($this->typeAdapter->show_events($this->dbName)) as $row) {
                array_push($this->events, $row['event_name']);
            }
        }
        return;
    }

    /**
     * Compare if $table name matches with a definition inside $arr
     * @param $table string
     * @param $arr array with strings or patterns
     * @return boolean
     */
    private function matches($table, $arr)
    {
        $match = false;

        foreach ($arr as $pattern) {
            if ('/' != $pattern[0]) {
                continue;
            }
            if (1 == preg_match($pattern, $table)) {
                $match = true;
            }
        }

        return in_array($table, $arr) || $match;
    }

    /**
     * Exports all the tables selected from database
     *
     * @return null
     */
    private function exportTables()
    {


        // Exporting tables one by one
        foreach ($this->tables as $table) {
            if ($this->matches($table, $this->dumpSettings['exclude-tables'])) {
                continue;
            }
            $this->getTableStructure($table);
            if (false === $this->dumpSettings['no-data']) { // don't break compatibility with old trigger
                $this->listValues($table);
            } elseif (true === $this->dumpSettings['no-data']
                 || $this->matches($table, $this->dumpSettings['no-data'])) {
                continue;
            } else {
                $this->listValues($table);
            }
        }
    }

    /**
     * Exports all the views found in database
     *
     * @return null
     */
    private function exportViews()
    {
        if (false === $this->dumpSettings['no-create-info']) {
            // Exporting views one by one
            foreach ($this->views as $view) {
                if ($this->matches($view, $this->dumpSettings['exclude-tables'])) {
                    continue;
                }
                $this->tableColumnTypes[$view] = $this->getTableColumnTypes($view);
                $this->getViewStructureTable($view);
            }
            foreach ($this->views as $view) {
                if ($this->matches($view, $this->dumpSettings['exclude-tables'])) {
                    continue;
                }
                $this->getViewStructureView($view);
            }
        }
    }

    /**
     * Exports all the triggers found in database
     *
     * @return null
     */
    private function exportTriggers()
    {
        // Exporting triggers one by one
        foreach ($this->triggers as $trigger) {
            $this->getTriggerStructure($trigger);
        }

    }

    /**
     * Exports all the procedures found in database
     *
     * @return null
     */
    private function exportProcedures()
    {
        // Exporting triggers one by one
        foreach ($this->procedures as $procedure) {
            $this->getProcedureStructure($procedure);
        }
    }

    /**
     * Exports all the functions found in database
     *
     * @return null
     */
    private function exportFunctions()
    {
        // Exporting triggers one by one
        foreach ($this->functions as $function) {
            $this->getFunctionStructure($function);
        }
    }

    /**
     * Exports all the events found in database
     *
     * @return null
     */
    private function exportEvents()
    {
        // Exporting triggers one by one
        foreach ($this->events as $event) {
            $this->getEventStructure($event);
        }
    }

    /**
     * Table structure extractor
     *
     * @todo move specific mysql code to typeAdapter
     * @param string $tableName  Name of table to export
     * @return null
     */
    private function getTableStructure($tableName)
    {
        if (!$this->dumpSettings['no-create-info']) {
            $ret = '';
            if (!$this->dumpSettings['skip-comments']) {
                $ret = "--".PHP_EOL.
                    "-- Table structure for table `$tableName`".PHP_EOL.
                    "--".PHP_EOL.PHP_EOL;
            }
            $stmt = $this->typeAdapter->show_create_table($tableName);
            foreach ($this->dbHandler->query($stmt) as $r) {
                $this->compressManager->write($ret);
                if ($this->dumpSettings['add-drop-table']) {
                    $this->compressManager->write(
                        $this->typeAdapter->drop_table($tableName)
                    );
                }
                $this->compressManager->write(
                    $this->typeAdapter->create_table($r)
                );
                break;
            }
        }
        $this->tableColumnTypes[$tableName] = $this->getTableColumnTypes($tableName);
        return;
    }

    /**
     * Store column types to create data dumps and for Stand-In tables
     *
     * @param string $tableName  Name of table to export
     * @return array type column types detailed
     */

    private function getTableColumnTypes($tableName)
    {
        $columnTypes = array();
        $columns = $this->dbHandler->query(
            $this->typeAdapter->show_columns($tableName)
        );
        $columns->setFetchMode(PDO::FETCH_ASSOC);

        foreach ($columns as $key => $col) {
            $types = $this->typeAdapter->parseColumnType($col);
            $columnTypes[$col['Field']] = array(
                'is_numeric'=> $types['is_numeric'],
                'is_blob' => $types['is_blob'],
                'type' => $types['type'],
                'type_sql' => $col['Type'],
                'is_virtual' => $types['is_virtual']
            );
        }

        return $columnTypes;
    }

    /**
     * View structure extractor, create table (avoids cyclic references)
     *
     * @todo move mysql specific code to typeAdapter
     * @param string $viewName  Name of view to export
     * @return null
     */
    private function getViewStructureTable($viewName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--".PHP_EOL.
                "-- Stand-In structure for view `{$viewName}`".PHP_EOL.
                "--".PHP_EOL.PHP_EOL;
            $this->compressManager->write($ret);
        }
        $stmt = $this->typeAdapter->show_create_view($viewName);

        // create views as tables, to resolve dependencies
        foreach ($this->dbHandler->query($stmt) as $r) {
            if ($this->dumpSettings['add-drop-table']) {
                $this->compressManager->write(
                    $this->typeAdapter->drop_view($viewName)
                );
            }

            $this->compressManager->write(
                $this->createStandInTable($viewName)
            );
            break;
        }
    }

    /**
     * Write a create table statement for the table Stand-In, show create
     * table would return a create algorithm when used on a view
     *
     * @param string $viewName  Name of view to export
     * @return string create statement
     */
    public function createStandInTable($viewName)
    {
        $ret = array();
        foreach ($this->tableColumnTypes[$viewName] as $k => $v) {
            $ret[] = "`{$k}` {$v['type_sql']}";
        }
        $ret = implode(PHP_EOL.",", $ret);

        $ret = "CREATE TABLE IF NOT EXISTS `$viewName` (".
            PHP_EOL.$ret.PHP_EOL.");".PHP_EOL;

        return $ret;
    }

    /**
     * View structure extractor, create view
     *
     * @todo move mysql specific code to typeAdapter
     * @param string $viewName  Name of view to export
     * @return null
     */
    private function getViewStructureView($viewName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--".PHP_EOL.
                "-- View structure for view `{$viewName}`".PHP_EOL.
                "--".PHP_EOL.PHP_EOL;
            $this->compressManager->write($ret);
        }
        $stmt = $this->typeAdapter->show_create_view($viewName);

        // create views, to resolve dependencies
        // replacing tables with views
        foreach ($this->dbHandler->query($stmt) as $r) {
            // because we must replace table with view, we should delete it
            $this->compressManager->write(
                $this->typeAdapter->drop_view($viewName)
            );
            $this->compressManager->write(
                $this->typeAdapter->create_view($r)
            );
            break;
        }
    }

    /**
     * Trigger structure extractor
     *
     * @param string $triggerName  Name of trigger to export
     * @return null
     */
    private function getTriggerStructure($triggerName)
    {
        $stmt = $this->typeAdapter->show_create_trigger($triggerName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            if ($this->dumpSettings['add-drop-trigger']) {
                $this->compressManager->write(
                    $this->typeAdapter->add_drop_trigger($triggerName)
                );
            }
            $this->compressManager->write(
                $this->typeAdapter->create_trigger($r)
            );
            return;
        }
    }

    /**
     * Procedure structure extractor
     *
     * @param string $procedureName  Name of procedure to export
     * @return null
     */
    private function getProcedureStructure($procedureName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--".PHP_EOL.
                "-- Dumping routines for database '".$this->dbName."'".PHP_EOL.
                "--".PHP_EOL.PHP_EOL;
            $this->compressManager->write($ret);
        }
        $stmt = $this->typeAdapter->show_create_procedure($procedureName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            $this->compressManager->write(
                $this->typeAdapter->create_procedure($r)
            );
            return;
        }
    }

    /**
     * Function structure extractor
     *
     * @param string $functionName  Name of function to export
     * @return null
     */
    private function getFunctionStructure($functionName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--".PHP_EOL.
                "-- Dumping routines for database '".$this->dbName."'".PHP_EOL.
                "--".PHP_EOL.PHP_EOL;
            $this->compressManager->write($ret);
        }
        $stmt = $this->typeAdapter->show_create_function($functionName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            $this->compressManager->write(
                $this->typeAdapter->create_function($r)
            );
            return;
        }
    }

    /**
     * Event structure extractor
     *
     * @param string $eventName  Name of event to export
     * @return null
     */
    private function getEventStructure($eventName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--".PHP_EOL.
                "-- Dumping events for database '".$this->dbName."'".PHP_EOL.
                "--".PHP_EOL.PHP_EOL;
            $this->compressManager->write($ret);
        }
        $stmt = $this->typeAdapter->show_create_event($eventName);
        foreach ($this->dbHandler->query($stmt) as $r) {
            $this->compressManager->write(
                $this->typeAdapter->create_event($r)
            );
            return;
        }
    }

    /**
     * Prepare values for output
     *
     * @param string $tableName Name of table which contains rows
     * @param array $row Associative array of column names and values to be
     *   quoted
     *
     * @return array
     */
    private function prepareColumnValues($tableName, array $row)
    {
        $ret = array();
        $columnTypes = $this->tableColumnTypes[$tableName];

        if ($this->transformTableRowCallable) {
            $row = call_user_func($this->transformTableRowCallable, $tableName, $row);
        }

        foreach ($row as $colName => $colValue) {
            if ($this->transformColumnValueCallable) {
                $colValue = call_user_func($this->transformColumnValueCallable, $tableName, $colName, $colValue, $row);
            }

            $ret[] = $this->escape($colValue, $columnTypes[$colName]);
        }

        return $ret;
    }

    /**
     * Escape values with quotes when needed
     *
     * @param string $tableName Name of table which contains rows
     * @param array $row Associative array of column names and values to be quoted
     *
     * @return string
     */
    private function escape($colValue, $colType)
    {
        if (is_null($colValue)) {
            return "NULL";
        } elseif ($this->dumpSettings['hex-blob'] && $colType['is_blob']) {
            if ($colType['type'] == 'bit' || !empty($colValue)) {
                return "0x{$colValue}";
            } else {
                return "''";
            }
        } elseif ($colType['is_numeric']) {
            return $colValue;
        }

        return $this->dbHandler->quote($colValue);
    }

    /**
     * Set a callable that will be used to transform table rows
     *
     * @param callable $callable
     *
     * @return void
     */
    public function setTransformTableRowHook($callable)
    {
        $this->transformTableRowCallable = $callable;
    }

    /**
     * Set a callable that will be used to transform column values
     *
     * @param callable $callable
     *
     * @return void
     *
     * @deprecated Use setTransformTableRowHook instead for better performance
     */
    public function setTransformColumnValueHook($callable)
    {
        $this->transformColumnValueCallable = $callable;
    }

    /**
     * Set a callable that will be used to report dump information
     *
     * @param callable $callable
     *
     * @return void
     */
    public function setInfoHook($callable)
    {
        $this->infoCallable = $callable;
    }

    /**
     * Table rows extractor
     *
     * @param string $tableName  Name of table to export
     *
     * @return null
     */
    private function listValues($tableName)
    {
        $this->prepareListValues($tableName);

        $onlyOnce = true;

        // colStmt is used to form a query to obtain row values
        $colStmt = $this->getColumnStmt($tableName);
        // colNames is used to get the name of the columns when using complete-insert
        if ($this->dumpSettings['complete-insert']) {
            $colNames = $this->getColumnNames($tableName);
        }

        $stmt = "SELECT ".implode(",", $colStmt)." FROM `$tableName`";

        // Table specific conditions override the default 'where'
        $condition = $this->getTableWhere($tableName);

        if ($condition) {
            $stmt .= " WHERE {$condition}";
        }

        $limit = $this->getTableLimit($tableName);

        if ($limit !== false) {
            $stmt .= " LIMIT {$limit}";
        }

        $resultSet = $this->dbHandler->query($stmt);
        $resultSet->setFetchMode(PDO::FETCH_ASSOC);

        $ignore = $this->dumpSettings['insert-ignore'] ? '  IGNORE' : '';

        $count = 0;
        $line = '';
        foreach ($resultSet as $row) {
            $count++;
            $vals = $this->prepareColumnValues($tableName, $row);
            if ($onlyOnce || !$this->dumpSettings['extended-insert']) {
                if ($this->dumpSettings['complete-insert']) {
                    $line .= "INSERT$ignore INTO `$tableName` (".
                        implode(", ", $colNames).
                        ") VALUES (".implode(",", $vals).")";
                } else {
                    $line .= "INSERT$ignore INTO `$tableName` VALUES (".implode(",", $vals).")";
                }
                $onlyOnce = false;
            } else {
                $line .= ",(".implode(",", $vals).")";
            }
            if ((strlen($line) > $this->dumpSettings['net_buffer_length']) ||
                    !$this->dumpSettings['extended-insert']) {
                $onlyOnce = true;
                $this->compressManager->write($line . ";".PHP_EOL);
                $line = '';
            }
            usleep($this->wastedMicroseconds);
        }
        $resultSet->closeCursor();

        if ('' !== $line) {
            $this->compressManager->write($line. ";".PHP_EOL);
        }

        $this->endListValues($tableName, $count);

        if ($this->infoCallable) {
            call_user_func($this->infoCallable, 'table', array('name' => $tableName, 'rowCount' => $count));
        }
    }

    /**
     * Table rows extractor, append information prior to dump
     *
     * @param string $tableName  Name of table to export
     *
     * @return null
     */
    public function prepareListValues($tableName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $this->compressManager->write(
                "--".PHP_EOL.
                "-- Dumping data for table `$tableName`".PHP_EOL.
                "--".PHP_EOL.PHP_EOL
            );
        }

        if ($this->dumpSettings['lock-tables'] && !$this->dumpSettings['single-transaction']) {
            $this->typeAdapter->lock_table($tableName);
        }

        if ($this->dumpSettings['add-locks']) {
            $this->compressManager->write(
                $this->typeAdapter->start_add_lock_table($tableName)
            );
        }

        if ($this->dumpSettings['disable-keys']) {
            $this->compressManager->write(
                $this->typeAdapter->start_add_disable_keys($tableName)
            );
        }

        // Disable autocommit for faster reload
        if ($this->dumpSettings['no-autocommit']) {
            $this->compressManager->write(
                $this->typeAdapter->start_disable_autocommit()
            );
        }

        return;
    }

    /**
     * Table rows extractor, close locks and commits after dump
     *
     * @param string $tableName Name of table to export.
     * @param integer    $count     Number of rows inserted.
     *
     * @return void
     */
    public function endListValues($tableName, $count = 0)
    {
        if ($this->dumpSettings['disable-keys']) {
            $this->compressManager->write(
                $this->typeAdapter->end_add_disable_keys($tableName)
            );
        }

        if ($this->dumpSettings['add-locks']) {
            $this->compressManager->write(
                $this->typeAdapter->end_add_lock_table($tableName)
            );
        }

        if ($this->dumpSettings['lock-tables'] && !$this->dumpSettings['single-transaction']) {
            $this->typeAdapter->unlock_table($tableName);
        }

        // Commit to enable autocommit
        if ($this->dumpSettings['no-autocommit']) {
            $this->compressManager->write(
                $this->typeAdapter->end_disable_autocommit()
            );
        }

        $this->compressManager->write(PHP_EOL);

        if (!$this->dumpSettings['skip-comments']) {
            $this->compressManager->write(
                "-- Dumped table `".$tableName."` with $count row(s)".PHP_EOL.
                '--'.PHP_EOL.PHP_EOL
            );
        }

        return;
    }

    /**
     * Build SQL List of all columns on current table which will be used for selecting
     *
     * @param string $tableName  Name of table to get columns
     *
     * @return array SQL sentence with columns for select
     */
    public function getColumnStmt($tableName)
    {
        $colStmt = array();
        foreach ($this->tableColumnTypes[$tableName] as $colName => $colType) {
            if ($colType['is_virtual']) {
                $this->dumpSettings['complete-insert'] = true;
                continue;
            } elseif ($colType['type'] == 'bit' && $this->dumpSettings['hex-blob']) {
                $colStmt[] = "LPAD(HEX(`{$colName}`),2,'0') AS `{$colName}`";
            } elseif ($colType['type'] == 'double' && PHP_VERSION_ID > 80100) {
                $colStmt[] = sprintf("CONCAT(`%s`) AS `%s`", $colName, $colName);
            } elseif ($colType['is_blob'] && $this->dumpSettings['hex-blob']) {
                $colStmt[] = "HEX(`{$colName}`) AS `{$colName}`";
            } else {
                $colStmt[] = "`{$colName}`";
            }
        }

        return $colStmt;
    }

    /**
     * Build SQL List of all columns on current table which will be used for inserting
     *
     * @param string $tableName  Name of table to get columns
     *
     * @return array columns for sql sentence for insert
     */
    public function getColumnNames($tableName)
    {
        $colNames = array();
        foreach ($this->tableColumnTypes[$tableName] as $colName => $colType) {
            if ($colType['is_virtual']) {
                $this->dumpSettings['complete-insert'] = true;
                continue;
            } else {
                $colNames[] = "`{$colName}`";
            }
        }
        return $colNames;
    }
}

/**
 * Enum with all available compression methods
 *
 */
abstract class CompressMethod
{
    public static $enums = array(
        Mysqldump::NONE,
        Mysqldump::GZIP,
        Mysqldump::BZIP2,
        Mysqldump::GZIPSTREAM,
        Mysqldump::AES256ENCRYPT,
    );

    /**
     * @param string $c
     * @return boolean
     */
    public static function isValid($c)
    {
        return in_array($c, self::$enums);
    }
}

abstract class CompressManagerFactory
{
    /**
     * @param string $c
     * @return CompressBzip2|CompressGzip|CompressNone
     */
    public static function create($c)
    {
        $c = ucfirst(strtolower($c));
        if (!CompressMethod::isValid($c)) {
            throw new Exception("Compression method ($c) is not defined yet");
        }

        $method = __NAMESPACE__."\\"."Compress".$c;

        return new $method;
    }
}

class CompressBzip2 extends CompressManagerFactory
{
    private $fileHandler = null;

    public function __construct()
    {
        if (!function_exists("bzopen")) {
            throw new Exception("Compression is enabled, but bzip2 lib is not installed or configured properly");
        }
    }

    /**
     * @param string $filename
     */
    public function open($filename)
    {
        $this->fileHandler = bzopen($filename, "w");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }

        return true;
    }

    public function write($str)
    {
        $bytesWritten = bzwrite($this->fileHandler, $str);
        if (false === $bytesWritten) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }

    public function close()
    {
        return bzclose($this->fileHandler);
    }
}

class CompressGzip extends CompressManagerFactory
{
    private $fileHandler = null;

    public function __construct()
    {
        if (!function_exists("gzopen")) {
            throw new Exception("Compression is enabled, but gzip lib is not installed or configured properly");
        }
    }

    /**
     * @param string $filename
     */
    public function open($filename)
    {
        $this->fileHandler = gzopen($filename, "wb");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }

        return true;
    }

    public function write($str)
    {
        $bytesWritten = gzwrite($this->fileHandler, $str);
        if (false === $bytesWritten) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }

    public function close()
    {
        return gzclose($this->fileHandler);
    }
}

class CompressNone extends CompressManagerFactory
{
    private $fileHandler = null;

    /**
     * @param string $filename
     */
    public function open($filename)
    {
        $this->fileHandler = fopen($filename, "wb");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }

        return true;
    }

    public function write($str)
    {
        $bytesWritten = fwrite($this->fileHandler, $str);
        if (false === $bytesWritten) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }

    public function close()
    {
        return fclose($this->fileHandler);
    }
}

class CompressAes256encrypt extends CompressManagerFactory
{
    private $fileHandler = null;

    /**
     * @param string $filename
     */
    public function open($filename)
    {
        $this->fileHandler = fopen($filename, "wb");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }

        stream_filter_append($this->fileHandler , 'aes256', STREAM_FILTER_WRITE, [
            'password' => $GLOBALS['password'],
        ]);

        return true;
    }

    public function write($str)
    {
        $bytesWritten = fwrite($this->fileHandler, $str);
        if (false === $bytesWritten) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }

    public function close()
    {
        return fclose($this->fileHandler);
    }
}

class CompressGzipstream extends CompressManagerFactory
{
    private $fileHandler = null;

    private $compressContext;

    /**
     * @param string $filename
     */
    public function open($filename)
    {
    $this->fileHandler = fopen($filename, "wb");
    if (false === $this->fileHandler) {
        throw new Exception("Output file is not writable");
    }

    $this->compressContext = deflate_init(ZLIB_ENCODING_GZIP, array('level' => 9));
    return true;
    }

    public function write($str)
    {

    $bytesWritten = fwrite($this->fileHandler, deflate_add($this->compressContext, $str, ZLIB_NO_FLUSH));
    if (false === $bytesWritten) {
        throw new Exception("Writting to file failed! Probably, there is no more free space left?");
    }
    return $bytesWritten;
    }

    public function close()
    {
    fwrite($this->fileHandler, deflate_add($this->compressContext, '', ZLIB_FINISH));
    return fclose($this->fileHandler);
    }
}

/**
 * Enum with all available TypeAdapter implementations
 *
 */
abstract class TypeAdapter
{
    public static $enums = array(
        "Sqlite",
        "Mysql"
    );

    /**
     * @param string $c
     * @return boolean
     */
    public static function isValid($c)
    {
        return in_array($c, self::$enums);
    }
}

/**
 * TypeAdapter Factory
 *
 */
abstract class TypeAdapterFactory
{
    protected $dbHandler = null;
    protected $dumpSettings = array();

    /**
     * @param string $c Type of database factory to create (Mysql, Sqlite,...)
     * @param PDO $dbHandler
     */
    public static function create($c, $dbHandler = null, $dumpSettings = array())
    {
        $c = ucfirst(strtolower($c));
        if (!TypeAdapter::isValid($c)) {
            throw new Exception("Database type support for ($c) not yet available");
        }
        $method = __NAMESPACE__."\\"."TypeAdapter".$c;
        return new $method($dbHandler, $dumpSettings);
    }

    public function __construct($dbHandler = null, $dumpSettings = array())
    {
        $this->dbHandler = $dbHandler;
        $this->dumpSettings = $dumpSettings;
    }

    /**
     * function databases Add sql to create and use database
     * @todo make it do something with sqlite
     */
    public function databases()
    {
        return "";
    }

    public function show_create_table($tableName)
    {
        return "SELECT tbl_name as 'Table', sql as 'Create Table' ".
            "FROM sqlite_master ".
            "WHERE type='table' AND tbl_name='$tableName'";
    }

    /**
     * function create_table Get table creation code from database
     * @todo make it do something with sqlite
     */
    public function create_table($row)
    {
        return "";
    }

    public function show_create_view($viewName)
    {
        return "SELECT tbl_name as 'View', sql as 'Create View' ".
            "FROM sqlite_master ".
            "WHERE type='view' AND tbl_name='$viewName'";
    }

    /**
     * function create_view Get view creation code from database
     * @todo make it do something with sqlite
     */
    public function create_view($row)
    {
        return "";
    }

    /**
     * function show_create_trigger Get trigger creation code from database
     * @todo make it do something with sqlite
     */
    public function show_create_trigger($triggerName)
    {
        return "";
    }

    /**
     * function create_trigger Modify trigger code, add delimiters, etc
     * @todo make it do something with sqlite
     */
    public function create_trigger($triggerName)
    {
        return "";
    }

    /**
     * function create_procedure Modify procedure code, add delimiters, etc
     * @todo make it do something with sqlite
     */
    public function create_procedure($procedureName)
    {
        return "";
    }

    /**
     * function create_function Modify function code, add delimiters, etc
     * @todo make it do something with sqlite
     */
    public function create_function($functionName)
    {
        return "";
    }

    public function show_tables()
    {
        return "SELECT tbl_name FROM sqlite_master WHERE type='table'";
    }

    public function show_views()
    {
        return "SELECT tbl_name FROM sqlite_master WHERE type='view'";
    }

    public function show_triggers()
    {
        return "SELECT name FROM sqlite_master WHERE type='trigger'";
    }

    public function show_columns()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "pragma table_info({$args[0]})";
    }

    public function show_procedures()
    {
        return "";
    }

    public function show_functions()
    {
        return "";
    }

    public function show_events()
    {
        return "";
    }

    public function setup_transaction()
    {
        return "";
    }

    public function start_transaction()
    {
        return "BEGIN EXCLUSIVE";
    }

    public function commit_transaction()
    {
        return "COMMIT";
    }

    public function lock_table()
    {
        return "";
    }

    public function unlock_table()
    {
        return "";
    }

    public function start_add_lock_table()
    {
        return PHP_EOL;
    }

    public function end_add_lock_table()
    {
        return PHP_EOL;
    }

    public function start_add_disable_keys()
    {
        return PHP_EOL;
    }

    public function end_add_disable_keys()
    {
        return PHP_EOL;
    }

    public function start_disable_foreign_keys_check()
    {
        return PHP_EOL;
    }

    public function end_disable_foreign_keys_check()
    {
        return PHP_EOL;
    }

    public function add_drop_database()
    {
        return PHP_EOL;
    }

    public function add_drop_trigger()
    {
        return PHP_EOL;
    }

    public function drop_table()
    {
        return PHP_EOL;
    }

    public function drop_view()
    {
        return PHP_EOL;
    }

    /**
     * Decode column metadata and fill info structure.
     * type, is_numeric and is_blob will always be available.
     *
     * @param array $colType Array returned from "SHOW COLUMNS FROM tableName"
     * @return array
     */
    public function parseColumnType($colType)
    {
        return array();
    }

    public function backup_parameters()
    {
        return PHP_EOL;
    }

    public function restore_parameters()
    {
        return PHP_EOL;
    }
}

class TypeAdapterPgsql extends TypeAdapterFactory
{
}

class TypeAdapterDblib extends TypeAdapterFactory
{
}

class TypeAdapterSqlite extends TypeAdapterFactory
{
}

class TypeAdapterMysql extends TypeAdapterFactory
{
    const DEFINER_RE = 'DEFINER=`(?:[^`]|``)*`@`(?:[^`]|``)*`';


    // Numerical Mysql types
    public $mysqlTypes = array(
        'numerical' => array(
            'bit',
            'tinyint',
            'smallint',
            'mediumint',
            'int',
            'integer',
            'bigint',
            'real',
            'double',
            'float',
            'decimal',
            'numeric'
        ),
        'blob' => array(
            'tinyblob',
            'blob',
            'mediumblob',
            'longblob',
            'binary',
            'varbinary',
            'bit',
            'geometry', /* http://bugs.mysql.com/bug.php?id=43544 */
            'point',
            'linestring',
            'polygon',
            'multipoint',
            'multilinestring',
            'multipolygon',
            'geometrycollection',
        )
    );

    public function databases()
    {
        if ($this->dumpSettings['no-create-db']) {
           return "";
        }

        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        $databaseName = $args[0];

        $resultSet = $this->dbHandler->query("SHOW VARIABLES LIKE 'character_set_database';");
        $characterSet = $resultSet->fetchColumn(1);
        $resultSet->closeCursor();

        $resultSet = $this->dbHandler->query("SHOW VARIABLES LIKE 'collation_database';");
        $collationDb = $resultSet->fetchColumn(1);
        $resultSet->closeCursor();
        $ret = "";

        $ret .= "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `{$databaseName}`".
            " /*!40100 DEFAULT CHARACTER SET {$characterSet} ".
            " COLLATE {$collationDb} */;".PHP_EOL.PHP_EOL.
            "USE `{$databaseName}`;".PHP_EOL.PHP_EOL;

        return $ret;
    }

    public function show_create_table($tableName)
    {
        return "SHOW CREATE TABLE `$tableName`";
    }

    public function show_create_view($viewName)
    {
        return "SHOW CREATE VIEW `$viewName`";
    }

    public function show_create_trigger($triggerName)
    {
        return "SHOW CREATE TRIGGER `$triggerName`";
    }

    public function show_create_procedure($procedureName)
    {
        return "SHOW CREATE PROCEDURE `$procedureName`";
    }

    public function show_create_function($functionName)
    {
        return "SHOW CREATE FUNCTION `$functionName`";
    }

    public function show_create_event($eventName)
    {
        return "SHOW CREATE EVENT `$eventName`";
    }

    public function create_table($row)
    {
        if (!isset($row['Create Table'])) {
            throw new Exception("Error getting table code, unknown output");
        }

        $createTable = $row['Create Table'];
        if ($this->dumpSettings['reset-auto-increment']) {
            $match = "/AUTO_INCREMENT=[0-9]+/s";
            $replace = "";
            $createTable = preg_replace($match, $replace, $createTable);
        }

		if ($this->dumpSettings['if-not-exists'] ) {
			$createTable = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $createTable);
        }

        $ret = "/*!40101 SET @saved_cs_client     = @@character_set_client */;".PHP_EOL.
            "/*!40101 SET character_set_client = ".$this->dumpSettings['default-character-set']." */;".PHP_EOL.
            $createTable.";".PHP_EOL.
            "/*!40101 SET character_set_client = @saved_cs_client */;".PHP_EOL.
            PHP_EOL;
        return $ret;
    }

    public function create_view($row)
    {
        $ret = "";
        if (!isset($row['Create View'])) {
            throw new Exception("Error getting view structure, unknown output");
        }

        $viewStmt = $row['Create View'];

        $definerStr = $this->dumpSettings['skip-definer'] ? '' : '/*!50013 \2 */'.PHP_EOL;

        if ($viewStmtReplaced = preg_replace(
            '/^(CREATE(?:\s+ALGORITHM=(?:UNDEFINED|MERGE|TEMPTABLE))?)\s+('
            .self::DEFINER_RE.'(?:\s+SQL SECURITY (?:DEFINER|INVOKER))?)?\s+(VIEW .+)$/',
            '/*!50001 \1 */'.PHP_EOL.$definerStr.'/*!50001 \3 */',
            $viewStmt,
            1
        )) {
            $viewStmt = $viewStmtReplaced;
        };

        $ret .= $viewStmt.';'.PHP_EOL.PHP_EOL;
        return $ret;
    }

    public function create_trigger($row)
    {
        $ret = "";
        if (!isset($row['SQL Original Statement'])) {
            throw new Exception("Error getting trigger code, unknown output");
        }

        $triggerStmt = $row['SQL Original Statement'];
        $definerStr = $this->dumpSettings['skip-definer'] ? '' : '/*!50017 \2*/ ';
        if ($triggerStmtReplaced = preg_replace(
            '/^(CREATE)\s+('.self::DEFINER_RE.')?\s+(TRIGGER\s.*)$/s',
            '/*!50003 \1*/ '.$definerStr.'/*!50003 \3 */',
            $triggerStmt,
            1
        )) {
            $triggerStmt = $triggerStmtReplaced;
        }

        $ret .= "DELIMITER ;;".PHP_EOL.
            $triggerStmt.";;".PHP_EOL.
            "DELIMITER ;".PHP_EOL.PHP_EOL;
        return $ret;
    }

    public function create_procedure($row)
    {
        $ret = "";
        if (!isset($row['Create Procedure'])) {
            throw new Exception("Error getting procedure code, unknown output. ".
                "Please check 'https://bugs.mysql.com/bug.php?id=14564'");
        }
        $procedureStmt = $row['Create Procedure'];
        if ($this->dumpSettings['skip-definer']) {
            if ($procedureStmtReplaced = preg_replace(
                '/^(CREATE)\s+('.self::DEFINER_RE.')?\s+(PROCEDURE\s.*)$/s',
                '\1 \3',
                $procedureStmt,
                1
            )) {
                $procedureStmt = $procedureStmtReplaced;
            }
        }

        $ret .= "/*!50003 DROP PROCEDURE IF EXISTS `".
            $row['Procedure']."` */;".PHP_EOL.
            "/*!40101 SET @saved_cs_client     = @@character_set_client */;".PHP_EOL.
            "/*!40101 SET character_set_client = ".$this->dumpSettings['default-character-set']." */;".PHP_EOL.
            "DELIMITER ;;".PHP_EOL.
            $procedureStmt." ;;".PHP_EOL.
            "DELIMITER ;".PHP_EOL.
            "/*!40101 SET character_set_client = @saved_cs_client */;".PHP_EOL.PHP_EOL;

        return $ret;
    }

    public function create_function($row)
    {
        $ret = "";
        if (!isset($row['Create Function'])) {
            throw new Exception("Error getting function code, unknown output. ".
                "Please check 'https://bugs.mysql.com/bug.php?id=14564'");
        }
        $functionStmt = $row['Create Function'];
        $characterSetClient = $row['character_set_client'];
        $collationConnection = $row['collation_connection'];
        $sqlMode = $row['sql_mode'];
        if ( $this->dumpSettings['skip-definer'] ) {
            if ($functionStmtReplaced = preg_replace(
                '/^(CREATE)\s+('.self::DEFINER_RE.')?\s+(FUNCTION\s.*)$/s',
                '\1 \3',
                $functionStmt,
                1
            )) {
                $functionStmt = $functionStmtReplaced;
            }
        }

        $ret .= "/*!50003 DROP FUNCTION IF EXISTS `".
            $row['Function']."` */;".PHP_EOL.
            "/*!40101 SET @saved_cs_client     = @@character_set_client */;".PHP_EOL.
            "/*!50003 SET @saved_cs_results     = @@character_set_results */ ;".PHP_EOL.
            "/*!50003 SET @saved_col_connection = @@collation_connection */ ;".PHP_EOL.
            "/*!40101 SET character_set_client = ".$characterSetClient." */;".PHP_EOL.
            "/*!40101 SET character_set_results = ".$characterSetClient." */;".PHP_EOL.
            "/*!50003 SET collation_connection  = ".$collationConnection." */ ;".PHP_EOL.
            "/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;;".PHP_EOL.
            "/*!50003 SET sql_mode              = '".$sqlMode."' */ ;;".PHP_EOL.
            "/*!50003 SET @saved_time_zone      = @@time_zone */ ;;".PHP_EOL.
            "/*!50003 SET time_zone             = 'SYSTEM' */ ;;".PHP_EOL.
            "DELIMITER ;;".PHP_EOL.
            $functionStmt." ;;".PHP_EOL.
            "DELIMITER ;".PHP_EOL.
            "/*!50003 SET sql_mode              = @saved_sql_mode */ ;".PHP_EOL.
            "/*!50003 SET character_set_client  = @saved_cs_client */ ;".PHP_EOL.
            "/*!50003 SET character_set_results = @saved_cs_results */ ;".PHP_EOL.
            "/*!50003 SET collation_connection  = @saved_col_connection */ ;".PHP_EOL.
            "/*!50106 SET TIME_ZONE= @saved_time_zone */ ;".PHP_EOL.PHP_EOL;


        return $ret;
    }

    public function create_event($row)
    {
        $ret = "";
        if (!isset($row['Create Event'])) {
            throw new Exception("Error getting event code, unknown output. ".
                "Please check 'http://stackoverflow.com/questions/10853826/mysql-5-5-create-event-gives-syntax-error'");
        }
        $eventName = $row['Event'];
        $eventStmt = $row['Create Event'];
        $sqlMode = $row['sql_mode'];
        $definerStr = $this->dumpSettings['skip-definer'] ? '' : '/*!50117 \2*/ ';

        if ($eventStmtReplaced = preg_replace(
            '/^(CREATE)\s+('.self::DEFINER_RE.')?\s+(EVENT .*)$/',
            '/*!50106 \1*/ '.$definerStr.'/*!50106 \3 */',
            $eventStmt,
            1
        )) {
            $eventStmt = $eventStmtReplaced;
        }

        $ret .= "/*!50106 SET @save_time_zone= @@TIME_ZONE */ ;".PHP_EOL.
            "/*!50106 DROP EVENT IF EXISTS `".$eventName."` */;".PHP_EOL.
            "DELIMITER ;;".PHP_EOL.
            "/*!50003 SET @saved_cs_client      = @@character_set_client */ ;;".PHP_EOL.
            "/*!50003 SET @saved_cs_results     = @@character_set_results */ ;;".PHP_EOL.
            "/*!50003 SET @saved_col_connection = @@collation_connection */ ;;".PHP_EOL.
            "/*!50003 SET character_set_client  = utf8 */ ;;".PHP_EOL.
            "/*!50003 SET character_set_results = utf8 */ ;;".PHP_EOL.
            "/*!50003 SET collation_connection  = utf8_general_ci */ ;;".PHP_EOL.
            "/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;;".PHP_EOL.
            "/*!50003 SET sql_mode              = '".$sqlMode."' */ ;;".PHP_EOL.
            "/*!50003 SET @saved_time_zone      = @@time_zone */ ;;".PHP_EOL.
            "/*!50003 SET time_zone             = 'SYSTEM' */ ;;".PHP_EOL.
            $eventStmt." ;;".PHP_EOL.
            "/*!50003 SET time_zone             = @saved_time_zone */ ;;".PHP_EOL.
            "/*!50003 SET sql_mode              = @saved_sql_mode */ ;;".PHP_EOL.
            "/*!50003 SET character_set_client  = @saved_cs_client */ ;;".PHP_EOL.
            "/*!50003 SET character_set_results = @saved_cs_results */ ;;".PHP_EOL.
            "/*!50003 SET collation_connection  = @saved_col_connection */ ;;".PHP_EOL.
            "DELIMITER ;".PHP_EOL.
            "/*!50106 SET TIME_ZONE= @save_time_zone */ ;".PHP_EOL.PHP_EOL;
            // Commented because we are doing this in restore_parameters()
            // "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;" . PHP_EOL . PHP_EOL;

        return $ret;
    }

    public function show_tables()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SELECT TABLE_NAME AS tbl_name ".
            "FROM INFORMATION_SCHEMA.TABLES ".
            "WHERE TABLE_TYPE='BASE TABLE' AND TABLE_SCHEMA='{$args[0]}' ".
            "ORDER BY TABLE_NAME";
    }

    public function show_views()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SELECT TABLE_NAME AS tbl_name ".
            "FROM INFORMATION_SCHEMA.TABLES ".
            "WHERE TABLE_TYPE='VIEW' AND TABLE_SCHEMA='{$args[0]}' ".
            "ORDER BY TABLE_NAME";
    }

    public function show_triggers()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SHOW TRIGGERS FROM `{$args[0]}`;";
    }

    public function show_columns()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SHOW COLUMNS FROM `{$args[0]}`;";
    }

    public function show_procedures()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SELECT SPECIFIC_NAME AS procedure_name ".
            "FROM INFORMATION_SCHEMA.ROUTINES ".
            "WHERE ROUTINE_TYPE='PROCEDURE' AND ROUTINE_SCHEMA='{$args[0]}'";
    }

    public function show_functions()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SELECT SPECIFIC_NAME AS function_name ".
            "FROM INFORMATION_SCHEMA.ROUTINES ".
            "WHERE ROUTINE_TYPE='FUNCTION' AND ROUTINE_SCHEMA='{$args[0]}'";
    }

    /**
     * Get query string to ask for names of events from current database.
     *
     * @param string Name of database
     * @return string
     */
    public function show_events()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "SELECT EVENT_NAME AS event_name ".
            "FROM INFORMATION_SCHEMA.EVENTS ".
            "WHERE EVENT_SCHEMA='{$args[0]}'";
    }

    public function setup_transaction()
    {
        return "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ";
    }

    public function start_transaction()
    {
        return "START TRANSACTION ".
            "/*!40100 WITH CONSISTENT SNAPSHOT */";
    }


    public function commit_transaction()
    {
        return "COMMIT";
    }

    public function lock_table()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return $this->dbHandler->exec("LOCK TABLES `{$args[0]}` READ LOCAL");
    }

    public function unlock_table()
    {
        return $this->dbHandler->exec("UNLOCK TABLES");
    }

    public function start_add_lock_table()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "LOCK TABLES `{$args[0]}` WRITE;".PHP_EOL;
    }

    public function end_add_lock_table()
    {
        return "UNLOCK TABLES;".PHP_EOL;
    }

    public function start_add_disable_keys()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "/*!40000 ALTER TABLE `{$args[0]}` DISABLE KEYS */;".
            PHP_EOL;
    }

    public function end_add_disable_keys()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "/*!40000 ALTER TABLE `{$args[0]}` ENABLE KEYS */;".
            PHP_EOL;
    }

    public function start_disable_autocommit()
    {
        return "SET autocommit=0;".PHP_EOL;
    }

    public function end_disable_autocommit()
    {
        return "COMMIT;".PHP_EOL;
    }

    public function add_drop_database()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "/*!40000 DROP DATABASE IF EXISTS `{$args[0]}`*/;".
            PHP_EOL.PHP_EOL;
    }

    public function add_drop_trigger()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "DROP TRIGGER IF EXISTS `{$args[0]}`;".PHP_EOL;
    }

    public function drop_table()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "DROP TABLE IF EXISTS `{$args[0]}`;".PHP_EOL;
    }

    public function drop_view()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "DROP TABLE IF EXISTS `{$args[0]}`;".PHP_EOL.
                "/*!50001 DROP VIEW IF EXISTS `{$args[0]}`*/;".PHP_EOL;
    }

    public function getDatabaseHeader()
    {
        $this->check_parameters(func_num_args(), $expected_num_args = 1, __METHOD__);
        $args = func_get_args();
        return "--".PHP_EOL.
            "-- Current Database: `{$args[0]}`".PHP_EOL.
            "--".PHP_EOL.PHP_EOL;
    }

    /**
     * Decode column metadata and fill info structure.
     * type, is_numeric and is_blob will always be available.
     *
     * @param array $colType Array returned from "SHOW COLUMNS FROM tableName"
     * @return array
     */
    public function parseColumnType($colType)
    {
        $colInfo = array();
        $colParts = explode(" ", $colType['Type']);

        if ($fparen = strpos($colParts[0], "(")) {
            $colInfo['type'] = substr($colParts[0], 0, $fparen);
            $colInfo['length'] = str_replace(")", "", substr($colParts[0], $fparen + 1));
            $colInfo['attributes'] = isset($colParts[1]) ? $colParts[1] : null;
        } else {
            $colInfo['type'] = $colParts[0];
        }
        $colInfo['is_numeric'] = in_array($colInfo['type'], $this->mysqlTypes['numerical']);
        $colInfo['is_blob'] = in_array($colInfo['type'], $this->mysqlTypes['blob']);
        // for virtual columns that are of type 'Extra', column type
        // could by "STORED GENERATED" or "VIRTUAL GENERATED"
        // MySQL reference: https://dev.mysql.com/doc/refman/5.7/en/create-table-generated-columns.html
        $colInfo['is_virtual'] = strpos($colType['Extra'], "VIRTUAL GENERATED") !== false || strpos($colType['Extra'], "STORED GENERATED") !== false;

        return $colInfo;
    }

    public function backup_parameters()
    {
        $ret = "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;".PHP_EOL.
            "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;".PHP_EOL.
            "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;".PHP_EOL.
            "/*!40101 SET NAMES ".$this->dumpSettings['default-character-set']." */;".PHP_EOL;

        if (false === $this->dumpSettings['skip-tz-utc']) {
            $ret .= "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;".PHP_EOL.
                "/*!40103 SET TIME_ZONE='+00:00' */;".PHP_EOL;
        }

        if ($this->dumpSettings['no-autocommit']) {
                $ret .= "/*!40101 SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT */;".PHP_EOL;
        }

        $ret .= "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;".PHP_EOL.
            "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;".PHP_EOL.
            "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;".PHP_EOL.
            "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;".PHP_EOL.PHP_EOL;

        return $ret;
    }

    public function restore_parameters()
    {
        $ret = "";

        if (false === $this->dumpSettings['skip-tz-utc']) {
            $ret .= "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;".PHP_EOL;
        }

        if ($this->dumpSettings['no-autocommit']) {
                $ret .= "/*!40101 SET AUTOCOMMIT=@OLD_AUTOCOMMIT */;".PHP_EOL;
        }

        $ret .= "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;".PHP_EOL.
            "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;".PHP_EOL.
            "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;".PHP_EOL.
            "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;".PHP_EOL.
            "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;".PHP_EOL.
            "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;".PHP_EOL.
            "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;".PHP_EOL.PHP_EOL;

        return $ret;
    }

    /**
     * Check number of parameters passed to function, useful when inheriting.
     * Raise exception if unexpected.
     *
     * @param integer $num_args
     * @param integer $expected_num_args
     * @param string $method_name
     */
    private function check_parameters($num_args, $expected_num_args, $method_name)
    {
        if ($num_args != $expected_num_args) {
            throw new Exception("Unexpected parameter passed to $method_name");
        }
        return;
    }
}

/**
 * Generates a progress bar as text based on the percentage.
 *
 * The function calculates the number of "#" characters based on the given percentage value
 * and returns a string representing the progress bar.
 * The length of the progress bar can be optionally adjusted.
 *
 * @param float $percent The progress as a percentage (between 0 and 100).
 * @param int $length The length of the progress bar (default is 100).
 * @return string The generated progress bar as a string.
 */
function progressBar(float $percent, int $length = 100): string {
    $percent = max(0, min(100, $percent)); // Ensure value is between 0-100
    $filled = (int) round($percent / 100 * $length);

    return '[' . str_repeat('#', $filled) . str_repeat(' ', $length - $filled) . ']';
}


/**
 * Counts the number of lines in a file using SplFileObject.
 * This method is memory-efficient and works well even for large files.
 *
 * @param string $filename The path to the file.
 *
 * @return int The number of lines in the file.
 *
 * @throws RuntimeException If the file cannot be opened or read.
 */
function count_lines_of_file(string $filename): int {
    try {
        $file = new SplFileObject($filename, 'r');
        $file->seek(PHP_INT_MAX); // Seek to the last line
        return $file->key();
    } catch (RuntimeException $e) {
        throw new RuntimeException("Unable to read the file: $filename", 0, $e);
    }
}


/**
 * Generates a PHP script, that is able to create self-extractinging wordpress-archive.
 *
 * This function creates a PHP file that itself can be used to create self-extract
 * wordprss archives. It first adds a shebang, then copies the content of the current
 * file (the file calling this function) to the output of file, and appends the
 * '__HALT_'.'COMPILER();' compiler-derictive at the end to stop the execution of the
 * file once it is extracted.
 *
 * @return void Does not return any values. The output file is saved to disk.
 */
function genwpack() {
    global $version;

    $input = __FILE__;
    $output = 'wpack.php';

    $shebang = "#!/usr/bin/env php\n";
    $halt = "\n__HALT_"."COMPILER();\n";

    // Open input and output files
    $in = fopen($input, 'rb') or die("Cannot open $input.");
    $out = fopen($output, 'wb') or die("Cannot write to $output.");

    // Write the shebang
    fwrite($out, $shebang);

    // Copy the content in chunks
    $bufferSize = 8192; // 8 KB
    while (!feof($in)) {
        fwrite($out, fread($in, $bufferSize));
    }

    // Write HALT_COMPILER at the end
    fwrite($out, $halt);

    // Close files
    fclose($in);
    fclose($out);
    echo "Created " . dq($output) . " " . rb('Version ' . $version) . ". "
       . "It has " . count_lines_of_file($output). " lines. " . "\n";

    // Make this generated php script executable for the owner of that file.
    chmod($output, 0755);
    echo "Made " . dq($output) . " executable.\n";
}


/**
 * Determines whether the given file is being directly executed,
 * rather than being included by another script.
 *
 * This function works reliably in both CLI and web server environments.
 * In CLI mode, it compares the given file with $argv[0].
 * In web mode, it compares the file with $_SERVER['SCRIPT_FILENAME'].
 *
 * @param string $file The absolute path to the file to check.
 *                     Defaults to the file where this function is called (__FILE__).
 *
 * @return bool True if the file is executed directly, false if it was included.
 */
function is_directly_executed(string $file = __FILE__): bool {
    if (PHP_SAPI === 'cli') {
        return realpath($file) === realpath($GLOBALS['argv'][0] ?? '');
    } else {
        return realpath($file) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    }
}



/**
 * Returns a comma-separated list of all loaded PHP extensions.
 *
 * This function retrieves all currently loaded PHP extensions using
 * `get_loaded_extensions()`, sorts them alphabetically, and returns
 * them as a single comma-separated string.
 *
 * @return string A comma-separated list of loaded PHP extension names.
 */
function get_loaded_php_modules(): string {
    $modules = get_loaded_extensions();
    sort($modules); // optional: alphabetisch sortieren
    return implode(', ', $modules);
}

/**
 * Executes a function based on whether the current script is being directly executed or included.
 *
 * This function checks if the current script is being directly executed via the command line interface (CLI)
 * or included by another script (e.g., a WordPress plugin). If the script is directly executed, it calls the `genwpack()` function.
 * If the script is included, no action is taken.
 *
 * @return void
 *
 * @throws Exception If there is an error during the execution of `genwpack()`.
 * @see is_directly_executed() Checks if the script is being executed directly or included.
 * @see genwpack() Generates a .wpack archive for the project.
 */
if (is_directly_executed() && $my_basename === 'helper.inc.php') {
    genwpack();
} elseif ( ! is_directly_executed() && $my_basename === 'helper.inc.php' ) {
    // this __FILE__ is included by the plugin
} elseif (is_directly_executed()) { // wpack.php (or in wpsfx..php) or wunpack.php
    echo "my_basename: $my_basename" . "\n\n";
    // stream_filter_register("aes256", AES256StreamFilter::class);
    __HALT_SFX();
}