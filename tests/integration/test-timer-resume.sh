#!/usr/bin/env bash
# Tests: timer resumes after full page reload
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-timer-resume ==="

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

rodney js "localStorage.setItem('gistats_timer_start', (Date.now() - 30000).toString())"
rodney open "$BASE_URL"
rodney waitload
rodney assert "document.getElementById('timer-btn').textContent === '■'"
rodney assert "document.querySelector('[name=\"duration\"]').readOnly === true"
rodney assert "localStorage.getItem('gistats_timer_start') !== null"
echo "PASS: timer resumes after page reload"

# Clean up so stale timer state doesn't leak into subsequent tests via shared Chrome profile
rodney js "localStorage.removeItem('gistats_timer_start')"

echo "ALL PASS"
