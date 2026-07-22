# GI Stats

A personal GI health tracker built around the Bristol Stool Scale.

---

## Overview

Log bowel movements, view trends on a calendar, track statistics over time, and export your data as CSV. Sign in and open the Admin page to import, export, back up, or restore your data, or visit `/export` directly for a quick CSV download. The download includes UTC timestamps, local timestamps, duration in seconds, Bristol stool type, and notes.

### Project structure

```text
├── index.php          # Entry point: routes and render() helper
├── config.php         # Runtime configuration
├── router.php         # Simple pattern-matching router
├── server.php         # PHP built-in server router (static file passthrough)
├── lib/
│   ├── db.php         # PDO/SQLite wrapper (singleton)
│   ├── helpers.php    # Pure functions: timezone, duration, calendar, stats
│   ├── entries.php    # Entry CRUD
│   ├── auth.php       # Session auth, account setup, password change
│   ├── backup.php     # Database backup and restore
│   └── csrf.php       # CSRF token helpers
├── views/
│   ├── layout.php     # HTML shell with nav drawer
│   ├── home.php       # Entry form + calendar + event list
│   ├── login.php      # Sign-in form
│   ├── setup.php      # One-time account creation form
│   ├── admin.php      # Password change, import/export, backup, restore
│   ├── stats.php      # Statistics with Chart.js
│   ├── about.php      # About page
│   ├── 404.php        # Standalone 404
│   └── partials/      # HTMX-targeted fragments
├── css/
│   ├── input.css      # Tailwind v4 source + design tokens
│   └── compiled.css   # Build output (gitignored)
├── images/bristol/    # Bristol Stool Chart type illustrations (CC BY-SA)
└── tests/             # PHPUnit test suite
```

---

## Stack

PHP 8+, SQLite, HTMX v2, Tailwind CSS v4, Chart.js v4

---

## Setup

**Requirements:** PHP 8+, Composer, Node.js

```bash
composer install
npm install
npm run build:css
```

Start the app (see [Tasks](#tasks) below), then open it in a browser. With no database present yet, the app creates one automatically and shows a one-time "Create your account" form — fill it in to finish setup.

To start over with a fresh database:

```bash
rm -f database.sqlite database.sqlite-wal database.sqlite-shm
```

Reloading the app recreates the schema and shows the setup form again.

### Configuring the app

Edit `config.php` to change defaults:

| Key        | Default             | Description                                    |
| ---------- | ------------------- | ----------------------------------------------- |
| `db_path`  | `database.sqlite`   | Path to the SQLite database file                |
| `timezone` | `America/New_York`  | Local timezone for display and date filtering    |
| `base_url` | `''`                | URL prefix if deployed at a sub-path             |

### Deploying with Apache

Copy the project directory to your web root. The `.htaccess` file routes all requests through `index.php`. Make sure `mod_rewrite` is enabled and `AllowOverride All` is set for the directory.

Set `base_url` in `config.php` if deploying at a sub-path (e.g. `/gi`).

### Deploying with GitHub Actions

Pushing to `main` runs a two-job pipeline (`.github/workflows/deploy.yml`):

- **`test`** — runs on every push and pull request: PHP syntax lint, then `vendor/bin/phpunit`.
- **`deploy`** — runs only on push to `main` (or manual `workflow_dispatch`), and only if `test` passes. Builds production dependencies and compiled CSS, stages a filtered copy of the repo into `dist/`, and SFTPs it to DreamHost.

**Required GitHub secrets** (repo Settings → Secrets and variables → Actions):

| Secret | Value |
| --- | --- |
| `DREAMHOST_HOST` | DreamHost SFTP host |
| `DREAMHOST_USERNAME` | DreamHost SFTP username |
| `DREAMHOST_PASSWORD` | DreamHost SFTP password |

**One-time server setup**, via SSH: after the first deploy creates the `gistats/` web directory, place `~/magicbydesign.com/gistats/config.local.php` on the server:

```php
<?php return [
    'base_url' => '/gistats',
    'db_path'  => '/home/ccshell/data/gitstats/database.sqlite',
];
```

`config.local.php` is gitignored and never deployed (the SFTP action never deletes remote files), so it survives every subsequent deploy. The app creates its own data directory, database, and schema automatically on first request — visit the site and fill out the one-time "Create your account" form to finish setup.

**Password recovery:** if you forget your password, SSH in and run:

```bash
cd ~/magicbydesign.com/gistats
php -r "
require 'lib/db.php';
\$config = require 'config.php';
DB::init(\$config);
DB::execute('UPDATE users SET password_hash = ? WHERE username = ?', [password_hash('newpassword', PASSWORD_BCRYPT), 'yourusername']);
echo \"Password updated.\n\";
"
```

---

## Tasks

Run `pitchfork start` to run the app locally. It starts two daemons:

- `web`: serves the app with PHP's built-in server at `http://localhost:8000`.
- `assets`: watches `css/input.css` and rebuilds the compiled Tailwind CSS on changes.

Open `http://localhost:8000` and sign in with the credentials you set up in Setup.

Other tasks, defined in `mise.toml`:

- `mise run build`: minify the compiled Tailwind CSS.
- `mise run test`: run the PHPUnit suite.
- `mise run lint`: PHP syntax check across all tracked PHP files.
- `mise run integration-test`: spin up an isolated PHP server and run browser-automated integration tests.

Integration tests live in `tests/integration/` and use the `rodney` browser automation tool. The task spins up an isolated PHP server backed by a temporary database, runs every `test-*.sh` script, then tears everything down. Your development database is never touched.

---

## Documentation

See [DESIGN.md](DESIGN.md) for the visual design system, and [docs/](docs) for feature plans and specs.

---

## License

[MIT](LICENSE)
