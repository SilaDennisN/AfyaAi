<?php

class AfyaHandshake
{
    private string $baseUrl;
    private string $platformName;
    private string $platformKey;
    private string $platformSecret;
    private string $callbackUrl;
    private string $tokenFile;

    // Encryption algorithm — AES 256-bit in CBC mode
    private const CIPHER = 'aes-256-cbc';

    public function __construct()
    {
        $this->loadEnv();

        $this->baseUrl        = getenv('AFYA_BASE_URL');
        $this->platformName   = getenv('AFYA_PLATFORM_NAME');
        $this->platformKey    = getenv('AFYA_PLATFORM_KEY');
        $this->platformSecret = getenv('AFYA_PLATFORM_SECRET');
        $this->callbackUrl    = getenv('AFYA_CALLBACK_URL');

        // .enc extension signals the file is encrypted (not plain JSON)
        $this->tokenFile      = __DIR__ . '/../storage/tokens.enc';

        $this->validateCredentials();
    }

    // -------------------------------------------------------------------------
    // ENV LOADER
    // -------------------------------------------------------------------------
    private function loadEnv(): void
    {
        $envFile = __DIR__ . '/../.env';

        if (!file_exists($envFile)) {
            return;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) {
                $value = $m[2];
            }

            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    // -------------------------------------------------------------------------
    // CREDENTIAL VALIDATOR — Fails fast if anything is missing
    // -------------------------------------------------------------------------
    private function validateCredentials(): void
    {
        $required = [
            'AFYA_BASE_URL'        => $this->baseUrl,
            'AFYA_PLATFORM_NAME'   => $this->platformName,
            'AFYA_PLATFORM_KEY'    => $this->platformKey,
            'AFYA_PLATFORM_SECRET' => $this->platformSecret,
            'AFYA_CALLBACK_URL'    => $this->callbackUrl,
        ];

        foreach ($required as $name => $value) {
            if (empty($value)) {
                throw new RuntimeException(
                    "Missing required credential: {$name}. Check your .env file."
                );
            }
        }
    }

    // =========================================================================
    // SECURE STORAGE — AES-256-CBC Encryption
    // =========================================================================

    /**
     * Derives a 256-bit encryption key from the platform secret.
     * SHA-256 ensures the key is always exactly 32 bytes.
     */
    private function getEncryptionKey(): string
    {
        return hash('sha256', $this->platformSecret, true);
    }

    /**
     * Encrypts plaintext using AES-256-CBC.
     *
     * Steps:
     * 1. Generate a random IV — makes every encryption unique
     * 2. Encrypt the data with the key + IV
     * 3. Compute HMAC of IV+ciphertext — detects tampering
     * 4. Return base64(hmac + iv + ciphertext)
     */
    private function encrypt(string $plaintext): string
    {
        $key   = $this->getEncryptionKey();
        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $iv    = openssl_random_pseudo_bytes($ivLen); // cryptographically secure random IV

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // HMAC-SHA256 proves the data hasn't been tampered with
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        // Pack: hmac(32 bytes) + iv(16 bytes) + ciphertext
        return base64_encode($hmac . $iv . $ciphertext);
    }

    /**
     * Decrypts data encrypted by encrypt().
     *
     * Steps:
     * 1. Base64 decode
     * 2. Split out HMAC, IV, ciphertext
     * 3. Verify HMAC — rejects tampered data
     * 4. Decrypt with key + IV
     */
    private function decrypt(string $encoded): string
    {
        $key   = $this->getEncryptionKey();
        $raw   = base64_decode($encoded);
        $ivLen = openssl_cipher_iv_length(self::CIPHER);

        $hmac       = substr($raw, 0, 32);
        $iv         = substr($raw, 32, $ivLen);
        $ciphertext = substr($raw, 32 + $ivLen);

        // hash_equals prevents timing attacks during comparison
        $expectedHmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        if (!hash_equals($expectedHmac, $hmac)) {
            throw new RuntimeException('Token storage integrity check failed. File may have been tampered with.');
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: ' . openssl_error_string());
        }

        return $plaintext;
    }

    /**
     * Saves token data to encrypted storage.
     *
     * Security:
     * - AES-256 encrypted before writing
     * - storage/ directory: chmod 0700 (owner only)
     * - tokens.enc file:    chmod 0600 (owner read/write only)
     * - LOCK_EX prevents race conditions during write
     */
    private function saveToStorage(string $key, array $data): void
    {
        $dir = dirname($this->tokenFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        chmod($dir, 0700);

        // Load and decrypt existing data
        $existing = [];
        if (file_exists($this->tokenFile)) {
            try {
                $existing = json_decode($this->decrypt(file_get_contents($this->tokenFile)), true) ?? [];
            } catch (RuntimeException $e) {
                $this->log('Existing storage unreadable — starting fresh.', 'WARN');
                $existing = [];
            }
        }

        $existing[$key] = $data;

        // Encrypt and write with exclusive lock
        file_put_contents($this->tokenFile, $this->encrypt(json_encode($existing)), LOCK_EX);

        // Restrict to owner read/write only
        chmod($this->tokenFile, 0600);

        $this->log("Tokens saved to encrypted storage.");
    }

    /**
     * Loads and decrypts token data from storage.
     */
    private function loadFromStorage(string $key): ?array
    {
        if (!file_exists($this->tokenFile)) {
            return null;
        }

        try {
            $data = json_decode($this->decrypt(file_get_contents($this->tokenFile)), true);
            return $data[$key] ?? null;
        } catch (RuntimeException $e) {
            $this->log('Could not read from storage: ' . $e->getMessage(), 'WARN');
            return null;
        }
    }

    // =========================================================================
    // STEP 1 — Initiate Handshake
    // =========================================================================
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
        $this->log("  Handshake Token : " . $this->maskToken($data['handshake_token']));
        $this->log("  Expires At      : " . $data['expires_at']);
        $this->log("  Expires In      : " . $data['expires_in_seconds'] . " seconds");

        $this->saveToStorage('handshake', [
            'handshake_token'    => $data['handshake_token'],
            'expires_at'         => $data['expires_at'],
            'expires_in_seconds' => $data['expires_in_seconds'],
            'initiated_at'       => date('c'),
        ]);

        return $data;
    }

    // =========================================================================
    // STEP 2 — Complete Handshake
    // =========================================================================
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
        $this->log("  Access Token    : " . $this->maskToken($data['access_token']));
        $this->log("  Refresh Token   : " . $this->maskToken($data['refresh_token']));
        $this->log("  Token Type      : " . $data['token_type']);
        $this->log("  Expires At      : " . $data['expires_at']);
        $this->log("  Platform        : " . $data['platform_name']);

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

    // =========================================================================
    // FULL FLOW — Initiate + Complete in one call
    // =========================================================================
    public function authenticate(): array
    {
        $this->log("=== Starting Afyanalytics Authentication Flow ===");

        $stored = $this->loadFromStorage('auth');
        if ($stored && $this->isTokenValid($stored['expires_at'])) {
            $this->log("Valid token found in secure storage. Skipping re-authentication.");
            return $stored;
        }

        $handshakeData = $this->initiateHandshake();

        if (!$this->isTokenValid($handshakeData['expires_at'])) {
            throw new RuntimeException("Handshake token expired immediately. Check server clock sync.");
        }

        $authData = $this->completeHandshake($handshakeData['handshake_token']);

        $this->log("=== Authentication Complete ===");

        return $authData;
    }

    // =========================================================================
    // TOKEN HELPERS
    // =========================================================================

    public function isTokenValid(string $expiresAt): bool
    {
        return strtotime($expiresAt) > (time() + 30);
    }

    public function getValidAccessToken(): string
    {
        $stored = $this->loadFromStorage('auth');

        if (!$stored) {
            $this->log("No stored token found. Re-authenticating...");
            return $this->authenticate()['access_token'];
        }

        if (!$this->isTokenValid($stored['expires_at'])) {
            $this->log("Access token expired. Re-authenticating...");
            return $this->authenticate()['access_token'];
        }

        return $stored['access_token'];
    }

    /**
     * Masks a token for safe logging.
     * "G2WAEeNRIqZn..." becomes "G2WAEeNR...LJr"
     * Prevents full tokens from ever appearing in log files.
     */
    private function maskToken(string $token): string
    {
        if (strlen($token) <= 12) {
            return str_repeat('*', strlen($token));
        }
        return substr($token, 0, 8) . '...' . substr($token, -4);
    }

    // =========================================================================
    // HTTP CLIENT — cURL wrapper
    // =========================================================================
    private function post(string $endpoint, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . $endpoint;

        $this->log("POST {$url}");

        // Mask secret in log — never log the real secret
        $safePayload = $payload;
        if (isset($safePayload['platform_secret'])) {
            $safePayload['platform_secret'] = $this->maskToken($safePayload['platform_secret']);
        }
        $this->log("Payload: " . json_encode($safePayload, JSON_PRETTY_PRINT));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload), // real payload (unmasked) sent to API
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

        if ($httpCode >= 400) {
            $message = $decoded['message'] ?? "HTTP {$httpCode} error";
            return ['success' => false, 'message' => $message, 'data' => []];
        }

        return $decoded;
    }

    // =========================================================================
    // LOGGER
    // =========================================================================
    private function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line      = "[{$timestamp}] [{$level}] {$message}";
        echo $line . PHP_EOL;

        $logDir  = __DIR__ . '/../storage';
        $logFile = $logDir . '/afya.log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0700, true);
        }

        file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Lock down log file too — logs can contain sensitive info
        if (file_exists($logFile)) {
            chmod($logFile, 0600);
        }
    }
}