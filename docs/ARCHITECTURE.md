# Architecture

## Overview

`migwp-migrator` is a source-side snapshot plugin for WordPress.

It is REST-driven. An admin page may exist, but snapshot creation and transfer should not depend on the admin UI.

The system is built around one final transport artifact:

- an encrypted filesystem snapshot

That filesystem snapshot contains:

- the WordPress `wp-content` data selected by snapshot config
- the encrypted database dump artifact created earlier

The final artifact is downloaded through the existing chunked file endpoints.

## Bootstrap

Plugin bootstrap:

- entry file: [migwp-migrator.php](../migwp-migrator.php)
- main plugin class: [includes/Plugin.php](../includes/Plugin.php)

Important plugin hooks:

- activation hook ensures the migration key exists
- `plugins_loaded` initializes runtime services
- `rest_api_init` registers REST routes

## Stored State

Long-lived state:

- migration key in option `migwp_migration_key`
- snapshot config in persistent plugin storage

Runtime state:

- latest database snapshot state as JSON on disk
- latest filesystem snapshot state as JSON on disk
- snapshot artifacts on disk

The rewrite should avoid frequent WordPress option updates during long-running jobs.

## REST Structure

Namespace:

- `migwp-migrator/v1`

Current main coordinator:

- [includes/Api.php](../includes/Api.php)

Current submodules:

- [includes/Api/Database.php](../includes/Api/Database.php)
- [includes/Api/Files.php](../includes/Api/Files.php)

The rewrite target is to move from dump-oriented and zip-oriented exports to snapshot-oriented jobs.

## Auth Layers

### 1. Bootstrap auth

Used only for initial trust/bootstrap.

Expected mechanisms:

- WordPress-authenticated REST access
- Application Passwords

### 2. Migration auth

Used by snapshot and transfer endpoints.

Permission callback:

- `Api::check_permission()`

Accepted credentials:

- `X-MigWP-Key`
- `secret`

## Frozen Snapshot Endpoints

### Database snapshot

- `POST /snapshot/database`
- `GET /snapshot/database`

Behavior:

- only one latest job exists
- starting a new job recreates the latest snapshot
- creates one gzipped, encrypted SQL dump
- this artifact is staging data only

### Filesystem snapshot

- `POST /snapshot/filesystem`
- `GET /snapshot/filesystem`

Behavior:

- only one latest job exists
- starting a new job recreates the latest snapshot
- creates one encrypted filesystem snapshot
- includes the encrypted database dump
- returns the final secret relative artifact path only from status

### Snapshot config

- `GET /snapshot/config`
- `POST /snapshot/config`

Purpose:

- configure include roots
- configure excludes
- configure pattern-based exclusions
- configure whether `.zipignore` is respected

Defaults:

- include only `wp-content/*`
- exclude core
- exclude backup plugin directories
- exclude generated snapshot directories

## Database Snapshot Design

The database snapshot is being redesigned.

The old dump-job implementation should be removed in favor of:

- one latest snapshot job
- one physical artifact on disk
- one status endpoint

Implementation target:

- pure `mysqli`
- no external `mysqldump`
- gzip before encryption

The database artifact is not the final transfer payload.

## Filesystem Snapshot Design

The filesystem snapshot is a rewrite of the older `wpmove` and `wpzip` archive concepts.

### Scope

Default content scope:

- `wp-content/uploads`
- `wp-content/plugins`
- `wp-content/mu-plugins`
- `wp-content/themes`
- other configured paths under `wp-content`

### Archive layout

The final artifact contains:

1. unencrypted archive header
2. encrypted stream of archive entries

Header metadata should include:

- archive magic
- format version
- snapshot id
- created timestamp
- site URL
- encryption algorithm id
- key derivation algorithm id
- checksum algorithm id
- total files
- total directories
- archive payload bytes
- dump relative path
- dump size

### Entry records

Each entry should contain:

- type
- stored payload length
- path length
- SHA-256 checksum of stored payload
- relative path
- payload

Entry types:

- `0` directory
- `1` stored file
- `2` gzip-compressed file

### Selective compression

Compression is per entry.

Rules:

- use gzip only
- compress only eligible file types
- compress only above a minimum size threshold
- keep compressed form only if it is smaller

## Encryption Design

The old buffered AES filter is not sufficient for large artifacts.

Rewrite target:

- streamable authenticated encryption
- `ext-sodium`
- per-snapshot derived encryption key

Key derivation:

- base secret: migration key
- derivation metadata:
  - snapshot id
  - salt
- KDF:
  - HKDF-SHA256

The raw migration key must not be used directly as the archive or dump encryption key.

## Artifact Path Exposure

The final filesystem snapshot should be stored under a long secret relative path.

Example shape:

- `wp-content/uploads/migwp-migrator/snapshots/<snapshot-id>/<unguessable-name>.fwsnap`

That path must be returned only by:

- `GET /snapshot/filesystem`

It must not be exposed by bootstrap endpoints.

## Transfer Layer

The final filesystem snapshot is transferred via:

- `GET /files/meta?path=...`
- `GET /files/stream?path=...&chunk=N`

The existing fixed-chunk resumable download model remains the transport layer.

## Progress Model

Snapshot jobs must expose progress suitable for remote visualization.

Status response fields should include:

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

Filesystem status should additionally include:

- `artifact_path` when complete

## Runtime Strategy

The REST API still runs in WordPress, but the heavy work should be handled with as little repeated WordPress overhead as possible.

Recommended strategy:

- WordPress bootstraps the job and exposes status
- lightweight worker code performs dump and packing
- workers write progress to disk-backed JSON state

This avoids expensive WordPress state writes during large loops.

## Testing Strategy

Recommended test layers:

1. local wp-env endpoint verification (port 8888)
2. direct HTTP testing of:
   - `/snapshot/database`
   - `/snapshot/filesystem`
   - `/snapshot/config`
   - `/files/meta`
   - `/files/stream`
3. plain PHP puller integration tests
4. archive header and checksum verification
5. decryption and unpack validation in a separate local restore harness

