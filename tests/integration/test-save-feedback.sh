#!/usr/bin/env bash
# Tests: Save button is disabled and reads "Saving…" while the request is in flight
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

echo "=== test-save-feedback ==="

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

# Idle state: enabled, shows "Save"
rodney assert "document.querySelector('#entry-form button[type=submit]').disabled" "false"
rodney assert "document.querySelector('#entry-form button[type=submit]').innerText" "Save"
echo "PASS: Save button idle (enabled, labelled Save)"

# Hold the request open for 1.5s so the in-flight state can be observed
rodney js "(function(){var s=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.send=function(){var x=this,a=arguments;setTimeout(function(){s.apply(x,a)},1500)}})()"

# Fixed timestamp distinct from other integration tests (unique user/occurred_at index);
# 2098 sorts below test-urgency-toggle's 2099 entry so that test still edits its own row
rodney js "document.querySelector('[name=\"occurred_at\"]').value = '2098-01-01T00:00'"
rodney click '#entry-form button[type=submit]'
rodney sleep 0.3

rodney assert "document.querySelector('#entry-form button[type=submit]').disabled" "true"
rodney assert "document.querySelector('#entry-form button[type=submit]').innerText" "Saving…"
echo "PASS: Save button disabled and labelled Saving… while in flight"

# After the response: fresh form, enabled Save, flash shown
rodney sleep 2
rodney assert "document.getElementById('flash-msg').innerText.trim()" "Saved!"
rodney assert "document.querySelector('#entry-form button[type=submit]').disabled" "false"
rodney assert "document.querySelector('#entry-form button[type=submit]').innerText" "Save"
echo "PASS: Save button re-enabled with the flash message"

echo "ALL PASS"
