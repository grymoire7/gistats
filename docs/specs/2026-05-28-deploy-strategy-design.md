# Deploy Strategy Design

**Date:** 2026-05-28  
**Target URL:** https://magicbydesign.com/gistats/  
**Host:** DreamHost shared hosting (Apache + PHP 8+)

---

## Pipeline Architecture

Two-job GitHub Actions workflow (`.github/workflows/deploy.yml`):

**`test` job** — triggers on every push (all branches) and every pull request.
- PHP syntax lint (`php -l` on all tracked `.php` files)
- PHPUnit test suite (`vendor/bin/phpunit`)

**`deploy` job** — triggers on push to `main` and `workflow_dispatch` only.
- `needs: test` — only runs if the test job passes
- Builds assets, stages production files, SFTPs to DreamHost

PRs get tested automatically. Only `main` pushes deploy.

---

## CI Build Steps (deploy job)

1. Checkout repository
2. Setup PHP; run `composer install --no-dev --optimize-autoloader`
3. Setup Node; run `npm ci && npm run build:css`
4. `rsync` production files into a `dist/` staging directory, excluding:
   - `tests/`, `seed.php`, `server.php`
   - `docs/`, `tmp/`
   - `css/input.css` (deploy compiled output only)
   - `*.md`, `phpunit.xml`
   - `composer.json`, `composer.lock`, `package*.json`
   - `.git/`, `.claude/`, `.rodney/`
   - `config.local.php`
5. SFTP `dist/*` to `/home/ccshell/magicbydesign.com/gistats/` using `wlixcc/SFTP-Deploy-Action@v1.2.4` with `delete_remote_files: false`

Reuses the existing `DREAMHOST_HOST`, `DREAMHOST_USERNAME`, `DREAMHOST_PASSWORD` GitHub secrets.

---

## Production Config (`config.local.php` pattern)

`config.php` is extended to merge in a gitignored `config.local.php` if it exists:

```php
// at the bottom of config.php, replacing the bare `return [...]`
$defaults = [
    'db_path'  => getenv('GISTATS_DB_PATH') ?: __DIR__ . '/database.sqlite',
    'timezone' => 'America/Chicago',
    'base_url' => '',
];
if (file_exists(__DIR__ . '/config.local.php')) {
    return array_merge($defaults, require __DIR__ . '/config.local.php');
}
return $defaults;
```

On the DreamHost server, `/home/ccshell/magicbydesign.com/gistats/config.local.php` contains:

```php
<?php return [
    'base_url' => '/gistats',
    'db_path'  => '/home/ccshell/data/gitstats/database.sqlite',
];
```

This file is never deployed (deploy uses `delete_remote_files: false` and it is added to `.gitignore`). Local development continues to use `config.php` defaults with no override needed.

---

## One-Time Server Setup

Performed once via SSH before or after the first deploy:

```bash
# Create data directory outside web root
mkdir -p ~/data/gitstats

# After first deploy creates the gistats/ web directory:
# Place config.local.php (contents above) at:
# ~/magicbydesign.com/gistats/config.local.php

# Seed the production database
cd ~/magicbydesign.com/gistats
php seed.php
```

The `gistats/` web directory is created by the first SFTP deploy. `config.local.php` survives all subsequent deploys because `delete_remote_files: false`.

---

## README Updates

A new "Deploying with GitHub Actions" section is added to `README.md` covering:
- Required GitHub secrets (`DREAMHOST_HOST`, `DREAMHOST_USERNAME`, `DREAMHOST_PASSWORD`)
- How the two-job pipeline works
- One-time server setup steps
- `config.local.php` contents for production

---

## Files Changed

- `.github/workflows/deploy.yml` — new file
- `config.php` — add `config.local.php` merge support; restructure into `$defaults` array
- `.gitignore` — add `config.local.php`
- `README.md` — add deploy section
