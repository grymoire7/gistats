# GI Stats — Design Spec
**Date:** 2026-05-12

## Overview

A personal GI health tracker combining the best features of the Poopify and Bowel Move Android apps. Logs bowel movements with Bristol Stool Chart type, duration, date/time, and optional notes. Displays history in a calendar view and event list, with statistics charts for trend analysis.

Deployed as a subdirectory of magicbydesign.com (e.g. `magicbydesign.com/gistats`).

---

## Stack

- **Backend:** PHP with SQLite (same router/auth/db pattern as the Enshortener at `~/projects/trcy.cc/`)
- **Frontend:** Server-rendered PHP views, dark mode by default, Tailwind CSS (compiled via npm)
- **Dynamic interactions:** HTMX via CDN — partial page swaps without full reloads, no build step
- **Charts:** Chart.js via CDN — client-side rendering from JSON embedded in the page
- **No Vue.js / no SPA** — vanilla JS only for the date picker, type selector widget, and flash dismiss

---

## File Structure

```
gistats/
├── index.php                    # Entry point — bootstraps router, dispatches all requests
├── config.php                   # DB path, app timezone, session config
├── router.php                   # Lightweight router (same as Enshortener)
├── server.php                   # Dev server helper
├── composer.json
├── package.json                 # Tailwind build scripts
├── tailwind.config.js
├── css/
│   ├── input.css
│   └── compiled.css
├── lib/
│   ├── db.php                   # SQLite PDO connection + query helpers
│   ├── auth.php                 # Session-based authentication
│   └── csrf.php                 # CSRF token generation and validation
├── views/
│   ├── layout.php               # HTML shell: dark mode, nav drawer, flash area
│   ├── home.php                 # Entry form + calendar + event list (full page)
│   ├── login.php
│   ├── stats.php
│   ├── about.php
│   └── partials/
│       ├── flash.php            # Flash notification banner
│       ├── entry-form.php       # New/edit/copy entry form
│       ├── calendar.php         # Month calendar grid
│       ├── event-list.php       # Full event list (first 30 entries)
│       ├── event-rows.php       # HTMX fragment: next N rows for "load more"
│       └── type-selector.php    # Bristol Stool Chart image grid widget
└── images/
    └── bristol/
        ├── type1.jpg            # CC-licensed medical illustration, Type 1
        ├── type2.jpg
        ├── type3.jpg
        ├── type4.jpg
        ├── type5.jpg
        ├── type6.jpg
        └── type7.jpg
```

---

## Data Model

### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | INTEGER PRIMARY KEY | |
| username | TEXT NOT NULL UNIQUE | |
| password_hash | TEXT NOT NULL | bcrypt via `password_hash()` |
| created_at | TEXT | UTC ISO 8601 |

Single admin user seeded at setup. Additional users added directly via DB or a future admin UI. If multi-user is needed later, add a `timezone` column here — no data migration required because all datetimes are stored in UTC.

### `entries`
| Column | Type | Notes |
|--------|------|-------|
| id | INTEGER PRIMARY KEY | |
| user_id | INTEGER NOT NULL | FK → users.id |
| occurred_at | TEXT NOT NULL | UTC ISO 8601 — converted from/to local time at the PHP layer using `config.php` timezone |
| duration_seconds | INTEGER | Nullable. Duration of the event in seconds. |
| stool_type | INTEGER NOT NULL | 1–7, Bristol Stool Chart type number |
| note | TEXT | Nullable. Free-text. |
| created_at | TEXT | UTC ISO 8601, set at insert time |

**Datetime rule:** All datetimes stored in UTC. The entry form accepts local time input; PHP converts to UTC before writing. PHP converts UTC back to local time for display. Timezone configured in `config.php`; moves to `users.timezone` if multi-user support is added.

---

## Routing

| Method | Path | Description |
|--------|------|-------------|
| GET | `/` | Home: entry form + calendar + event list |
| GET | `/entries?date=YYYY-MM-DD` | Home with event list filtered to a specific date (`partial=1` returns list fragment for HTMX) |
| POST | `/entries` | Save new entry. HTMX: returns flash + blank form fragment. |
| GET | `/entries/more?offset=N` | HTMX fragment: next 30 rows for "load more" |
| GET | `/entries/:id/edit` | HTMX fragment: entry form pre-filled with existing data |
| GET | `/entries/:id/copy` | HTMX fragment: entry form pre-filled with data, occurred_at reset to now |
| POST | `/entries/:id` | Update entry. HTMX: returns flash + updated row. |
| DELETE | `/entries/:id` | Delete entry. HTMX: returns empty 200, row removed client-side. |
| GET | `/stats` | Statistics page |
| GET | `/export` | CSV download of all user entries |
| GET | `/login` | Login form |
| POST | `/login` | Authenticate, set session, redirect to `/` |
| POST | `/logout` | Destroy session, redirect to `/login` |
| GET | `/about` | About page |

---

## UI / UX

### General

- **Mobile-first.** Single column layout, max-width ~480px, centered on wider screens.
- **Dark mode by default.** No light/dark toggle — dark only.
- **Primary use case:** logging on a phone immediately after the fact.

### Dark Mode Palette (from DESIGN.md Starbucks system)

