#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-offline-queue ==="

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

# Clear any pre-existing queue
rodney js "localStorage.removeItem('gistats_pending')"

# Go offline
rodney js "window.dispatchEvent(new Event('offline'))"
rodney sleep 0.2

# Simulate a send error on the entry form (dispatch on form itself to match HTMX behavior)
rodney js "((f) => f && f.dispatchEvent(new CustomEvent('htmx:sendError', { bubbles: true, detail: { elt: f } })))(document.getElementById('entry-form'))"
rodney sleep 0.2

# Queue must contain 1 item
rodney assert "JSON.parse(localStorage.getItem('gistats_pending') || '[]').length" "1"
echo "PASS: entry queued in localStorage"

# Pending indicator must show count
rodney assert "document.getElementById('pending-indicator').textContent" "1 queued"
echo "PASS: pending indicator shows count"

echo "ALL PASS"
