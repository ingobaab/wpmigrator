# Puller Progress Bars

## Goal

Freeze the progress display model for the plain PHP puller and any matching UI.

We do not use one weighted overall progress bar.

We use three separate progress bars in sequence:

1. database snapshot creation
2. filesystem snapshot creation
3. final artifact transfer

Each bar runs from `0` to `100`.

The bars are shown in order, not blended into one combined percentage.

## Frozen Model

### 1. Database snapshot bar

Source:

- `GET /migwp-migrator/v1/snapshot/database`

Primary progress value:

- `progress_percent`

Supporting fields for text/details:

- `status`
- `current_phase`
- `current_item`
- `items_done`
- `items_total`
- `processed_bytes`
- `total_bytes_estimate`
- `written_bytes`
- `error`

Behavior:

- starts at `0`
- updates until `100`
- completes before the filesystem snapshot bar begins
- if status becomes `failed`, the puller stops and shows the error

### 2. Filesystem snapshot bar

Source:

- `GET /migwp-migrator/v1/snapshot/filesystem`

Primary progress value:

- `progress_percent`

Supporting fields for text/details:

- `status`
- `current_phase`
- `current_item`
- `items_done`
- `items_total`
- `processed_bytes`
- `total_bytes_estimate`
- `written_bytes`
- `error`

Behavior:

- starts at `0` after the database snapshot is complete
- updates until `100`
- completes before the transfer bar begins
- if status becomes `failed`, the puller stops and shows the error
- `artifact_path` is read only after status becomes `complete`

### 3. Transfer bar

Source:

- `GET /migwp-migrator/v1/files/meta`
- `GET /migwp-migrator/v1/files/stream`

Primary progress value:

- downloaded bytes divided by final artifact size

Supporting fields for text/details:

- `size`
- `chunk_size`
- `total_chunks`
- `etag`
- current chunk index
- downloaded bytes
- final local file path

Behavior:

- starts at `0` after the filesystem snapshot is complete and `artifact_path` is known
- updates until `100`
- uses the transfer artifact size from `/files/meta`
- resumes from saved local progress when possible
- if `etag` changes during download, the puller stops
- if a chunk checksum fails, the puller stops

## No Overall Weighting

This is frozen:

- no single weighted overall percentage
- no attempt to combine database, filesystem, and transfer into one bar

Reason:

- the phases represent different kinds of work
- they progress at different speeds
- weighting would add false precision without improving operator understanding

## Update Frequency

This is frozen:

- puller and UI should update progress approximately once per second

Target behavior:

- poll snapshot status endpoints about every `1` second while a snapshot phase is active
- update transfer progress about every `1` second during chunk download, or on each completed chunk if chunks finish slower than that

Rationale:

- this should feel fluid enough for common WordPress sizes and hosting environments
- it avoids excessive polling noise
- it does not require sub-second update behavior

## UI Expectations

Display model:

- show three named progress bars
- only one bar is active at a time
- completed bars stay visible at `100`
- later bars remain at `0` until their phase begins

Recommended labels:

- `Database Snapshot`
- `Filesystem Snapshot`
- `Transfer`

Recommended detail text:

- current phase
- current item when available
- items done / items total when available
- bytes processed / bytes total estimate when available
- explicit failure message when present

## Puller Expectations

The plain PHP puller should:

1. `POST /snapshot/database`
2. poll `GET /snapshot/database` roughly every second
3. `POST /snapshot/filesystem`
4. poll `GET /snapshot/filesystem` roughly every second
5. read `artifact_path`
6. call `/files/meta`
7. download `/files/stream`
8. update transfer progress during download

The puller does not need to invent extra progress math beyond:

- snapshot `progress_percent`
- transfer bytes downloaded / total size

## Current Data Contract

For snapshot phases, the server should continue exposing:

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

Additional filesystem-only field when complete:

- `artifact_path`

For transfer, the client should continue using:

- `/files/meta` for total size and chunk metadata
- `/files/stream` for chunk retrieval and checksum validation

## Open Questions After This Freeze

These are not resolved by this document:

- whether the puller should print progress every second or only when values change
- whether transfer progress should be shown by bytes, chunks, or both
- whether the snapshot status endpoints need smoother sub-phase progress
- whether the puller should persist more detailed transfer state
