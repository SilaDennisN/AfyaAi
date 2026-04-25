# Afyanalytics Platform Integration (PHP)

A PHP integration with the Afyanalytics Health Platform using the two-step handshake authentication flow.

---

## Prerequisites

- PHP 7.4 or higher
- cURL extension enabled (`php-curl`)
- HTTPS support for production

---

## Setup Instructions

### 1. Clone the repository

```bash
git clone https://github.com/YOUR_USERNAME/afyanalytics-integration.git
cd afyanalytics-integration
```

### 2. Configure credentials

```bash
cp .env.example .env
```

Edit `.env` and set your credentials (defaults are already filled in for the test assignment):

```env
AFYA_BASE_URL=https://staging.collabmed.net/api/external
AFYA_PLATFORM_NAME=Test Platform v2
AFYA_PLATFORM_KEY=afya_2d00d74512953c933172ab924f5073fa
AFYA_PLATFORM_SECRET=e0502a5c052842cf19d0305455437b791d201761c88e2ad641680b2d5d356ba8
AFYA_CALLBACK_URL=https://your-platform.com/callback
```

### 3. Load environment variables and run

```bash
export $(cat .env | xargs) && php run.php
```

Or on Windows:

```bash
php run.php
```

---

## Project Structure

```
afyanalytics-integration/
├── src/
│   └── AfyaHandshake.php     # Core authentication class
├── storage/                  # Auto-created: stores tokens + logs (gitignored)
├── run.php                   # Entry point — runs the full auth flow
├── .env.example              # Template for credentials
├── .gitignore
└── README.md
```

---

## How the Handshake Flow Works

### Step 1 — Initiate Handshake

The client sends platform credentials to `/initiate-handshake`:

```json
POST /api/external/initiate-handshake
{
  "platform_name": "Test Platform v2",
  "platform_key": "afya_2d00d...",
  "platform_secret": "e0502a5c...",
  "callback_url": "https://your-platform.com/callback"
}
```

The API responds with a `handshake_token` that is **valid for 15 minutes**.

### Step 2 — Complete Handshake

The client immediately uses that token to call `/complete-handshake`:

```json
POST /api/external/complete-handshake
{
  "handshake_token": "xyz789abc...",
  "platform_key": "afya_2d00d..."
}
```

The API responds with a long-lived `access_token` and `refresh_token`.

---

## How Token Expiry Is Handled

| Scenario | Behaviour |
|---|---|
| Valid access token exists in storage | Skips re-authentication, reuses token |
| Access token expired | Automatically re-runs full handshake flow |
| Handshake token expires before Step 2 | Throws a clear error with message |
| API returns 4xx/5xx | Throws `RuntimeException` with the API message |
| Network/cURL failure | Throws `RuntimeException` with cURL error detail |

A **30-second buffer** is applied when checking token validity to avoid race conditions near the expiry boundary.

Tokens are stored locally in `storage/tokens.json` and all activity is logged to `storage/afya.log`.

---

## Example Output

```
╔══════════════════════════════════════════════════╗
║     Afyanalytics Platform - Auth Integration     ║
╚══════════════════════════════════════════════════╝

[2026-04-24 10:00:00] [INFO] === Starting Afyanalytics Authentication Flow ===
[2026-04-24 10:00:00] [INFO] Initiating handshake...
[2026-04-24 10:00:01] [INFO] POST https://staging.collabmed.net/api/external/initiate-handshake
[2026-04-24 10:00:01] [INFO] HTTP Status: 200
[2026-04-24 10:00:01] [INFO] Handshake initiated successfully.
[2026-04-24 10:00:01] [INFO]   Handshake Token : xyz789abc...
[2026-04-24 10:00:01] [INFO]   Expires At      : 2026-04-24T10:15:01+00:00
[2026-04-24 10:00:01] [INFO]   Expires In      : 900 seconds
[2026-04-24 10:00:01] [INFO] Completing handshake...
[2026-04-24 10:00:02] [INFO] Handshake completed successfully!
[2026-04-24 10:00:02] [INFO]   Access Token    : abc123def456...
[2026-04-24 10:00:02] [INFO]   Expires At      : 2026-04-24T16:00:02+00:00
[2026-04-24 10:00:02] [INFO]   Platform        : Test Platform v2

✅ Authentication Successful!
```

---

## Error Handling

```
❌ Authentication Failed!
Error: Handshake initiation failed: Invalid platform credentials
```

All errors are caught and displayed with a clear message. HTTP 4xx/5xx responses, invalid JSON, and network failures are all handled gracefully.
