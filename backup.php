<?php

declare(strict_types=1);

/**
 * Portable backup and restore routines for the i18n API.
 *
 * The archive contains the SQLite database, .env (when present) and a manifest.
 * It intentionally does not include source code or the database.backups directory.
 */

function backupDirectory(string $baseDir): string
{
    $directory = $baseDir . '/backups';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the backup directory.');
    }
    return $directory;
}

function backupArchiveName(): string
{
    return 'i18n-api-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.tar.gz';
}

function removeBackupTempDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . '/' . $entry;
        if (is_dir($path)) {
            removeBackupTempDirectory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}

function createBackupArchive(string $baseDir, ?string $destination = null): string
{
    $databasePath = $baseDir . '/database.sqlite';
    if (!is_file($databasePath)) {
        throw new RuntimeException('database.sqlite was not found.');
    }
    if (!class_exists(PharData::class)) {
        throw new RuntimeException('The PHP PharData extension is required for backups.');
    }

    $destination = $destination ?: backupDirectory($baseDir) . '/' . backupArchiveName();
    if (is_dir($destination)) {
        $destination = rtrim($destination, '/') . '/' . backupArchiveName();
    }
    $destinationDir = dirname($destination);
    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0750, true) && !is_dir($destinationDir)) {
        throw new RuntimeException('Could not create the backup destination directory.');
    }

    $workDirectory = sys_get_temp_dir() . '/i18n-api-backup-' . bin2hex(random_bytes(8));
    if (!mkdir($workDirectory, 0700, true)) {
        throw new RuntimeException('Could not create a temporary backup directory.');
    }
    $tarPath = $workDirectory . '.tar';
    $archivePath = $tarPath . '.gz';

    try {
        $stagedDatabase = $workDirectory . '/database.sqlite';
        try {
            $database = new PDO('sqlite:' . $databasePath);
            $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $database->exec('PRAGMA busy_timeout = 5000');
            $database->exec('VACUUM INTO ' . $database->quote($stagedDatabase));
        } catch (Throwable $e) {
            @unlink($stagedDatabase);
            if (!copy($databasePath, $stagedDatabase)) {
                throw new RuntimeException('Could not copy database.sqlite into the backup.');
            }
        }
        $files = ['database.sqlite'];
        if (is_file($baseDir . '/.env')) {
            if (!copy($baseDir . '/.env', $workDirectory . '/.env')) {
                throw new RuntimeException('Could not copy .env into the backup.');
            }
            $files[] = '.env';
        }
        $manifest = [
            'format' => 'i18n-api-backup',
            'version' => 1,
            'created_at' => gmdate('c'),
            'files' => $files,
        ];
        file_put_contents($workDirectory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $archive = new PharData($tarPath);
        foreach (['database.sqlite', '.env', 'manifest.json'] as $file) {
            if (is_file($workDirectory . '/' . $file)) {
                $archive->addFile($workDirectory . '/' . $file, $file);
            }
        }
        $archive->compress(Phar::GZ);
        unset($archive);
        if (!is_file($archivePath) || !copy($archivePath, $destination)) {
            throw new RuntimeException('Could not create the compressed backup archive.');
        }
        chmod($destination, 0600);
        return $destination;
    } finally {
        @unlink($tarPath);
        @unlink($archivePath);
        removeBackupTempDirectory($workDirectory);
    }
}

function validateRestoredDatabase(string $databasePath): void
{
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
    if ($integrity !== 'ok') {
        throw new RuntimeException('The backup database failed SQLite integrity_check.');
    }
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['source_messages', 'messages'] as $requiredTable) {
        if (!in_array($requiredTable, $tables, true)) {
            throw new RuntimeException("The backup is missing the '$requiredTable' table.");
        }
    }
}

function extractBackupArchive(string $archivePath, string $extractDirectory): void
{
    if (!class_exists(PharData::class)) {
        throw new RuntimeException('The PHP PharData extension is required for restore.');
    }
    if (!is_dir($extractDirectory) && !mkdir($extractDirectory, 0700, true) && !is_dir($extractDirectory)) {
        throw new RuntimeException('Could not create a temporary extraction directory.');
    }
    $localArchive = $extractDirectory . '/backup.tar.gz';
    if (!copy($archivePath, $localArchive)) {
        throw new RuntimeException('Could not stage the backup archive for extraction.');
    }
    $archive = new PharData($localArchive);
    $tarPath = $localArchive;
    if (str_ends_with(strtolower($localArchive), '.gz')) {
        $tarPath = substr($localArchive, 0, -3);
        if (!is_file($tarPath)) {
            $archive->decompress();
        }
    }
    $tar = new PharData($tarPath);
    $tar->extractTo($extractDirectory, null, true);
}

