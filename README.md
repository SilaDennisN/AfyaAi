# Afyanalytics Platform Integration (PHP)

A PHP integration with the Afyanalytics Health Platform using a secure two-step handshake authentication flow, encrypted token storage, and a callback endpoint.

---

## Prerequisites

- PHP 7.4 or higher
- cURL extension enabled (`php-curl`)
- OpenSSL extension enabled (`php-openssl`)
- HTTPS support for production

---

## Setup Instructions

### 1. Clone the repository

```bash
git clone https://github.com/SilaDennisN/AfyaAi.git
cd AfyaAi
```

### 2. Configure credentials

```bash
cp .env.example .env
```

Edit `.env` with your credentials:

```env
AFYA_BASE_URL=https://staging.collabmed.net/api/external
AFYA_PLATFORM_NAME=Test Platform v2
AFYA_PLATFORM_KEY=afya_2d00d74512953c933172ab924f5073fa
AFYA_PLATFORM_SECRET=e0502a5c052842cf19d0305455437b791d201761c88e2ad641680b2d5d356ba8
AFYA_CALLBACK_URL=http://localhost:8000/callback.php
```

### 3. Run the authentication flow

```bash
export $(cat .env | xargs) && php run.php
```

Or on Windows (credentials are already defaulted in the class):

```bash
php run.php
```

### 4. (Optional) Start the callback server

Open a second terminal and run:

```bash
php -S localhost:8000
```

This exposes `callback.php` at `http://localhost:8000/callback.php` so Afyanalytics can send notifications back to your platform.

---

## Project Structure

```
AfyaAi/
├── src/
│   └── AfyaHandshake.php     # Core authentication class
├── storage/                  # Auto-created at runtime (gitignored)
│   ├── tokens.enc            # Encrypted token storage (AES-256-CBC)
│   └── afya.log              # Activity log (owner-only permissions)
├── callback.php              # Webhook endpoint for Afyanalytics callbacks
├── run.php                   # Entry point — runs the full auth flow
├── .env.example              # Credentials template
├── .gitignore
└── README.md
```

---

## How the Handshake Flow Works

The authentication uses a two-step handshake before your platform can access the API.

```
Your Platform                  Afyanalytics API
     |                                |
     |--- POST /initiate-handshake -->|
     |                                |
     |<-- handshake_token (15 min) ---|
     |                                |
     |--- POST /complete-handshake -->|
     |                                |
     |<-- access_token + refresh_token|
```

### Step 1 — Initiate Handshake

```json
POST /api/external/initiate-handshake
{
  "platform_name": "Test Platform v2",
  "platform_key": "afya_2d00d...",
  "platform_secret": "e0502a5c...",
  "callback_url": "http://localhost:8000/callback.php"
}
```

The API responds with a `handshake_token` valid for **15 minutes**.

### Step 2 — Complete Handshake

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
| Valid access token in storage | Skips re-authentication, reuses token |
| Access token expired | Automatically re-runs the full handshake flow |
| Handshake token expires before Step 2 | Throws a clear error message |
| API returns 4xx/5xx | Throws `RuntimeException` with the API error message |
| Network/cURL failure | Throws `RuntimeException` with the cURL error detail |

A **30-second buffer** is applied when checking token validity to prevent race conditions near the expiry boundary.

---

## How Secure Storage Works

Tokens are never stored in plain text. The storage system uses:

- **AES-256-CBC encryption** — tokens are encrypted before being written to disk
- **Encryption key** derived from your `platform_secret` using SHA-256
- **HMAC-SHA256 integrity check** — detects if the file has been tampered with
- **File permissions** — `storage/` directory is `chmod 0700`, `tokens.enc` is `chmod 0600` (owner read/write only)
- **Masked logs** — tokens and secrets are partially masked in `afya.log` (e.g. `G2WAEeNR...LJr`)

If the storage file is corrupted or tampered with, the system resets gracefully and re-authenticates.

---

## How the Callback Works

`callback.php` is a webhook endpoint that Afyanalytics calls after processing your handshake request. It handles three scenarios:

| Incoming payload | Action |
|---|---|
| Contains `handshake_token` | Automatically calls `completeHandshake()` |
| Contains `status` | Acknowledges the status ping |
| Empty / unknown | Acknowledges receipt gracefully |

All incoming requests are logged to `storage/afya.log`.

For production, deploy `callback.php` behind a real domain and set `AFYA_CALLBACK_URL` accordingly. For local testing, use [ngrok](https://ngrok.com): `ngrok http 8000`.

---

## Example Output

```
╔══════════════════════════════════════════════════╗
║     Afyanalytics Platform - Auth Integration     ║
╚══════════════════════════════════════════════════╝

[2026-04-24 14:06:00] [INFO] === Starting Afyanalytics Authentication Flow ===
[2026-04-24 14:06:00] [INFO] Initiating handshake...
[2026-04-24 14:06:00] [INFO] POST https://staging.collabmed.net/api/external/initiate-handshake
[2026-04-24 14:06:01] [INFO] HTTP Status: 200
[2026-04-24 14:06:01] [INFO] Handshake initiated successfully.
[2026-04-24 14:06:01] [INFO]   Handshake Token : bjOFi1rK...AK82
[2026-04-24 14:06:01] [INFO]   Expires At      : 2026-04-24T17:21:00+03:00
[2026-04-24 14:06:01] [INFO]   Expires In      : 900 seconds
[2026-04-24 14:06:01] [INFO] Completing handshake...
[2026-04-24 14:06:01] [INFO] HTTP Status: 200
[2026-04-24 14:06:01] [INFO] Handshake completed successfully!
[2026-04-24 14:06:01] [INFO]   Access Token    : G2WAEeNR...NLJr
[2026-04-24 14:06:01] [INFO]   Expires At      : 2026-04-24T23:06:01+03:00
[2026-04-24 14:06:01] [INFO]   Platform        : Test Platform v2
[2026-04-24 14:06:01] [INFO] Tokens saved to encrypted storage.
[2026-04-24 14:06:01] [INFO] === Authentication Complete ===

Authentication Successful!
```

---

## Error Handling

```
Authentication Failed!
Error: Handshake initiation failed: Invalid platform credentials
```

All errors surface with a clear message. HTTP 4xx/5xx, invalid JSON, network failures, tampered storage, and missing credentials are all handled gracefully.