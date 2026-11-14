# TIG Corporate Mileage Tracker (WordPress Plugin v3.3.2)

**Entity:** Homeextent Inc. o/a The Innovative Group (St. Catharines, ON)  
**Vehicle Asset:** 2019 Ram 1500 Rebel (Class 10 Commercial Pickup • Uconnect BT + USB Android Auto)  
**Hosting Environment:** SiteGround WordPress (`theinnovativegroup.ca/wp-admin`)  

---

## 1. Installation & Upgrade (Zero Data Loss)

1. Log in to `https://theinnovativegroup.ca/wp-admin/plugins.php`.
2. Click **Add New** $\rightarrow$ **Upload Plugin**, select `tig-mileage-tracker-3.3.2.zip`, and click **Install Now** $\rightarrow$ **Replace current with uploaded**.
3. Click **Purge SG Cache** in the top WordPress admin bar and open **TIG Mileage** (`admin.php?page=tig-mileage`).

---

## 2. Pixel 9 & Android Auto REST API Setup (`/wp-json/tig-mileage/v1/`)

1. Open **Users** $\rightarrow$ **Profile** (`wp-admin/profile.php`) and scroll to **Application Passwords**.
2. Enter `Pixel 9 Ram Rebel`, click **Add New Application Password**, and copy the 24-character password.
3. Open **TIG Mileage** on your Pixel 9 $\rightarrow$ **Setup**, enter `https://theinnovativegroup.ca`, your WordPress username, and the Application Password, select your Ram's **Uconnect** Bluetooth device, and tap **Save & Test Connection**.

---

## 3. Quarterly & Year-End Logbook Export SOP

1. **Source A — Live Pixel 9 / Android Auto Trips:** Click **Load Live Pixel 9 Trips** in Tab 1 to pull all drives captured automatically via Uconnect Bluetooth and USB Android Auto.
2. **Source B — Google Timeline JSON (Historical 2025/2026):** Drop `Timeline.json` or `location-history.json` into the right-hand dropzone (processed 100% in browser memory).
3. **Optional QBO Receipt Corroboration:** Attach *Transaction List by Supplier* (`.csv`) from QuickBooks Online, resolve any flagged items in the Quick Review Queue, and click **Download CRA-Ready Logbook (.xlsx)**.

---

## Current Production Architecture (v3.3.2)

- **Tab 1 Auto-Load & Inline Logbook Editor:** Stored Pixel 9 drives (`wp_tig_mileage_trips`) auto-load on page open and tax-year switch. Step 3 renders an interactive CRA Vehicle Logbook table allowing inline edits to `Origin`, `Destination`, `Type` (`Work` / `Personal`), and `CRA Purpose` with real-time odometer chaining and Excel export updates.
- **Stored Pixel 9 Trips Manager:** `Inspect / Edit Stored Trips` supports inline editing of `origin_raw`, `dest_raw`, `manual_purpose`, and `manual_category` via `wp_ajax_tig_live_trips_action`.
- **Odometer Anchors (`tig_mileage_anchors`):** Stores verified historical/current readings only (`2025-12-31` backward anchor at `124,644.1 km` and `2026-11-13` pivot anchor at `136,220.0 km`); future calendar-year anchors remain unset until that date arrives.
- **Deployment Packaging (`./build.sh`):** Outputs `/media/jeremy/MEDIA/GITHUB/tig-mileage-tracker.zip` containing a single top-level `tig-mileage-tracker/` directory for seamless WordPress in-place replacement.
