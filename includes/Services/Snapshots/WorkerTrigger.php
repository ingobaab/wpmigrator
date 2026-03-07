<?php

namespace FlyWP\Migrator\Services\Snapshots;

class WorkerTrigger {
	/**
	 * Fire a non-blocking loopback POST request.
	 *
	 * @param string $route Internal REST route.
	 * @param array  $query Query parameters.
	 *
	 * @return void
	 */
	public function dispatch( $route, array $query = [] ) {
		$scheme    = is_ssl() ? 'https' : 'http';
		$base_path = (string) parse_url( home_url( '/index.php' ), PHP_URL_PATH );
		$url       = $scheme . '://127.0.0.1';

		$url .= $base_path;
		$url = add_query_arg(
			array_merge(
				[
					'rest_route' => '/' . ltrim( $route, '/' ),
				],
				$query
			),
			$url
		);

		wp_remote_post(
			$url,
			[
				'timeout'  => 0.01,
				'blocking' => false,
				'headers'  => [
					'Connection' => 'close',
					'Host'       => (string) parse_url( home_url(), PHP_URL_HOST ),
				],
			]
		);
	}
}
