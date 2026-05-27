#!/usr/bin/env bash
# Tests: calendar reflects new entry immediately after HTMX form submit (no reload)
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-calendar-updates-on-entry ==="

rodney start --local
trap 'rodney stop --local' EXIT

rodney open "$BASE_URL/login"
rodney waitload
rodney input '[name="username"]' "$USERNAME"
rodney input '[name="password"]' "$PASSWORD"
rodney click '[type="submit"]'
rodney waitload

rodney visible '#entry-form-wrap'
echo "PASS: logged in (home page loaded)"

# Verify calendar starts with no entry type images (fresh DB, no entries yet for current month)
rodney assert "document.querySelectorAll('#calendar-wrap img[alt^=\"Type\"]').length === 0"
echo "PASS: calendar initially shows no entries"

# Submit a new entry dated today so it falls in the currently-displayed month
NOW=$(date '+%Y-%m-%dT%H:%M')
rodney js "document.querySelector('[name=\"occurred_at\"]').value = '$NOW'"
rodney js "document.getElementById('stool-type-input').value = '4'"
rodney click 'button.btn-primary'
rodney sleep 0.5

# Calendar must update immediately via OOB swap — no page reload required
rodney assert "document.querySelectorAll('#calendar-wrap img[alt^=\"Type\"]').length > 0"
echo "PASS: calendar shows entry after add (no reload)"

echo "ALL PASS"
