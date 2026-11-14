# SYSTEM BIBLE: Homeextent Inc. Corporate Vehicle Mileage & CRA Compliance Architecture

## 1. Tax & Accounting Governance (CRA & Income Tax Act)

* **Corporate Entity:** Homeextent Inc. (o/a The Innovative Group), licensed HCRA residential builder & general contractor in the Niagara Region, Ontario.
* **Vehicle Classification:** 2019 Ram 1500 Rebel crew-cab pickup used to haul tools, trim, doors, and construction materials. Eligible for **Class 10** CCA treatment and **100% HST Input Tax Credits (ITCs)** on operating expenses whenever commercial usage exceeds 50%.
* **Statutory Deduction Basis (*ITA* s. 18(1)(a)):** Operating expenses (fuel, repairs, insurance, licensing, financing interest) and CCA are deductible in exact proportion to verified commercial kilometers divided by total annual odometer kilometers.
* **Dispatch / Head Office Doctrine (*ITA* s. 18(1)(h)):** `1 Hewko St, St. Catharines` functions as corporate dispatch, tool staging, and administrative headquarters. Trips from active project sites or trade counters back to `1 Hewko St` are classified as `Return to Head Office / Dispatch` (deductible). Trips returning from personal errands (`ZEHRS`, `Fit4Less`, or out-of-region trips) are automatically reclassified as `Personal`.
* **2026 Base Year & 2027+ Simplified Logbook Rule:** Calendar year 2026 establishes the full 12-month CRA Base Year. From 2027 onward, the CRA permits a continuous **3-month sample logbook** per year provided annual commercial usage remains within **±10 percentage points** of the 2026 baseline.

---

## 2. Geographic & Vendor Classification Rules

### A. Grimsby Out-of-Region Geo-Fence
Any destination containing municipalities outside the Niagara operating territory (`Burlington`, `Hamilton`, `Oakville`, `Mississauga`, `Toronto`, `Oshawa`, `Whitby`, `Clarington`, `Cobourg`, `Port Hope`, `Brighton`, `Quinte West`, `Picton`, `Kawartha Lakes`, `Dunnville`) is automatically classified as `Personal (Out of Region)`.

### B. Approved Pick-Up Counter Allowlist vs. Subcontractor Exclusion
QuickBooks Online stores both retail supply counters and on-site subcontractors under "Suppliers." To prevent audit red flags:
* **Included (Physical Pick-Up Depots & Fleet Shops):** Home Depot (St. Catharines weekday & Niagara Falls), RONA / RONA+, Turkstra Lumber, St. Catharines Building Supplies, Regional Doors & Hardware (`38 Scott St W` cluster), Fonthill Lumber & Truss (`7 Woodlawn Rd / Primeway Dr`), Convoy Supply (`29 Seapark Dr`), A-1 Flooring, Goemans Appliances, BYRCON Wood Products, Home Hardware, Art's Tools, Princess Auto, and Ontario St automotive shops.
* **Excluded (On-Site Trades & Delivered Goods):** Subcontractors who travel to job sites (e.g., Integrated Plumbing & Heating, Board Boss Drywall, Andy's Roofing, Ciampa Painting, Dirty Deeds Demolition, D&D Diamond Cutting, ECO Insulation) and delivered apparel/consultants (Graphix Works, Canvas Engineering, CDGA) are blocked from address and QBO receipt matching.

---

## 3. Odometer Calibration & Multi-Year Bridge

| Period | Opening Odo | Closing / Pivot Odo | Verified Calibration Anchor |
| :--- | :--- | :--- | :--- |
| **2025 Historical** | 113,318.4 km | 124,644.1 km | Chained backwards from Jan 1, 2026 opening (`0.0 km` gap) |
| **2026 Base Year** | 124,644.1 km | 136,111.0 km (Nov 10) | Sept 11, 2026 oil change (`134,432.0 km`) & Nov 10 dash photo (`136,111.0 km`); chains forward through Dec 31 |
| **2027+ Sample/Full** | Prior Dec 31 Close | Live forward chain | Configurable per year in WP Admin Tab 2 |

---

## 4. Technical Architecture (v3.0.0)

* **Client-Side JSON Streaming:** `FileReader` parses 50 MB+ `Timeline.json` / `location-history.json` files inside browser memory, eliminating SiteGround PHP `upload_max_filesize` and `max_execution_time` timeouts.
* **Two-Tier Geo-Cache:** Loads `data/geo_cache.json` + WordPress `wp_options` (`tig_mileage_runtime_geocache`). Any brand-new coordinates encountered in 2027+ are reverse-geocoded via Nominatim (1.1s throttle) and persisted via `wp_ajax_tig_save_geocache`.
* **Excel Workbook Engine:** SheetJS (`xlsx`) generates a 2-tab `.xlsx` workbook (`Executive Summary` + `Vehicle Log`) embedding both live Excel formulas (`SUM`, `SUMIF`, `COUNTIF`) and pre-computed cached values.

---

## Current Production Architecture (v3.3.2)

- **Tab 1 Auto-Load & Inline Logbook Editor:** Stored Pixel 9 drives (`wp_tig_mileage_trips`) auto-load on page open and tax-year switch. Step 3 renders an interactive CRA Vehicle Logbook table allowing inline edits to `Origin`, `Destination`, `Type` (`Work` / `Personal`), and `CRA Purpose` with real-time odometer chaining and Excel export updates.
- **Stored Pixel 9 Trips Manager:** `Inspect / Edit Stored Trips` supports inline editing of `origin_raw`, `dest_raw`, `manual_purpose`, and `manual_category` via `wp_ajax_tig_live_trips_action`.
- **Odometer Anchors (`tig_mileage_anchors`):** Stores verified historical/current readings only (`2025-12-31` backward anchor at `124,644.1 km` and `2026-11-13` pivot anchor at `136,220.0 km`); future calendar-year anchors remain unset until that date arrives.
- **Deployment Packaging (`./build.sh`):** Outputs `/media/jeremy/MEDIA/GITHUB/tig-mileage-tracker.zip` containing a single top-level `tig-mileage-tracker/` directory for seamless WordPress in-place replacement.
