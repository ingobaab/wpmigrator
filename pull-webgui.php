<?php

require_once __DIR__ . '/tools/pull-lib.php';

const MIGWP_PULL_WEBGUI_STATE_DIR = '/tmp/migwp-puller-webgui';

if ( PHP_SAPI === 'cli' ) {
	migwp_pull_webgui_run_worker_cli( $argv );
	exit( 0 );
}

$action = isset( $_GET['action'] ) ? (string) $_GET['action'] : '';

if ( 'start' === $action ) {
	migwp_pull_webgui_handle_start();
	return;
}

if ( 'status' === $action ) {
	migwp_pull_webgui_handle_status();
	return;
}

migwp_pull_webgui_render_page();

function migwp_pull_webgui_run_worker_cli( array $argv ) {
	$job_id      = null;
	$config_path = migwp_puller_default_config_path();

	foreach ( $argv as $arg ) {
		if ( 0 === strpos( (string) $arg, '--worker=' ) ) {
			$job_id = substr( (string) $arg, 9 );
		}

		if ( 0 === strpos( (string) $arg, '--config=' ) ) {
			$config_path = substr( (string) $arg, 9 );
		}
	}

	if ( null === $job_id || '' === $job_id ) {
		fwrite( STDERR, "Usage: php pull-webgui.php --worker=<job-id> [--config=/path/to/pull-config.json]\n" );
		exit( 1 );
	}

	$config = migwp_puller_load_config( $config_path );
	$state  = migwp_pull_webgui_read_state( $job_id );

	if ( ! is_array( $state ) ) {
		throw new RuntimeException( "Unknown job: {$job_id}" );
	}

	$state['status']     = 'running';
	$state['started_at'] = gmdate( 'c' );
	$state['updated_at'] = $state['started_at'];
	migwp_pull_webgui_write_state( $job_id, $state );

	try {
		$result = migwp_puller_run(
			$config,
			static function ( $event, array $payload ) use ( $job_id ) {
				$state = migwp_pull_webgui_read_state( $job_id );
				if ( ! is_array( $state ) ) {
					return;
				}

				$state['updated_at'] = gmdate( 'c' );

				if ( 'job.started' === $event ) {
					$state['summary'] = $payload;
				}

				if ( 'snapshot.progress' === $event || 'snapshot.complete' === $event ) {
					$key                        = $payload['key'];
					$state['phase']             = $key;
					$state['phases'][ $key ]    = array_merge( $state['phases'][ $key ], $payload['status'] );
					$state['phases'][ $key ]['label'] = $payload['label'];
				}

				if ( 'transfer.started' === $event || 'transfer.progress' === $event || 'transfer.complete' === $event ) {
					$state['phase']              = 'transfer';
					$state['phases']['transfer'] = array_merge( $state['phases']['transfer'], $payload );
				}

				if ( 'job.complete' === $event ) {
					$state['status']      = 'complete';
					$state['phase']       = 'complete';
					$state['finished_at'] = gmdate( 'c' );
					$state['result']      = $payload;
				}

				migwp_pull_webgui_write_state( $job_id, $state );
			}
		);

		$state               = migwp_pull_webgui_read_state( $job_id );
		$state['status']     = 'complete';
		$state['phase']      = 'complete';
		$state['updated_at'] = gmdate( 'c' );
		$state['finished_at'] = $state['updated_at'];
		$state['result']     = $result;
		migwp_pull_webgui_write_state( $job_id, $state );
	} catch ( RuntimeException $e ) {
		$state                = migwp_pull_webgui_read_state( $job_id );
		$state['status']      = 'failed';
		$state['error']       = $e->getMessage();
		$state['updated_at']  = gmdate( 'c' );
		$state['finished_at'] = $state['updated_at'];

		if ( 'database' === ( $state['phase'] ?? '' ) || 'filesystem' === ( $state['phase'] ?? '' ) ) {
			$state['phases'][ $state['phase'] ]['error']  = $e->getMessage();
			$state['phases'][ $state['phase'] ]['status'] = 'failed';
		}

		if ( 'transfer' === ( $state['phase'] ?? '' ) ) {
			$state['phases']['transfer']['error']  = $e->getMessage();
			$state['phases']['transfer']['status'] = 'failed';
		}

		migwp_pull_webgui_write_state( $job_id, $state );
		exit( 1 );
	}
}

