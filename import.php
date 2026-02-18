<?php
/**
 * Import Script for JUSTIS Historical Data (Admin Only)
 *
 * This script provides a password-protected web interface to add historical data.
 * Upload to: https://sync.roger.tips/import.php
 *
 * Security: Password protected - not accessible by the iOS app
 */

session_start();

$adminPassword = getenv('ADMIN_PASSWORD');
if (empty($adminPassword)) {
    http_response_code(503);
    die('Admin panel not configured (ADMIN_PASSWORD env var missing)');
}
define('ADMIN_PASSWORD', $adminPassword);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: import.php');
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals(ADMIN_PASSWORD, $_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['auth_time'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        sleep(1);
        $loginError = 'Falsches Passwort';
    }
}

// Session timeout: 1 hour
if (isset($_SESSION['auth_time']) && (time() - $_SESSION['auth_time']) > 3600) {
    session_destroy();
    header('Location: import.php');
    exit;
}

// Check authentication
$isAuthenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;

// Show login page if not authenticated
if (!$isAuthenticated) {
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>JUSTIS Admin - Login</title>
        <meta name="robots" content="noindex, nofollow">
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
                padding: 20px;
            }
            .login-box {
                background: white;
                padding: 40px;
                border-radius: 15px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                max-width: 400px;
                width: 100%;
                text-align: center;
            }
            h1 { color: #333; margin-bottom: 30px; font-size: 1.5em; }
            .lock-icon { font-size: 3em; margin-bottom: 20px; }
            input[type="password"] {
                width: 100%;
                padding: 15px;
                border: 2px solid #ddd;
                border-radius: 8px;
                font-size: 16px;
                box-sizing: border-box;
                margin-bottom: 20px;
            }
            input[type="password"]:focus {
                border-color: #667eea;
                outline: none;
            }
            button {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                padding: 15px 30px;
                border-radius: 8px;
                font-size: 16px;
                cursor: pointer;
                width: 100%;
            }
            button:hover { opacity: 0.9; }
            .error {
                background: #f8d7da;
                color: #721c24;
                padding: 10px;
                border-radius: 5px;
                margin-bottom: 20px;
            }
        </style>
    </head>
    <body>
        <div class="login-box">
            <div class="lock-icon">🔐</div>
            <h1>JUSTIS Admin</h1>
            <?php if (isset($loginError)): ?>
                <div class="error"><?php echo htmlspecialchars($loginError); ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="password" name="password" placeholder="Passwort eingeben..." required autofocus>
                <button type="submit">Anmelden</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============= AUTHENTICATED SECTION =============

$filename = 'justis_shared_history.dat';
$filepath = '/data/' . $filename;

// Handle AJAX request to load variable data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['load_variable'])) {
    header('Content-Type: application/json');

    try {
        $variable = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['load_variable']);

        if (!file_exists($filepath)) {
            throw new Exception("Keine Datendatei vorhanden");
        }

        $fileContent = file_get_contents($filepath);
        $decompressed = @gzinflate($fileContent);
        $jsonData = json_decode($decompressed !== false ? $decompressed : $fileContent, true);

        if ($jsonData === null || !isset($jsonData['fieldHistory'][$variable])) {
            throw new Exception("Variable '$variable' nicht gefunden");
        }

        $entries = $jsonData['fieldHistory'][$variable];

        // Sort by date
        usort($entries, function($a, $b) {
            $dateA = is_string($a['date']) ? strtotime($a['date']) : $a['date'];
            $dateB = is_string($b['date']) ? strtotime($b['date']) : $b['date'];
            return $dateA <=> $dateB;
        });

        // Format as tab-separated text (DD.MM.YY format)
        $lines = [];
        foreach ($entries as $entry) {
            $timestamp = is_string($entry['date']) ? strtotime($entry['date']) : $entry['date'];
            $dateStr = date('d.m.y', $timestamp);
            $value = is_float($entry['value']) && floor($entry['value']) == $entry['value']
                ? number_format($entry['value'], 0, '', '')
                : $entry['value'];
            $lines[] = "$dateStr\t$value";
        }

        echo json_encode([
            'success' => true,
            'data' => implode("\n", $lines),
            'count' => count($entries)
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle data import (AJAX POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['variable'])) {
    header('Content-Type: application/json');

    try {
        // Validate CSRF token
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            throw new Exception("CSRF validation failed");
        }

        $variable = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['variable'] ?? '');
        $data = $_POST['data'] ?? '';
        $replaceAll = isset($_POST['replace_all']) && $_POST['replace_all'] === '1';

        if (empty($variable) || empty($data)) {
            throw new Exception("Variable and data are required");
        }

        // Load existing data (handle both compressed and JSON formats)
        // V2 format uses Unix timestamps, V1 uses ISO8601 strings
        $fieldHistory = [];
        if (file_exists($filepath)) {
            $fileContent = file_get_contents($filepath);
            if ($fileContent !== false) {
                // Try to decompress first (raw DEFLATE - compatible with iOS COMPRESSION_ZLIB)
                $decompressed = @gzinflate($fileContent);
                $jsonData = json_decode($decompressed !== false ? $decompressed : $fileContent, true);

                if ($jsonData === null) {
                    throw new Exception("Invalid data format in existing file");
                }

                // Extract fieldHistory, converting V1 dates to Unix timestamps if needed
                if (isset($jsonData['fieldHistory'])) {
                    foreach ($jsonData['fieldHistory'] as $field => $entries) {
                        $fieldHistory[$field] = [];
                        foreach ($entries as $entry) {
                            $timestamp = $entry['date'];
                            // Convert ISO8601 string to Unix timestamp if needed
                            if (is_string($timestamp)) {
                                $timestamp = strtotime($timestamp);
                            }
                            $fieldHistory[$field][] = [
                                'date' => (float)$timestamp,
                                'value' => (float)$entry['value']
                            ];
                        }
                    }
                }
            }
        }

        // Initialize field - if replaceAll, clear existing data
        if ($replaceAll || !isset($fieldHistory[$variable])) {
            $fieldHistory[$variable] = [];
        }

        // Parse the input data
        $lines = explode("\n", trim($data));
        $addedCount = 0;
        $updatedCount = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Split by tab or space
            $parts = preg_split('/[\t\s]+/', $line, 2);
            if (count($parts) !== 2) continue;

            $dateStr = trim($parts[0]);
            $value = trim($parts[1]);

            // Convert date to Unix timestamp
            $timestamp = null;

            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2})$/', $dateStr, $matches)) {
                // DD.MM.YY format
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                $year = '20' . $matches[3];
                $timestamp = strtotime("$year-$month-$day 22:00:00 UTC");
            } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $dateStr, $matches)) {
                // DD.MM.YYYY format
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                $year = $matches[3];
                $timestamp = strtotime("$year-$month-$day 22:00:00 UTC");
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
                // YYYY-MM-DD format
                $timestamp = strtotime("$dateStr 22:00:00 UTC");
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $dateStr)) {
                // ISO8601 format
                $timestamp = strtotime($dateStr);
            }

            if ($timestamp === null || $timestamp === false) continue;

            // Validate and convert value
            $cleanValue = str_replace(["'", ",", " "], ["", ".", ""], $value);
            if (is_numeric($cleanValue)) {
                $numericValue = floatval($cleanValue);

                // Check if date already exists
                $exists = false;
                foreach ($fieldHistory[$variable] as &$entry) {
                    if (abs($entry['date'] - $timestamp) < 86400) { // Same day (within 24h)
                        if ($numericValue > $entry['value']) {
                            $entry['value'] = $numericValue;
                            $updatedCount++;
                        }
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $fieldHistory[$variable][] = [
                        'date' => (float)$timestamp,
                        'value' => $numericValue
                    ];
                    $addedCount++;
                }
            }
        }

        // Sort by date (Unix timestamp)
        usort($fieldHistory[$variable], function($a, $b) {
            return $a['date'] <=> $b['date'];
        });

        // Create backup
        if (file_exists($filepath)) {
            $backupPath = $filepath . '.backup.' . date('Y-m-d_H-i-s');
            copy($filepath, $backupPath);
        }

        // Build V2 format with metadata (compatible with iOS ExportDataV2)
        $v2Data = [
            'version' => 2,
            'exportDate' => time(),  // Unix timestamp
            'appVersion' => 'WebImport',
            'fieldHistory' => $fieldHistory
        ];

        // Save as compressed data (raw DEFLATE compatible with iOS COMPRESSION_ZLIB)
        $jsonString = json_encode($v2Data);
        $compressed = gzdeflate($jsonString, 6);
        $result = file_put_contents($filepath, $compressed, LOCK_EX);

        if ($result === false) {
            throw new Exception("Failed to write file");
        }

        chmod($filepath, 0644);

        echo json_encode([
            'success' => true,
            'message' => "Daten für $variable erfolgreich importiert",
            'added' => $addedCount,
            'updated' => $updatedCount,
            'total_entries' => count($fieldHistory[$variable])
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Load existing variables for dropdown
$variables = [];
$fileInfo = '';
if (file_exists($filepath)) {
    $fileContent = file_get_contents($filepath);
    if ($fileContent !== false) {
        // Try raw DEFLATE first (iOS format), then plain JSON
        $decompressed = @gzinflate($fileContent);
        $jsonData = json_decode($decompressed !== false ? $decompressed : $fileContent, true);

        if ($jsonData !== null && isset($jsonData['fieldHistory'])) {
            $variables = array_keys($jsonData['fieldHistory']);
            // Show file info
            $version = $jsonData['version'] ?? 1;
            $entryCount = 0;
            foreach ($jsonData['fieldHistory'] as $entries) {
                $entryCount += count($entries);
            }
            $fileInfo = "V$version Format, " . count($variables) . " Felder, $entryCount Einträge";
        }
    }
}

// Common variable names as options
$commonVariables = ['sum', 'clients', 'today', 'this_month', 'this_week'];
foreach ($commonVariables as $var) {
    if (!in_array($var, $variables)) {
        $variables[] = $var;
    }
}
sort($variables);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JUSTIS Admin - Datenimport</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f7;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .logout-btn {
            background: #ff3b30;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; margin: 0; }
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        select, textarea, input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        textarea {
            height: 200px;
            font-family: monospace;
            resize: vertical;
        }
        button[type="submit"] {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
        }
        button:disabled { background: #ccc; cursor: not-allowed; }
        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
        }
        .btn-secondary:hover { background: #5a6268; }
        .btn-secondary:disabled { background: #ccc; cursor: not-allowed; }
        .variable-row {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .variable-row select { flex: 1; }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            padding: 10px;
            background: #fff3cd;
            border-radius: 8px;
            font-size: 14px;
        }
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        .data-info {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .example {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-family: monospace;
            font-size: 13px;
            border-left: 4px solid #667eea;
        }
        .status {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            display: none;
        }
        .status.success { background: #d4edda; color: #155724; }
        .status.error { background: #f8d7da; color: #721c24; }
        .instructions {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .instructions ol { margin: 10px 0 0 0; padding-left: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 JUSTIS Datenimport</h1>
        <a href="?logout=1" class="logout-btn">Abmelden</a>
    </div>

    <div class="container">
        <?php if ($fileInfo): ?>
        <div class="file-info" style="background: #d4edda; padding: 10px 15px; border-radius: 8px; margin-bottom: 15px; font-size: 14px;">
            <strong>Aktuelle Datei:</strong> <?php echo htmlspecialchars($fileInfo); ?>
        </div>
        <?php endif; ?>

        <div class="instructions">
            <strong>Anleitung:</strong>
            <ol>
                <li>Variable auswählen oder neue eingeben</li>
                <li>Daten im Format <code>DATUM[TAB]WERT</code> eingeben</li>
                <li>Datumsformate: DD.MM.YY, DD.MM.YYYY, oder YYYY-MM-DD</li>
            </ol>
        </div>

        <form id="importForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="form-group">
                <label for="variable">Variable:</label>
                <div class="variable-row">
                    <select id="variable" name="variable" onchange="toggleCustomVariable()" required>
                        <option value="">-- Variable wählen --</option>
                        <?php foreach ($variables as $var): ?>
                            <option value="<?php echo htmlspecialchars($var); ?>"><?php echo htmlspecialchars($var); ?></option>
                        <?php endforeach; ?>
                        <option value="__custom__">-- Neue Variable --</option>
                    </select>
                    <button type="button" class="btn-secondary" id="loadBtn" onclick="loadVariableData()">📥 Laden</button>
                </div>
            </div>

            <div class="form-group" id="customVariableGroup" style="display: none;">
                <label for="customVariable">Neue Variable:</label>
                <input type="text" id="customVariable" name="customVariable" placeholder="Variablenname...">
            </div>

            <div class="form-group">
                <label for="data">Historische Daten:</label>
                <textarea id="data" name="data" placeholder="Daten eingeben oder mit 'Laden' bestehende Daten abrufen..." required></textarea>
                <div id="dataInfo" class="data-info" style="display: none;"></div>
                <div class="checkbox-group" id="replaceGroup" style="display: none;">
                    <input type="checkbox" id="replaceAll" name="replace_all" value="1">
                    <label for="replaceAll" style="margin: 0; font-weight: normal;">
                        <strong>Ersetzen:</strong> Bestehende Daten für diese Variable komplett ersetzen (statt zusammenführen)
                    </label>
                </div>
                <div class="example">
                    <strong>Beispiel:</strong><br>
                    21.09.25	7481<br>
                    22.09.25	7495<br>
                    23.09.25	7502
                </div>
            </div>

            <button type="submit" id="submitBtn">Daten importieren</button>
        </form>

        <div id="status" class="status"></div>
    </div>

    <script>
        function toggleCustomVariable() {
            const select = document.getElementById('variable');
            const customGroup = document.getElementById('customVariableGroup');
            const isCustom = select.value === '__custom__';
            customGroup.style.display = isCustom ? 'block' : 'none';

            // Hide replace checkbox for custom variables
            if (isCustom) {
                document.getElementById('replaceGroup').style.display = 'none';
                document.getElementById('dataInfo').style.display = 'none';
            }
        }

        async function loadVariableData() {
            const select = document.getElementById('variable');
            const loadBtn = document.getElementById('loadBtn');
            const dataTextarea = document.getElementById('data');
            const dataInfo = document.getElementById('dataInfo');
            const replaceGroup = document.getElementById('replaceGroup');
            const status = document.getElementById('status');

            if (!select.value || select.value === '__custom__') {
                alert('Bitte zuerst eine Variable auswählen');
                return;
            }

            loadBtn.disabled = true;
            loadBtn.textContent = '⏳ Lade...';
            status.style.display = 'none';

            try {
                const response = await fetch(`import.php?load_variable=${encodeURIComponent(select.value)}`);
                const result = await response.json();

                if (result.success) {
                    dataTextarea.value = result.data;
                    dataInfo.textContent = `✅ ${result.count} Einträge geladen für "${select.value}"`;
                    dataInfo.style.display = 'block';
                    dataInfo.style.color = '#155724';

                    // Show replace checkbox when data is loaded
                    replaceGroup.style.display = 'flex';
                    document.getElementById('replaceAll').checked = true; // Default to replace when editing

                    status.className = 'status success';
                    status.innerHTML = `<strong>📥 Daten geladen!</strong><br>
                        ${result.count} Einträge für "${select.value}" wurden in das Textfeld geladen.<br>
                        <em>Bearbeiten Sie die Werte und klicken Sie auf "Daten importieren" zum Speichern.</em>`;
                    status.style.display = 'block';
                } else {
                    throw new Error(result.error);
                }
            } catch (error) {
                dataInfo.textContent = `❌ ${error.message}`;
                dataInfo.style.display = 'block';
                dataInfo.style.color = '#721c24';
                replaceGroup.style.display = 'none';
            }

            loadBtn.disabled = false;
            loadBtn.textContent = '📥 Laden';
        }

        document.getElementById('importForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const submitBtn = document.getElementById('submitBtn');
            const status = document.getElementById('status');
            const formData = new FormData(this);

            const variableSelect = document.getElementById('variable');
            if (variableSelect.value === '__custom__') {
                const customVar = document.getElementById('customVariable').value.trim();
                if (!customVar) {
                    alert('Bitte Variablennamen eingeben');
                    return;
                }
                formData.set('variable', customVar);
            }

            // Add replace_all checkbox value
            const replaceAll = document.getElementById('replaceAll');
            if (replaceAll.checked) {
                formData.set('replace_all', '1');
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Importiere...';

            try {
                const response = await fetch('import.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    const action = replaceAll.checked ? 'ersetzt' : 'zusammengeführt';
                    status.className = 'status success';
                    status.textContent = '';
                    const b = document.createElement('strong');
                    b.textContent = 'Erfolg! ' + result.message;
                    status.appendChild(b);
                    status.appendChild(document.createElement('br'));
                    status.appendChild(document.createTextNode(
                        `Hinzugefügt: ${result.added} | Aktualisiert: ${result.updated} | Gesamt: ${result.total_entries} (${action})`
                    ));
                    document.getElementById('data').value = '';
                    document.getElementById('dataInfo').style.display = 'none';
                    document.getElementById('replaceGroup').style.display = 'none';
                    document.getElementById('replaceAll').checked = false;
                } else {
                    throw new Error(result.error);
                }
            } catch (error) {
                status.className = 'status error';
                status.innerHTML = `<strong>❌ Fehler:</strong> ${error.message}`;
            }

            status.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Daten importieren';
        });
    </script>
</body>
</html>
