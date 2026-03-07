# Implementation Next

## Goal

Implement the frozen snapshot architecture described in:

- [AGENT.md](/home/ingo/flywp-migrator/docs/AGENT.md)
- [ARCHITECTURE.md](/home/ingo/flywp-migrator/docs/ARCHITECTURE.md)
- [TESTING.md](/home/ingo/flywp-migrator/docs/TESTING.md)

## First Build Order

1. Replace the current database export API model.

- remove the old dump-job behavior in [includes/Api/Database.php](/home/ingo/flywp-migrator/includes/Api/Database.php)
- implement singular endpoints:
  - `POST /snapshot/database`
  - `GET /snapshot/database`
- support one latest database snapshot only
- starting a new one recreates the snapshot

2. Introduce disk-backed snapshot job state.

- create a job-state service under a new snapshot service area
- persist latest database snapshot state as JSON
- persist latest filesystem snapshot state as JSON
- avoid frequent WordPress option writes during active work

3. Implement snapshot config storage and API.

- add:
  - `GET /snapshot/config`
  - `POST /snapshot/config`
- support:
  - include roots
  - exclude paths
  - exclude patterns
  - `.zipignore` enabled/disabled
- default scope:
  - include `wp-content/*`
  - exclude core
  - exclude backup plugin dirs
  - exclude generated snapshot dirs

4. Implement the new pure `mysqli` database snapshot writer.

- no external `mysqldump`
- no legacy resumable table scheduler
- create one SQL dump file
- gzip before encryption
- remove DB credentials from exported config-like content if any config file is emitted

5. Implement streamable authenticated encryption.

- use `ext-sodium`
- do not use the old buffered AES stream filter
- derive per-snapshot encryption key from:
  - migration key
  - snapshot id
  - salt
- use HKDF-SHA256

6. Implement the new filesystem archive writer.

- use the `wpzip` archive concept as reference
- add an unencrypted global archive header
- encrypted entry stream after the header
- entry types:
  - `0` directory
  - `1` stored file
  - `2` gzip-compressed file
- per-entry SHA-256 over stored payload
- per-entry gzip only when beneficial

7. Embed the database dump inside the filesystem snapshot.

- `POST /snapshot/filesystem` must require a completed latest DB snapshot
- include the encrypted DB dump as a normal file in the filesystem snapshot
- final downloadable artifact exists only for filesystem snapshot

8. Implement filesystem snapshot status.

- add singular endpoints:
  - `POST /snapshot/filesystem`
  - `GET /snapshot/filesystem`
- support one latest filesystem snapshot only
- status must return the final secret relative artifact path only when complete

9. Reuse existing file transfer endpoints for delivery.

- keep:
  - `/files/meta`
  - `/files/stream`
- final artifact path should be long and unguessable
- expose it only from `GET /snapshot/filesystem`

10. Update the plain PHP puller.

- make it use:
  - `POST /snapshot/database`
  - `GET /snapshot/database`
  - `POST /snapshot/filesystem`
  - `GET /snapshot/filesystem`
- wait for completion
- read final artifact path from filesystem status
- then use `/files/meta` and `/files/stream`

## Suggested New Service Areas

Likely new folders:

- `includes/Services/Snapshots/`
- `includes/Services/Snapshots/Database/`
- `includes/Services/Snapshots/Filesystem/`
- `includes/Services/Crypto/`

## Frozen Rules

- singular snapshot routes, not plural
- only one latest snapshot per type
- DB snapshot is staging only
- final transfer artifact is filesystem snapshot only
- only filesystem status returns final secret artifact path
- gzip only, no bzip2
- `mysqli`, `sodium`, `zlib` required

## Local Environment Notes

- In this workspace, `wp-env` under Podman was unstable on the main site port
- the test site instance on port `8889` was usable for local validation
- the current puller test config reflects that local setup