function migwp_pull_webgui_handle_start() {
	migwp_pull_webgui_send_json_headers();

	try {
		$config_path = isset( $_POST['config_path'] ) ? trim( (string) $_POST['config_path'] ) : migwp_puller_default_config_path();
		$config      = migwp_puller_load_config( $config_path );
		$job_id      = bin2hex( random_bytes( 8 ) );
		$created_at  = gmdate( 'c' );

		$state = [
			'job_id'      => $job_id,
			'status'      => 'queued',
			'phase'       => 'queued',
			'created_at'  => $created_at,
			'started_at'  => null,
			'updated_at'  => $created_at,
			'finished_at' => null,
			'config_path' => $config_path,
			'error'       => null,
			'summary'     => [
				'base_url'              => $config['base_url'],
				'rest_root'             => $config['rest_root'],
				'output_dir'            => $config['output_dir'],
				'poll_interval_seconds' => $config['poll_interval_seconds'],
			],
			'phases'      => [
				'database'   => migwp_pull_webgui_initial_snapshot_phase( 'Database Snapshot' ),
				'filesystem' => migwp_pull_webgui_initial_snapshot_phase( 'Filesystem Snapshot' ),
				'transfer'   => migwp_pull_webgui_initial_transfer_phase(),
			],
		];

		migwp_pull_webgui_write_state( $job_id, $state );
		migwp_pull_webgui_spawn_worker( $job_id, $config_path );

		echo json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	} catch ( Throwable $e ) {
		http_response_code( 500 );
		echo json_encode(
			[
				'error' => $e->getMessage(),
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}
}

function migwp_pull_webgui_handle_status() {
	migwp_pull_webgui_send_json_headers();

	$job_id = isset( $_GET['job_id'] ) ? trim( (string) $_GET['job_id'] ) : '';
	if ( '' === $job_id ) {
		http_response_code( 400 );
		echo json_encode( [ 'error' => 'Missing job_id.' ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return;
	}

	$state = migwp_pull_webgui_read_state( $job_id );
	if ( ! is_array( $state ) ) {
		http_response_code( 404 );
		echo json_encode( [ 'error' => 'Job not found.' ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return;
	}

	echo json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

function migwp_pull_webgui_render_page() {
	$default_config_path = htmlspecialchars( migwp_puller_default_config_path(), ENT_QUOTES, 'UTF-8' );
	$self                = htmlspecialchars( $_SERVER['PHP_SELF'] ?? 'pull-webgui.php', ENT_QUOTES, 'UTF-8' );

	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MigWP Pull Web GUI</title>
    <style>
        :root {
            --bg: #f4efe5;
            --panel: #fffdf8;
            --ink: #1e1e1a;
            --muted: #6b675d;
            --accent: #9a3412;
            --accent-soft: #f97316;
            --line: #ded6c8;
            --ok: #2f855a;
            --err: #c53030;
        }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            background: radial-gradient(circle at top left, #fff7ed, var(--bg) 55%);
            color: var(--ink);
        }
        .shell {
            max-width: 860px;
            margin: 48px auto;
            padding: 0 20px;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 18px 50px rgba(67, 56, 41, 0.08);
            padding: 28px;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 34px;
        }
        p {
            color: var(--muted);
            margin: 0 0 18px;
        }
        .controls {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            margin-bottom: 22px;
        }
        input, button {
            font: inherit;
        }
        input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #fff;
        }
        button {
            border: 0;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--accent), var(--accent-soft));
            color: #fff;
            padding: 12px 18px;
            cursor: pointer;
        }
        button:disabled {
            opacity: 0.6;
            cursor: default;
        }
        .meta {
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 18px;
        }
        .phase {
            border-top: 1px solid var(--line);
            padding-top: 16px;
            margin-top: 16px;
        }
        .phase:first-of-type {
            border-top: 0;
            padding-top: 0;
            margin-top: 0;
        }
        .phase-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: baseline;
            margin-bottom: 8px;
        }
        .bar {
            height: 16px;
            background: #efe7da;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        .fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--accent), var(--accent-soft));
            transition: width 0.25s ease;
        }
        .details {
            font-size: 14px;
            color: var(--muted);
            min-height: 20px;
        }
        .error {
            color: var(--err);
        }
        .ok {
            color: var(--ok);
        }
        pre {
            background: #201f1b;
            color: #f7f1e6;
            border-radius: 12px;
            padding: 14px;
            overflow: auto;
            font-size: 12px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="panel">
        <h1>MigWP Pull Web GUI</h1>
        <p>Server-side puller runner with three sequential progress bars and disk-backed job state.</p>

        <form id="start-form" class="controls">
            <input id="config-path" name="config_path" value="<?php echo $default_config_path; ?>" autocomplete="off">
            <button id="start-button" type="submit">Start Pull</button>
        </form>

        <div id="meta" class="meta">Idle.</div>

        <div class="phase">
            <div class="phase-head">
                <strong>Database Snapshot</strong>
                <span id="database-percent">0%</span>
            </div>
            <div class="bar"><div id="database-bar" class="fill"></div></div>
            <div id="database-details" class="details"></div>
        </div>

        <div class="phase">
            <div class="phase-head">
                <strong>Filesystem Snapshot</strong>
                <span id="filesystem-percent">0%</span>
            </div>
            <div class="bar"><div id="filesystem-bar" class="fill"></div></div>
            <div id="filesystem-details" class="details"></div>
        </div>

        <div class="phase">
            <div class="phase-head">
                <strong>Transfer</strong>
                <span id="transfer-percent">0%</span>
            </div>
            <div class="bar"><div id="transfer-bar" class="fill"></div></div>
            <div id="transfer-details" class="details"></div>
        </div>

        <pre id="state-view" hidden></pre>
    </div>
</div>
<script>
(function () {
    const endpoint = <?php echo json_encode($self, JSON_UNESCAPED_SLASHES); ?>;
    const form = document.getElementById('start-form');
    const startButton = document.getElementById('start-button');
    const configPath = document.getElementById('config-path');
    const meta = document.getElementById('meta');
    const stateView = document.getElementById('state-view');
    let jobId = null;
    let timer = null;

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        startButton.disabled = true;
        meta.textContent = 'Queueing background pull job...';
        clearTimeout(timer);

        const body = new URLSearchParams();
        body.set('config_path', configPath.value);

        const response = await fetch(endpoint + '?action=start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString()
        });

        const state = await response.json();
        if (!response.ok) {
            meta.textContent = state.error || 'Failed to start pull job.';
            meta.className = 'meta error';
            startButton.disabled = false;
            return;
        }

        jobId = state.job_id;
        render(state);
        poll();
    });

    async function poll() {
        if (!jobId) {
            return;
        }

        const response = await fetch(endpoint + '?action=status&job_id=' + encodeURIComponent(jobId), {
            headers: { 'Accept': 'application/json' }
        });
        const state = await response.json();
        render(state);

        if (state.status === 'queued' || state.status === 'running') {
            timer = setTimeout(poll, 1000);
            return;
        }

        startButton.disabled = false;
    }

    function render(state) {
        stateView.hidden = false;
        stateView.textContent = JSON.stringify(state, null, 2);

        const statusText = [
            'job=' + (state.job_id || '-'),
            'status=' + (state.status || '-'),
            'phase=' + (state.phase || '-')
        ];

        if (state.error) {
            meta.className = 'meta error';
            statusText.push('error=' + state.error);
        } else if (state.status === 'complete') {
            meta.className = 'meta ok';
        } else {
            meta.className = 'meta';
        }

        meta.textContent = statusText.join('  ');

        renderSnapshotPhase('database', state.phases && state.phases.database ? state.phases.database : {});
        renderSnapshotPhase('filesystem', state.phases && state.phases.filesystem ? state.phases.filesystem : {});
        renderTransferPhase(state.phases && state.phases.transfer ? state.phases.transfer : {});
    }

    function renderSnapshotPhase(key, phase) {
        const percent = clampPercent(phase.progress_percent || 0);
        document.getElementById(key + '-bar').style.width = percent + '%';
        document.getElementById(key + '-percent').textContent = Math.round(percent) + '%';

        const details = [];
        if (phase.status) details.push('status=' + phase.status);
        if (phase.current_phase) details.push('phase=' + phase.current_phase);
        if (phase.current_item) details.push('item=' + phase.current_item);
        if (phase.items_total) details.push('items=' + (phase.items_done || 0) + '/' + phase.items_total);
        if (phase.total_bytes_estimate) details.push('bytes=' + formatBytes(phase.processed_bytes || 0) + '/' + formatBytes(phase.total_bytes_estimate));
        if (phase.error) details.push('error=' + phase.error);

        const el = document.getElementById(key + '-details');
        el.textContent = details.join('  ');
        el.className = phase.error ? 'details error' : 'details';
    }

    function renderTransferPhase(phase) {
        const percent = clampPercent(phase.progress_percent || 0);
        document.getElementById('transfer-bar').style.width = percent + '%';
        document.getElementById('transfer-percent').textContent = Math.round(percent) + '%';

        const details = [];
        if (phase.status) details.push('status=' + phase.status);
        if (phase.total_chunks) details.push('chunks=' + (phase.chunk_index || 0) + '/' + phase.total_chunks);
        if (phase.size) details.push('bytes=' + formatBytes(phase.downloaded_bytes || 0) + '/' + formatBytes(phase.size));
        if (phase.final_path) details.push('file=' + phase.final_path.split('/').pop());
        if (phase.error) details.push('error=' + phase.error);

        const el = document.getElementById('transfer-details');
        el.textContent = details.join('  ');
        el.className = phase.error ? 'details error' : 'details';
    }

    function clampPercent(value) {
        return Math.max(0, Math.min(100, Number(value) || 0));
    }

    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let value = Number(bytes) || 0;
        let idx = 0;
        while (value >= 1024 && idx < units.length - 1) {
            value /= 1024;
            idx++;
        }
        return (value >= 100 || idx === 0 ? value.toFixed(0) : value.toFixed(1)) + ' ' + units[idx];
    }
})();
</script>
</body>
</html>
<?php
}

