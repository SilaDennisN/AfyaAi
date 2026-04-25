<?php

require_once __DIR__ . '/src/AfyaHandshake.php';

echo "╔══════════════════════════════════════════════════╗" . PHP_EOL;
echo "║     Afyanalytics Platform - Auth Integration     ║" . PHP_EOL;
echo "╚══════════════════════════════════════════════════╝" . PHP_EOL;
echo PHP_EOL;

try {
    $afya = new AfyaHandshake();

    // Run the full authentication flow
    $authData = $afya->authenticate();

    echo PHP_EOL;
    echo "✅ Authentication Successful!" . PHP_EOL;
    echo "──────────────────────────────────────────────────" . PHP_EOL;
    echo "Access Token  : " . $authData['access_token'] . PHP_EOL;
    echo "Refresh Token : " . $authData['refresh_token'] . PHP_EOL;
    echo "Expires At    : " . $authData['expires_at'] . PHP_EOL;
    echo "Platform      : " . $authData['platform_name'] . PHP_EOL;
    echo "──────────────────────────────────────────────────" . PHP_EOL;
    echo PHP_EOL;

    // Demo: get a valid token at any time (auto-refreshes if expired)
    echo "Getting valid access token for API usage..." . PHP_EOL;
    $token = $afya->getValidAccessToken();
    echo "Token ready: " . substr($token, 0, 20) . "..." . PHP_EOL;

} catch (RuntimeException $e) {
    echo PHP_EOL;
    echo "❌ Authentication Failed!" . PHP_EOL;
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
