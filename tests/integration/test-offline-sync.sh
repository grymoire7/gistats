#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${GISTATS_URL:-http://localhost:8000}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"

echo "=== test-offline-sync ==="

rodney start --local
trap 'rodney stop --local' EXIT

rodney open "$BASE_URL/login"
rodney waitload
rodney input '[name="username"]' "$USERNAME"
rodney input '[name="password"]' "$PASSWORD"
rodney click '[type="submit"]'
rodney waitload

# Pre-populate queue with one valid entry
NOW=$(date '+%Y-%m-%dT%H:%M')
rodney js "localStorage.setItem('gistats_pending', JSON.stringify([{ occurred_at: '$NOW', duration: '05:00', stool_type: '4', note: 'integration test' }]))"

# Fire online event to trigger auto-sync
rodney js "window.dispatchEvent(new Event('online'))"
rodney sleep 2

# Queue must be empty after sync
rodney assert "JSON.parse(localStorage.getItem('gistats_pending') || '[]').length" "0"
echo "PASS: queue drained after sync"

# Flash message must be present
rodney assert "document.getElementById('flash-msg') !== null" "true"
echo "PASS: sync flash message appeared"

echo "ALL PASS"
