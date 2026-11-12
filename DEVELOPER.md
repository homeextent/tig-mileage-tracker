# Developer Build, Release & Deployment Guide — TIG Corporate Mileage Tracker

Operational workflow for packaging `homeextent/tig-mileage-tracker` into a clean, WordPress-installable `.zip` archive and deploying updates to `theinnovativegroup.ca`.

---

## 1. Git & One-Command Production ZIP Build

Run these commands from `/media/jeremy/MEDIA/GITHUB/tig-mileage-tracker`:

```bash
# 1. Verify status (ensure no Timeline*.json, *.csv, or *.xlsx files are untracked/staged)
git status

# 2. Stage and commit completed milestone
git add -A
git commit -m "feat: release v3.3.0 with Pixel 9 & Android Auto REST API"
git push origin main

# 3. Build pristine WordPress-ready .zip (outputs ../tig-mileage-tracker.zip)
./build.sh
```

---

## 2. WordPress Application Password Setup (For Pixel 9 Companion)

1. Log into `https://theinnovativegroup.ca/wp-admin/profile.php`.
2. Scroll down to **Application Passwords**.
3. Enter `Pixel 9 Ram Rebel` as the name and click **Add New Application Password**.
4. Copy the generated 24-character password and paste it into the **TIG Mileage** Pixel 9 app settings alongside `https://theinnovativegroup.ca` and your WordPress username.

---

## 3. SiteGround WordPress Deployment (Zero Data Loss)

All user-configured project locations (`tig_mileage_locations`), odometer anchors (`tig_mileage_anchors`), runtime reverse-geocoded coordinates (`tig_mileage_runtime_geocache`), and live Pixel 9 drives (`wp_tig_mileage_trips`) are stored in MySQL, so replacing the plugin `.zip` preserves 100% of your data.

1. Log into `https://theinnovativegroup.ca/wp-admin/plugins.php`.
2. Click **Add New > Upload Plugin**, choose `tig-mileage-tracker-v3.3.0.zip`, and click **Install Now**.
3. Click **Replace current with uploaded**.
4. Click **Purge SG Cache** in the WordPress top admin bar and hard-refresh **TIG Mileage** (`Ctrl + Shift + R`).
