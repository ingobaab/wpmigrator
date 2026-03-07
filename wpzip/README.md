# WordPress Migrator Prototype

Ein WordPress-Plugin zur Migration von WordPress-Installationen mittels reinem PHP.

## Hauptfunktionen

Das Plugin bietet drei Hauptfunktionen:

1. **wdump** - MySQL Datenbank-Dump in reinem PHP (ohne externe Programme)
2. **wpack** - Archivierung des gesamten Filesystems mit eigenem Archivformat
3. **wunpack** - Entpacken von Archiven

## Architektur

### Zentrale Include-Datei: `helper.inc.php`

Enthält alle Kernfunktionen:
- `wdump(array $config, ?ProgressHandler $progress): array` - Datenbank-Dump
- `wpack(string $source_dir, string $archive_file, string $password, array $options, ?ProgressHandler $progress): array` - Archivierung
- `wunpack(string $archive_file, string $output_dir, string $password, int $offset, ?ProgressHandler $progress): array` - Entpackung

### ProgressHandler-Pattern

Ermöglicht einheitliche Fortschrittsanzeige für CLI und WebGUI:

```php
interface ProgressHandler {
    public function update(int $current, int $total, string $message = ''): void;
    public function finish(array $stats = []): void;
}
```

Implementierungen:
- **CliProgressHandler** - Terminal-Ausgabe mit Progress Bar (Verbosity 0, 1, 2)
- **AjaxProgressHandler** - JSON-Datei für AJAX-Polling
- **SilentProgressHandler** - Keine Ausgabe (für Tests)

## CLI-Tools

### wdump.php - Datenbank-Dump

```bash
# Auto-Modus (WordPress-Konfiguration automatisch erkennen)
./wdump.php -a -o backup.sql

# Mit Kompression
./wdump.php -a --compress=gzip -o backup.sql.gz

# Mit Verschlüsselung
./wdump.php -a --compress=gzip -C geheimnis -o backup.sql.gz.aes

# Manuell
./wdump.php -h localhost -u root -p password mydb -o dump.sql

# Verbosity-Levels
./wdump.php -a -v -o backup.sql      # Normal (~20 Zeilen)
./wdump.php -a -vv -o backup.sql     # Sehr verbose (jede Tabelle)
```

**Optionen:**
- `-h, --host=HOST` - MySQL Host
- `-u, --user=USER` - MySQL Benutzer
- `-p, --pass=PASS` - MySQL Passwort
- `-P, --port=PORT` - MySQL Port (default: 3306)
- `-c, --charset=SET` - Character Set (default: utf8mb4)
- `-o, --out=FILE` - Ausgabedatei
- `-a, --auto` - WordPress-Konfiguration automatisch erkennen
- `--compress=TYPE` - Kompression: none, gzip, bzip2 (default: none)
- `--no-data` - Nur Struktur, keine Daten
- `-C, --cryptpass=PWD` - Mit Passwort verschlüsseln
- `-v` - Verbose (~20 Zeilen)
- `-vv` - Sehr verbose (jede Tabelle anzeigen)

### wpack.php - Archivierung

```bash
# Erstellt Self-Extracting Archive (SFX)
./wpack.php -p password -o backup.php /var/www/html

# Mit Optionen
./wpack.php -p secret -m=4k -c5 /var/www/html
```

**Optionen:**
- `-p PASSWORD` - Passwort für Verschlüsselung (erforderlich)
- `-o FILE` - Ausgabedatei
- `-m SIZE` - Minimale Dateigröße für Kompression (z.B. 4k, 2.5k)
- `-c LEVEL` - Gzip-Kompressionslevel (0-9, default: 5)
- `-v` - Verbose
- `-vv` - Sehr verbose

### wunpack.php - Entpackung

```bash
# Archiv entpacken
./wunpack.php -p password archive.php /var/www/html

# Mit Verbosity
./wunpack.php -p secret -v backup.wpack /restore
```

