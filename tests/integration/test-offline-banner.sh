#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-offline-banner ==="

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

# Fire offline event and wait for DOM update
rodney js "window.dispatchEvent(new Event('offline'))"
rodney sleep 0.3

# Banner must be visible
rodney visible '#offline-banner'
echo "PASS: offline banner is visible"

# At least one read control must carry the disabled class
rodney assert "document.querySelector('[data-offline-disable]').classList.contains('offline-disabled')" "true"
echo "PASS: read controls are disabled"

# Fire online event — banner must hide
rodney js "window.dispatchEvent(new Event('online'))"
rodney sleep 0.3
rodney assert "document.getElementById('offline-banner').style.display" "none"
echo "PASS: banner hides when back online"

echo "ALL PASS"