function migwp_pull_webgui_initial_snapshot_phase( $label ) {
	return [
		'label'                => $label,
		'status'               => 'idle',
		'progress_percent'     => 0,
		'processed_bytes'      => 0,
		'total_bytes_estimate' => 0,
		'written_bytes'        => 0,
		'current_phase'        => null,
		'current_item'         => null,
		'items_done'           => 0,
		'items_total'          => 0,
		'result_size'          => 0,
		'error'                => null,
	];
}

function migwp_pull_webgui_initial_transfer_phase() {
	return [
		'label'            => 'Transfer',
		'status'           => 'idle',
		'progress_percent' => 0,
		'size'             => 0,
		'chunk_size'       => 0,
		'total_chunks'     => 0,
		'etag'             => null,
		'chunk_index'      => 0,
		'downloaded_bytes' => 0,
		'final_path'       => null,
		'artifact_path'    => null,
		'sha256'           => null,
		'error'            => null,
	];
}

function migwp_pull_webgui_state_dir() {
	if ( ! is_dir( MIGWP_PULL_WEBGUI_STATE_DIR ) && ! mkdir( MIGWP_PULL_WEBGUI_STATE_DIR, 0777, true ) && ! is_dir( MIGWP_PULL_WEBGUI_STATE_DIR ) ) {
		throw new RuntimeException( 'Could not create web GUI state dir.' );
	}

	return MIGWP_PULL_WEBGUI_STATE_DIR;
}

function migwp_pull_webgui_state_path( $job_id ) {
	return migwp_pull_webgui_state_dir() . '/' . preg_replace( '/[^a-f0-9]/', '', (string) $job_id ) . '.json';
}

function migwp_pull_webgui_read_state( $job_id ) {
	$path = migwp_pull_webgui_state_path( $job_id );
	if ( ! is_file( $path ) ) {
		return null;
	}

	return json_decode( (string) file_get_contents( $path ), true );
}

function migwp_pull_webgui_write_state( $job_id, array $state ) {
	$path      = migwp_pull_webgui_state_path( $job_id );
	$temp_path = $path . '.tmp';
	$json      = json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	file_put_contents( $temp_path, $json );
	rename( $temp_path, $path );
}

function migwp_pull_webgui_spawn_worker( $job_id, $config_path ) {
	$php = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
	$cmd = sprintf(
		'%s %s %s %s > /dev/null 2>&1 &',
		escapeshellarg( $php ),
		escapeshellarg( __FILE__ ),
		escapeshellarg( '--worker=' . $job_id ),
		escapeshellarg( '--config=' . $config_path )
	);

	exec( $cmd );
}

function migwp_pull_webgui_send_json_headers() {
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate' );
}
