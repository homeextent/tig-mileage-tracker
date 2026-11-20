#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
rm -f tig-mileage-tracker.zip tig-mileage-tracker-v*.zip
zip -rq tig-mileage-tracker.zip tig-mileage-tracker \
  -x "tig-mileage-tracker/.git/*" "tig-mileage-tracker/.git" \
     "tig-mileage-tracker/.gitignore" "tig-mileage-tracker/.gitattributes" \
     "tig-mileage-tracker/build.sh" "tig-mileage-tracker/DEVELOPER.md"
echo "Built /media/jeremy/MEDIA/GITHUB/tig-mileage-tracker.zip"
