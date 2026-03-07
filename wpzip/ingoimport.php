#!/usr/bin/env php
<?php

if (!class_exists('MySQLiDump')) { require_once 'helper.inc.php'; }

class SqlImporter
{
    private mysqli $db;
    private string $dump_file;
    private int $query_count = 0;

    public function __construct(
        string $host,
        string $user,
        string $password,
        string $database,
        string $dump_file,
        int $port = 3306
    ) {
        $this->db = new mysqli($host, $user, $password, $database, $port=null, $socket=null);

        if ($this->db->connect_error) {
            throw new RuntimeException("Connection failed: " . $this->db->connect_error);
        }

        if (!is_readable($dump_file)) {
            throw new RuntimeException("Dump file not readable: $dump_file");
        }

        $this->dump_file = $dump_file;
    }

    public function import(): void
    {
        $handle = fopen($this->dump_file, 'r');
        if (!$handle) {
            throw new RuntimeException("Failed to open dump file: $this->dump_file");
        }

        $query = '';
        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);

            // Skip comments and empty lines
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $query .= $line;

            if (str_ends_with(rtrim($trimmed), ';')) {
                $this->execute_query($query);
                $query = '';
            }
        }

        fclose($handle);

        echo "Import finished. Executed $this->query_count queries.\n";
    }

    private function execute_query(string $query): void
    {
        if (!$this->db->query($query)) {
            fwrite(STDERR, "MySQL error: " . $this->db->error . "\nQuery: $query\n");
        } else {
            $this->query_count++;
        }
    }

    public function __destruct()
    {
        $this->db->close();
    }
}

// ------------- ENTRY POINT -------------

// Parameterverarbeitung
$shortopts = "vh:u:p:d:P:a";
$longopts = [
    "host:",
    "user:",
    "password:",
    "database:",
    "port:",
    "help",
    "auto"
];
$options = getopt($shortopts, $longopts, $rest_index);
$args = array_slice($_SERVER['argv'], $rest_index);

if (isset($options['a']) || isset($options['auto']) ) {

  @ require_once "helper.inc.php";

  $wpConfigPath = find_wp_config();
  if ($wpConfigPath) {
      // echo 'Found wp-config.php at: ' . $wpConfigPath . "\n";
  } else {
      echo 'wp-config.php not found! Exit 1.' . "\n";
      exit(1);
  }

  $dbconfig = getDbConfig($wpConfigPath);
  print_r($dbconfig);
}

if (isset($options['v'])) $verb=(is_int($options['v'])) ? $options['v'] : (is_string($options['v']) ? 1+strlen($options['v']) : ((is_bool($options['v'])) ? 1 : count($options['v'])));  // '-v' '-vv'$host = $options['h'] ?? $options['host'] ?? $dbconfig['host'] ?? '127.0.0.1';
$user = $options['u'] ?? $options['user'] ?? $dbconfig['user'] ?? 'root';
$pass = $options['p'] ?? $options['password'] ?? $dbconfig['pass'] ?? '';
$port = $options['P'] ?? $options['port'] ?? $dbconfig['port'] ?? null;
$socket = $options['S'] ?? $options['socket'] ?? null;
$database = $options['database'] ?? $dbconfig['name'] ?? null;
$file = $options['file'] ?? $args[0] ?? null;

if ($verb>=1) {
  echo "\n" . 'host:        ' . $host;
  echo "\n" . 'user:        ' . $user;
  echo "\n" . 'pass:        ' . $pass;
  echo "\n" . 'port:        ' . $port;
  echo "\n" . 'socket:      ' . $socket;
  echo "\n" . 'database:    ' . $database;
  echo "\n" . 'file:        ' . $file;
  echo "\n\n";
}

if (!$database || !$file) {
    fwrite(STDERR, "\nUsage: php ingoimport.php --host=HOST --user=USER --password=PWD --database=DB --file=DUMP.sql [--port=3306]\n\n\n");
    exit(1);
}

try {
    $importer = new SqlImporter($host, $user, $pass, $database, $file, $port, $socket);
    $importer->import();
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
echo 'Imported ' . dq($file) . "\n";