**Optionen:**
- `-p, --password=PWD` - Passwort für Entschlüsselung (erforderlich)
- `-o, --offset=NUM` - Byte-Offset (für SFX-Archive)
- `-v` - Verbose (~20 Zeilen)
- `-vv` - Sehr verbose (jede Datei anzeigen)

## Features

### MySQL Dump (wdump)
- ✅ Reines PHP ohne externe mysqldump-Programme
- ✅ Unterstützt Kompression (gzip, bzip2)
- ✅ AES-256-Verschlüsselung
- ✅ Progress-Tracking
- ✅ Automatische WordPress-Config-Erkennung
- ✅ Structure-only Modus (--no-data)

### Archivierung (wpack)
- ✅ Eigenes Archivformat mit File-Header
- ✅ Selektive Kompression je nach Dateityp
- ✅ AES-256-Verschlüsselung des gesamten Archivs
- ✅ MD5-Checksummen pro Datei
- ✅ .zipignore Unterstützung
- ✅ Self-Extracting Archive (SFX)
- ✅ Progress-Tracking

### Entpackung (wunpack)
- ✅ AES-256-Entschlüsselung
- ✅ Automatische Dekompression
- ✅ MD5-Checksum-Verifizierung
- ✅ Verzeichnisstruktur-Wiederherstellung
- ✅ Progress-Tracking

## Archivformat

Jede Datei im Archiv hat folgenden Header:

```
Byte 0:      Type (0=Verzeichnis, 1=Datei, 2=Komprimierte Datei)
Byte 1-4:    Datenlänge (4 Bytes, Big Endian)
Byte 5-6:    Pfadlänge (2 Bytes, Big Endian)
Byte 7-22:   MD5-Checksum (16 Bytes)
Byte 23+:    Relativer Pfad
Danach:      Dateiinhalt (falls Type != 0)
```

## Verbosity-Levels

Alle CLI-Tools unterstützen drei Verbosity-Levels:

- **Level 0 (default)**: Einzelne selbstüberschreibende Zeile mit Progress Bar
  ```
  [========================================] 100.0% (5234/5234) ETA: 0s
  ```

- **Level 1 (-v)**: ~20 Zeilen mit periodischen Updates
  ```
  [====================                    ]  50.0% | Table: wp_posts | ETA: 2m 15s | Elapsed: 2m 10s
  ```

- **Level 2+ (-vv)**: Jeder Datensatz/jede Datei wird angezeigt
  ```
  [1/100] Table: wp_posts (523 rows)
  [2/100] Table: wp_postmeta (12453 rows)
  ...
  ```

## WebGUI Integration

Die Hauptfunktionen können sowohl von CLI-Tools als auch vom WebGUI verwendet werden:

```php
// CLI-Verwendung
$progress = new CliProgressHandler($verbosity);
$result = wdump($config, $progress);

// WebGUI-Verwendung (AJAX)
$progress = new AjaxProgressHandler('/tmp/progress_dump.json');
$result = wdump($config, $progress);

// JavaScript pollt dann die JSON-Datei:
fetch('/tmp/progress_dump.json')
    .then(r => r.json())
    .then(data => updateProgressBar(data.percent, data.message));
```

## Entwicklung

### Dateien

- `helper.inc.php` - Zentrale Include-Datei mit allen Funktionen und Klassen
- `wdump.php` - CLI-Tool für Datenbank-Dump
- `wpack.php` - CLI-Tool für Archivierung (wird von helper.inc.php generiert)
- `wunpack.php` - CLI-Tool für Entpackung
- `wpmove.php` - WordPress-Plugin-Hauptdatei mit WebGUI

### wpack.php Regenerierung

`wpack.php` wird automatisch aus `helper.inc.php` generiert:

```bash
php helper.inc.php
# Erstellt wpack.php mit __HALT_COMPILER() am Ende
```

## Sicherheit

- ✅ AES-256-CBC Verschlüsselung
- ✅ MD5-Checksummen für Integritätsprüfung
- ✅ Passwort-Schutz für alle Archive
- ✅ Keine externen Programme (sicherer)
- ✅ Stream-basierte Verarbeitung (speichereffizient)

## Version

1.6.4

## Lizenz

GPLv2.0 or later
