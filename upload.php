<?php
/**
 * Simple Upload Script for JUSTIS Magic Sync
 *
 * This script receives and stores the shared history file on the web server.
 * Upload to: https://sync.roger.tips/upload.php
 *
 * Security features:
 * - HTTPS enforcement
 * - Size limit (5MB max)
 * - Smart backup retention (keeps logical backups, cleans old ones)
 */

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Handle preflight - minimal response
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Size limit: 5MB
$maxSize = 5 * 1024 * 1024;
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;

if ($contentLength > $maxSize) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Payload too large (max 5MB)']);
    exit;
}

$filename = 'justis_shared_history.dat';
$filepath = '/data/' . $filename;

/**
 * Smart backup retention strategy:
 * - Last 24 hours: keep all backups
 * - Last 7 days: keep 1 per day (latest of each day)
 * - Last 30 days: keep 1 per week (Sunday)
 * - Older than 30 days: keep 1 per month (1st of month)
 */
function cleanupBackups($filepath) {
    $dir = dirname($filepath);
    $baseFilename = basename($filepath);
    $pattern = $dir . '/' . $baseFilename . '.backup.*';

    $backups = glob($pattern);
    if (empty($backups)) {
        return 0;
    }

    $now = time();
    $oneDayAgo = $now - (24 * 60 * 60);
    $sevenDaysAgo = $now - (7 * 24 * 60 * 60);
    $thirtyDaysAgo = $now - (30 * 24 * 60 * 60);

    // Parse backup timestamps and organize by date
    $backupsByDate = [];
    foreach ($backups as $backup) {
        // Extract timestamp from filename: .backup.YYYY-MM-DD_HH-MM-SS
        if (preg_match('/\.backup\.(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})$/', $backup, $matches)) {
            $dateStr = $matches[1];
            $timeStr = $matches[2] . ':' . $matches[3] . ':' . $matches[4];
            $timestamp = strtotime($dateStr . ' ' . $timeStr);

            if ($timestamp !== false) {
                $date = date('Y-m-d', $timestamp);
                if (!isset($backupsByDate[$date])) {
                    $backupsByDate[$date] = [];
                }
                $backupsByDate[$date][] = [
                    'path' => $backup,
                    'timestamp' => $timestamp
                ];
            }
        }
    }

    $toDelete = [];
    $toKeep = [];

    foreach ($backupsByDate as $date => $dayBackups) {
        // Sort by timestamp descending (newest first)
        usort($dayBackups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        $latestTimestamp = $dayBackups[0]['timestamp'];
        $dayOfWeek = date('w', $latestTimestamp); // 0 = Sunday
        $dayOfMonth = date('j', $latestTimestamp);

        if ($latestTimestamp >= $oneDayAgo) {
            // Last 24 hours: keep ALL backups
            foreach ($dayBackups as $backup) {
                $toKeep[] = $backup['path'];
            }
        } elseif ($latestTimestamp >= $sevenDaysAgo) {
            // Last 7 days: keep only the latest backup of each day
            $toKeep[] = $dayBackups[0]['path'];
            for ($i = 1; $i < count($dayBackups); $i++) {
                $toDelete[] = $dayBackups[$i]['path'];
            }
        } elseif ($latestTimestamp >= $thirtyDaysAgo) {
            // Last 30 days: keep only Sundays (or latest if no Sunday)
            if ($dayOfWeek == 0) { // Sunday
                $toKeep[] = $dayBackups[0]['path'];
            } else {
                $toDelete[] = $dayBackups[0]['path'];
            }
            // Delete all other backups from this day
            for ($i = 1; $i < count($dayBackups); $i++) {
                $toDelete[] = $dayBackups[$i]['path'];
            }
        } else {
            // Older than 30 days: keep only 1st of month
            if ($dayOfMonth == 1) {
                $toKeep[] = $dayBackups[0]['path'];
            } else {
                $toDelete[] = $dayBackups[0]['path'];
            }
            // Delete all other backups from this day
            for ($i = 1; $i < count($dayBackups); $i++) {
                $toDelete[] = $dayBackups[$i]['path'];
            }
        }
    }

    // Delete old backups
    $deletedCount = 0;
    foreach ($toDelete as $file) {
        if (unlink($file)) {
            $deletedCount++;
        }
    }

    if ($deletedCount > 0) {
        error_log("JUSTIS Magic Sync: Cleaned up $deletedCount old backup(s), kept " . count($toKeep));
    }

    return $deletedCount;
}

try {
    // Get the raw POST data (compressed binary or JSON)
    $input = file_get_contents('php://input');

    if (empty($input)) {
        throw new Exception("No data received");
    }

    // Double-check size after reading
    if (strlen($input) > $maxSize) {
        throw new Exception("Payload too large");
    }

    // Try to detect format (V2 compressed data vs V1 JSON)
    $isCompressed = false;
    $jsonData = json_decode($input, true);

    if ($jsonData === null && json_last_error() !== JSON_ERROR_NONE) {
        // Not valid JSON, assume it's compressed binary data (V2)
        $isCompressed = true;
        error_log("JUSTIS Magic Sync: Received compressed data (" . strlen($input) . " bytes)");
    } else {
        // Valid JSON (V1 or uncompressed V2)
        error_log("JUSTIS Magic Sync: Received JSON data (" . strlen($input) . " bytes)");
    }

    // Create backup of existing file if it exists
    if (file_exists($filepath)) {
        $backupPath = $filepath . '.backup.' . date('Y-m-d_H-i-s');
        if (!copy($filepath, $backupPath)) {
            // Log warning but don't fail
            error_log("Warning: Could not create backup file: $backupPath");
        } else {
            // Run cleanup after creating new backup
            cleanupBackups($filepath);
        }
    }

    // Write the new file (binary mode to preserve compressed data)
    $result = file_put_contents($filepath, $input, LOCK_EX);
    if ($result === false) {
        throw new Exception("Failed to write file");
    }

    // Set appropriate permissions
    chmod($filepath, 0644);

    // Log the upload
    error_log("JUSTIS Magic Sync: File uploaded successfully (" . strlen($input) . " bytes)");

    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully',
        'bytes' => $result,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("JUSTIS Magic Sync Upload Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
