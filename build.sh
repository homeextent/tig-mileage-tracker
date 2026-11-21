#!/usr/bin/env bash
set -euo pipefail
REPOS_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILDS_DIR="$(dirname "$REPOS_DIR")/builds"
OUT_ZIP="$BUILDS_DIR/tig-mileage-tracker.zip"

mkdir -p "$BUILDS_DIR"
rm -f "$OUT_ZIP" "$BUILDS_DIR"/tig-mileage-tracker-v*.zip

cd "$REPOS_DIR"
zip -rq "$OUT_ZIP" tig-mileage-tracker \
  -x "tig-mileage-tracker/.git/*" "tig-mileage-tracker/.git" \
     "tig-mileage-tracker/.gitignore" "tig-mileage-tracker/.gitattributes" \
     "tig-mileage-tracker/build.sh" "tig-mileage-tracker/DEVELOPER.md"
echo "Built $OUT_ZIP"
