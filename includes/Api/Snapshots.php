<?php

namespace FlyWP\Migrator\Api;

use FlyWP\Migrator\Api;
use FlyWP\Migrator\Services\Snapshots\ConfigStore;
use FlyWP\Migrator\Services\Snapshots\FilesystemSnapshotService;
use FlyWP\Migrator\Services\Snapshots\StateStore;
use WP_REST_Request;
use WP_REST_Server;

class Snapshots {
	/**
	 * @var ConfigStore
	 */
	private $config_store;

	/**
	 * @var StateStore
	 */
	private $state_store;

	/**
	 * @var FilesystemSnapshotService
	 */
	private $filesystem_snapshot_service;

	public function __construct( ConfigStore $config_store = null, StateStore $state_store = null ) {
		$this->config_store                = $config_store ?: new ConfigStore();
		$this->state_store                 = $state_store ?: new StateStore();
		$this->filesystem_snapshot_service = new FilesystemSnapshotService( $this->state_store );
	}

	/**
	 * Register snapshot config and filesystem status routes.
	 *
	 * @param string $namespace API namespace.
	 *
	 * @return void
	 */
	public function register_routes( $namespace ) {
		register_rest_route(
			$namespace,
			'/snapshot/config',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_config' ],
				'permission_callback' => [ Api::class, 'check_permission' ],
			]
		);

		register_rest_route(
			$namespace,
			'/snapshot/config',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'update_config' ],
				'permission_callback' => [ Api::class, 'check_permission' ],
			]
		);

		register_rest_route(
			$namespace,
			'/snapshot/filesystem',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_filesystem_snapshot' ],
				'permission_callback' => [ Api::class, 'check_permission' ],
			]
		);
	}

	/**
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_config( WP_REST_Request $request ) {
		return rest_ensure_response( $this->config_store->get() );
	}

	/**
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_config( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$config = $this->config_store->update( $params );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return rest_ensure_response( $config );
	}

	/**
	 * Return the latest filesystem snapshot status placeholder.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_filesystem_snapshot( WP_REST_Request $request ) {
		return rest_ensure_response( $this->filesystem_snapshot_service->format_state( $this->filesystem_snapshot_service->get_latest_state(), true ) );
	}
}
