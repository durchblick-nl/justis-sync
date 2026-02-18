<?php
/**
 * Simple Download Script for JUSTIS Magic Sync
 *
 * This script serves the shared history file directly from the web server.
 * Upload to: https://sync.roger.tips/download.php
 *
 * Security features:
 * - HTTPS enforcement
 */

header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$filename = 'justis_shared_history.dat';
$filepath = '/data/' . $filename;

try {
    // Check if file exists
    if (!file_exists($filepath)) {
        // File doesn't exist yet - this is OK for first sync
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'file not found']);
        exit;
    }

    // Read and return the file content (binary safe)
    $fileContent = file_get_contents($filepath);
    if ($fileContent === false) {
        throw new Exception("Failed to read file");
    }

    // Try to detect format
    $jsonData = json_decode($fileContent, true);
    $isCompressed = ($jsonData === null && json_last_error() !== JSON_ERROR_NONE);

    // Return the raw content with appropriate content type
    if ($isCompressed) {
        header('Content-Type: application/octet-stream');
        error_log("JUSTIS Magic Sync: Serving compressed data (" . strlen($fileContent) . " bytes)");
    } else {
        header('Content-Type: application/json');
        error_log("JUSTIS Magic Sync: Serving JSON data (" . strlen($fileContent) . " bytes)");
    }

    echo $fileContent;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
