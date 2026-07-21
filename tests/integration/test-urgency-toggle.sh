#!/usr/bin/env bash
# Tests: urgency toggle on the entry form persists through create and edit
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-urgency-toggle ==="

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

# Default state: green, off
rodney assert "document.getElementById('urgency-input').value" "0"
rodney assert "document.getElementById('urgency-btn').classList.contains('active')" "false"
echo "PASS: urgency toggle defaults to off"

# Toggle on, then submit a new entry
rodney click '#urgency-btn'
rodney assert "document.getElementById('urgency-input').value" "1"
rodney assert "document.getElementById('urgency-btn').classList.contains('active')" "true"
echo "PASS: toggle switches to on (active class, hidden input = 1)"

# Fixed, far-future timestamp: distinct from other integration tests'
# occurred_at values (avoids the unique (user_id, occurred_at) index
# colliding with a floating $NOW used elsewhere in the suite within the
# same clock minute) and guaranteed to sort first in the DESC-ordered
# entries list regardless of which real date the suite runs on.
rodney js "document.querySelector('[name=\"occurred_at\"]').value = '2099-01-01T00:00'"
rodney js "document.getElementById('stool-type-input').value = '4'"
rodney click 'button.btn-primary'
rodney sleep 0.5
echo "PASS: submitted entry with urgency on"

# Edit the entry back open and confirm the toggle pre-loads as on
rodney click '[hx-get*="/edit"]'
rodney sleep 0.3
rodney assert "document.getElementById('urgency-input').value" "1"
rodney assert "document.getElementById('urgency-btn').classList.contains('active')" "true"
echo "PASS: edit form pre-loads urgency as on"

echo "ALL PASS"
