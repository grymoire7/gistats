#!/usr/bin/env bash
# Tests: submitting while running captures duration and stops timer
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-timer-submit ==="

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

rodney js "localStorage.setItem('gistats_timer_start', (Date.now() - 90000).toString())"
rodney open "$BASE_URL"
rodney waitload
# Fill required fields and submit
rodney js "document.querySelector('[name=\"occurred_at\"]').value = '2026-01-01T10:00'"
rodney js "document.getElementById('stool-type-input').value = '4'"
rodney click "[type='submit']"
rodney sleep 0.5
rodney assert "localStorage.getItem('gistats_timer_start') === null"
echo "PASS: timer stops on form submit"

echo "ALL PASS"
