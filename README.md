# GI Stats

Personal GI health tracker using the Bristol Stool Scale. Log bowel movements, view trends on a calendar, track statistics over time, and export your data as CSV.

**Tech stack:** PHP 8+, SQLite, HTMX v2, Tailwind CSS v4, Chart.js v4

---

## First-time setup

**Requirements:** PHP 8+, Composer, Node.js

```bash
composer install
npm install
npm run build:css
```

Create the database and your user account:

```bash
php seed.php
```

The script will prompt for a username and password. It creates the SQLite database at `database.sqlite` and applies the schema.

---

## Running locally

```bash
npm run server:start
```

Open `http://localhost:8000` and sign in with the credentials you set up above.

To rebuild CSS after editing `css/input.css`:

```bash
npm run build:css      # one-shot
npm run watch:css      # watch mode
```

---

## Testing

```bash
npm test               # run PHPUnit suite
npm run test:syntax    # check PHP syntax on all tracked files
```

---

## Configuration

Edit `config.php` to change defaults:

| Key | Default | Description |
|-----|---------|-------------|
| `db_path` | `database.sqlite` | Path to the SQLite database file |
| `timezone` | `America/New_York` | Local timezone for display and date filtering |
| `base_url` | `''` | URL prefix if deployed at a sub-path |

---

## Admin tasks

**Add or reset a user password:**

```bash
php seed.php
```

The script uses `INSERT OR REPLACE`, so running it again with the same username updates the password.

**Export all data as CSV:**

Navigate to **Export CSV** in the nav drawer while signed in, or visit `/export` directly. The download includes UTC timestamps, local timestamps, duration in seconds, Bristol stool type, and notes.

**Wipe and recreate the database:**

```bash
rm database.sqlite
php seed.php
```

---

## Deploying with Apache

Copy the project directory to your web root. The `.htaccess` file routes all requests through `index.php`. Ensure `mod_rewrite` is enabled and `AllowOverride All` is set for the directory.

Set `base_url` in `config.php` if deploying at a sub-path (e.g. `'/gi'`).

---

## Project structure

```
├── index.php          # Entry point: routes and render() helper
├── config.php         # Runtime configuration
├── router.php         # Simple pattern-matching router
├── server.php         # PHP built-in server router (static file passthrough)
├── seed.php           # CLI: create database and admin user
├── lib/
│   ├── db.php         # PDO/SQLite wrapper (singleton)
│   ├── helpers.php    # Pure functions: timezone, duration, calendar, stats
│   ├── entries.php    # Entry CRUD
│   ├── auth.php       # Session auth
│   └── csrf.php       # CSRF token helpers
├── views/
│   ├── layout.php     # HTML shell with nav drawer
│   ├── home.php       # Entry form + calendar + event list
│   ├── login.php      # Sign-in form
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
