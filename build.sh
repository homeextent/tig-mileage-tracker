#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"
OUT_ZIP="../tig-mileage-tracker-v3.3.0.zip"
rm -f "$OUT_ZIP"
zip -r "$OUT_ZIP" tig-mileage-tracker.php data/geo_cache.json README.md SYSTEM_BIBLE.md CHANGELOG.md
echo "Built $OUT_ZIP"
