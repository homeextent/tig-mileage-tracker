#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_ROOT"

PLUGIN_SLUG="tig-mileage-tracker"
OUT_ZIP="../${PLUGIN_SLUG}.zip"

echo "==> Running pre-flight checks for ${PLUGIN_SLUG}..."

# 1. Verify PHP syntax if php CLI is installed
if command -v php >/dev/null 2>&1; then
    php -l tig-mileage-tracker.php
fi

# 2. Verify header Version matches TIG_MILEAGE_VERSION
HEADER_VER=$(grep -E '^\s*\*\s*Version:' tig-mileage-tracker.php | sed -E 's/.*Version:\s*([0-9]+\.[0-9]+\.[0-9]+).*/\1/')
CONST_VER=$(grep -E "define\('TIG_MILEAGE_VERSION'" tig-mileage-tracker.php | sed -E "s/.*'([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/")

if [[ -z "$HEADER_VER" || "$HEADER_VER" != "$CONST_VER" ]]; then
    echo "ERROR: Version mismatch! Header='${HEADER_VER}' vs Constant='${CONST_VER}'"
    exit 1
fi

# 3. Verify CHANGELOG.md documents this version
if ! grep -q "## \[${HEADER_VER}\]" CHANGELOG.md; then
    echo "ERROR: CHANGELOG.md is missing an entry for [${HEADER_VER}]"
    exit 1
fi

# 4. Verify bundled geo_cache.json exists
if [[ ! -f "data/geo_cache.json" ]]; then
    echo "ERROR: data/geo_cache.json is missing!"
    exit 1
fi

# 5. Warn if there are uncommitted changes (git archive packages HEAD)
if [[ -n "$(git status --porcelain)" ]]; then
    echo "WARNING: You have uncommitted changes. git archive packages the latest committed HEAD."
fi

# 6. Build WordPress-ready archive with internal tig-mileage-tracker/ folder prefix
rm -f "$OUT_ZIP"
git archive --format=zip --prefix="${PLUGIN_SLUG}/" --output="$OUT_ZIP" HEAD

echo ""
echo "✓ Built clean production archive: $(realpath "$OUT_ZIP") (v${HEADER_VER})"
unzip -l "$OUT_ZIP"