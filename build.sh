#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"
OUT_ZIP="$PARENT_DIR/tig-mileage-tracker.zip"

rm -f "$OUT_ZIP" "$PARENT_DIR"/tig-mileage-tracker-v*.zip
cd "$PARENT_DIR"
zip -r "$OUT_ZIP" \
  tig-mileage-tracker/tig-mileage-tracker.php \
  tig-mileage-tracker/data/geo_cache.json \
  tig-mileage-tracker/README.md \
  tig-mileage-tracker/SYSTEM_BIBLE.md \
  tig-mileage-tracker/CHANGELOG.md

echo "Built $OUT_ZIP"
