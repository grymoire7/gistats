# Known limitations

Things we know about, decided not to fix yet, and why. Each entry says what happens today, what it would take to fix, and when it's worth doing. Add new entries at the bottom and delete one when it's fixed.

## Demo seed script writes to the real database by default

**Where:** `bin/seed_demo.php`

**What happens:** The script uses `config.php`, so by default it adds the `urgency` demo user and about 500 fake entries to your real `database.sqlite`. It doesn't ask first. It also doesn't print which database it's using.

The safety guard only protects users who already have entries that aren't demo data. A real user named `urgency` with zero entries would get their password reset to `password` and be filled with demo data. `--remove` would delete that account.

**Fix:** Print the resolved database path before doing anything. Optionally require `GISTATS_DB_PATH` to be set, or a `--yes` flag.

**Worth doing when:** Someone other than you runs the script, or the app has more than one real user.

**Before deploying:** Run `php bin/seed_demo.php --remove` on any database that goes to production. The demo account has a weak, well-known password.

## Heat map is a long tab stop for keyboard users

**Where:** `views/stats.php`, the `.heatmap-cell` elements

**What happens:** Every day cell is focusable (`tabindex="0"`), so a keyboard user has to tab through up to 365 stops to get past the heat map. The cells do show the tooltip on focus, and each has a proper `aria-label`.

**Fix:** Use a roving tabindex. Only one cell is in the tab order at a time, and the arrow keys move between cells (left and right for weeks, up and down for days). Home and End could jump to the ends of a row.

**Worth doing when:** Keyboard or screen reader use matters for this app, or before sharing it with other people.

## Charts stay blank if the Chart.js CDN is down

**Where:** `views/stats.php`, the `<script src="https://cdn.jsdelivr.net/...">` tag

**What happens:** If the CDN fails to load, the `Chart.defaults` line throws a `ReferenceError` and the three charts never draw. There's no message, just empty cards. The heat map keeps working because its script runs first and doesn't depend on Chart.js. The script tag also has no integrity (SRI) hash.

**Fix:** Add a short fallback message when `window.Chart` is missing, and pin the script with an SRI hash. Or serve Chart.js from the app itself, which would also help with offline use.

**Worth doing when:** You see it happen, or the app needs to work fully offline.

## Date range end uses 23:59:59

**Where:** `lib/entries.php`, `get_entries_in_range` (and the older `get_entries` and `get_entries_for_month`, which use the same pattern)

**What happens:** The end of a day is `T23:59:59` in local time, converted to UTC. That's fine while timestamps have whole-second precision, which they do today. An entry at `23:59:59.5` would fall just outside its day. In a time zone that skips midnight, the `00:00:00` start is shifted to 01:00 by PHP.

**Fix:** Query for "before the start of the next day" (`occurred_at < next_midnight`) instead of "at or before 23:59:59".

**Worth doing when:** Entries ever get sub-second timestamps, or you hit a day-boundary bug in a time zone with a midnight DST change.

## An out-of-range stool type would raise a PHP warning

**Where:** `views/stats.php`, the `HEATMAP_TYPE_COLORS[$day['type']]` lookup

**What happens:** The `stool_type` column has no `CHECK` constraint. Form input and CSV import both validate that the type is 1 to 7, so a bad value shouldn't get in. If one did, the heat map would trigger an undefined-key warning for that day.

**Fix:** Add `CHECK (stool_type BETWEEN 1 AND 7)` to the schema (existing databases need a migration), or fall back to a neutral color in the view.

**Worth doing when:** You add another way to write entries, such as an API, or you edit the database by hand.
