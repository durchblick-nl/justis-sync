<?php
/**
 * Simple Download Script for JUSTIS Magic Sync
 *
 * This script serves the shared history file directly from the web server.
 * TLS terminated by Traefik. Optional API key auth via SYNC_API_KEY env var.
 */

// Optional API key authentication
$apiKey = getenv('SYNC_API_KEY');
if ($apiKey) {
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($apiKey, $provided)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

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
