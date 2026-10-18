# Shared settings for the integration test scripts (sourced, not executed).
# run.php overrides GISTATS_URL with its isolated server; the default is the
# pitchfork dev server (see pitchfork.toml).
BASE_URL="${GISTATS_URL:-http://localhost:8300}"
USERNAME="${GISTATS_USER:-admin}"
PASSWORD="${GISTATS_PASS:-secret}"
