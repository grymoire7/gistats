#!/usr/bin/env bash
# Tests: tapping |> starts the timer
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-timer-start ==="

rodney start --local
trap 'rodney stop --local' EXIT

rodney open "$BASE_URL/login"
rodney waitload
rodney input '[name="username"]' "$USERNAME"
rodney input '[name="password"]' "$PASSWORD"
rodney click '[type="submit"]'
rodney waitload

# Verify login succeeded — entry form only exists on the home page
rodney visible '#entry-form-wrap'
echo "PASS: logged in (home page loaded)"

rodney click "#timer-btn"
rodney assert "document.getElementById('timer-btn').textContent === '[]'"
rodney assert "document.querySelector('[name=\"duration\"]').readOnly === true"
rodney assert "localStorage.getItem('gistats_timer_start') !== null"
echo "PASS: timer starts correctly"

echo "ALL PASS"
