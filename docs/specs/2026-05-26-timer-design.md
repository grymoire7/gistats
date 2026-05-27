# Timer Feature — Design Spec
**Date:** 2026-05-26

## Overview

Add a start/stop timer to the new entry form so users can capture duration in
real time. The running timer displays in the duration field. Timer state
persists in `localStorage` so it survives full page navigations and HTMX
partial swaps.

---

## Scope

**In scope:**
- Start/stop button on the new entry form only (not edit mode).
- Running timer displayed in the duration field (read-only while running).
- Adaptive display format: `MM:SS` under 1 hour, `H:MM:SS` at 1 hour or over.
- Timer persists across page navigation via `localStorage`.
- Timer resumes when returning from edit mode (HTMX swap round-trip).
- Submitting the form while the timer is running captures elapsed duration and stops the timer before submitting.
- Reset stops the timer and resets duration to `05:00`.
- Duration remains manually editable after the timer is stopped.
- Updated duration input `pattern` and backend parser to support `H:MM:SS`.
- PHPUnit tests for updated helpers and form partial rendering.
- Rodney bash integration tests for timer behavior.

**Out of scope:**
- Timer on the edit entry form.
- Timer on the copy entry form.
- Multiple simultaneous timers.
- Server-side timer state.

---

## UI

### Button

A single `|>` / `[]` button is added to the right of the Reset button in the new entry form button row:

```
[Save]  [Reset]  [|>]    ← idle
[Save]  [Reset]  [[ ]]   ← running
```

- ID: `timer-btn`
- Class: `btn-outline` (same as Reset)
- Text: `|>` when idle, `[]` when running
- Only rendered when `$isEdit` is false

### Duration Field

- While timer is running: `readonly` attribute set, reduced opacity to signal non-editable state
- While timer is stopped: normal editable state
- Format while running: `MM:SS` (e.g. `07:42`) under 1 hour; `H:MM:SS` (e.g. `1:05:30`) at 1 hour or over
- No upper bound on hours — a forgotten timer running for 10+ hours displays correctly

---

## Architecture

### Storage

`localStorage` key `gistats_timer_start` holds the Unix timestamp (ms) at which
the timer was started. The key is absent when no timer is running.

### Script Location

Timer JS lives as an inline `<script>` in `views/partials/entry-form.php`,
alongside the existing `resetForm()` function. It is only rendered when
`$isEdit` is false.

Because `#entry-form-wrap` is replaced on every HTMX swap (save, edit, cancel),
the script runs fresh on each render. A single `window.gistatsTimerInterval`
global tracks the active interval so any orphaned interval from a prior render
is cleared before a new one starts.

### Functions

**`startTimer()`**
Save `Date.now()` to `localStorage` as `gistats_timer_start`. Set duration
field to `readonly`. Swap button text to `[]`. Start a 1-second `setInterval`
calling `updateTimerDisplay()`, storing the interval ID in
`window.gistatsTimerInterval`.

**`stopTimer()`**
Clear `window.gistatsTimerInterval`. Remove `gistats_timer_start` from
`localStorage`. Re-enable the duration field. Swap button text to `|>`. Does
not write to the duration field — callers are responsible for capturing the
elapsed value first if needed.

**`updateTimerDisplay()`**
Compute elapsed seconds as `Math.floor((Date.now() - startTime) / 1000)`.
Format adaptively and write to the duration field.

**`captureAndStopTimer()`**
Calls `updateTimerDisplay()` to write the final elapsed time to the duration
field, then calls `stopTimer()`. This is the standard way to stop the timer —
used by the `[]` button click, form submit, and Reset.

**`isTimerRunning()`**
Returns true if `gistats_timer_start` is present in `localStorage`.

### Duration Format

```
formatDuration(seconds):
  if seconds < 3600:
    return MM:SS  (e.g. "07:42")
  else:
    return H:MM:SS  (e.g. "1:05:30")
```

Hours are unbounded (no zero-padding): `1:05:30`, `10:00:00`.

### On Script Load

Runs on initial page load and after every HTMX swap that replaces `#entry-form-wrap`:

