# TIG Corporate Mileage Tracker (WordPress Plugin)

**Entity:** Homeextent Inc. o/a The Innovative Group (St. Catharines, ON)  
**Vehicle Asset:** 2019 Ram 1500 Rebel (Class 10 Commercial Pickup)  
**Hosting Environment:** SiteGround WordPress (`theinnovativegroup.ca/wp-admin`)  

---

## 1. Installation & Directory Setup

1. Upload the `tig-mileage-tracker` folder to `/wp-content/plugins/tig-mileage-tracker/`.
2. Copy your offline `geo_cache.json` file into `/wp-content/plugins/tig-mileage-tracker/data/geo_cache.json`.
3. Log in to `https://theinnovativegroup.ca/wp-admin/plugins.php` and click **Activate** under **TIG Corporate Mileage Tracker**.
4. Open **TIG Mileage** in the left WordPress admin sidebar (`admin.php?page=tig-mileage`).

---

## 2. How to Add New 2027+ Projects & Visited Locations

1. Open **TIG Mileage** $\rightarrow$ **2. Projects, Suppliers & Odometer Anchors**.
2. Enter the **Clean Display Name** (e.g., `125 Lakeport Rd, St. Catharines`), **Street Keywords** (e.g., `125 lakeport, lakeport road`), **CRA Trip Purpose** (`Project Site Visit`, `Quote / Estimate`, `Materials / Supplies`, etc.), and **Tax Year**.
3. Click **+ Save Location to Rulebook**. All future `Timeline.json` runs automatically classify stops at that location without code edits.

---

## 3. Quarterly & Year-End Processing SOP

1. **Export Google Timeline JSON from Phone:**
   * **Android:** Settings $\rightarrow$ Location $\rightarrow$ Location services $\rightarrow$ Timeline $\rightarrow$ Export Timeline data (`Timeline.json`).
   * **iPhone:** Google Maps $\rightarrow$ Profile $\rightarrow$ Your Timeline $\rightarrow$ `•••` $\rightarrow$ Location & privacy settings $\rightarrow$ Export Timeline data (`location-history.json`).
2. **Optional QBO Receipt Corroboration:** Export *Transaction List by Supplier* (`.csv`) from QuickBooks Online for the target tax year.
3. **Run in WordPress:** Open **TIG Mileage**, select the target tax year, drop in `Timeline.json` (processed 100% locally in your browser—no PHP upload limits), review any flagged rows in the Quick Review Queue, and click **Download CRA-Ready Logbook (.xlsx)**.