| Role | Value |
|------|-------|
| Page canvas | `#1a1a1a` |
| Card / section surface | `#2a2a2a` |
| Borders | `#3a3a3a` |
| Primary CTA | `#00754A` (Green Accent) |
| Heading accent | `#006241` (Starbucks Green) |
| Body text | `rgba(255,255,255,0.87)` |
| Secondary text | `rgba(255,255,255,0.55)` |
| Destructive | `#c82014` (Red) |

Full-pill buttons (`50px` radius), `scale(0.95)` active state. Inter font (open-source substitute for SoDoSans).

### Navigation

Hamburger (`=`) in the top-right corner opens a slide-out drawer. Links:
- Login / Logout
- Statistics
- Export CSV
- About

### Home Page Sections (top to bottom)

1. **Flash notification area** — fixed banner at top, auto-dismisses after 3 seconds.
2. **New Entry form** — date/time (defaults to now), duration as `MM:SS` (defaults to `05:00`), type selector, note textarea, Save / Reset buttons.
3. **Calendar view** — month grid, navigable by prev/next month buttons.
4. **Event list** — most recent first, 30 entries per page, "Load more" button.

### Entry Form

- **Date/time:** native `<input type="datetime-local">`, defaulting to current local time.
- **Duration:** text input, `MM:SS` format, defaulting to `05:00`. Stored as integer seconds.
- **Type selector:** 7 Bristol Stool Chart medical illustration thumbnails in a 4+3 grid. Tap to select; selected image gets a `2px solid #00754A` ring. Required field.
- **Note:** `<textarea>`, optional.
- **Save:** submits via HTMX, returns flash + blank form.
- **Reset:** clears form and resets date/time to current via vanilla JS.

### Calendar View

- Month grid (7 columns, Sun–Sat).
- Each day cell: date number + dominant-type medical thumbnail (small) + entry count (`×N`). Empty days show only the date number. Dominant type = most frequent that day; ties broken by whichever type is furthest from Type 4 (more clinically significant).
- Today gets a `2px solid #00754A` border.
- Tapping a day sends an HTMX request that swaps the event list section to show only that day's entries. A "Show all entries" link clears the filter.
- Prev/Next month navigation swaps the calendar section via HTMX.

### Event List

Each row: local date, local time, type thumbnail, note preview (if any), Edit / Copy / Delete icon buttons.

- **Edit:** swaps the entry form at page top with the entry's data pre-filled.
- **Copy:** same, but `occurred_at` reset to now.
- **Delete:** inline two-step confirmation via HTMX (button swaps to "Confirm?" with Yes/Cancel), then row fades out on confirm.
- **Load more:** appends the next 30 rows via HTMX `beforeend` swap.

---

## HTMX Interactions

| Interaction | Trigger | HTMX target | Server returns |
|-------------|---------|-------------|----------------|
| Save entry | Form submit | `#entry-form` | Flash partial + blank form HTML |
| Load more | Button click | `#event-list` (beforeend) | Next 30 `<tr>` rows |
| Calendar day tap | Day link | `#event-list-section` | Filtered list partial |
| Show all entries | Link | `#event-list-section` | Full list partial |
| Edit entry | Edit button | `#entry-form` | Pre-filled form partial |
| Copy entry | Copy button | `#entry-form` | Pre-filled form partial (occurred_at = now) |
| Delete confirm | Delete button | row element | Confirmation swap |
| Delete execute | Confirm button | row element | Empty 200 → row removed |
| Prev/next month | Nav buttons | `#calendar-section` | Calendar partial for new month |

Flash notifications auto-dismiss after 3 seconds via a small inline `<script>` in the flash partial.

---

## Statistics

All data provided as JSON embedded in the `<script>` block of `stats.php`. Chart.js loaded via CDN.

### Charts

1. **Moving average** *(primary)* — Line chart. X-axis: event index (chronological, 1-based). Y-axis: stool type 1–7. 7-event rolling average plotted as a smooth line. When fewer than 7 events are available, the average uses however many exist. Gives a clear trend of GI health over time.

2. **Type frequency** — Horizontal bar chart. X-axis: count. Y-axis: Type 1 through Type 7 (with small thumbnail labels). Shows the overall distribution of logged types.

3. **Daily frequency** — Bar chart. X-axis: date (last 30 days). Y-axis: entry count per day. Shows logging consistency and bowel frequency at a glance.

---

## Authentication

- PHP session-based (same pattern as Enshortener).
- Single admin user, credentials set by running `seed.php` from the CLI once at setup.
- All routes except `/login` require an authenticated session; unauthenticated requests redirect to `/login`.
- CSRF tokens on all POST forms.

---

## Bristol Stool Chart Images

- Source: Wikimedia Commons (CC-licensed medical illustrations).
- Stored locally in `images/bristol/type1.jpg` … `type7.jpg`.
- Used in the type selector, event list rows, and calendar day thumbnails.
- License file stored alongside images.

---

## Export

`GET /export` streams a CSV file with columns: `occurred_at_utc`, `occurred_at_local`, `duration_seconds`, `stool_type`, `note`. All entries for the authenticated user, oldest first.

---

## Error Handling

- Database connection failure: friendly error page (same pattern as Enshortener).
- HTMX endpoints: return appropriate HTTP status codes; HTMX `hx-on::response-error` shows a generic flash error.
- Form validation: server-side only; invalid submissions return the form with inline error messages.
- 404: shared `views/404.php`.
