<?php
/**
 * Index/Redirect Page for JUSTIS Sync Subdomain
 * 
 * This file provides a simple redirect to the main website
 * and displays basic information about the sync service.
 * 
 * Upload to: https://sync.roger.tips/index.php
 */

// Set headers for proper caching and content type
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour

// Check if we should redirect immediately (for automated requests)
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isBot = stripos($userAgent, 'bot') !== false || 
         stripos($userAgent, 'crawler') !== false ||
         stripos($userAgent, 'spider') !== false;

// Redirect bots immediately
if ($isBot) {
    header('Location: https://roger.tips', true, 301);
    exit;
}

// For humans, show a brief page with auto-redirect
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JUSTIS Sync Service</title>
    <meta name="description" content="JUSTIS Status App Sync Service - Automatische Weiterleitung zu roger.tips">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Auto-redirect after 5 seconds -->
    <meta http-equiv="refresh" content="5;url=https://roger.tips">
    
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 40px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            text-align: center;
            max-width: 500px;
            background: rgba(255, 255, 255, 0.1);
            padding: 40px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        h1 {
            margin: 0 0 20px 0;
            font-size: 2.5em;
            font-weight: 300;
        }
        
        .logo {
            font-size: 4em;
            margin-bottom: 20px;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.2));
        }
        
        p {
            margin: 15px 0;
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .redirect-info {
            background: rgba(255, 255, 255, 0.15);
            padding: 20px;
            border-radius: 10px;
            margin: 30px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: 25px;
            transition: all 0.3s ease;
            margin: 10px;
        }
        
        .btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .status {
            margin-top: 30px;
            font-size: 0.9em;
            opacity: 0.7;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .sync-indicator {
            animation: pulse 2s infinite;
            font-size: 1.2em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">🔄</div>
        <h1>JUSTIS Sync Service</h1>
        
        <p>Diese Subdomain hostet den Synchronisations-Service für die JUSTIS Status App.</p>
        
        <div class="redirect-info">
            <p class="sync-indicator">Sie werden automatisch weitergeleitet...</p>
            <p>Weiterleitung zu <strong>roger.tips</strong> in <span id="countdown">5</span> Sekunden</p>
        </div>
        
        <a href="https://roger.tips" class="btn">Sofort weiterleiten</a>
        
        <div class="status">
            <p>🔒 HTTPS Secure | ⚡ Active Service | 📱 iOS App Support</p>
        </div>
    </div>

    <script>
        // Countdown timer
        let countdown = 5;
        const countdownElement = document.getElementById('countdown');
        
        const timer = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                window.location.href = 'https://roger.tips';
            }
        }, 1000);
        
        // Immediate redirect if JavaScript is disabled
        setTimeout(() => {
            window.location.href = 'https://roger.tips';
        }, 5000);
    </script>
</body>
</html>