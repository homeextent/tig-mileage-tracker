# Changelog — TIG Corporate Mileage Tracker

All notable changes to the Homeextent Inc. vehicle mileage pipeline and WordPress plugin are documented here.

## [3.0.0] - 2026-11-11
### Added
- **Built-In `data/geo_cache.json` Auto-Loader:** Plugin automatically loads bundled `/data/geo_cache.json` merged with persistent WordPress `wp_options` cache—eliminating manual cache uploads on every run.
- **Persistent Live Geocoding:** Newly encountered coordinates in 2027+ automatically resolve via OpenStreetMap Nominatim and save permanently to WordPress options and `/data/geo_cache.json`.
- **Projects, Suppliers & Odometer Anchors Manager (Tab 2):** Full admin UI to add/delete 2027+ projects, prospective quote addresses, pick-up counters, and annual odometer anchors without editing code.
- **Interactive Quick Review Queue:** Allows instant purpose reclassification of any `Review` trips directly in the browser prior to `.xlsx` export.
- **Documentation Suite:** Added `README.md`, `SYSTEM_BIBLE.md`, and `CHANGELOG.md`.

## [2.2.0] - 2026-11-11
### Fixed
- Restored canonical `tig-mileage` admin menu slug and added legacy slug redirects (`tig-mileage-tracker`, `tig-mileage-pro`) to prevent WordPress permission blocks on open tabs.
- Added dual-CDN fallback (`jsDelivr` -> `unpkg`) plus CSV fallback for SheetJS `.xlsx` generation.
- Added asynchronous UI progress bar to prevent browser freeze on 50 MB+ JSON files.

## [2.1.0] - 2026-11-11
### Fixed
- Added RFC-4180 CSV parser and `DD/MM/YYYY` date normalizer to skip QuickBooks Online report title headers and index commercial supplier dates accurately.
- Added Return-from-Personal rule (`ZEHRS`, `Fit4Less`, `Out of Region` -> `1 Hewko St` reclassified as `Personal`), aligning plugin output (`75.55%`) with audited Colab baseline.

## [2.0.0] - 2026-11-11
### Added
- Initial client-side WordPress plugin architecture for `theinnovativegroup.ca` (SiteGround), moving 50 MB+ `Timeline.json` parsing from Google Colab into the browser.

## [1.0.0 - 1.4.0] - 2026-11-10 to 2026-11-11
### Added
- Initial Python/Colab geospatial pipeline (`Timeline12.csv`), 2,626-point `geo_cache.json`, 2025 project codex (`89 Bayview Dr`, `10 Arden Ct`, `Garner Ave`), 2026 project codex (`74 Royal Manor Dr`, `7155 Brian Cres`), Regional Doors & Hardware (`38 Scott St W`) override, Approved Pick-Up Counter Allowlist, and 0.0 km year-end odometer bridge at `124,644.1 km`.
