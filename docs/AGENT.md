# MigWP Migrator Notes

## Purpose

This plugin is a source-side WordPress snapshot plugin.

It does not perform the full migration by itself. Its job is to:

- authenticate a migration client
- create an encrypted database snapshot
- create an encrypted filesystem snapshot
- embed the database snapshot into the filesystem snapshot
- expose the final filesystem snapshot through the resumable file endpoints

The final transferable artifact is the filesystem snapshot only.

## Auth Model

There are two distinct auth phases:

### 1. Bootstrap auth

Used only for the initial trust/bootstrap flow.

This phase uses normal WordPress authentication and is expected to support:

- WordPress user auth
- Application Passwords

### 2. Migration auth

Used for all snapshot creation and transfer endpoints.

This phase uses the migration key via:

- `X-MigWP-Key`
- or `secret`

The migration key is the long-lived shared secret, but it is not used directly as the archive encryption key.

## Frozen Snapshot Flow

The frozen workflow is:

1. The migration server bootstraps trust with WordPress auth.
2. The migration server gets or already knows the migration key.
3. `POST /snapshot/database` creates the latest encrypted database dump.
4. `GET /snapshot/database` returns status and progress only.
5. `POST /snapshot/filesystem` creates the latest encrypted filesystem snapshot and includes the database dump.
6. `GET /snapshot/filesystem` returns status, progress, and when complete the secret relative path of the final artifact.
7. The migration server downloads that final filesystem snapshot through:
   - `GET /files/meta`
   - `GET /files/stream`

The database snapshot is staging data only. It is not the final transfer artifact.

## Snapshot Endpoints

### Database snapshot

- `POST /migwp-migrator/v1/snapshot/database`
- `GET /migwp-migrator/v1/snapshot/database`

Behavior:

- only one latest database snapshot exists
- starting a new one replaces the old one
- output is one gzipped, encrypted SQL dump
- implementation target is pure `mysqli`

### Filesystem snapshot

- `POST /migwp-migrator/v1/snapshot/filesystem`
- `GET /migwp-migrator/v1/snapshot/filesystem`

Behavior:

- only one latest filesystem snapshot exists
- starting a new one replaces the old one
- output is one encrypted custom archive
- the encrypted database dump is included inside this archive
- only this endpoint exposes the final secret relative artifact path

### Snapshot config

- `GET /migwp-migrator/v1/snapshot/config`
- `POST /migwp-migrator/v1/snapshot/config`

This config applies to future filesystem snapshots only.

Config responsibilities:

- include roots
- exclude paths
- exclude patterns
- whether `.zipignore` is respected

Default filesystem scope:

- include `wp-content/*`
- exclude WordPress core
- exclude backup plugin directories
- exclude generated snapshot directories

## Filesystem Snapshot Format

The new filesystem snapshot is a rewrite inspired by `wpmove` and `wpzip`.

### Archive structure

The archive has:

1. an unencrypted archive header
2. an encrypted entry stream

The header should contain non-secret metadata such as:

- magic
- format version
- snapshot id
- creation timestamp
- site URL
- encryption algorithm id
- key derivation algorithm id
- checksum algorithm id
- total files
- total directories
- archive payload bytes
- dump relative path
- dump size

### Entry format

Each entry contains:

- type
- stored payload length
- relative path length
- SHA-256 of the stored payload
- relative path
- payload

Entry types:

- `0` directory
- `1` uncompressed file
- `2` gzip-compressed file

## Compression

### Filesystem snapshot

Compression is per file entry, not for the whole archive stream.

Rules:

- only compress allowed file types
- only compress files above the configured size threshold
- only keep compressed data if it is actually smaller
- use gzip only

### Database snapshot

The database dump is:

- SQL text
- gzipped
- then encrypted

If gzip proves too expensive on weak hosts, compression level can be revisited later, but gzip-before-encryption is the frozen v1 plan.

## Encryption

The rewrite target is streamable authenticated encryption.

Planned implementation:

- PHP `ext-sodium`
- streaming authenticated encryption
- per-snapshot key derivation with HKDF-SHA256

Key inputs:

- migration key
- snapshot id
- salt

The encryption key must be derived per snapshot. The raw migration key must not be used directly as the archive key.

## Status And Progress

Both status endpoints should expose structured progress for a remote UI.

Expected fields:

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

That `artifact_path` is a long secret relative path and should only be returned by `GET /snapshot/filesystem`.

## Runtime Model

The REST API lives inside WordPress, but the heavy work should minimize repeated WordPress overhead.

Recommended execution split:

- WordPress-loaded REST layer for auth, configuration, job start, and status polling
- lightweight worker code for dump and pack execution

Job and progress state should be disk-backed JSON, not frequently updated WordPress options.

Recommended storage:

- job state JSON files under uploads
- snapshot artifacts under uploads

## Environment Baseline

- PHP: `8.2+`
- WordPress: `6.9+`
- Required extensions:
  - `mysqli`
  - `sodium`
  - `zlib`

Not required for the new design:

- external `mysqldump`
- `bz2`

