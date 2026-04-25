<?php

class AfyaHandshake
{
    private string $baseUrl;
    private string $platformName;
    private string $platformKey;
    private string $platformSecret;
    private string $callbackUrl;
    private string $tokenFile;

    public function __construct()
    {
        $this->baseUrl       = getenv('AFYA_BASE_URL');
        $this->platformName  = getenv('AFYA_PLATFORM_NAME');
        $this->platformKey   = getenv('AFYA_PLATFORM_KEY');
        $this->platformSecret= getenv('AFYA_PLATFORM_SECRET');
        $this->callbackUrl   = getenv('AFYA_CALLBACK_URL');
        $this->tokenFile     = __DIR__ . '/../storage/tokens.json';
    }

    // -------------------------------------------------------------------------
    // STEP 1 — Initiate Handshake
    // -------------------------------------------------------------------------
    public function initiateHandshake(): array
    {
        $this->log("Initiating handshake...");

        $payload = [
            'platform_name'   => $this->platformName,
            'platform_key'    => $this->platformKey,
            'platform_secret' => $this->platformSecret,
            'callback_url'    => $this->callbackUrl,
        ];

        $response = $this->post('/initiate-handshake', $payload);

        if (!$response['success']) {
            $this->log("Handshake initiation FAILED: " . $response['message'], 'ERROR');
            throw new RuntimeException("Handshake initiation failed: " . $response['message']);
        }

        $data = $response['data'];
        $this->log("Handshake initiated successfully.");
        $this->log("  Handshake Token : " . $data['handshake_token']);
        $this->log("  Expires At      : " . $data['expires_at']);
        $this->log("  Expires In      : " . $data['expires_in_seconds'] . " seconds");

        // Save handshake token temporarily
        $this->saveToStorage('handshake', [
            'handshake_token'    => $data['handshake_token'],
            'expires_at'         => $data['expires_at'],
            'expires_in_seconds' => $data['expires_in_seconds'],
            'initiated_at'       => date('c'),
        ]);

        return $data;
    }

    // -------------------------------------------------------------------------
    // STEP 2 — Complete Handshake
    // -------------------------------------------------------------------------
    public function completeHandshake(string $handshakeToken): array
    {
        $this->log("Completing handshake...");

        $payload = [
            'handshake_token' => $handshakeToken,
            'platform_key'    => $this->platformKey,
        ];

        $response = $this->post('/complete-handshake', $payload);

        if (!$response['success']) {
            $this->log("Handshake completion FAILED: " . $response['message'], 'ERROR');
            throw new RuntimeException("Handshake completion failed: " . $response['message']);
        }

        $data = $response['data'];
        $this->log("Handshake completed successfully!");
        $this->log("  Access Token    : " . $data['access_token']);
        $this->log("  Refresh Token   : " . $data['refresh_token']);
        $this->log("  Token Type      : " . $data['token_type']);
        $this->log("  Expires At      : " . $data['expires_at']);
        $this->log("  Platform        : " . $data['platform_name']);

        // Save access tokens to storage
        $this->saveToStorage('auth', [
            'access_token'       => $data['access_token'],
            'refresh_token'      => $data['refresh_token'],
            'token_type'         => $data['token_type'],
            'expires_at'         => $data['expires_at'],
            'expires_in_seconds' => $data['expires_in_seconds'],
            'platform_name'      => $data['platform_name'],
            'authenticated_at'   => date('c'),
        ]);

        return $data;
    }

    // -------------------------------------------------------------------------
    // FULL FLOW — Initiate + Complete in one call
    // -------------------------------------------------------------------------
    public function authenticate(): array
    {
        $this->log("=== Starting Afyanalytics Authentication Flow ===");

        // Check if we already have a valid access token
        $stored = $this->loadFromStorage('auth');
        if ($stored && $this->isTokenValid($stored['expires_at'])) {
            $this->log("Valid access token found in storage. Skipping re-authentication.");
            return $stored;
        }

        // Step 1
        $handshakeData = $this->initiateHandshake();

        // Guard: check token hasn't already expired (edge case)
        if (!$this->isTokenValid($handshakeData['expires_at'])) {
            throw new RuntimeException("Handshake token expired immediately. Check server clock sync.");
        }

        // Step 2
        $authData = $this->completeHandshake($handshakeData['handshake_token']);

        $this->log("=== Authentication Complete ===");

        return $authData;
    }

    // -------------------------------------------------------------------------
    // TOKEN HELPERS
    // -------------------------------------------------------------------------

    public function isTokenValid(string $expiresAt): bool
    {
        // Add a 30-second buffer to avoid edge cases
        return strtotime($expiresAt) > (time() + 30);
    }

    public function getValidAccessToken(): string
    {
        $stored = $this->loadFromStorage('auth');

        if (!$stored) {
            $this->log("No stored token found. Re-authenticating...");
            $auth = $this->authenticate();
            return $auth['access_token'];
        }

        if (!$this->isTokenValid($stored['expires_at'])) {
            $this->log("Access token expired. Re-authenticating...");
            $auth = $this->authenticate();
            return $auth['access_token'];
        }

        return $stored['access_token'];
    }

    // -------------------------------------------------------------------------
    // STORAGE — Save/Load tokens to a local JSON file
    // -------------------------------------------------------------------------

    private function saveToStorage(string $key, array $data): void
    {
        $dir = dirname($this->tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $existing = [];
        if (file_exists($this->tokenFile)) {
            $existing = json_decode(file_get_contents($this->tokenFile), true) ?? [];
        }

        $existing[$key] = $data;
        file_put_contents($this->tokenFile, json_encode($existing, JSON_PRETTY_PRINT));
    }

    private function loadFromStorage(string $key): ?array
    {
        if (!file_exists($this->tokenFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($this->tokenFile), true);
        return $data[$key] ?? null;
    }

    // -------------------------------------------------------------------------
    // HTTP CLIENT — cURL wrapper
    // -------------------------------------------------------------------------

    private function post(string $endpoint, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . $endpoint;

        $this->log("POST {$url}");
        $this->log("Payload: " . json_encode($payload, JSON_PRETTY_PRINT));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("cURL error: {$error}");
        }

        $this->log("HTTP Status: {$httpCode}");
        $this->log("Response: {$raw}");

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON response from API: {$raw}");
        }

        // Treat 4xx/5xx as failures
        if ($httpCode >= 400) {
            $message = $decoded['message'] ?? "HTTP {$httpCode} error";
            return ['success' => false, 'message' => $message, 'data' => []];
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // LOGGER
    // -------------------------------------------------------------------------

    private function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line      = "[{$timestamp}] [{$level}] {$message}";
        echo $line . PHP_EOL;

        // Also write to log file
        $logDir = __DIR__ . '/../storage';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0700, true);
        }
        file_put_contents($logDir . '/afya.log', $line . PHP_EOL, FILE_APPEND);
    }
}
