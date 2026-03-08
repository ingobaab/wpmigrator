<?php

namespace MigWP\Migrator\Api;

use MigWP\Migrator\Api;
use MigWP\Migrator\Services\Snapshots\DatabaseSnapshotService;
use MigWP\Migrator\Services\Snapshots\WorkerTrigger;
use WP_REST_Request;
use WP_REST_Server;

class Database {
	/**
	 * @var DatabaseSnapshotService
	 */
	private $snapshot_service;

	/**
	 * @var WorkerTrigger
	 */
	private $worker_trigger;

	public function __construct( DatabaseSnapshotService $snapshot_service = null, WorkerTrigger $worker_trigger = null ) {
		$this->snapshot_service = $snapshot_service ?: new DatabaseSnapshotService();
		$this->worker_trigger   = $worker_trigger ?: new WorkerTrigger();
	}

	/**
	 * Register database snapshot routes.
	 *
	 * @param string $namespace API namespace.
	 *
	 * @return void
	 */
	public function register_routes( $namespace ) {
		register_rest_route(
			$namespace,
			'/snapshot/database',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_database_snapshot' ],
				'permission_callback' => [ Api::class, 'check_permission' ],
			]
		);

		register_rest_route(
			$namespace,
			'/snapshot/database',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_database_snapshot' ],
				'permission_callback' => [ Api::class, 'check_permission' ],
			]
		);

		register_rest_route(
			$namespace,
			'/internal/snapshot/database/run',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'run_database_snapshot_worker' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Create the latest database snapshot.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_database_snapshot( WP_REST_Request $request ) {
		$state = $this->snapshot_service->queue_snapshot();

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$this->worker_trigger->dispatch(
			Api::NAMESPACE . '/internal/snapshot/database/run',
			[
				'token' => $state['worker_token'],
			]
		);

		return rest_ensure_response( $this->format_state( $state ) );
	}

	/**
	 * Return the latest database snapshot status.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_database_snapshot( WP_REST_Request $request ) {
		return rest_ensure_response( $this->format_state( $this->snapshot_service->get_latest_state() ) );
	}

	/**
	 * Internal worker endpoint for database snapshot execution.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_database_snapshot_worker( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$state = $this->snapshot_service->run_queued_snapshot( $token );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * @param array $state Internal state.
	 *
	 * @return array
	 */
	private function format_state( array $state ) {
		return [
			'status'               => isset( $state['status'] ) ? $state['status'] : 'idle',
			'snapshot_id'          => isset( $state['snapshot_id'] ) ? $state['snapshot_id'] : null,
			'created_at'           => isset( $state['created_at'] ) ? $state['created_at'] : null,
			'started_at'           => isset( $state['started_at'] ) ? $state['started_at'] : null,
			'updated_at'           => isset( $state['updated_at'] ) ? $state['updated_at'] : null,
			'finished_at'          => isset( $state['finished_at'] ) ? $state['finished_at'] : null,
			'progress_percent'     => isset( $state['progress_percent'] ) ? (int) $state['progress_percent'] : 0,
			'processed_bytes'      => isset( $state['processed_bytes'] ) ? (int) $state['processed_bytes'] : 0,
			'total_bytes_estimate' => isset( $state['total_bytes_estimate'] ) ? (int) $state['total_bytes_estimate'] : 0,
			'written_bytes'        => isset( $state['written_bytes'] ) ? (int) $state['written_bytes'] : 0,
			'current_phase'        => isset( $state['current_phase'] ) ? $state['current_phase'] : null,
			'current_item'         => isset( $state['current_item'] ) ? $state['current_item'] : null,
			'items_done'           => isset( $state['items_done'] ) ? (int) $state['items_done'] : 0,
			'items_total'          => isset( $state['items_total'] ) ? (int) $state['items_total'] : 0,
			'result_size'          => isset( $state['result_size'] ) ? (int) $state['result_size'] : 0,
			'error'                => isset( $state['error'] ) ? $state['error'] : null,
		];
	}
}
