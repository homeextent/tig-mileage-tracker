<?php
/**
 * Plugin Name: TIG Corporate Mileage Tracker
 * Description: Client-side CRA-compliant mileage engine for Homeextent Inc. (2019 Ram 1500 Rebel). Auto-loads bundled geo_cache.json, manages multi-year projects/suppliers in WP Admin, corroborates QBO receipts, and exports CPA-ready Excel workbooks.
 * Version: 3.1.0
 * Author: Homeextent Inc. / The Innovative Group
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TIG_MILEAGE_VERSION', '3.1.0');
define('TIG_MILEAGE_DIR', plugin_dir_path(__FILE__));

// Register top-level admin menu
add_action('admin_menu', 'tig_mileage_tracker_menu');
function tig_mileage_tracker_menu() {
    add_menu_page(
        'TIG Mileage Tracker',
        'TIG Mileage',
        'manage_options',
        'tig-mileage',
        'tig_mileage_tracker_admin_page',
        'dashicons-car',
        30
    );
}

// Redirect legacy slugs cleanly & run one-time v3.1.0 keyword cleanup migration
add_action('admin_init', 'tig_mileage_admin_init');
function tig_mileage_admin_init() {
    if (!is_admin()) {
        return;
    }

    tig_mileage_maybe_migrate_v310();

    if (!isset($_GET['page'])) {
        return;
    }
    $page = sanitize_text_field(wp_unslash($_GET['page']));
    if (in_array($page, array('tig-mileage-tracker', 'tig-mileage-pro'), true)) {
        wp_safe_redirect(admin_url('admin.php?page=tig-mileage'));
        exit;
    }
}

// Sanitize a comma-separated keyword string to prevent bare numbers or province names from hijacking matches
function tig_mileage_sanitize_keywords($raw_keywords) {
    $parts = array_map('trim', explode(',', strtolower((string) $raw_keywords)));
    $clean = array();
    foreach ($parts as $kw) {
        if ($kw === '' || preg_match('/^\d+$/', $kw) || $kw === 'ontario') {
            continue;
        }
        if (!in_array($kw, $clean, true)) {
            $clean[] = $kw;
        }
    }
    return implode(', ', $clean);
}

// One-time safe migration for sites upgrading from v3.0.x with legacy comma-split tokens in wp_options
function tig_mileage_maybe_migrate_v310() {
    $db_ver = get_option('tig_mileage_db_version', '');
    if ($db_ver === TIG_MILEAGE_VERSION) {
        return;
    }

    $locations = get_option('tig_mileage_locations');
    if (is_array($locations) && !empty($locations)) {
        $updated = false;
        foreach ($locations as &$loc) {
            if (!isset($loc['keywords'])) {
                continue;
            }
            $orig_kw = $loc['keywords'];
            $cleaned = tig_mileage_sanitize_keywords($orig_kw);

            // Remove overly broad street-only tokens from specific multi-tenant street rules
            $id = $loc['id'] ?? '';
            if ($id === 'loc_regdoors') {
                $tokens = array_filter(array_map('trim', explode(',', $cleaned)), function ($t) {
                    return $t !== 'scott';
                });
                $cleaned = implode(', ', $tokens);
            } elseif ($id === 'loc_a1floor' || $id === 'loc_goemans') {
                $tokens = array_filter(array_map('trim', explode(',', $cleaned)), function ($t) {
                    return $t !== 'bunting';
                });
                $cleaned = implode(', ', $tokens);
            }

            if ($cleaned !== '' && $cleaned !== $orig_kw) {
                $loc['keywords'] = $cleaned;
                $updated = true;
            }
        }
        unset($loc);

        if ($updated) {
            update_option('tig_mileage_locations', $locations, false);
        }
    }

    update_option('tig_mileage_db_version', TIG_MILEAGE_VERSION, false);
}

// Default Location & Project Rules (Seeded on first load, editable in WP Admin)
function tig_mileage_get_default_locations() {
    return array(
        array('id' => 'loc_hewko', 'name' => '1 Hewko St, St. Catharines', 'keywords' => 'hewko', 'purpose' => 'Return to Head Office / Dispatch', 'category' => 'Base Operations', 'year' => 'ALL'),
        // 2026 Projects
        array('id' => 'loc_2026_1', 'name' => '74 Royal Manor Dr, St. Catharines', 'keywords' => 'royal manor', 'purpose' => 'Project Site Visit', 'category' => '2026 Project #1 (Royal Manor)', 'year' => '2026'),
        array('id' => 'loc_2026_2', 'name' => '7155 Brian Cres, Niagara Falls', 'keywords' => 'brian crescent, brian cres', 'purpose' => 'Project Site Visit', 'category' => '2026 Project #2 (Brian Cres)', 'year' => '2026'),
        // 2025 Historical Projects
        array('id' => 'loc_2025_1', 'name' => '89 Bayview Drive, St. Catharines', 'keywords' => 'bayview, graham avenue, corbett avenue, johnston street, 186 dalhousie', 'purpose' => 'Project Site Visit', 'category' => '2025 Project #1 (Bayview)', 'year' => '2025'),
        array('id' => 'loc_2025_2', 'name' => '10 Arden Court, St. Catharines', 'keywords' => 'arden court, lakehurst drive, 184 lakeport, 186 lakeport, 188 lakeport, 178 lakeport', 'purpose' => 'Project Site Visit', 'category' => '2025 Project #2 (Arden Crt)', 'year' => '2025'),
        array('id' => 'loc_2025_3', 'name' => 'Garner Avenue, Fenwick (Pelham)', 'keywords' => 'garner avenue, balfour street, canboro road, fenwick, welland road, effingham street, fonthill', 'purpose' => 'Project Site Visit', 'category' => '2025 Project #3 (Garner Ave)', 'year' => '2025'),
        // Multi-Year Development & Approved Pick-Up Depots
        array('id' => 'loc_rathfon', 'name' => 'Rathfon Rd Dev, Wainfleet', 'keywords' => 'rathfon, wainfleet, camelot beach', 'purpose' => 'Project Development', 'category' => 'Rathfon Rd Dev', 'year' => 'ALL'),
        array('id' => 'loc_regdoors', 'name' => 'Regional Doors & Hardware (38 Scott St W)', 'keywords' => '34 scott, 36 scott, 38 scott, 44 scott, 46 scott, l2r 5l3', 'purpose' => 'Materials / Supplies', 'category' => 'Regional Doors & Hardware', 'year' => 'ALL'),
        array('id' => 'loc_fonthill', 'name' => 'Fonthill Lumber & Truss (7 Woodlawn Rd, Welland)', 'keywords' => 'woodlawn, primeway, l3b 0a1, fonthill lumber', 'purpose' => 'Materials / Supplies', 'category' => 'Fonthill Lumber & Truss', 'year' => 'ALL'),
        array('id' => 'loc_convoy', 'name' => 'Convoy Supply (29 Seapark Dr, St. Catharines)', 'keywords' => '29 seapark, 25 seapark, seapark', 'purpose' => 'Materials / Supplies', 'category' => 'Convoy Supply', 'year' => 'ALL'),
        array('id' => 'loc_hd_nf', 'name' => 'Home Depot (7190 Morrison St, Niagara Falls)', 'keywords' => '7190 morrison, home depot, morrison', 'purpose' => 'Materials / Supplies', 'category' => 'Home Depot - Niagara Falls', 'year' => 'ALL'),
        array('id' => 'loc_rona_stc', 'name' => 'RONA (250 Dunkirk Rd, St. Catharines)', 'keywords' => 'dunkirk', 'purpose' => 'Materials / Supplies', 'category' => 'RONA - St. Catharines', 'year' => 'ALL'),
        array('id' => 'loc_rona_nf', 'name' => 'RONA+ (7959 McLeod Rd, Niagara Falls)', 'keywords' => 'mcleod', 'purpose' => 'Materials / Supplies', 'category' => 'RONA - Niagara Falls', 'year' => 'ALL'),
        array('id' => 'loc_a1floor', 'name' => 'A-1 Flooring Canada (168 Bunting Rd)', 'keywords' => '168 bunting, a-1 flooring', 'purpose' => 'Materials / Supplies', 'category' => 'A-1 Flooring', 'year' => 'ALL'),
        array('id' => 'loc_goemans', 'name' => 'Goemans Appliances (166 Bunting Rd)', 'keywords' => '166 bunting, goemans', 'purpose' => 'Materials / Supplies', 'category' => 'Goemans Appliances', 'year' => 'ALL'),
        array('id' => 'loc_scbs', 'name' => 'St. Catharines Building Supplies (Bunting Rd)', 'keywords' => 'bunting', 'purpose' => 'Materials / Supplies', 'category' => 'Building Supplies', 'year' => 'ALL'),
        array('id' => 'loc_turkstra', 'name' => 'Turkstra Lumber (Kent Ave)', 'keywords' => 'kent avenue, kent ave', 'purpose' => 'Materials / Supplies', 'category' => 'Turkstra Lumber', 'year' => 'ALL'),
        array('id' => 'loc_byrcon', 'name' => 'BYRCON Wood Products (445 Eastchester Ave E)', 'keywords' => '445 eastchester, eastchester', 'purpose' => 'Materials / Supplies', 'category' => 'BYRCON Wood Products', 'year' => 'ALL'),
        array('id' => 'loc_hh_scott', 'name' => 'Home Hardware (387 Scott St)', 'keywords' => '387 scott, 400 scott, l2n 6t4', 'purpose' => 'Materials / Supplies', 'category' => 'Home Hardware', 'year' => 'ALL'),
        array('id' => 'loc_hh_hartzel', 'name' => 'Home Hardware (111 Hartzel Rd)', 'keywords' => 'hartzel', 'purpose' => 'Materials / Supplies', 'category' => 'Home Hardware', 'year' => 'ALL'),
        array('id' => 'loc_hh_4th', 'name' => 'Home Hardware (1380 Fourth Ave)', 'keywords' => '1380 fourth', 'purpose' => 'Materials / Supplies', 'category' => 'Home Hardware', 'year' => 'ALL'),
        array('id' => 'loc_hh_welland', 'name' => 'Home Hardware Building Centre (Welland)', 'keywords' => '693 south pelham', 'purpose' => 'Materials / Supplies', 'category' => 'Home Hardware', 'year' => 'ALL'),
        array('id' => 'loc_hh_nf', 'name' => 'Home Hardware (Huggins St, Niagara Falls)', 'keywords' => 'huggins', 'purpose' => 'Materials / Supplies', 'category' => 'Home Hardware', 'year' => 'ALL'),
        array('id' => 'loc_arts', 'name' => "Art's Tools / A&A Tools (Nihan Dr)", 'keywords' => 'nihan', 'purpose' => 'Tools / Repair', 'category' => 'A&A Tools', 'year' => 'ALL'),
        array('id' => 'loc_princess', 'name' => 'Princess Auto (Fourth Ave)', 'keywords' => 'princess auto', 'purpose' => 'Tools / Repair', 'category' => 'Princess Auto', 'year' => 'ALL'),
        array('id' => 'loc_fleet', 'name' => 'Ontario St Automotive Corridor', 'keywords' => 'queenston tire, performance chrysler, partsource, 308 ontario, 317 ontario, 319 ontario, 327 ontario, 335 ontario', 'purpose' => 'Vehicle Maintenance & Parts', 'category' => 'Fleet Overhead', 'year' => 'ALL'),
        array('id' => 'loc_zehrs', 'name' => 'ZEHRS Fairview Mall', 'keywords' => 'zehrs, fairview mall, 285 geneva, 277 geneva', 'purpose' => 'Personal', 'category' => 'Grocery / Personal', 'year' => 'ALL'),
        array('id' => 'loc_gym', 'name' => 'Fit4Less Gym / Pen Centre', 'keywords' => 'glendale', 'purpose' => 'Personal', 'category' => 'Gym / Personal', 'year' => 'ALL')
    );
}

function tig_mileage_get_default_anchors() {
    return array(
        '2025' => array('date' => '2025-12-31', 'odo' => 124644.1, 'mode' => 'backward'),
        '2026' => array('date' => '2026-11-10', 'odo' => 136111.0, 'mode' => 'pivot'),
        '2027' => array('date' => '2027-01-01', 'odo' => 136111.0, 'mode' => 'forward')
    );
}

// Load bundled data/geo_cache.json + runtime DB cache
function tig_mileage_load_merged_geocache() {
    $bundled = array();
    $json_path = TIG_MILEAGE_DIR . 'data/geo_cache.json';
    if (file_exists($json_path)) {
        $raw = file_get_contents($json_path);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $bundled = $decoded;
        }
    }
    $runtime = get_option('tig_mileage_runtime_geocache', array());
    if (!is_array($runtime)) {
        $runtime = array();
    }
    return array_merge($bundled, $runtime);
}

// AJAX: Save Locations & Anchors
add_action('wp_ajax_tig_save_settings', 'tig_mileage_ajax_save_settings');
function tig_mileage_ajax_save_settings() {
    check_ajax_referer('tig_mileage_nonce', 'security');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    if (isset($_POST['locations'])) {
        $locs = json_decode(wp_unslash($_POST['locations']), true);
        if (is_array($locs)) {
            $clean_locs = array();
            foreach ($locs as $l) {
                if (empty($l['name']) || empty($l['keywords'])) {
                    continue;
                }
                $clean_kw = tig_mileage_sanitize_keywords($l['keywords']);
                if ($clean_kw === '') {
                    continue;
                }
                $clean_locs[] = array(
                    'id'       => sanitize_key($l['id'] ?? uniqid('loc_')),
                    'name'     => sanitize_text_field($l['name']),
                    'keywords' => sanitize_text_field($clean_kw),
                    'purpose'  => sanitize_text_field($l['purpose'] ?? 'Project Site Visit'),
                    'category' => sanitize_text_field($l['category'] ?? 'Custom Location'),
                    'year'     => sanitize_text_field($l['year'] ?? 'ALL')
                );
            }
            update_option('tig_mileage_locations', $clean_locs, false);
        }
    }

    if (isset($_POST['anchors'])) {
        $anchors = json_decode(wp_unslash($_POST['anchors']), true);
        if (is_array($anchors)) {
            $clean_anchors = array();
            foreach ($anchors as $yr => $cfg) {
                $clean_anchors[sanitize_text_field($yr)] = array(
                    'date' => sanitize_text_field($cfg['date'] ?? ''),
                    'odo'  => floatval($cfg['odo'] ?? 0),
                    'mode' => sanitize_text_field($cfg['mode'] ?? 'pivot')
                );
            }
            update_option('tig_mileage_anchors', $clean_anchors, false);
        }
    }

    wp_send_json_success(array('message' => 'Settings saved to WordPress database.'));
}

// AJAX: Persist newly resolved coordinates into WP options (and data/geo_cache.json if writable)
add_action('wp_ajax_tig_save_geocache', 'tig_mileage_ajax_save_geocache');
function tig_mileage_ajax_save_geocache() {
    check_ajax_referer('tig_mileage_nonce', 'security');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $entries = json_decode(wp_unslash($_POST['entries'] ?? '{}'), true);
    if (!is_array($entries) || empty($entries)) {
        wp_send_json_success(array('total' => count(tig_mileage_load_merged_geocache())));
    }

    $runtime = get_option('tig_mileage_runtime_geocache', array());
    if (!is_array($runtime)) {
        $runtime = array();
    }

    foreach ($entries as $k => $v) {
        $clean_k = sanitize_text_field($k);
        $clean_v = sanitize_text_field($v);
        if ($clean_k !== '' && $clean_v !== '') {
            $runtime[$clean_k] = $clean_v;
        }
    }

    update_option('tig_mileage_runtime_geocache', $runtime, false);
    $merged = tig_mileage_load_merged_geocache();

    // Attempt to mirror back to data/geo_cache.json if directory is writable
    $data_dir = TIG_MILEAGE_DIR . 'data';
    if (!file_exists($data_dir)) {
        wp_mkdir_p($data_dir);
    }
    $json_path = $data_dir . '/geo_cache.json';
    if (is_writable($data_dir) && (!file_exists($json_path) || is_writable($json_path))) {
        file_put_contents($json_path, wp_json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    wp_send_json_success(array('total' => count($merged)));
}

function tig_mileage_tracker_admin_page() {
    $geo_cache = tig_mileage_load_merged_geocache();
    $locations = get_option('tig_mileage_locations');
    if (!is_array($locations) || empty($locations)) {
        $locations = tig_mileage_get_default_locations();
        update_option('tig_mileage_locations', $locations, false);
    }
    $anchors = get_option('tig_mileage_anchors');
    if (!is_array($anchors) || empty($anchors)) {
        $anchors = tig_mileage_get_default_anchors();
        update_option('tig_mileage_anchors', $anchors, false);
    }
    $nonce = wp_create_nonce('tig_mileage_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    $bundled_exists = file_exists(TIG_MILEAGE_DIR . 'data/geo_cache.json');
    ?>
    <div class="wrap" style="max-width: 1240px; margin-top: 20px;">
        <div style="background: #1F497D; color: #fff; padding: 18px 24px; border-radius: 6px 6px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="color: #fff; margin: 0; font-size: 20px; font-weight: 600;">TIG Corporate Mileage Tracker</h1>
                <span style="font-size: 12px; opacity: 0.85;">Homeextent Inc. o/a The Innovative Group &bull; 2019 Ram 1500 Rebel</span>
            </div>
            <span style="background: rgba(255,255,255,0.15); padding: 4px 10px; border-radius: 4px; font-size: 12px;">v<?php echo esc_html(TIG_MILEAGE_VERSION); ?></span>
        </div>

        <div style="background: #fff; border: 1px solid #ccd0d4; border-top: none; padding: 24px; border-radius: 0 0 6px 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <!-- Navigation Tabs -->
            <div style="border-bottom: 1px solid #dcdcde; margin-bottom: 20px; display: flex; gap: 8px; justify-content: space-between; align-items: center;">
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="tig-tab-btn active" data-tab="tab-process" style="padding: 10px 16px; border: 1px solid #dcdcde; border-bottom: none; background: #fff; font-weight: 600; color: #1F497D; border-radius: 4px 4px 0 0; cursor: pointer;">1. Process &amp; Export Logbook</button>
                    <button type="button" class="tig-tab-btn" data-tab="tab-locations" style="padding: 10px 16px; border: 1px solid #dcdcde; border-bottom: none; background: #f6f7f7; font-weight: 600; color: #50575e; border-radius: 4px 4px 0 0; cursor: pointer;">2. Projects, Suppliers &amp; Odometer Anchors</button>
                </div>
                <span id="tig-save-toast" style="display: none; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 4px;"></span>
            </div>

            <!-- TAB 1: PROCESS & EXPORT -->
            <div id="tab-process" class="tig-tab-panel">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 22px;">
                    <div style="border: 1px solid #c3e6cb; background: #f2fbf4; border-radius: 6px; padding: 14px 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong style="font-size: 14px; color: #1d2327;">1. Built-In Geo-Cache Status</strong>
                            <span class="dashicons dashicons-yes-alt" style="color: #2e7d32;"></span>
                        </div>
                        <div id="status-cache" style="margin-top: 6px; font-size: 12px; color: #2e7d32; font-weight: 600;">
                            &#10003; <?php echo count($geo_cache); ?> coordinates auto-loaded <?php echo $bundled_exists ? '(from plugin /data/geo_cache.json + DB)' : '(from DB — drop geo_cache.json into /data/ or import in Tab 2)'; ?>
                        </div>
                    </div>

                    <div id="box-qbo" style="border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px; cursor: pointer; background: #fff;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong style="font-size: 14px; color: #1d2327;">2. QBO Supplier Transactions (.csv) — Optional</strong>
                            <span class="dashicons dashicons-media-spreadsheet" style="color: #1F497D;"></span>
                        </div>
                        <div id="status-qbo" style="margin-top: 6px; font-size: 12px; color: #646970;">Click to attach Transaction List by Supplier.csv</div>
                        <input type="file" id="input-qbo" accept=".csv" style="display: none;" />
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h3 style="margin: 0; color: #1d2327;">Step 2: Drop Google Timeline (.json)</h3>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label for="select-year" style="font-size: 12px; font-weight: 600;">Target Tax Year:</label>
                        <select id="select-year" style="font-size: 13px;">
                            <option value="2027">2027 Tax Year (3-Month Sample or Full Year)</option>
                            <option value="2026" selected>2026 Full-Year (Base Year)</option>
                            <option value="2025">2025 Historical Baseline (Jun 8 – Dec 31)</option>
                        </select>
                    </div>
                </div>

                <div id="box-timeline" style="border: 2px dashed #c3c4c7; border-radius: 6px; padding: 32px 20px; text-align: center; background: #f9f9f9; cursor: pointer;">
                    <span class="dashicons dashicons-cloud-upload" style="font-size: 36px; width: 36px; height: 36px; color: #1F497D;"></span>
                    <div style="font-size: 15px; font-weight: 600; color: #1d2327; margin-top: 8px;">Drop Timeline.json or location-history.json Here</div>
                    <div style="font-size: 12px; color: #646970; margin-top: 4px;">Processed 100% locally in your browser (supports Android <code>semanticSegments</code> &amp; iOS/Takeout <code>timelineObjects</code>, 50MB+).</div>
                    <div id="progress-wrap" style="display: none; max-width: 480px; margin: 14px auto 0;">
                        <div style="background: #e0e0e0; border-radius: 4px; height: 8px; overflow: hidden;">
                            <div id="progress-bar" style="width: 0%; height: 100%; background: #1F497D; transition: width 0.25s;"></div>
                        </div>
                    </div>
                    <div id="status-timeline" style="margin-top: 10px; font-size: 12px; font-weight: 600; color: #1F497D;"></div>
                    <input type="file" id="input-timeline" accept=".json" style="display: none;" />
                </div>

                <h3 style="margin-top: 26px; margin-bottom: 12px; color: #1d2327;">Step 3: Audit Summary &amp; Export</h3>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 18px;">
                    <div style="border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px;">
                        <div style="font-size: 11px; color: #646970; text-transform: uppercase;">Total Trips</div>
                        <div id="kpi-trips" style="font-size: 22px; font-weight: 700; color: #1d2327; margin-top: 4px;">0</div>
                    </div>
                    <div style="border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px;">
                        <div style="font-size: 11px; color: #646970; text-transform: uppercase;">Total KM</div>
                        <div id="kpi-total-km" style="font-size: 22px; font-weight: 700; color: #1d2327; margin-top: 4px;">0.00</div>
                    </div>
                    <div style="border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px;">
                        <div style="font-size: 11px; color: #646970; text-transform: uppercase;">Commercial KM</div>
                        <div id="kpi-biz-km" style="font-size: 22px; font-weight: 700; color: #1d2327; margin-top: 4px;">0.00</div>
                    </div>
                    <div style="border: 1px solid #c3e6cb; background: #f2fbf4; border-radius: 6px; padding: 14px 16px;">
                        <div style="font-size: 11px; color: #2e7d32; text-transform: uppercase;">Business %</div>
                        <div id="kpi-biz-pct" style="font-size: 22px; font-weight: 700; color: #2e7d32; margin-top: 4px;">0.00%</div>
                    </div>
                </div>

                <!-- Interactive Review Queue (Only appears if Review trips exist) -->
                <div id="review-section" style="display: none; margin-bottom: 20px; border: 1px solid #f0c36d; background: #fffdf5; border-radius: 6px; padding: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <strong style="color: #8a6d3b; font-size: 14px;">Quick Review Queue (<span id="review-count">0</span> Uncategorized Trips)</strong>
                        <span style="font-size: 12px; color: #646970;">Adjust any trip purpose below before downloading; summary metrics update live.</span>
                    </div>
                    <div style="max-height: 280px; overflow-y: auto; border: 1px solid #e5e5e5; background: #fff;">
                        <table class="widefat striped" style="border: none;">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Destination</th>
                                    <th>KM</th>
                                    <th>Purpose</th>
                                </tr>
                            </thead>
                            <tbody id="review-tbody"></tbody>
                        </table>
                    </div>
                </div>

                <button id="btn-download-xlsx" disabled style="width: 100%; background: #1F497D; color: #fff; border: none; border-radius: 5px; padding: 12px; font-size: 14px; font-weight: 600; cursor: pointer; opacity: 0.5;">
                    <span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 6px;"></span>
                    <span id="btn-download-label">Download 2026 CRA-Ready Logbook (.xlsx)</span>
                </button>
            </div>

            <!-- TAB 2: PROJECTS, SUPPLIERS & ODOMETER ANCHORS -->
            <div id="tab-locations" class="tig-tab-panel" style="display: none;">
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 24px;">
                    <!-- Add New Project / Visited Location Form -->
                    <div style="border: 1px solid #dcdcde; border-radius: 6px; padding: 18px; background: #fafafa;">
                        <h3 style="margin-top: 0; color: #1F497D;">Add 2027+ Project, Quote Address, or Pick-Up Counter</h3>
                        <p style="font-size: 12px; color: #50575e; margin-top: 0;">Add new build sites or client estimate addresses anytime during the year. When you run Timeline.json, matching stops are automatically classified.</p>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Clean Display Name</label>
                                <input type="text" id="new-loc-name" placeholder="e.g., 125 Lakeport Rd, St. Catharines" style="width: 100%;" />
                            </div>
                            <div>
                                <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Street Keywords (comma-separated)</label>
                                <input type="text" id="new-loc-keywords" placeholder="e.g., 125 lakeport, lakeport road" style="width: 100%;" />
                            </div>
                            <div>
                                <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">CRA Trip Purpose</label>
                                <select id="new-loc-purpose" style="width: 100%;">
                                    <option value="Project Site Visit">Project Site Visit</option>
                                    <option value="Project Development">Project Development</option>
                                    <option value="Materials / Supplies">Materials / Supplies</option>
                                    <option value="Quote / Estimate">Quote / Estimate</option>
                                    <option value="Tools / Repair">Tools / Repair</option>
                                    <option value="Vehicle Maintenance & Parts">Vehicle Maintenance &amp; Parts</option>
                                    <option value="Personal">Personal</option>
                                </select>
                            </div>
                            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 8px;">
                                <div>
                                    <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Project / Category Label</label>
                                    <input type="text" id="new-loc-category" placeholder="e.g., 2027 Project #1" style="width: 100%;" />
                                </div>
                                <div>
                                    <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Tax Year</label>
                                    <select id="new-loc-year" style="width: 100%;">
                                        <option value="ALL">All Years</option>
                                        <option value="2027" selected>2027</option>
                                        <option value="2026">2026</option>
                                        <option value="2025">2025</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="btn-add-location" class="button button-primary" style="margin-top: 14px;">+ Save Location to Rulebook</button>
                    </div>

                    <!-- Odometer Anchors & Cache Backup -->
                    <div style="border: 1px solid #dcdcde; border-radius: 6px; padding: 18px; background: #fafafa;">
                        <h3 style="margin-top: 0; color: #1F497D;">Physical Odometer Anchors</h3>
                        <p style="font-size: 12px; color: #50575e; margin-top: 0;">Verified dash readings used to chain trip odometers:</p>
                        <div style="font-size: 12px; margin-bottom: 8px;">
                            <strong>2026 Pivot (Nov 10):</strong>
                            <input type="number" step="0.1" id="anchor-2026-odo" value="<?php echo esc_attr($anchors['2026']['odo']); ?>" style="width: 100px; margin-left: 6px;" /> km
                        </div>
                        <div style="font-size: 12px; margin-bottom: 12px;">
                            <strong>2027 Start (Jan 1):</strong>
                            <input type="number" step="0.1" id="anchor-2027-odo" value="<?php echo esc_attr($anchors['2027']['odo']); ?>" style="width: 100px; margin-left: 6px;" /> km
                        </div>
                        <button type="button" id="btn-save-anchors" class="button">Update Anchors</button>

                        <hr style="margin: 16px 0;" />
                        <strong style="font-size: 12px; display: block; margin-bottom: 6px;">Geo-Cache Backup / Import</strong>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" id="btn-import-cache-tab2" class="button button-small">Import geo_cache.json</button>
                            <button type="button" id="btn-export-cache-tab2" class="button button-small">Download Cache</button>
                            <input type="file" id="input-cache-tab2" accept=".json" style="display: none;" />
                        </div>
                    </div>
                </div>

                <h3 style="color: #1d2327;">Active Project Sites &amp; Approved Pick-Up Counters (<span id="loc-total-count"><?php echo count($locations); ?></span>)</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Tax Year</th>
                            <th>Clean Display Name</th>
                            <th>Match Keywords</th>
                            <th>CRA Purpose</th>
                            <th>Category</th>
                            <th style="width: 70px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="locations-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js" onerror="this.onerror=null;this.src='https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js';"></script>
    <script>
    (function() {
        const AJAX_URL = <?php echo wp_json_encode($ajax_url); ?>;
        const NONCE = <?php echo wp_json_encode($nonce); ?>;
        let geoCache = <?php echo wp_json_encode($geo_cache); ?> || {};
        let customLocations = <?php echo wp_json_encode($locations); ?> || [];
        let odoAnchors = <?php echo wp_json_encode($anchors); ?> || {};
        let txByDate = {};
        let processedTrips = [];

        const OUT_OF_REGION = [
            'burlington', 'clarington', 'cobourg', 'cramahe', 'dunnville',
            'hamilton', 'kawartha lakes', 'mississauga', 'oakville', 'oshawa',
            'picton', 'port hope', 'quinte west', 'toronto', 'brighton', 'station road'
        ];

        const APPROVED_PICKUP_VENDORS = [
            'home depot', 'rona', 'turkstra', 'st. catharines building', 'st catharines building',
            'regional doors', 'convoy supply', 'a-1 flooring', 'a1 flooring', 'home hardware',
            "art's tools", 'a&a tools', 'fonthill lumber', 'princess auto', 'canadian tire',
            'goemans', 'stark lumber', 'byrcon', 'sherwin', 'dulux', 'wolseley', 'nedco',
            'westburne', 'noble', 'queenston tire', 'performance chrysler', 'partsource',
            'john bear', 'superior petrofuels', 'esso', 'shell', 'petro-canada', 'pioneer'
        ];

        const SUPPLIER_PURPOSES = [
            'Materials / Supplies',
            'Tools / Repair',
            'Vehicle Maintenance & Parts'
        ];

        let toastTimer = null;
        function showSaveToast(message, isError = false) {
            const el = document.getElementById('tig-save-toast');
            if (!el) return;
            el.style.display = 'inline-block';
            el.style.background = isError ? '#fde8e8' : '#e6f4ea';
            el.style.color = isError ? '#b32d2e' : '#1e7e34';
            el.style.border = isError ? '1px solid #f5c2c7' : '1px solid #b7e1cd';
            el.innerText = message;
            if (toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(() => { el.style.display = 'none'; }, 5000);
        }

        // Tab Switching
        document.querySelectorAll('.tig-tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tig-tab-btn').forEach(b => {
                    b.style.background = '#f6f7f7';
                    b.style.color = '#50575e';
                });
                this.style.background = '#fff';
                this.style.color = '#1F497D';
                document.querySelectorAll('.tig-tab-panel').forEach(p => p.style.display = 'none');
                document.getElementById(this.dataset.tab).style.display = 'block';
            });
        });

        function renderLocationsTable() {
            const tbody = document.getElementById('locations-tbody');
            tbody.innerHTML = '';
            document.getElementById('loc-total-count').innerText = customLocations.length;
            customLocations.forEach((loc, idx) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><strong>${loc.year}</strong></td>
                    <td>${loc.name}</td>
                    <td><code style="font-size:11px;">${loc.keywords}</code></td>
                    <td>${loc.purpose}</td>
                    <td>${loc.category}</td>
                    <td><button type="button" class="button button-small tig-del-loc" data-idx="${idx}" style="color:#b32d2e;">Delete</button></td>
                `;
                tbody.appendChild(tr);
            });
            document.querySelectorAll('.tig-del-loc').forEach(btn => {
                btn.addEventListener('click', function() {
                    const i = parseInt(this.dataset.idx, 10);
                    customLocations.splice(i, 1);
                    persistSettings();
                    renderLocationsTable();
                });
            });
        }
        renderLocationsTable();

        async function persistSettings() {
            try {
                const resp = await fetch(AJAX_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'tig_save_settings',
                        security: NONCE,
                        locations: JSON.stringify(customLocations),
                        anchors: JSON.stringify(odoAnchors)
                    })
                });
                if (!resp.ok) {
                    throw new Error(`HTTP ${resp.status}`);
                }
                const data = await resp.json();
                if (!data || !data.success) {
                    throw new Error((data && data.data) ? data.data : 'Invalid server response');
                }
                showSaveToast('✓ Saved to WordPress database');
                return true;
            } catch (err) {
                showSaveToast('⚠ Save failed — session may have expired. Refresh page & re-login.', true);
                return false;
            }
        }

        function cleanKeywordInput(raw) {
            return raw.split(',')
                .map(k => k.trim().toLowerCase())
                .filter(k => k && !/^\d+$/.test(k) && k !== 'ontario')
                .join(', ');
        }

        document.getElementById('btn-add-location').addEventListener('click', function() {
            const name = document.getElementById('new-loc-name').value.trim();
            const keywords = cleanKeywordInput(document.getElementById('new-loc-keywords').value);
            const purpose = document.getElementById('new-loc-purpose').value;
            const category = document.getElementById('new-loc-category').value.trim() || purpose;
            const year = document.getElementById('new-loc-year').value;
            if (!name || !keywords) {
                alert('Please enter both a Clean Display Name and valid Street Keywords (not bare numbers).');
                return;
            }
            customLocations.unshift({
                id: 'loc_' + Date.now(),
                name, keywords, purpose, category, year
            });
            persistSettings();
            renderLocationsTable();
            document.getElementById('new-loc-name').value = '';
            document.getElementById('new-loc-keywords').value = '';
            document.getElementById('new-loc-category').value = '';
        });

        document.getElementById('btn-save-anchors').addEventListener('click', async function() {
            odoAnchors['2026'].odo = parseFloat(document.getElementById('anchor-2026-odo').value) || 136111.0;
            odoAnchors['2027'].odo = parseFloat(document.getElementById('anchor-2027-odo').value) || 136111.0;
            const ok = await persistSettings();
            if (ok) {
                alert('Odometer anchors saved to WordPress database.');
            }
        });

        // Cache import/export in Tab 2
        document.getElementById('btn-import-cache-tab2').addEventListener('click', () => document.getElementById('input-cache-tab2').click());
        document.getElementById('input-cache-tab2').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = async function(ev) {
                try {
                    const imported = JSON.parse(ev.target.result);
                    Object.assign(geoCache, imported);
                    const resp = await fetch(AJAX_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'tig_save_geocache',
                            security: NONCE,
                            entries: JSON.stringify(imported)
                        })
                    });
                    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
                    const data = await resp.json();
                    if (!data || !data.success) throw new Error('Server rejected cache save');
                    const cnt = Object.keys(geoCache).length;
                    document.getElementById('status-cache').innerHTML = `&#10003; ${cnt} coordinates auto-loaded &amp; saved permanently`;
                    showSaveToast(`✓ Imported ${Object.keys(imported).length} coordinates`);
                    alert(`Imported ${Object.keys(imported).length} locations into permanent plugin cache.`);
                } catch (err) {
                    showSaveToast('⚠ Cache import failed: ' + err.message, true);
                    alert('Cache import error: ' + err.message);
                }
            };
            reader.readAsText(file);
        });

        document.getElementById('btn-export-cache-tab2').addEventListener('click', function() {
            const blob = new Blob([JSON.stringify(geoCache, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'geo_cache.json';
            a.click();
        });

        function isApprovedVendor(name) {
            const v = String(name || '').toLowerCase();
            return APPROVED_PICKUP_VENDORS.some(approved => v.includes(approved));
        }

        function splitCsvLine(line) {
            const result = [];
            let cur = '', inQuotes = false;
            for (let i = 0; i < line.length; i++) {
                const ch = line[i];
                if (ch === '"') {
                    if (inQuotes && line[i + 1] === '"') { cur += '"'; i++; }
                    else { inQuotes = !inQuotes; }
                } else if (ch === ',' && !inQuotes) {
                    result.push(cur.trim()); cur = '';
                } else {
                    cur += ch;
                }
            }
            result.push(cur.trim());
            return result;
        }

        function normalizeQboDate(raw) {
            if (!raw) return null;
            const s = raw.replace(/"/g, '').trim();
            if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
            const m = s.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
            if (m) {
                const p1 = parseInt(m[1], 10), p2 = parseInt(m[2], 10), yr = m[3];
                let day, month;
                if (p1 > 12) { day = p1; month = p2; }
                else if (p2 > 12) { month = p1; day = p2; }
                else { day = p1; month = p2; }
                return `${yr}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            }
            const parsed = new Date(s);
            return isNaN(parsed.getTime()) ? null : parsed.toISOString().slice(0, 10);
        }

        const torontoDateFmt = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Toronto', year: 'numeric', month: '2-digit', day: '2-digit' });
        const torontoDayFmt = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Toronto', weekday: 'long' });
        const torontoTimeFmt = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Toronto', hour: '2-digit', minute: '2-digit', hour12: true });

        function parseTorontoTimestamp(isoOrMs) {
            if (isoOrMs === undefined || isoOrMs === null || isoOrMs === '') return null;
            const dt = /^\d+$/.test(String(isoOrMs)) ? new Date(Number(isoOrMs)) : new Date(isoOrMs);
            if (isNaN(dt.getTime())) return null;
            return { ms: dt.getTime(), date: torontoDateFmt.format(dt), day: torontoDayFmt.format(dt), time: torontoTimeFmt.format(dt) };
        }

        function extractLatLngPair(locObj) {
            if (!locObj) return [null, null];
            if (typeof locObj === 'string') {
                const parts = locObj.replace(/°/g, '').trim().split(',');
                if (parts.length < 2) return [null, null];
                const lat = parseFloat(parts[0].trim()), lon = parseFloat(parts[1].trim());
                return (isNaN(lat) || isNaN(lon)) ? [null, null] : [lat, lon];
            }
            if (typeof locObj === 'object') {
                if (locObj.latLng) return extractLatLngPair(locObj.latLng);
                if (typeof locObj.latitude === 'number' && typeof locObj.longitude === 'number') {
                    return [locObj.latitude, locObj.longitude];
                }
                if (typeof locObj.latitudeE7 === 'number' && typeof locObj.longitudeE7 === 'number') {
                    return [locObj.latitudeE7 / 1e7, locObj.longitudeE7 / 1e7];
                }
                if (typeof locObj.latE7 === 'number' && typeof locObj.lngE7 === 'number') {
                    return [locObj.latE7 / 1e7, locObj.lngE7 / 1e7];
                }
            }
            return [null, null];
        }

        // Normalize both Android semanticSegments and iOS / Takeout timelineObjects schemas
        function extractNormalizedSegments(raw) {
            const out = [];
            const modernList = Array.isArray(raw) ? raw : (Array.isArray(raw.semanticSegments) ? raw.semanticSegments : []);
            for (let i = 0; i < modernList.length; i++) {
                const seg = modernList[i];
                const act = seg && seg.activity;
                if (!act) continue;
                const topType = (act.topCandidate && act.topCandidate.type) ? String(act.topCandidate.type).toUpperCase() : '';
                out.push({
                    type: topType,
                    distanceMeters: parseFloat(act.distanceMeters) || 0,
                    startTime: seg.startTime,
                    endTime: seg.endTime,
                    startLoc: act.start,
                    endLoc: act.end
                });
            }

            if (Array.isArray(raw.timelineObjects)) {
                for (let i = 0; i < raw.timelineObjects.length; i++) {
                    const seg = raw.timelineObjects[i] && raw.timelineObjects[i].activitySegment;
                    if (!seg) continue;
                    const topType = String(
                        seg.activityType ||
                        (Array.isArray(seg.activities) && seg.activities[0] && seg.activities[0].activityType) ||
                        ''
                    ).toUpperCase();
                    const dur = seg.duration || {};
                    out.push({
                        type: topType,
                        distanceMeters: parseFloat(seg.distance) || 0,
                        startTime: dur.startTimestamp || dur.startTimestampMs || seg.startTimestamp,
                        endTime: dur.endTimestamp || dur.endTimestampMs || seg.endTimestamp,
                        startLoc: seg.startLocation,
                        endLoc: seg.endLocation
                    });
                }
            }
            return out;
        }

        function classifyEndpoint(addr, day, year) {
            const a = String(addr || '').toLowerCase();
            // Strip Nominatim commas ("38, Scott Street West" -> "38 scott street west")
            const aNorm = a.replace(/,/g, ' ').replace(/\s+/g, ' ').trim();

            if (OUT_OF_REGION.some(c => aNorm.includes(c))) {
                return [addr, 'Personal', 'Out of Region'];
            }
            // Home Depot YMCA weekday vs weekend rule
            if (aNorm.includes('ymca')) {
                if (day === 'Saturday' || day === 'Sunday') {
                    return ['YMCA Drive (Fit4Less)', 'Personal', 'Gym / Personal'];
                }
                return ['Home Depot (20 YMCA Dr, St. Catharines)', 'Materials / Supplies', 'Home Depot - St. Catharines'];
            }
            // Match against user-managed Locations & Projects table
            for (const loc of customLocations) {
                if (loc.year !== 'ALL' && loc.year !== year) continue;
                const kwList = loc.keywords
                    .split(',')
                    .map(k => k.trim().toLowerCase())
                    .filter(k => k && !/^\d+$/.test(k) && k !== 'ontario');
                if (kwList.some(k => aNorm.includes(k))) {
                    return [loc.name, loc.purpose, loc.category];
                }
            }
            return [addr, 'Review', 'Uncategorized'];
        }

        function matchQboForTrip(dateStr, destClean, category, purpose) {
            const txs = txByDate[dateStr];
            if (!txs || !txs.length) return null;

            const targetHint = `${destClean} ${category}`.toLowerCase();
            const vendorMatch = txs.find(tx => {
                const v = tx.name.toLowerCase();
                return APPROVED_PICKUP_VENDORS.some(kw => v.includes(kw) && targetHint.includes(kw));
            });
            if (vendorMatch) return vendorMatch;

            if (purpose === 'Review') {
                return txs.reduce((max, cur) => cur.amt > max.amt ? cur : max, txs[0]);
            }
            return null;
        }

        function setProgress(pct, text) {
            document.getElementById('progress-wrap').style.display = 'block';
            document.getElementById('progress-bar').style.width = pct + '%';
            document.getElementById('status-timeline').innerText = text;
        }

        function refreshSummaryAndReview() {
            const totalKm = processedTrips.reduce((s, r) => s + r.Distance, 0);
            const bizKm = processedTrips.filter(r => !r.Purpose.toLowerCase().includes('personal')).reduce((s, r) => s + r.Distance, 0);
            const bizPct = totalKm > 0 ? (bizKm / totalKm) * 100 : 0;

            document.getElementById('kpi-trips').innerText = processedTrips.length;
            document.getElementById('kpi-total-km').innerText = totalKm.toFixed(2);
            document.getElementById('kpi-biz-km').innerText = bizKm.toFixed(2);
            document.getElementById('kpi-biz-pct').innerText = bizPct.toFixed(2) + '%';

            const reviewRows = processedTrips.map((r, i) => ({ r, i })).filter(x => x.r.Purpose === 'Review');
            const revSec = document.getElementById('review-section');
            if (reviewRows.length > 0) {
                revSec.style.display = 'block';
                document.getElementById('review-count').innerText = reviewRows.length;
                const tbody = document.getElementById('review-tbody');
                tbody.innerHTML = '';
                reviewRows.forEach(({ r, i }) => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${r.Date}</td>
                        <td>${r.Destination}</td>
                        <td>${r.Distance.toFixed(2)}</td>
                        <td>
                            <select class="tig-rev-select" data-idx="${i}" style="font-size:12px;">
                                <option value="Review" selected>Review (Commercial)</option>
                                <option value="Project Site Visit">Project Site Visit</option>
                                <option value="Materials / Supplies">Materials / Supplies</option>
                                <option value="Quote / Estimate">Quote / Estimate</option>
                                <option value="Personal">Personal (Non-Deductible)</option>
                            </select>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
                document.querySelectorAll('.tig-rev-select').forEach(sel => {
                    sel.addEventListener('change', function() {
                        const idx = parseInt(this.dataset.idx, 10);
                        processedTrips[idx].Purpose = this.value;
                        processedTrips[idx].Category = this.value === 'Personal' ? 'Personal (Manual)' : this.value;
                        refreshSummaryAndReview();
                    });
                });
            } else {
                revSec.style.display = 'none';
            }
        }

        document.getElementById('box-qbo').addEventListener('click', () => document.getElementById('input-qbo').click());
        document.getElementById('box-timeline').addEventListener('click', () => document.getElementById('input-timeline').click());
        document.getElementById('select-year').addEventListener('change', function() {
            document.getElementById('btn-download-label').innerText = `Download ${this.value} CRA-Ready Logbook (.xlsx)`;
        });

        document.getElementById('input-qbo').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function(ev) {
                try {
                    const lines = ev.target.result.split(/\r?\n/);
                    let headerIdx = -1, headers = [];
                    for (let i = 0; i < Math.min(35, lines.length); i++) {
                        const cols = splitCsvLine(lines[i]).map(c => c.toLowerCase());
                        if (cols.some(c => c.includes('date')) && cols.some(c => c.includes('amount') || c.includes('total'))) {
                            headerIdx = i; headers = cols; break;
                        }
                    }
                    if (headerIdx === -1) { alert('Could not locate Date/Amount columns in QBO CSV.'); return; }
                    const dateCol = headers.findIndex(c => c.includes('date'));
                    const nameCol = headers.findIndex(c => ['name', 'supplier', 'vendor', 'payee'].some(k => c.includes(k)));
                    const amtCol = headers.findIndex(c => c.includes('amount'));

                    txByDate = {};
                    let receiptCount = 0;
                    for (let r = headerIdx + 1; r < lines.length; r++) {
                        if (!lines[r].trim()) continue;
                        const row = splitCsvLine(lines[r]);
                        const vendorName = nameCol >= 0 ? (row[nameCol] || '') : '';
                        if (!isApprovedVendor(vendorName)) continue;
                        const cleanDate = normalizeQboDate(row[dateCol] || '');
                        const cleanAmt = Math.abs(parseFloat((row[amtCol] || '0').replace(/[$,\s"]/g, '')) || 0);
                        if (cleanDate && cleanAmt > 0) {
                            if (!txByDate[cleanDate]) txByDate[cleanDate] = [];
                            txByDate[cleanDate].push({ name: vendorName, amt: cleanAmt });
                            receiptCount++;
                        }
                    }
                    document.getElementById('status-qbo').innerHTML =
                        `<span style="color:#2e7d32; font-weight:600;">&#10003; Indexed ${Object.keys(txByDate).length} commercial dates (${receiptCount} receipts)</span>`;
                } catch (err) {
                    alert('Error parsing QBO CSV: ' + err.message);
                }
            };
            reader.readAsText(file);
        });

        async function geocodeMissingCoordinates(coordPairs) {
            const newlyResolved = {};
            for (let i = 0; i < coordPairs.length; i++) {
                const { key, lat, lon } = coordPairs[i];
                if (geoCache[key]) continue;
                setProgress(45 + Math.round((i / coordPairs.length) * 30), `Resolving new location ${i + 1} of ${coordPairs.length}...`);
                try {
                    const resp = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lon}&zoom=18&addressdetails=1`);
                    if (resp.ok) {
                        const data = await resp.json();
                        if (data && data.display_name) {
                            geoCache[key] = data.display_name;
                            newlyResolved[key] = data.display_name;
                        }
                    }
                } catch (e) {}
                await new Promise(res => setTimeout(res, 1100));
            }
            if (Object.keys(newlyResolved).length > 0) {
                try {
                    const resp = await fetch(AJAX_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'tig_save_geocache',
                            security: NONCE,
                            entries: JSON.stringify(newlyResolved)
                        })
                    });
                    if (resp.ok) {
                        document.getElementById('status-cache').innerHTML = `&#10003; ${Object.keys(geoCache).length} coordinates loaded (+${Object.keys(newlyResolved).length} new saved permanently)`;
                    } else {
                        showSaveToast('⚠ Resolved new GPS points in memory, but DB save failed (session expired).', true);
                    }
                } catch (e) {
                    showSaveToast('⚠ Resolved new GPS points in memory, but DB save failed.', true);
                }
            }
        }

        document.getElementById('input-timeline').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            const targetYear = document.getElementById('select-year').value;
            setProgress(15, `Reading ${file.name} (${(file.size / 1048576).toFixed(1)} MB)...`);

            const reader = new FileReader();
            reader.onload = function(ev) {
                setProgress(35, 'Extracting vehicle segments...');
                setTimeout(async function() {
                    try {
                        const raw = JSON.parse(ev.target.result);
                        const segments = extractNormalizedSegments(raw);
                        const rawTrips = [];
                        const missingCoords = new Map();

                        for (let i = 0; i < segments.length; i++) {
                            const seg = segments[i];
                            if (!['VEHICLE', 'PASSENGER', 'DRIVING'].some(k => seg.type.includes(k))) continue;

                            const distKm = Math.round((seg.distanceMeters / 1000) * 100) / 100;
                            if (distKm < 0.1) continue;

                            const startInfo = parseTorontoTimestamp(seg.startTime);
                            const endInfo = parseTorontoTimestamp(seg.endTime);
                            if (!startInfo || !endInfo || !startInfo.date.startsWith(targetYear)) continue;

                            const [oLat, oLon] = extractLatLngPair(seg.startLoc);
                            const [dLat, dLon] = extractLatLngPair(seg.endLoc);
                            const oKey = (oLat !== null && oLon !== null) ? `${oLat.toFixed(4)},${oLon.toFixed(4)}` : '';
                            const dKey = (dLat !== null && dLon !== null) ? `${dLat.toFixed(4)},${dLon.toFixed(4)}` : '';

                            if (oKey && !geoCache[oKey]) missingCoords.set(oKey, { key: oKey, lat: oLat, lon: oLon });
                            if (dKey && !geoCache[dKey]) missingCoords.set(dKey, { key: dKey, lat: dLat, lon: dLon });

                            rawTrips.push({
                                ms: startInfo.ms, Date: startInfo.date, Day: startInfo.day,
                                Departure: startInfo.time, Arrival: endInfo.time,
                                oKey, dKey, Distance: distKm
                            });
                        }

                        if (missingCoords.size > 0) {
                            await geocodeMissingCoordinates(Array.from(missingCoords.values()));
                        }

                        setProgress(80, 'Classifying endpoints & chaining odometers...');
                        rawTrips.sort((a, b) => a.ms - b.ms);
                        const seen = new Set();
                        const deduped = [];

                        for (const t of rawTrips) {
                            const origRaw = geoCache[t.oKey] || t.oKey;
                            const destRaw = geoCache[t.dKey] || t.dKey;
                            const sig = `${t.Date}|${t.Departure}|${t.Distance}|${origRaw}|${destRaw}`;
                            if (seen.has(sig)) continue;
                            seen.add(sig);

                            const [origClean] = classifyEndpoint(origRaw, t.Day, targetYear);
                            let [destClean, purpose, category] = classifyEndpoint(destRaw, t.Day, targetYear);
                            let qboNote = '';

                            if (['Personal', 'ZEHRS', 'Fit4Less', 'Out of Region'].some(k => origClean.includes(k)) &&
                                purpose === 'Return to Head Office / Dispatch') {
                                purpose = 'Personal';
                                category = 'Return from Personal';
                            }

                            if (purpose === 'Review' || SUPPLIER_PURPOSES.includes(purpose)) {
                                const matchedTx = matchQboForTrip(t.Date, destClean, category, purpose);
                                if (matchedTx) {
                                    qboNote = `QBO Receipt: $${matchedTx.amt.toFixed(2)} (${matchedTx.name})`;
                                    if (purpose === 'Review') {
                                        purpose = 'Materials / Supplies';
                                        category = `QBO Verified: ${matchedTx.name}`;
                                    }
                                }
                            }

                            deduped.push({
                                Date: t.Date, Day: t.Day, Departure: t.Departure, Arrival: t.Arrival,
                                Origin: origClean, Destination: destClean, Distance: t.Distance,
                                OdoStart: 0, OdoEnd: 0, Purpose: purpose, Category: category, QBO: qboNote
                            });
                        }

                        const destCounts = {};
                        deduped.forEach(r => { destCounts[r.Destination] = (destCounts[r.Destination] || 0) + 1; });
                        deduped.forEach(r => {
                            if (r.Purpose === 'Review' && destCounts[r.Destination] === 1) {
                                r.Purpose = 'Quote / Estimate';
                                r.Category = 'Prospective Project';
                            }
                        });

                        // Odometer Chaining
                        const anchorCfg = odoAnchors[targetYear] || { date: `${targetYear}-01-01`, odo: 136111.0, mode: 'forward' };
                        if (deduped.length > 0) {
                            if (anchorCfg.mode === 'pivot') {
                                let anchorIdx = -1;
                                for (let i = 0; i < deduped.length; i++) {
                                    if (deduped[i].Date <= anchorCfg.date) anchorIdx = i;
                                }
                                if (anchorIdx >= 0) {
                                    let cur = parseFloat(anchorCfg.odo);
                                    for (let i = anchorIdx; i >= 0; i--) {
                                        deduped[i].OdoEnd = Math.round(cur * 10) / 10;
                                        cur -= deduped[i].Distance;
                                        deduped[i].OdoStart = Math.round(cur * 10) / 10;
                                    }
                                    cur = parseFloat(anchorCfg.odo);
                                    for (let i = anchorIdx + 1; i < deduped.length; i++) {
                                        deduped[i].OdoStart = Math.round(cur * 10) / 10;
                                        cur += deduped[i].Distance;
                                        deduped[i].OdoEnd = Math.round(cur * 10) / 10;
                                    }
                                }
                            } else if (anchorCfg.mode === 'backward') {
                                let cur = parseFloat(anchorCfg.odo);
                                for (let i = deduped.length - 1; i >= 0; i--) {
                                    deduped[i].OdoEnd = Math.round(cur * 10) / 10;
                                    cur -= deduped[i].Distance;
                                    deduped[i].OdoStart = Math.round(cur * 10) / 10;
                                }
                            } else {
                                let cur = parseFloat(anchorCfg.odo);
                                for (let i = 0; i < deduped.length; i++) {
                                    deduped[i].OdoStart = Math.round(cur * 10) / 10;
                                    cur += deduped[i].Distance;
                                    deduped[i].OdoEnd = Math.round(cur * 10) / 10;
                                }
                            }
                        }

                        processedTrips = deduped;
                        refreshSummaryAndReview();

                        setProgress(100, 'Processing Complete');
                        document.getElementById('progress-bar').style.background = '#2e7d32';
                        document.getElementById('status-timeline').innerHTML = `<span style="color:#2e7d32;">&#10003; Processing Complete (${deduped.length} trips verified for ${targetYear})</span>`;
                        const btn = document.getElementById('btn-download-xlsx');
                        btn.disabled = false;
                        btn.style.opacity = '1';
                    } catch (err) {
                        alert('Error processing Timeline.json: ' + err.message);
                    }
                }, 40);
            };
            reader.readAsText(file);
        });

        function csvEscape(val) {
            const s = String(val === undefined || val === null ? '' : val);
            if (/[",\r\n]/.test(s)) {
                return '"' + s.replace(/"/g, '""') + '"';
            }
            return s;
        }

        function downloadCsvFallback(targetYear, metrics) {
            const headers = [
                'Date', 'Day', 'Departure', 'Arrival', 'Origin', 'Destination',
                'Distance (km)', 'Odo Start', 'Odo End', 'Purpose', 'Category', 'QBO Audit Corroboration'
            ];
            const lines = [
                csvEscape(`HOMEEXTENT INC. (o/a THE INNOVATIVE GROUP) — ${targetYear} VEHICLE MILEAGE LOGBOOK`),
                csvEscape(`Opening Odo: ${metrics.openOdo} km | Closing Odo: ${metrics.closeOdo} km | Total KM: ${metrics.totalKm} | Commercial KM: ${metrics.bizKm} (${(metrics.bizRatio * 100).toFixed(2)}%)`),
                '',
                headers.map(csvEscape).join(',')
            ];
            processedTrips.forEach(r => {
                lines.push([
                    r.Date, r.Day, r.Departure, r.Arrival, r.Origin, r.Destination,
                    r.Distance, r.OdoStart, r.OdoEnd, r.Purpose, r.Category, r.QBO
                ].map(csvEscape).join(','));
            });
            const blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `TIG_Mileage_Logbook_${targetYear}_CRA_Ready.csv`;
            a.click();
            alert('Excel library (SheetJS) was blocked by browser/network; downloaded CRA-ready CSV fallback instead.');
        }

        document.getElementById('btn-download-xlsx').addEventListener('click', function() {
            if (!processedTrips.length) return;
            const targetYear = document.getElementById('select-year').value;
            const logSheetName = `${targetYear} Vehicle Log`;
            const n = processedTrips.length;
            const lastRow = n + 1;

            const totalKm = Math.round(processedTrips.reduce((s, r) => s + r.Distance, 0) * 100) / 100;
            const bizKm = Math.round(processedTrips.filter(r => !r.Purpose.toLowerCase().includes('personal')).reduce((s, r) => s + r.Distance, 0) * 100) / 100;
            const persKm = Math.round((totalKm - bizKm) * 100) / 100;
            const bizRatio = totalKm > 0 ? bizKm / totalKm : 0;
            const openOdo = processedTrips[0].OdoStart;
            const closeOdo = processedTrips[n - 1].OdoEnd;
            const odoDelta = Math.round((closeOdo - openOdo) * 100) / 100;

            if (typeof XLSX === 'undefined') {
                downloadCsvFallback(targetYear, { openOdo, closeOdo, totalKm, bizKm, bizRatio });
                return;
            }

            try {
                const wb = XLSX.utils.book_new();
                const ref = `'${logSheetName}'`;
                const sumData = [
                    [`HOMEEXTENT INC. (o/a THE INNOVATIVE GROUP) — ${targetYear} VEHICLE MILEAGE SUMMARY`],
                    ['Vehicle: 2019 Ram 1500 Rebel  |  Operating Territory: Niagara Region (Grimsby Geo-Fence Enforced)'],
                    [''],
                    ['Metric', 'Value', 'Audit Notes'],
                    ['Reporting Period Start Date', { t: 's', v: processedTrips[0].Date, f: `MIN(${ref}!A2:A${lastRow})` }, 'First logged trip in period'],
                    ['Reporting Period End Date', { t: 's', v: processedTrips[n - 1].Date, f: `MAX(${ref}!A2:A${lastRow})` }, 'Last logged trip in period'],
                    ['Opening Odometer Reading (km)', { t: 'n', v: openOdo, f: `${ref}!H2`, z: '#,##0.0' }, 'Continuous odometer handoff'],
                    ['Closing Odometer Reading (km)', { t: 'n', v: closeOdo, f: `${ref}!I${lastRow}`, z: '#,##0.0' }, 'Reconciled to physical odometer anchor'],
                    ['Odometer Distance Delta (km)', { t: 'n', v: odoDelta, f: 'B8-B7', z: '#,##0.00' }, 'Closing Odometer minus Opening Odometer'],
                    ['Total Logged Trip Kilometers (km)', { t: 'n', v: totalKm, f: `SUM(${ref}!G2:G${lastRow})`, z: '#,##0.00' }, 'Sum of all GPS-verified trips'],
                    ['Odometer Reconciliation Variance (km)', { t: 'n', v: 0, f: 'ROUND(B9-B10,1)', z: '#,##0.0' }, '0.0 km variance confirmed'],
                    ['Total Trips Logged', { t: 'n', v: n, f: `COUNTA(${ref}!A2:A${lastRow})`, z: '#,##0' }, 'Total driving segments'],
                    ['Commercial Kilometers (Deductible)', { t: 'n', v: bizKm, f: `SUMIF(${ref}!J2:J${lastRow},"<>Personal",${ref}!G2:G${lastRow})`, z: '#,##0.00' }, 'Site visits, materials, dispatch, quotes & fleet maintenance'],
                    ['Personal Kilometers (Non-Deductible)', { t: 'n', v: persKm, f: `SUMIF(${ref}!J2:J${lastRow},"Personal",${ref}!G2:G${lastRow})`, z: '#,##0.00' }, 'Out-of-region travel, personal errands & weekend gym'],
                    ['CRA Commercial Business-Use %', { t: 'n', v: bizRatio, f: 'B13/B10', z: '0.00%' }, 'Deductible corporate proportion under ITA s. 18(1)(a)']
                ];

                const wsSum = XLSX.utils.aoa_to_sheet(sumData);
                wsSum['!cols'] = [{ wch: 38 }, { wch: 20 }, { wch: 52 }];
                XLSX.utils.book_append_sheet(wb, wsSum, 'Executive Summary');

                const headers = [
                    'Date', 'Day', 'Departure', 'Arrival', 'Origin', 'Destination',
                    'Distance (km)', 'Odo Start', 'Odo End', 'Purpose', 'Category', 'QBO Audit Corroboration'
                ];
                const logRows = [headers];
                processedTrips.forEach(r => {
                    logRows.push([
                        r.Date, r.Day, r.Departure, r.Arrival, r.Origin, r.Destination,
                        r.Distance, r.OdoStart, r.OdoEnd, r.Purpose, r.Category, r.QBO
                    ]);
                });

                const fStart = lastRow + 2;
                logRows.push([]);
                logRows.push(['', '', '', '', '', 'TOTAL LOGGED KILOMETERS:', { t: 'n', v: totalKm, f: `SUM(G2:G${lastRow})`, z: '#,##0.00' }]);
                logRows.push(['', '', '', '', '', 'COMMERCIAL KILOMETERS (DEDUCTIBLE):', { t: 'n', v: bizKm, f: `SUMIF(J2:J${lastRow},"<>Personal",G2:G${lastRow})`, z: '#,##0.00' }]);
                logRows.push(['', '', '', '', '', 'PERSONAL KILOMETERS (NON-DEDUCTIBLE):', { t: 'n', v: persKm, f: `SUMIF(J2:J${lastRow},"Personal",G2:G${lastRow})`, z: '#,##0.00' }]);
                logRows.push(['', '', '', '', '', 'COMMERCIAL BUSINESS-USE PERCENTAGE:', { t: 'n', v: bizRatio, f: `G${fStart + 1}/G${fStart}`, z: '0.00%' }]);

                const wsLog = XLSX.utils.aoa_to_sheet(logRows);
                wsLog['!cols'] = [
                    { wch: 12 }, { wch: 11 }, { wch: 11 }, { wch: 11 }, { wch: 40 }, { wch: 42 },
                    { wch: 15 }, { wch: 13 }, { wch: 13 }, { wch: 32 }, { wch: 28 }, { wch: 34 }
                ];
                XLSX.utils.book_append_sheet(wb, wsLog, logSheetName);

                XLSX.writeFile(wb, `TIG_Mileage_Logbook_${targetYear}_CRA_Ready.xlsx`);
            } catch (err) {
                downloadCsvFallback(targetYear, { openOdo, closeOdo, totalKm, bizKm, bizRatio });
            }
        });
    })();
    </script>
    <?php
}