<div align="center">

<br/>

```
  ❄  F L A K E
```

**Product website for FlakeTrader & FlakeSecure**
*by SnowyStudio*

<br/>

[![HTML](https://img.shields.io/badge/HTML5-single--file-e03535?style=flat-square&logo=html5&logoColor=white)](.)
[![PHP](https://img.shields.io/badge/PHP-8.1+-777bb4?style=flat-square&logo=php&logoColor=white)](.)
[![License](https://img.shields.io/badge/license-proprietary-4a4a55?style=flat-square)](.)
[![IONOS](https://img.shields.io/badge/hosting-IONOS-003d8f?style=flat-square)](.)

<br/>

</div>

---

## Overview

Flake is the product hub for [SnowyStudio](https://snowystudio.dev), currently home to two products:

- **FlakeTrader** — a Discord bot for simulated trading on real market data using fictional Flake Coins (₣)
- **FlakeSecure** *(coming soon)* — a password-free authentication system built on a zero-knowledge architecture, consisting of a mobile app and browser extension

The website is a **zero-dependency, single-file HTML application** with client-side routing, a PHP waitlist backend, custom error pages, and a complete set of legal pages.

---

## Table of Contents

- [Features](#features)
- [File Structure](#file-structure)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [Waitlist API](#waitlist-api)
- [FlakeTrader Commands](#flaketrader-commands)
- [Error Pages](#error-pages)
- [Design Tokens](#design-tokens)
- [Security](#security)
- [Legal](#legal)

---

## Features

- **Single-file frontend** — all pages, styles, and scripts in one `index.html` with lightweight client-side routing
- **Animated hero** — typewriter word-rotation effect (`FlakeTrader is ___`)
- **Commands popup** — searchable, categorised slash-command reference, openable via button or `Cmd/Ctrl+K`
- **Waitlist backend** — PHP endpoint that validates, deduplicates, stores, and confirms signups via IONOS SMTP
- **Styled transactional email** — branded HTML confirmation sent to every waitlist subscriber
- **Custom error pages** — 400, 401, 403, 404, 500, 503 — all matching the site design
- **Security hardening** — credentials outside webroot, CORS restriction, directory listing disabled, `.env` access blocked
- **Full legal pages** — Imprint (§5 TMG), Privacy Policy (GDPR), Terms of Use

---

## File Structure

```
/                                   ← server root
│
├── httpdocs/                       ← public webroot
│   ├── index.html                  ← entire frontend (all pages)
│   ├── waitlist.php                ← waitlist API endpoint
│   ├── .htaccess                   ← error routing, security, HTTPS redirect
│   │
│   ├── PHPMailer/                  ← PHPMailer library (install separately)
│   │   └── src/
│   │       ├── PHPMailer.php
│   │       ├── SMTP.php
│   │       └── Exception.php
│   │
│   └── errors/                     ← custom error pages
│       ├── 400.html
│       ├── 401.html
│       ├── 403.html
│       ├── 404.html
│       ├── 500.html
│       └── 503.html
│
└── config/                         ← private — NOT inside webroot
    ├── .env                        ← SMTP credentials
    └── waitlist.csv                ← subscriber list (auto-created on first signup)
```

> **Why `config/` outside the webroot?**
> Placing sensitive files one level above `httpdocs/` makes them completely unreachable via HTTP. See [Security](#security) for the full picture.

---

## Prerequisites

- PHP **8.1+** with `openssl` and `mbstring` extensions (standard on IONOS shared hosting)
- A real IONOS mailbox for the sender address (aliases cannot authenticate via SMTP)
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) — download the latest release manually or via Composer

---

## Installation

### 1. Clone or download

```bash
git clone https://github.com/SchneeherzStudio/flake.snowystudio.dev.git
```

### 2. Install PHPMailer

**Via Composer:**
```bash
composer require phpmailer/phpmailer
```

**Manually:** Download the [latest release](https://github.com/PHPMailer/PHPMailer/releases), extract it, and place the `src/` folder at `httpdocs/PHPMailer/src/`.

### 3. Create the config directory

On IONOS, your webroot is typically:
```
/homepages/XX/dXXXXXXXXX/htdocs/
```

Create the config directory **one level above**:
```bash
mkdir /homepages/XX/dXXXXXXXXX/config
```

### 4. Set up environment variables

```bash
cp env.example config/.env
nano config/.env
```

See [Configuration](#configuration) for all available options.

### 5. Upload to IONOS

Upload the contents of `httpdocs/` to your webroot via FTP or the IONOS FileManager. Upload `config/.env` to the `config/` directory outside the webroot.

### 6. Enable HTTPS redirect *(once SSL is active)*

Uncomment these lines in `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```

---

## Configuration

All sensitive configuration lives in `config/.env`. Never commit this file.

```env
# IONOS mailbox used as the SMTP sender (must be a real mailbox, not an alias)
MAIL_USER=noreply@your-domain.com

# Password for the mailbox above — set in IONOS Control Panel → Email
MAIL_PASS=your-password-here

# From address shown to recipients (usually same as MAIL_USER)
MAIL_FROM=noreply@your-domain.com

# Your personal address — receives a notification on every new signup
MAIL_NOTIFY=you@your-domain.com
```

Additionally, update the following in `waitlist.php`:

```php
$allowed_origin = 'https://deine-domain.de';
```

And replace all instances of `deine-domain.de` in the error pages and footer links.

---

## Waitlist API

`waitlist.php` exposes a single `POST` endpoint that handles the full signup flow.

**Request**

```http
POST /waitlist.php
Content-Type: application/json

{ "email": "user@example.com" }
```

**Responses**

```json
{ "success": true,  "message": "You're on the list! Check your inbox..." }
{ "success": false, "message": "Please enter a valid email address." }
```

**What happens on a successful signup:**

1. Email is validated and sanitised
2. `config/waitlist.csv` is checked for duplicates
3. Entry is appended to the CSV with a timestamp and SHA-256 hashed IP
4. A styled HTML confirmation email is sent to the subscriber
5. A plain-text notification is sent to `MAIL_NOTIFY`

**CSV format** (`config/waitlist.csv`):

```csv
email,timestamp,ip_hash
user@example.com,2025-01-15 14:32:00,a3f2c1...
```

> IP addresses are hashed with SHA-256 before storage and never recorded in plain text, in accordance with GDPR data minimisation principles (Art. 5(1)(c)).

---

## FlakeTrader Commands

The command reference popup is driven by the `COMMANDS` array in the `<script>` block at the bottom of `index.html`. No build step required — edit the array and save.

**Entry structure:**

```js
{
  name:     '/buy',
  category: 'Trading',     // Trading | Portfolio | Market | Leaderboard | Admin
  desc:     'Buy an asset with your Flake Coin balance.',
  params: [
    { name: 'asset',  req: true  },   // required — shown in red
    { name: 'amount', req: false },   // optional — shown in grey
  ]
}
```

**Opening the popup:**

| Trigger | Action |
|---------|--------|
| Button | "View Commands" on the FlakeTrader page |
| Keyboard | `Cmd + K` / `Ctrl + K` from anywhere |
| Close | `Esc` or click the backdrop |

---

## Error Pages

All error pages live in `errors/` and are registered in `.htaccess`:

| File | HTTP Status | Accent |
|------|-------------|--------|
| `400.html` | Bad Request | Red |
| `401.html` | Unauthorized | Red |
| `403.html` | Forbidden | Red |
| `404.html` | Not Found | Red |
| `500.html` | Internal Server Error | Red |
| `503.html` | Service Unavailable | Gold |

Each page shares the same grain overlay, typography, and dark background as the main site, and includes a back-to-home button and a support email link.

---

## Design Tokens

The entire visual language is defined via CSS custom properties:

```css
:root {
  /* Backgrounds */
  --bg:            #0a0a0b;              /* page background         */
  --bg-2:          #111113;              /* footer                  */
  --bg-3:          #161618;              /* inputs                  */
  --bg-card:       #131315;              /* cards                   */

  /* Borders */
  --border:        rgba(255,255,255,0.07);
  --border-bright: rgba(255,255,255,0.14);

  /* Text */
  --text:          #e8e8ec;              /* primary                 */
  --text-muted:    #7a7a85;              /* secondary               */
  --text-dim:      #4a4a55;              /* labels, footnotes       */

  /* Accents */
  --red:           #e03535;              /* CTAs, losses, errors    */
  --green:         #22c55e;              /* gains, success          */
  --gold:          #d4a843;              /* FlakeSecure, coming-soon*/
  --white:         #ffffff;              /* headlines               */
}
```

**Typography:**
- Headlines — [Syne](https://fonts.google.com/specimen/Syne) (700, 800) via Google Fonts
- Body — [DM Sans](https://fonts.google.com/specimen/DM+Sans) (300, 400, 500) via Google Fonts

---

## Security

| Threat | Mitigation |
|--------|-----------|
| Exposed SMTP credentials | Stored in `config/.env` outside the webroot |
| Exposed subscriber list | `waitlist.csv` written to `config/` outside the webroot |
| `.env` accidentally served | `.htaccess` `FilesMatch` rule returns 403 for `.env` files |
| Directory enumeration | `Options -Indexes` disables directory listings |
| Cross-origin abuse of the API | `HTTP_ORIGIN` validated against `$allowed_origin` in `waitlist.php` |
| Plain-text IP logging | IPs are SHA-256 hashed before being written to CSV |
| MIME sniffing | `X-Content-Type-Options: nosniff` header set on PHP responses |

---

## Legal

The following pages are included in `index.html` and linked in the footer:

| Page | Standard | Required action |
|------|----------|-----------------|
| **Imprint** | §5 TMG (Germany) | Replace `[Your Name]` and `[Street Address]` |
| **Privacy Policy** | GDPR (EU) | Review and update contact email addresses |
| **Terms of Use** | — | Review the governing law clause and contact details |

---

## Contributing

This is a private project by SnowyStudio. If you've found a bug or have a suggestion, open an issue or reach out at [legal@snowystudio.dev](mailto:legal@snowystudio.dev).

---

<div align="center">
<br/>

*© 2026 SnowyStudio. All rights reserved.*

</div>
