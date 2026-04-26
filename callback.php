<?php

/**
 * callback.php
 *
 * This is the endpoint Afyanalytics calls after the handshake is initiated.
 * You expose this file via a web server and pass its URL as "callback_url"
 * in the /initiate-handshake request.
 *
 * Local URL  : http://localhost:8000/callback.php
 * Production : https://your-domain.com/callback.php
 */

require_once __DIR__ . '/src/AfyaHandshake.php';

// ─────────────────────────────────────────────
// 1. Always respond with JSON
// ─────────────────────────────────────────────
header('Content-Type: application/json');

// ─────────────────────────────────────────────
// 2. Log the incoming callback immediately
// ─────────────────────────────────────────────
$logDir  = __DIR__ . '/storage';
$logFile = $logDir . '/afya.log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0700, true);
}

$timestamp = date('Y-m-d H:i:s');

file_put_contents($logFile,
    "[{$timestamp}] [CALLBACK] Incoming request from: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . PHP_EOL,
    FILE_APPEND
);

// ─────────────────────────────────────────────
// 3. Read the incoming payload
//    Afyanalytics may send JSON body or query params
// ─────────────────────────────────────────────
$rawBody = file_get_contents('php://input'); 
$body    = json_decode($rawBody, true);

// Also capture any query string params (?handshake_token=xxx)
$queryParams = $_GET;

$payload = array_merge($queryParams, $body ?? []);

file_put_contents($logFile,
    "[{$timestamp}] [CALLBACK] Payload received: " . json_encode($payload) . PHP_EOL,
    FILE_APPEND
);

// ─────────────────────────────────────────────
// 4. Handle the callback data
// ─────────────────────────────────────────────
try {

    // Scenario A: Afyanalytics sends back the handshake_token in the callback
    // and expects YOU to complete the handshake from here
    if (!empty($payload['handshake_token'])) {

        file_put_contents($logFile,
            "[{$timestamp}] [CALLBACK] Handshake token received. Completing handshake..." . PHP_EOL,
            FILE_APPEND
        );

        $afya     = new AfyaHandshake();
        $authData = $afya->completeHandshake($payload['handshake_token']);

        file_put_contents($logFile,
            "[{$timestamp}] [CALLBACK] Handshake completed via callback. Access token saved." . PHP_EOL,
            FILE_APPEND
        );

        echo json_encode([
            'success' => true,
            'message' => 'Callback received and handshake completed.',
            'data'    => [
                'access_token' => $authData['access_token'],
                'expires_at'   => $authData['expires_at'],
                'platform'     => $authData['platform_name'],
            ],
        ]);

    // Scenario B: Afyanalytics sends a status/confirmation ping
    } elseif (!empty($payload['status'])) {

        file_put_contents($logFile,
            "[{$timestamp}] [CALLBACK] Status ping received: " . $payload['status'] . PHP_EOL,
            FILE_APPEND
        );

        echo json_encode([
            'success' => true,
            'message' => 'Status callback acknowledged.',
            'status'  => $payload['status'],
        ]);

    // Scenario C: Empty or unrecognised payload — just acknowledge receipt
    } else {

        file_put_contents($logFile,
            "[{$timestamp}] [CALLBACK] Empty or unrecognised payload — acknowledged." . PHP_EOL,
            FILE_APPEND
        );

        echo json_encode([
            'success' => true,
            'message' => 'Callback received. No action taken.',
            'payload' => $payload,
        ]);
    }

} catch (RuntimeException $e) {

    http_response_code(500);

    file_put_contents($logFile,
        "[{$timestamp}] [CALLBACK] [ERROR] " . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );

    echo json_encode([
        'success' => false,
        'message' => 'Callback processing failed: ' . $e->getMessage(),
    ]);
}