function restoreBackupArchive(string $baseDir, string $archivePath): array
{
    if (!is_file($archivePath)) {
        throw new RuntimeException('Backup archive was not found.');
    }
    $workDirectory = sys_get_temp_dir() . '/i18n-api-restore-' . bin2hex(random_bytes(8));
    if (!mkdir($workDirectory, 0700, true)) {
        throw new RuntimeException('Could not create a temporary restore directory.');
    }
    $emergencyBackup = null;
    $databaseTemp = $baseDir . '/.database.restore-' . bin2hex(random_bytes(6)) . '.tmp';
    $envTemp = $baseDir . '/.env.restore-' . bin2hex(random_bytes(6)) . '.tmp';

    try {
        extractBackupArchive($archivePath, $workDirectory . '/extracted');
        $extractedDir = $workDirectory . '/extracted';
        $restoredDatabase = $extractedDir . '/database.sqlite';
        if (!is_file($restoredDatabase)) {
            throw new RuntimeException('The backup archive does not contain database.sqlite.');
        }
        validateRestoredDatabase($restoredDatabase);

        if (is_file($baseDir . '/database.sqlite')) {
            $emergencyBackup = createBackupArchive($baseDir);
        }
        if (!copy($restoredDatabase, $databaseTemp)) {
            throw new RuntimeException('Could not stage the restored database.');
        }
        chmod($databaseTemp, 0640);
        if (is_file($extractedDir . '/.env')) {
            if (!copy($extractedDir . '/.env', $envTemp)) {
                throw new RuntimeException('Could not stage the restored environment file.');
            }
            chmod($envTemp, 0600);
        }
        if (!rename($databaseTemp, $baseDir . '/database.sqlite')) {
            throw new RuntimeException('Could not replace database.sqlite.');
        }
        if (is_file($envTemp) && !rename($envTemp, $baseDir . '/.env')) {
            throw new RuntimeException('Database restored, but .env could not be replaced.');
        }
        return [
            'database' => true,
            'environment' => is_file($extractedDir . '/.env'),
            'emergency_backup' => $emergencyBackup,
        ];
    } finally {
        @unlink($databaseTemp);
        @unlink($envTemp);
        removeBackupTempDirectory($workDirectory);
    }
}

function listBackupArchives(string $baseDir): array
{
    $directory = backupDirectory($baseDir);
    $archives = [];
    foreach (glob($directory . '/*.tar.gz') ?: [] as $path) {
        $archives[] = [
            'name' => basename($path),
            'size' => filesize($path) ?: 0,
            'created_at' => date(DATE_ATOM, filemtime($path) ?: time()),
        ];
    }
    usort($archives, static fn(array $left, array $right): int => strcmp($right['created_at'], $left['created_at']));
    return $archives;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $baseDir = __DIR__;
    $action = $argv[1] ?? '--help';
    try {
        if (in_array($action, ['--create', '-c'], true)) {
            $destination = $argv[2] ?? null;
            $path = createBackupArchive($baseDir, $destination);
            echo "Backup created: $path\n";
            exit(0);
        }
        if (in_array($action, ['--restore', '-r'], true)) {
            if (empty($argv[2])) {
                throw new InvalidArgumentException('Usage: php backup.php --restore <backup.tar.gz>');
            }
            $result = restoreBackupArchive($baseDir, $argv[2]);
            echo "Restore completed.\n";
            if ($result['emergency_backup']) {
                echo "Emergency backup: {$result['emergency_backup']}\n";
            }
            exit(0);
        }
        if (in_array($action, ['--list', '-l'], true)) {
            foreach (listBackupArchives($baseDir) as $backup) {
                echo $backup['name'] . ' | ' . $backup['size'] . ' bytes | ' . $backup['created_at'] . "\n";
            }
            exit(0);
        }
        echo "Usage:\n";
        echo "  php backup.php --create [destination.tar.gz]\n";
        echo "  php backup.php --restore <backup.tar.gz>\n";
        echo "  php backup.php --list\n";
        exit($action === '--help' ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: {$e->getMessage()}\n");
        exit(1);
    }
}
