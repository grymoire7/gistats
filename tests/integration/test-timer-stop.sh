#!/usr/bin/env bash
# Tests: tapping [] stops the timer and writes elapsed duration
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-timer-stop ==="

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

# Pre-seed a timer started 65 seconds ago
rodney js "localStorage.setItem('gistats_timer_start', (Date.now() - 65000).toString())"
rodney open "$BASE_URL"
rodney waitload
rodney assert "document.getElementById('timer-btn').textContent === '[]'"
rodney assert "document.querySelector('[name=\"duration\"]').readOnly === true"
rodney click "#timer-btn"
rodney assert "document.getElementById('timer-btn').textContent === '|>'"
rodney assert "document.querySelector('[name=\"duration\"]').readOnly === false"
rodney assert "localStorage.getItem('gistats_timer_start') === null"
# Duration should be approximately 01:05 (65 seconds)
rodney assert "['01:05','01:06','01:07'].includes(document.querySelector('[name=\"duration\"]').value)"
echo "PASS: timer stops and writes duration correctly"

echo "ALL PASS"
