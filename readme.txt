=== FlyWP Migrator - Migrate Your Site to FlyWP ===
Contributors: flywp, tareq1988
Tags: migration, backup, flywp, site-migration, transfer
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.3.0
Requires PHP: 7.4
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Move your WordPress site to FlyWP's lightning-fast hosting platform with just a few clicks. No technical knowledge required!

== Description ==

Ready to give your WordPress site the speed and security it deserves? FlyWP Migrator makes moving your site to FlyWP's powerful hosting platform a breeze. No more complicated migration processes or downtime – just simple, stress-free transfers.

= Why Move to FlyWP? =

* 🚀 **Blazing Fast Performance** - Your site will run on FlyWP's optimized servers
* 🔒 **Rock-Solid Security** - Built-in protection against threats and attacks
* 🎯 **One-Click Migration** - Move your entire site with just a migration key
* ⚡ **Zero Downtime** - Your site stays live during the whole process
* 💪 **Expert Support** - Get help when you need it

= Everything Moves With You =

Don't worry about losing anything – we move it all:

* 📸 All your images and media files
* 🎨 Your theme and customizations
* 🔌 Every plugin and its settings
* 📝 All your posts and pages
* 💬 Comments and user data
* ⚙️ Site settings and configurations

= It's As Easy As 1-2-3 =

1. Install the plugin
2. Copy your migration key
3. Paste the migration key in your FlyWP migrate site wizard
3. Let us handle the rest!

Perfect for bloggers, business owners, and anyone looking to give their WordPress site a better home. No technical knowledge needed!

= We've Got Your Back =

* Clear progress updates throughout the migration
* Automatic handling of large files
* Smart error recovery if anything interrupts the process
* Friendly support team ready to help

Join thousands of happy website owners who've already moved to FlyWP's optimized hosting platform. Experience better performance, tighter security, and peace of mind.

== Installation ==

1. Install and activate the plugin through WordPress
2. Go to FlyWP Migration in your admin menu
3. Copy your migration key
4. Enter the migration key in FlyWP to start the migration

== Frequently Asked Questions ==

= Do I need technical knowledge to use this plugin? =

Not at all! If you can copy and paste your migration key, you can move your site to FlyWP.

= Will my site go down during migration? =

No! Your site stays completely functional during the entire process.

= What if something goes wrong during migration? =

Don't worry! The plugin automatically picks up where it left off if anything interrupts the process.

= Is my data safe during transfer? =

Absolutely! We use secure transfer methods to protect your data throughout the migration.

== REST API ==

= Resumable file streaming =

The plugin exposes a headless pull endpoint for transferring one file in fixed 10 MB chunks:

`GET /wp-json/flywp-migrator/v1/files/stream?path=<relative-path>&chunk=<index>&secret=<migration-key>`

Authentication:

* Use the same shared-secret mechanism as the other migration endpoints.
* Send `X-FlyWP-Key: <migration-key>` or pass `secret=<migration-key>` as a query parameter.

Request parameters:

* `path` - Relative path to the file inside an allowed WordPress content root.
* `chunk` - Zero-based chunk index.

Allowed roots:

* uploads directory
* `wp-content`
* plugins directory
* mu-plugins directory
* themes directory

Response:

* HTTP `200 OK` with the raw chunk bytes in the response body.
* HTTP `416` if the requested chunk is outside the file bounds.

Response headers:

* `X-FlyWP-File-Path` - URL-encoded relative file path
* `X-FlyWP-File-Size` - Full file size in bytes
* `X-FlyWP-Chunk-Size` - Fixed chunk size in bytes (`10485760`)
* `X-FlyWP-Chunk-Index` - Current chunk index
* `X-FlyWP-Chunk-Checksum` - SHA-256 checksum of the returned chunk body
* `X-FlyWP-Chunk-Bytes` - Byte range of the returned chunk
* `X-FlyWP-Total-Chunks` - Total number of chunks for the file
* `X-FlyWP-Is-Last-Chunk` - `1` for the final chunk, otherwise `0`

Resume strategy:

1. Request chunk `0`.
2. Verify the body against `X-FlyWP-Chunk-Checksum`.
3. Persist the chunk locally.
4. Request the next chunk index.
5. If a transfer fails, restart from the first missing chunk index.

This design makes the transfer interruptable and resumable without requiring server-side session state.

== Screenshots ==

1. **Admin Interface** – Easily configure your migration settings for a seamless transfer to FlyWP.

== Changelog ==

= v1.3.0 (20 January, 2026) =

 * **Improved:** `/files/stream` now supports resumable 10 MB chunk polling with per-chunk SHA-256 checksums.
 * **New:** Added mysqldump support for database export.

= v1.2.0 (28 March, 2025) =

 * **New:** Added REST API endpoint to retrieve the structure of all database tables.

= v1.1.0 (20 March, 2025) =

 * **New:** Added a new REST API endpoint to retrieve migration info for authorized users.

### 1.0.0 (16 March, 2025)
- 🎉 **Initial Release** – Migrate WordPress sites to FlyWP with ease.

== Upgrade Notice ==

= 1.0.0 =
Welcome to FlyWP Migrator! Start enjoying better WordPress hosting today.
