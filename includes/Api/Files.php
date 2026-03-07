<?php

namespace FlyWP\Migrator\Api;

use Exception;
use FlyWP\Migrator\Api;
use FlyWP\Migrator\Services\Snapshots\FilesystemSnapshotService;
use FlyWP\Migrator\Services\Snapshots\WorkerTrigger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use ZipArchive;

/**
 * Files API Handler Class
 */
class Files {

    /**
     * Fixed chunk size for resumable file streaming (10MB)
     */
    const STREAM_CHUNK_SIZE = 10485760;

    /**
     * Maximum size of each zip chunk (100MB)
     */
    const MAX_CHUNK_SIZE = 104857600; // 100MB

    /**
     * Directory for temporary files
     */
    private $temp_dir;

    /**
     * Manifest file path for uploads
     */
    private $manifest_file;

    /**
     * @var FilesystemSnapshotService
     */
    private $filesystem_snapshot_service;

    /**
     * @var WorkerTrigger
     */
    private $worker_trigger;

    /**
     * List of directory patterns to ignore during scanning
     */
    private $ignored_directories = [
        '.git',
        'node_modules',
    ];

    /**
     * Constructor
     */
    public function __construct() {
        $this->temp_dir      = $this->get_temp_dir();
        $this->manifest_file = $this->temp_dir . '/manifest.json';
        $this->filesystem_snapshot_service = new FilesystemSnapshotService();
        $this->worker_trigger = new WorkerTrigger();

        $this->init_filesystem();
    }

    /**
     * Register API routes
     */
    public function register_routes( $namespace ) {
        // Routes for uploads (chunked with manifest)
        register_rest_route( $namespace, '/uploads/manifest', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_uploads_manifest'],
            'permission_callback' => [Api::class, 'check_permission'],
        ] );

