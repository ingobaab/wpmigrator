# Testing

## Goal

Test the source plugin locally before building the full migration server workflow.

The frozen v1 test target is:

1. create a database snapshot
2. create a filesystem snapshot that contains the database dump
3. download the final filesystem snapshot through the chunked file endpoints
4. verify header, checksums, encryption/decryption, and extraction

## Local Runtime

This repository includes [`.wp-env.json`](../.wp-env.json) for local development.

### wp-env Container Layout

`wp-env start` creates (typically via Podman or Docker):

| Port | Service |
|------|---------|
| 8888 | Main WordPress site |
| 8889 | Tests WordPress site |

Each environment: WordPress container, MariaDB, CLI container. The plugin is loaded via `"plugins": ["."]` in `.wp-env.json`.

### Execution Model

- **Plugin** runs inside wp-env (WordPress in container), exposes REST API
- **pull.php** runs on the host, connects via HTTP to `http://localhost:8888` (or `8889`)

`tools/pull-config.json` (from `pull-config.example.json`) must contain `base_url`, `rest_root`, and `migration_key`.

### Recommended local runtime

1. start `wp-env`
2. activate the plugin
3. ensure the migration key exists
4. trigger `/snapshot/database`
5. wait for completion through `GET /snapshot/database`
6. trigger `/snapshot/filesystem`
7. wait for completion through `GET /snapshot/filesystem`
8. use the returned secret relative path with `/files/meta` and `/files/stream`

## Required Environment

The source PHP environment should provide:

- `mysqli`
- `sodium`
- `zlib`

The new design does not require:

- external `mysqldump`
- `bz2`

## Endpoint Test Coverage

### `/snapshot/config`

Verify:

- default config matches the frozen architecture
- config updates persist
- includes are restricted to intended snapshot roots
- excludes are applied on future filesystem snapshots only
- `.zipignore` support can be enabled and disabled

### `/snapshot/database`

Verify:

- starting a job recreates the latest snapshot
- only one latest job exists
- status progresses through expected phases
- final artifact exists on disk
- output is gzipped then encrypted
- response never exposes the final filesystem artifact path

Expected progress fields:

- `status`
- `progress_percent`
- `processed_bytes`
- `written_bytes`
- `current_phase`
- `items_done`
- `items_total`

### `/snapshot/filesystem`

Verify:

- starting a job recreates the latest snapshot
- only one latest job exists
- status exposes progress and the secret relative artifact path only when complete
- the created snapshot contains the encrypted database dump artifact
- default scope includes `wp-content/*` but not core
- excludes and `.zipignore` are respected

### `/files/meta`

Verify:

- the secret final artifact path resolves
- response returns:
  - `size`
  - `chunk_size`
  - `total_chunks`
  - `etag`
- invalid paths are rejected
- guessed or unrelated paths are rejected

### `/files/stream`

Verify:

- chunk `0` returns bytes
- checksum header matches the response body
- file `etag` remains stable
- later chunks can be fetched independently
- out-of-range chunks return `416`
- full artifact can be reconstructed from ordered chunks

## Artifact Verification

After downloading the final filesystem snapshot:

1. read the unencrypted archive header
2. verify:
   - magic
   - version
   - snapshot id
   - algorithm ids
   - file and directory counts
   - dump relative path
   - dump size
3. derive the per-snapshot encryption key
4. decrypt the entry stream
5. unpack the archive
6. verify per-entry SHA-256 checksums
7. locate the embedded database dump
8. decrypt the database dump
9. gunzip the database dump
10. inspect the resulting SQL

## Puller Test Plan

The existing plain PHP puller should evolve to:

1. call the snapshot status endpoints
2. wait for completion
3. read the final secret relative path from `GET /snapshot/filesystem`
4. call `/files/meta`
5. download `/files/stream` sequentially
6. verify per-chunk checksums
7. persist progress to disk
8. resume from the first missing chunk
9. verify the final downloaded artifact size and hash

## Failure Tests

Test these failure cases:

- wrong migration key
- wrong derived decryption key inputs
- interrupted DB snapshot creation
- interrupted filesystem snapshot creation
- interrupted chunk download
- filesystem snapshot requested without a completed DB snapshot
- invalid include/exclude config
- checksum mismatch on unpack
- corrupted encrypted payload

## Notes

- prefer direct HTTP and CLI validation over browser automation
- use browser automation only if the bootstrap authorization flow itself becomes the feature under test
