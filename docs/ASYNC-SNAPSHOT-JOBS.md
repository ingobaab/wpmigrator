# Async Snapshot Jobs

## Goal

Freeze the minimum async execution model required so polling can observe real in-progress snapshot state.

This applies to:

- `POST /snapshot/database`
- `GET /snapshot/database`
- `POST /snapshot/filesystem`
- `GET /snapshot/filesystem`

## Problem

If a snapshot `POST` request performs the full job inside the same HTTP request, the client receives the response only after completion.

That means polling `GET` during the run cannot see meaningful intermediate state.

## Frozen Execution Model

### Start endpoint behavior

`POST /snapshot/database` and `POST /snapshot/filesystem` must:

1. create or replace the latest job state on disk
2. set initial job status
3. trigger background work
4. return immediately

The `POST` request must not perform the whole snapshot synchronously.

### Status endpoint behavior

`GET /snapshot/database` and `GET /snapshot/filesystem` must:

- read the latest state from disk
- return current progress only
- not run the main snapshot workload inline

### Worker behavior

The actual snapshot workload runs in a separate worker request.

That worker:

- reads the latest state from disk
- validates it is still the current job
- performs the heavy work
- persists progress repeatedly during the run
- finishes with `complete` or `failed`

## Trigger Model

The frozen minimum implementation uses:

- a loopback HTTP request from WordPress to WordPress

Reason:

- minimal implementation cost
- works with the existing REST-driven plugin shape
- sufficient for local validation and common hosting setups

This is not a claim that it is the final long-term worker architecture.

## Latest Job Model

This remains frozen:

- only one latest snapshot job exists per type
- one latest database snapshot
- one latest filesystem snapshot

Starting a new job replaces the latest job state.

## Worker Token

Each queued job gets a worker token.

The worker token is used to:

- identify the worker request
- ensure the worker is operating on the currently active latest job
- let superseded workers stop early

The worker token is internal state and is not part of the public status response.

## Disk State

The latest state remains disk-backed JSON.

State must include enough data for:

- current status
- progress reporting
- worker validation
- artifact paths
- error reporting

## Status Lifecycle

Minimum status values:

- `idle`
- `queued`
- `running`
- `complete`
- `failed`

Recommended behavior:

- `POST` writes `queued`
- worker moves state to `running`
- worker updates progress while running
- worker ends in `complete` or `failed`

## Progress Persistence

The worker must persist progress repeatedly during execution.

Minimum rule:

- persist state at meaningful phase changes
- persist state during iterative work such as tables or files

This is what makes polling useful.

## Superseded Workers

If a new latest job replaces an older one, the older worker must stop when it detects that its worker token is no longer current.

This does not require hard process termination.

It is enough for the older worker to:

- detect it is no longer current
- stop further work
- avoid overwriting the new latest state

## Public Contract

Public status responses continue to expose:

- `status`
- `created_at`
- `started_at`
- `updated_at`
- `finished_at`
- `progress_percent`
- `processed_bytes`
- `total_bytes_estimate`
- `written_bytes`
- `current_phase`
- `current_item`
- `items_done`
- `items_total`
- `result_size`
- `error`

Filesystem-only when complete:

- `artifact_path`

## Puller Contract

The puller continues to:

1. call `POST /snapshot/database`
2. poll `GET /snapshot/database`
3. call `POST /snapshot/filesystem`
4. poll `GET /snapshot/filesystem`
5. read `artifact_path`
6. transfer the final artifact

The puller does not need special worker knowledge.

## Notes

This freeze is intentionally minimal.

It does not define:

- resumable multi-request table dumping
- resumable multi-request file packing
- WP-Cron worker scheduling
- daemon or CLI worker execution

Those can be discussed later if loopback workers prove insufficient.
