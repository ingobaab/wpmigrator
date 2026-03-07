#!/usr/bin/env php
<?php

if (!class_exists('MySQLiDump')) { require_once 'helper.inc.php'; }

/*
class SecureFileEncryptor {
  const SALT_LENGTH = 16; // 16 Bytes Salt
  const IV_LENGTH = 16;   // 16 Bytes für AES IV
  const KEY_LENGTH = 32;  // 32 Bytes für AES-256
  const PBKDF2_ITERATIONS = 100000; // Anzahl der Hash-Iterationen

  public static function encryptWithPassword($inputFile, $outputFile, $password, $compression = null) {
      // Zufälliges Salt generieren
      $salt = random_bytes(self::SALT_LENGTH);

      // Schlüssel aus Passwort ableiten (PBKDF2)
      $key = hash_pbkdf2("sha256", $password, $salt, self::PBKDF2_ITERATIONS, self::KEY_LENGTH, true);

      // IV generieren
      $iv = random_bytes(self::IV_LENGTH);

      // Verschlüsseln
      $crypto = new ChunkedAES256CBC($key, $iv);
      $input = fopen($inputFile, 'rb');
      $output = fopen($outputFile, 'wb');

      // Salt und IV an den Anfang der Datei schreiben
      fwrite($output, $salt);
      fwrite($output, $iv);

      $crypto->encrypt($input, $output, $compression);
      fclose($input);
      fclose($output);
  }

  public static function decryptWithPassword($inputFile, $outputFile, $password, $compression = null) {
      $input = fopen($inputFile, 'rb');

      // Salt und IV aus der Datei lesen
      $salt = fread($input, self::SALT_LENGTH);
      $iv = fread($input, self::IV_LENGTH);

      if (strlen($salt) !== self::SALT_LENGTH || strlen($iv) !== self::IV_LENGTH) {
          fclose($input);
          throw new Exception("Invalid encrypted file format");
      }

      // Schlüssel aus Passwort ableiten (PBKDF2)
      $key = hash_pbkdf2("sha256", $password, $salt, self::PBKDF2_ITERATIONS, self::KEY_LENGTH, true);

      // Entschlüsseln
      $crypto = new ChunkedAES256CBC($key, $iv);
      $output = fopen($outputFile, 'wb');
      $crypto->decrypt($input, $output, $compression);
      fclose($input);
      fclose($output);
  }
}



*/

// Example usage:
try {
    // Generate a random key (32 bytes for AES-256) and IV
    $key = random_bytes(32);
    $iv = random_bytes(16);

    // Create our encryptor/decryptor
    $crypto = new ChunkedAES256CBC($key, $iv);

    // Test file paths
    $originalFile = __DIR__ . '/helper.inc.php';
    $encryptedFile = __DIR__ . '/encrypted.enc';
    $decryptedFile = __DIR__ . '/decrypted.txt';

    // Create a sample test file if it doesn't exist
    if (!file_exists($originalFile)) {
        file_put_contents($originalFile, "This is a test file.\n".str_repeat("Sample content for testing encryption and compression.\n", 100));
    }

    echo "Original file size: " . filesize($originalFile) . " bytes\n";

    // Encrypt with Gzip compression
    $input = fopen($originalFile, 'rb');
    $output = fopen($encryptedFile, 'wb');
    $crypto->encrypt($input, $output, 'gzip');
    fclose($input);
    fclose($output);

    echo "Encrypted file size: " . filesize($encryptedFile) . " bytes\n";

    // Decrypt with Gzip decompression
    $input = fopen($encryptedFile, 'rb');
    $output = fopen($decryptedFile, 'wb');
    $crypto->decrypt($input, $output, 'gzip');
    fclose($input);
    fclose($output);

    echo "Decrypted file size: " . filesize($decryptedFile) . " bytes\n";

    // Verify the decrypted file matches the original
    $originalHash = md5_file($originalFile);
    $decryptedHash = md5_file($decryptedFile);

    if ($originalHash === $decryptedHash) {
        echo "Success! The decrypted file matches the original.\n";
    } else {
        echo "Error: The decrypted file does not match the original.\n";
        echo "Original hash: $originalHash\n";
        echo "Decrypted hash: $decryptedHash\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