1. Clear `window.gistatsTimerInterval` if it exists.
2. Read `gistats_timer_start` from `localStorage`.
3. If found → restore running state: set field to `readonly`, swap button to
   `[]`, call `updateTimerDisplay()`, start interval.
4. If not found → idle state: field editable, button shows `|>`.

### Form Submit Integration

A `submit` event listener on `#entry-form` checks `isTimerRunning()`. If true,
calls `captureAndStopTimer()` before HTMX reads the `FormData`. No changes to
HTMX configuration needed.

### Reset Integration

`resetForm()` gains a `captureAndStopTimer()` call at the top so the timer is
stopped (if running) before the field is reset to `05:00`.

---

## Data Flow

**Happy path — timer used:**
1. User taps `|>` → `startTimer()` → localStorage set, field readonly, button `[]`
2. Duration field ticks every second
3. User taps `[]` → `stopTimer()` → elapsed time in field, field editable, button `|>`
4. User fills in remaining fields, taps Save
5. Form submits; HTMX swaps in blank form; timer already stopped

**Submit while running:**
1. User taps Save → `submit` fires → `captureAndStopTimer()` writes final
   elapsed time to field → HTMX POSTs with correct duration → blank form swaps in

**Page navigation while running:**
1. User navigates away (full page load) → `gistats_timer_start` persists in `localStorage`
2. User returns to home → form renders → script finds key → timer resumes

**Edit mode round-trip while running:**
1. Timer running → user taps Edit on existing entry → `#entry-form-wrap` swaps to edit form
2. Edit form has no timer script; orphaned interval becomes inert (its DOM targets no longer exist)
3. User taps Cancel → new entry form swaps in → script finds `gistats_timer_start` → timer resumes

---

## Duration Format: Full-Stack Impact

The existing `MM:SS` format is used throughout. `H:MM:SS` support requires changes in three places:

| Location                                     | Current              | Updated                        |
| -------------------------------------------- | -------------------- | ------------------------------ |
| Duration `<input pattern>`                   | `\d{1,2}:\d{2}`      | `\d+:\d{2}(:\d{2})?`           |
| `seconds_to_duration()` in `lib/helpers.php` | Returns `MM:SS` only | Returns `H:MM:SS` when ≥ 3600s |
| Duration parser in `lib/helpers.php`         | Parses `MM:SS` only  | Also parses `H:MM:SS`          |

The database column `duration_seconds` is an integer and requires no change.

---

## File Changes

| File                            | Change                                                                                                                      |
| ------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `views/partials/entry-form.php` | Add timer button (new entry only); add timer JS; update `resetForm()`; add submit listener; update duration input `pattern` |
| `lib/helpers.php`               | Update `seconds_to_duration()` and duration parser for `H:MM:SS`                                                            |
| `css/input.css`                 | Add readonly opacity style for duration field while timer is running                                                        |

---

## Testing

### PHPUnit

- `seconds_to_duration()` returns `MM:SS` for values under 3600 seconds
- `seconds_to_duration()` returns `H:MM:SS` for values at 3600 seconds and over
- Duration parser correctly converts `H:MM:SS` strings to integer seconds
- Duration parser continues to correctly convert `MM:SS` strings (regression)
- Entry form partial renders timer button when `$isEdit = false`
- Entry form partial does not render timer button when `$isEdit = true`

### Rodney Integration Tests (`tests/integration/`)

Not wired to CI — serve as runnable documentation of expected behavior.

- **`test-timer-start.sh`** — tap `|>`, assert button shows `[]`, duration
  field is `readonly`, `gistats_timer_start` is set in `localStorage`
- **`test-timer-stop.sh`** — set `gistats_timer_start` to a past timestamp via
  `rodney js`, load page, assert timer is running; tap `[]`, assert field shows
  elapsed time and is editable, `gistats_timer_start` absent from `localStorage`
- **`test-timer-submit.sh`** — set `gistats_timer_start`, submit form, assert
  entry saved with correct duration, `gistats_timer_start` cleared from
  `localStorage`
- **`test-timer-resume.sh`** — set `gistats_timer_start` via `rodney js`,
  reload page, assert timer resumes (field ticking, button shows `[]`)