        register_rest_route( $namespace, '/uploads/download', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'download_uploads_chunk'],
            'permission_callback' => [Api::class, 'check_permission'],
        ] );

        // Routes for plugins (single download)
        register_rest_route( $namespace, '/plugins/download', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'download_plugins'],
            'permission_callback' => [Api::class, 'check_permission'],
        ] );

        // Routes for mu-plugins (single download)
        register_rest_route( $namespace, '/mu-plugins/download', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'download_mu_plugins'],
            'permission_callback' => [Api::class, 'check_permission'],
        ] );

        // Routes for themes (single download)
        register_rest_route( $namespace, '/themes/download', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'download_themes'],
            'permission_callback' => [Api::class, 'check_permission'],
        ] );

        // Route for streaming a single file in fixed-size chunks.
        register_rest_route( $namespace, '/files/meta', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_file_metadata' ],
            'permission_callback' => [ Api::class, 'check_permission' ],
        ] );

        register_rest_route( $namespace, '/files/stream', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'stream_file' ],
            'permission_callback' => [ Api::class, 'check_permission' ],
        ] );

        register_rest_route( $namespace, '/snapshot/filesystem', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_filesystem_snapshot' ],
            'permission_callback' => [ Api::class, 'check_permission' ],
        ] );

        register_rest_route( $namespace, '/internal/snapshot/filesystem/run', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'run_filesystem_snapshot_worker' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * @param WP_REST_Request $request Request object.
     *
     * @return \WP_REST_Response|WP_Error
     */
    public function create_filesystem_snapshot( WP_REST_Request $request ) {
        $state = $this->filesystem_snapshot_service->queue_snapshot();

        if ( is_wp_error( $state ) ) {
            return $state;
        }

        $this->worker_trigger->dispatch(
            Api::NAMESPACE . '/internal/snapshot/filesystem/run',
            [
                'token' => $state['worker_token'],
            ]
        );

        return rest_ensure_response( $this->filesystem_snapshot_service->format_state( $state, false ) );
    }

    /**
     * Internal worker endpoint for filesystem snapshot execution.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function run_filesystem_snapshot_worker( WP_REST_Request $request ) {
        $token = (string) $request->get_param( 'token' );
        $state = $this->filesystem_snapshot_service->run_queued_snapshot( $token );

        if ( is_wp_error( $state ) ) {
            return $state;
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    /**
     * Return metadata for a streamable file.
     *
     * Query params:
     * - path: relative path within an allowed root (uploads, wp-content, plugins, mu-plugins, themes)
     *
     * @param WP_REST_Request $request
     *
     * @return \WP_REST_Response|WP_Error
     */
    public function get_file_metadata( WP_REST_Request $request ) {
        $relative = (string) $request->get_param( 'path' );

        if ( '' === $relative || false !== strpos( $relative, "\0" ) ) {
            return new WP_Error( 'invalid_path', __( 'File path is required', 'flywp-migrator' ), [ 'status' => 400 ] );
        }

        $relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
        $resolved = $this->resolve_allowed_path( $relative );

        if ( is_wp_error( $resolved ) ) {
            return $resolved;
        }

        if ( ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
            return new WP_Error( 'file_not_found', __( 'File not found', 'flywp-migrator' ), [ 'status' => 404 ] );
        }

        $file_size    = filesize( $resolved );
        $chunk_size   = self::STREAM_CHUNK_SIZE;
        $total_chunks = (int) ceil( $file_size / $chunk_size );
        $modified_at  = @filemtime( $resolved );

        if ( 0 === $file_size ) {
            $total_chunks = 1;
        }

        if ( ! $modified_at ) {
            $modified_at = time();
        }

        return rest_ensure_response( [
            'success'       => true,
            'path'          => $relative,
            'filename'      => basename( $resolved ),
            'size'          => $file_size,
            'chunk_size'    => $chunk_size,
            'total_chunks'  => $total_chunks,
            'etag'          => $this->get_file_etag( $resolved, $file_size, $modified_at ),
            'modified_gmt'  => gmdate( 'c', $modified_at ),
        ] );
    }

    /**
     * Stream a single file chunk.
     *
     * Query params:
     * - path: relative path within an allowed root (uploads, wp-content, plugins, mu-plugins, themes)
     * - chunk: zero-based chunk index to fetch
     *
     * Response headers include file metadata and a SHA-256 checksum for the returned chunk.
     * Clients can resume by requesting the next missing chunk index.
     *
     * @param WP_REST_Request $request
     *
     * @return mixed
     */
    public function stream_file( WP_REST_Request $request ) {
        $relative = (string) $request->get_param( 'path' );
        $chunk    = $request->get_param( 'chunk' );

        if ( '' === $relative || false !== strpos( $relative, "\0" ) ) {
            return new WP_Error( 'invalid_path', __( 'File path is required', 'flywp-migrator' ), [ 'status' => 400 ] );
        }

        if ( null === $chunk || '' === $chunk || ! is_numeric( $chunk ) || (int) $chunk < 0 ) {
            return new WP_Error( 'invalid_chunk', __( 'A valid chunk index is required', 'flywp-migrator' ), [ 'status' => 400 ] );
        }

        // Normalize input to a relative path.
        $relative = ltrim( str_replace( '\\', '/', $relative ), '/' );

        $resolved = $this->resolve_allowed_path( $relative );

        if ( is_wp_error( $resolved ) ) {
            return $resolved;
        }

        $file_path = $resolved;

        if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
            return new WP_Error( 'file_not_found', __( 'File not found', 'flywp-migrator' ), [ 'status' => 404 ] );
        }

        $file_size    = filesize( $file_path );
        $chunk_index  = (int) $chunk;
        $chunk_size   = self::STREAM_CHUNK_SIZE;
        $total_chunks = (int) ceil( $file_size / $chunk_size );
        $modified_at  = @filemtime( $file_path );

        if ( 0 === $file_size ) {
            $total_chunks = 1;
        }

        if ( ! $modified_at ) {
            $modified_at = time();
        }

        if ( $chunk_index >= $total_chunks ) {
            return new WP_Error(
                'chunk_out_of_range',
                __( 'Requested chunk is outside the file bounds', 'flywp-migrator' ),
                [ 'status' => 416 ]
            );
        }

        $offset = $chunk_index * $chunk_size;
        $length = min( $chunk_size, max( 0, $file_size - $offset ) );

        $fh = @fopen( $file_path, 'rb' );
        if ( ! $fh ) {
            return new WP_Error( 'file_open_error', __( 'Could not open the file', 'flywp-migrator' ), [ 'status' => 500 ] );
        }

        if ( $offset > 0 ) {
            @fseek( $fh, $offset );
        }

        $payload = ( $length > 0 ) ? fread( $fh, $length ) : '';
        fclose( $fh );

        if ( false === $payload ) {
            return new WP_Error( 'file_read_error', __( 'Could not read the file chunk', 'flywp-migrator' ), [ 'status' => 500 ] );
        }

        $payload_length = strlen( $payload );
        $chunk_checksum = hash( 'sha256', $payload );
        $offset_end     = $offset + max( 0, $payload_length - 1 );

        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
        header( 'Content-Length: ' . $payload_length );
        header( 'Accept-Ranges: none' );
        header( 'X-FlyWP-File-Path: ' . rawurlencode( $relative ) );
        header( 'X-FlyWP-File-Size: ' . $file_size );
        header( 'X-FlyWP-File-Etag: ' . $this->get_file_etag( $file_path, $file_size, $modified_at ) );
        header( 'X-FlyWP-Chunk-Size: ' . $chunk_size );
        header( 'X-FlyWP-Chunk-Index: ' . $chunk_index );
        header( 'X-FlyWP-Chunk-Checksum: ' . $chunk_checksum );
        header( 'X-FlyWP-Chunk-Bytes: ' . $offset . '-' . $offset_end );
        header( 'X-FlyWP-Total-Chunks: ' . $total_chunks );
        header( 'X-FlyWP-Is-Last-Chunk: ' . ( $chunk_index + 1 >= $total_chunks ? '1' : '0' ) );
        status_header( 200 );

        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }

        if ( ob_get_level() ) {
            ob_end_clean();
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $payload;

        if ( function_exists( 'flush' ) ) {
            flush();
        }

        exit;
    }

    /**
     * Build a stable file version token for resumable downloads.
     *
     * @param string $file_path   Absolute file path
     * @param int    $file_size   File size in bytes
     * @param int    $modified_at Unix timestamp
     *
     * @return string
     */
    private function get_file_etag( $file_path, $file_size, $modified_at ) {
        return sha1( $file_path . '|' . $file_size . '|' . $modified_at );
    }

    /**
     * Resolve a relative path to a real path within allowed roots.
     *
     * @param string $relative Relative path (forward-slash delimited)
     *
     * @return string|WP_Error
     */
    private function resolve_allowed_path( $relative ) {
        $roots = $this->get_allowed_roots();

        foreach ( $roots as $root ) {
            if ( empty( $root ) ) {
                continue;
            }

            $root_real = realpath( $root );
            if ( false === $root_real ) {
                continue;
            }

            $candidate = realpath( $root_real . DIRECTORY_SEPARATOR . $relative );
            if ( false === $candidate ) {
                continue;
            }

            $prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

            // Ensure the candidate is within the allowed root.
            if ( 0 !== strpos( $candidate, $prefix ) ) {
                continue;
            }

            return $candidate;
        }

        return new WP_Error( 'forbidden_path', __( 'Path is outside allowed roots', 'flywp-migrator' ), [ 'status' => 403 ] );
    }

    /**
     * Get allowed root directories for streaming.
     *
     * @return string[]
     */
    private function get_allowed_roots() {
        $upload_dir = wp_upload_dir();

        $roots = [
            isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '',
            defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
            defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
            defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
            function_exists( 'get_theme_root' ) ? get_theme_root() : '',
        ];

        // De-duplicate and remove empties.
        $roots = array_values( array_unique( array_filter( $roots ) ) );

        return $roots;
    }

    /**
     * Generate the manifest file for uploads directory.
     */
    public function get_uploads_manifest() {
        if ( ! file_exists( $this->manifest_file ) ) {
            $upload_dir   = wp_upload_dir();
            $base_dir     = $upload_dir['basedir'];

            // Scan the uploads directory and split files into chunks
            $files        = $this->scan_directory( $base_dir );
            $chunks       = $this->split_into_chunks( $files, self::MAX_CHUNK_SIZE );
            $total_chunks = count( $chunks );

            $manifest_data = [
                'total_chunks' => $total_chunks,
                'chunks'       => $chunks,
            ];

            file_put_contents( $this->manifest_file, json_encode( $manifest_data, JSON_PRETTY_PRINT ) );
        } else {
            $manifest_data = json_decode( file_get_contents( $this->manifest_file ), true );
            $total_chunks  = $manifest_data['total_chunks'];
        }

        return rest_ensure_response( [
            'message'      => __( 'Manifest generated successfully', 'flywp-migrator' ),
            'total_chunks' => $total_chunks,
        ] );
    }

    /**
     * Handle downloading a specific uploads chunk.
     */
    public function download_uploads_chunk( WP_REST_Request $request ) {
        $chunk_index = (int) $request->get_param( 'chunk' );

        if ( !file_exists( $this->manifest_file ) ) {
            // If manifest doesn't exist, try to create it
            $this->get_uploads_manifest();

            // Check again if it was created
            if ( !file_exists( $this->manifest_file ) ) {
                return $this->create_empty_zip( 'uploads_chunk_' . $chunk_index );
            }
        }

        $manifest = json_decode( file_get_contents( $this->manifest_file ), true );

        if ( !isset( $manifest['chunks'][$chunk_index] ) ) {
            return new WP_Error(
                'invalid_chunk',
                sprintf(
                    /* translators: %d: chunk number, %d: total chunks */
                    __( 'Invalid chunk number: %1$d. Total chunks: %2$d', 'flywp-migrator' ),
                    $chunk_index,
                    $manifest['total_chunks']
                )
            );
        }

        // If this chunk has no files (extremely rare but possible)
        if ( empty( $manifest['chunks'][$chunk_index] ) ) {
            return $this->create_empty_zip( 'uploads_chunk_' . $chunk_index );
        }

        return $this->zip_and_send_files(
            $manifest['chunks'][$chunk_index],
            'uploads_chunk_' . $chunk_index,
            wp_upload_dir()['basedir']
        );
    }

    /**
     * Download all plugins as a single zip file.
     */
    public function download_plugins() {
        return $this->download_directory( WP_PLUGIN_DIR, 'plugins' );
    }

    /**
     * Download all mu-plugins as a single zip file.
     */
    public function download_mu_plugins() {
        $mu_plugins_dir = WPMU_PLUGIN_DIR;

        // If mu-plugins directory doesn't exist, create an empty zip file
        if ( !is_dir( $mu_plugins_dir ) || !is_readable( $mu_plugins_dir ) ) {
            return $this->create_empty_zip( 'mu-plugins-empty' );
        }

        return $this->download_directory( $mu_plugins_dir, 'mu-plugins' );
    }

    /**
     * Create and send an empty zip file.
     *
     * @param string $filename Base name for the zip file without extension
     *
     * @return mixed
     */
    private function create_empty_zip( $filename ) {
        global $wp_filesystem;

        $zip          = new ZipArchive();
        $zip_filename = $this->temp_dir . "/{$filename}.zip";

        if ( $zip->open( $zip_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            return new WP_Error(
                'zip_error',
                __( 'Could not create empty zip file', 'flywp-migrator' )
            );
        }

        // Add a README file to indicate this is an empty directory
        $readme_content = 'This directory was empty on the source site.';
        $zip->addFromString( 'README.txt', $readme_content );

        // Create a comment with metadata
        $zip->setArchiveComment( json_encode( [
            'generator'    => 'FlyWP Migration Plugin',
            'date'         => current_time( 'mysql' ),
            'site_url'     => get_site_url(),
            'file_count'   => 1,
            'content_type' => $filename,
            'empty'        => true,
        ] ) );

        $zip->close();

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . basename( $zip_filename ) . '"' );
        header( 'Content-Length: ' . filesize( $zip_filename ) );

        if ( ob_get_level() ) {
            ob_end_clean();
        }

        $file_content = $wp_filesystem->get_contents( $zip_filename );

        if ( $file_content === false ) {
            return new WP_Error(
                'file_read_error',
                __( 'Could not read the zip file', 'flywp-migrator' )
            );
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $file_content;

        wp_delete_file( $zip_filename );
        exit;
    }

    /**
     * Download all themes as a single zip file.
     */
    public function download_themes() {
        return $this->download_directory( get_theme_root(), 'themes' );
    }

    /**
     * Generic method to download an entire directory as a zip file
     *
     * @param string $directory The directory path to download
     * @param string $name      The name to use for the zip file
     *
     * @return mixed
     */
    private function download_directory( $directory, $name ) {
        $files = $this->scan_directory( $directory );

        $file_paths = array_map( function ( $file ) {
            return $file['path'];
        }, $files );

        return $this->zip_and_send_files(
            $file_paths,
            $name,
            $directory
        );
    }

    /**
     * Check if a file should be skipped based on its path and context
     *
     * @param string $relative_path    The relative path of the file
     * @param string $base_dir         The base directory being scanned
     * @param array  $exclude_patterns Additional patterns to exclude
     *
     * @return bool Whether the file should be skipped
     */
    private function should_skip_file( $relative_path, $base_dir, $exclude_patterns = [] ) {
        // Check if we're in plugins or themes directory
        $is_plugins_or_themes = in_array( basename( $base_dir ), ['plugins', 'themes'] );

        // For plugins and themes, check top-level directories only
        if ( $is_plugins_or_themes ) {
            $path_parts    = explode( '/', $relative_path );
            $top_level_dir = $path_parts[0];

            // Skip if the top-level directory is in ignored_directories
            if ( in_array( $top_level_dir, $this->ignored_directories ) ) {
                return true;
            }
        }

        // Check against additional exclude patterns
        foreach ( $exclude_patterns as $pattern ) {
            if ( strpos( $relative_path, $pattern ) !== false ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scan a directory recursively using RecursiveIteratorIterator.
     *
     * @param string $dir              Directory to scan
     * @param string $base_dir         Base directory for creating relative paths
     * @param array  $exclude_patterns Optional patterns to exclude (simple string match)
     *
     * @return array Array of files with path and size
     */
    private function scan_directory( $dir, $base_dir = '', $exclude_patterns = [] ) {
        if ( empty( $base_dir ) ) {
            $base_dir = $dir;
        }

        $files = [];

        // Return empty array if directory doesn't exist or isn't readable
        if ( !is_dir( $dir ) || !is_readable( $dir ) ) {
            return $files;
        }

        try {
            $directory = new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS
            );

            $iterator = new RecursiveIteratorIterator(
                $directory,
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                // Skip if it's not a file or not readable
                if ( !$file->isFile() || !$file->isReadable() ) {
                    continue;
                }

                $path          = $file->getPathname();
                $relative_path = str_replace( $base_dir . '/', '', $path );

                // Check if file should be skipped
                if ( $this->should_skip_file( $relative_path, $base_dir, $exclude_patterns ) ) {
                    continue;
                }

                $files[] = [
                    'path' => $relative_path,
                    'size' => $file->getSize(),
                ];
            }
        } catch ( Exception $e ) {
            // Log error but continue with empty file list
            error_log( 'FlyWP Migration: Error scanning directory: ' . $e->getMessage() );
        }

        return $files;
    }

    /**
     * Split files into chunks based on a maximum chunk size.
     *
     * @param array $files          Array of files with path and size
     * @param int   $max_chunk_size Maximum size of each chunk
     *
     * @return array Array of chunks, each containing file paths
     */
    private function split_into_chunks( $files, $max_chunk_size ) {
        $chunks        = [];
        $current_chunk = [];
        $current_size  = 0;

        // Sort files by size (larger files first) to optimize chunk distribution
        usort( $files, function ( $a, $b ) {
            return $b['size'] - $a['size'];
        } );

        foreach ( $files as $file ) {
            // If this single file is larger than the max chunk size, it gets its own chunk
            if ( $file['size'] > $max_chunk_size ) {
                // Add the current chunk if it's not empty
                if ( !empty( $current_chunk ) ) {
                    $chunks[]      = $current_chunk;
                    $current_chunk = [];
                    $current_size  = 0;
                }

                // Add this large file as its own chunk
                $chunks[] = [$file['path']];
                continue;
            }

            // If adding this file would exceed the chunk size, start a new chunk
            if ( $current_size + $file['size'] > $max_chunk_size && !empty( $current_chunk ) ) {
                $chunks[]      = $current_chunk;
                $current_chunk = [];
                $current_size  = 0;
            }

            // Add the file to the current chunk
            $current_chunk[] = $file['path'];
            $current_size += $file['size'];
        }

        // Add the last chunk if it's not empty
        if ( !empty( $current_chunk ) ) {
            $chunks[] = $current_chunk;
        }

        return $chunks;
    }

    /**
     * Zip files and send them for download.
     *
     * @param array  $files    Array of file paths relative to the base_dir
     * @param string $filename Base name for the zip file
     * @param string $base_dir Base directory path
     *
     * @return mixed
     */
    private function zip_and_send_files( $files, $filename, $base_dir ) {
        global $wp_filesystem;

        // Ensure we have files to zip
        if ( empty( $files ) ) {
            return new WP_Error(
                'empty_file_list',
                __( 'No files found to zip', 'flywp-migrator' )
            );
        }

        // Initialize zip
        $zip          = new ZipArchive();
        $zip_filename = $this->temp_dir . "/{$filename}.zip";

        if ( $zip->open( $zip_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            return new WP_Error(
                'zip_error',
                __( 'Could not create zip file', 'flywp-migrator' )
            );
        }

        // Add files to the zip
        $added_files = 0;

        foreach ( $files as $file ) {
            $file_path = $base_dir . '/' . $file;

            if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
                try {
                    $zip->addFile( $file_path, $file );
                    $added_files++;
                } catch ( Exception $e ) {
                    // Skip problematic files but continue with the rest
                    error_log( 'FlyWP Migration: Error adding file to zip: ' . $e->getMessage() );
                }
            }
        }

        // Ensure we actually added files
        if ( $added_files === 0 ) {
            $zip->close();

            wp_delete_file( $zip_filename );

            return new WP_Error(
                'no_files_added',
                __( 'No files could be added to the zip archive', 'flywp-migrator' )
            );
        }

        // Create a comment with metadata
        $zip->setArchiveComment( json_encode( [
            'generator'    => 'FlyWP Migration Plugin',
            'date'         => current_time( 'mysql' ),
            'site_url'     => get_site_url(),
            'file_count'   => $added_files,
            'content_type' => $filename,
        ] ) );

        $zip->close();

        // Send appropriate headers and file
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . basename( $zip_filename ) . '"' );
        header( 'Content-Length: ' . filesize( $zip_filename ) );

        // Disable output buffering to handle large files
        if ( ob_get_level() ) {
            ob_end_clean();
        }

        $file_content = $wp_filesystem->get_contents( $zip_filename );

        if ( $file_content === false ) {
            return new WP_Error(
                'file_read_error',
                __( 'Could not read the zip file', 'flywp-migrator' )
            );
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $file_content;

        wp_delete_file( $zip_filename );
        exit;
    }

    /**
     * Get the temporary directory for storing files.
     *
     * @return string
     */
    private function get_temp_dir() {
        $dirname = get_option( 'flywp_migrate_temp_dir' );

        if ( empty( $dirname ) ) {
            $dirname = 'flywp-temp-' . substr( md5( time() . wp_rand() ), 0, 6 );
            update_option( 'flywp_migrate_temp_dir', $dirname );
        }

        $content_dir = WP_CONTENT_DIR;
        $temp_dir    = $content_dir . '/' . $dirname;

        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
        }

        return $temp_dir;
    }

    /**
     * Initialize the WP Filesystem for use.
     *
     * This is necessary for reading and writing files.
     *
     * @return void
     */
    private function init_filesystem() {
        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
    }
}
