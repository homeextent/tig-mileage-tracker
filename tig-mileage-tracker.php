<?php
/**
 * Plugin Name: TIG Corporate Mileage Tracker
 * Description: CRA-compliant mileage engine & REST API collector for Homeextent Inc. (2019 Ram 1500 Rebel). Supports Pixel 9 Bluetooth/Android Auto live sync, Google Timeline JSON, QBO receipt corroboration, and CPA-ready Excel workbooks.
 * Version: 3.3.0
 * Author: Homeextent Inc. / The Innovative Group
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TIG_MILEAGE_VERSION', '3.3.0');
define('TIG_MILEAGE_DIR', plugin_dir_path(__FILE__));
define('TIG_MILEAGE_TABLE', 'tig_mileage_trips');

register_activation_hook(__FILE__, 'tig_mileage_activate');
function tig_mileage_activate() {
    tig_mileage_create_trips_table();
    tig_mileage_maybe_migrate();
}

// Create or upgrade custom table for live Pixel 9 / Android Auto trips
function tig_mileage_create_trips_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . TIG_MILEAGE_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        trip_uid varchar(100) NOT NULL,
        tax_year varchar(4) NOT NULL,
        start_ms bigint(20) unsigned NOT NULL,
        end_ms bigint(20) unsigned NOT NULL,
        start_lat decimal(10,6) DEFAULT NULL,
        start_lon decimal(10,6) DEFAULT NULL,
        end_lat decimal(10,6) DEFAULT NULL,
        end_lon decimal(10,6) DEFAULT NULL,
        gps_km decimal(8,2) NOT NULL DEFAULT 0.00,
        trigger_source varchar(64) NOT NULL DEFAULT 'Uconnect BT',
        origin_raw text DEFAULT NULL,
        dest_raw text DEFAULT NULL,
        manual_purpose varchar(120) NOT NULL DEFAULT '',
        manual_category varchar(120) NOT NULL DEFAULT '',
        photo_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY trip_uid (trip_uid),
        KEY tax_year (tax_year),
        KEY start_ms (start_ms)
    ) {$charset_collate};";

    dbDelta($sql);
}

// SiteGround / Apache FastCGI fallback for Application Passwords over REST API
add_filter('determine_current_user', 'tig_mileage_rest_basic_auth_fallback', 20);
function tig_mileage_rest_basic_auth_fallback($user_id) {
    if (!empty($user_id)) {
        return $user_id;
    }
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return $user_id;
    }

    $auth_header = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth_header = wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (!empty($_SERVER['HTTP_X_TIG_AUTH'])) {
        $auth_header = wp_unslash($_SERVER['HTTP_X_TIG_AUTH']);
    }

    if (stripos($auth_header, 'Basic ') !== 0) {
        return $user_id;
    }

    $decoded = base64_decode(substr($auth_header, 6), true);
    if ($decoded === false || strpos($decoded, ':') === false) {
        return $user_id;
    }

    list($username, $password) = explode(':', $decoded, 2);
    if ($username === '' || $password === '') {
        return $user_id;
    }

    if (function_exists('wp_authenticate_application_password')) {
        $user = wp_authenticate_application_password(null, $username, $password);
        if ($user instanceof WP_User) {
            return $user->ID;
        }
    }

    return $user_id;
}

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

// Redirect legacy slugs cleanly & run schema migrations
add_action('admin_init', 'tig_mileage_admin_init');
function tig_mileage_admin_init() {
    if (!is_admin()) {
        return;
    }

    tig_mileage_maybe_migrate();

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

// Safe upgrade migration preserving user-added rules while cleaning legacy v3.0.x tokens & creating v3.3.0 table
function tig_mileage_maybe_migrate() {
    $db_ver = get_option('tig_mileage_db_version', '');
    if ($db_ver === TIG_MILEAGE_VERSION) {
        return;
    }

    if ($db_ver === '' || version_compare($db_ver, '3.1.0', '<')) {
        $locations = get_option('tig_mileage_locations');
        if (is_array($locations) && !empty($locations)) {
            $updated = false;
            foreach ($locations as &$loc) {
                if (!isset($loc['keywords'])) {
                    continue;
                }
                $orig_kw = $loc['keywords'];
                $cleaned = tig_mileage_sanitize_keywords($orig_kw);

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
    }

    if ($db_ver === '' || version_compare($db_ver, '3.3.0', '<')) {
        tig_mileage_create_trips_table();
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

function tig_mileage_get_locations_or_default() {
    $locations = get_option('tig_mileage_locations');
    if (!is_array($locations) || empty($locations)) {
        $locations = tig_mileage_get_default_locations();
        update_option('tig_mileage_locations', $locations, false);
    }
    return $locations;
}

function tig_mileage_get_anchors_or_default() {
    $anchors = get_option('tig_mileage_anchors');
    if (!is_array($anchors) || empty($anchors)) {
        $anchors = tig_mileage_get_default_anchors();
        update_option('tig_mileage_anchors', $anchors, false);
    }
    return $anchors;
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

// Persist new entries to runtime DB cache & data/geo_cache.json
function tig_mileage_persist_geocache_entries($new_entries) {
    if (!is_array($new_entries) || empty($new_entries)) {
        return count(tig_mileage_load_merged_geocache());
    }
    $runtime = get_option('tig_mileage_runtime_geocache', array());
    if (!is_array($runtime)) {
        $runtime = array();
    }
    foreach ($new_entries as $k => $v) {
        $clean_k = sanitize_text_field($k);
        $clean_v = sanitize_text_field($v);
        if ($clean_k !== '' && $clean_v !== '') {
            $runtime[$clean_k] = $clean_v;
        }
    }
    update_option('tig_mileage_runtime_geocache', $runtime, false);
    $merged = tig_mileage_load_merged_geocache();

    $data_dir = TIG_MILEAGE_DIR . 'data';
    if (!file_exists($data_dir)) {
        wp_mkdir_p($data_dir);
    }
    $json_path = $data_dir . '/geo_cache.json';
    if (is_writable($data_dir) && (!file_exists($json_path) || is_writable($json_path))) {
        file_put_contents($json_path, wp_json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    return count($merged);
}

// Server-side coordinate lookup with Nominatim fallback + geo_cache persistence
function tig_mileage_resolve_coord_server($lat, $lon, $fallback_raw = '') {
    $fallback_raw = trim((string) $fallback_raw);
    if ($lat === null || $lon === null || !is_numeric($lat) || !is_numeric($lon)) {
        return $fallback_raw;
    }
    $lat_f = floatval($lat);
    $lon_f = floatval($lon);
    if ($lat_f == 0.0 && $lon_f == 0.0) {
        return $fallback_raw;
    }
    $key = sprintf('%.4f,%.4f', $lat_f, $lon_f);
    $cache = tig_mileage_load_merged_geocache();
    if (!empty($cache[$key])) {
        return $cache[$key];
    }

    if ($fallback_raw !== '' && strpos($fallback_raw, ',') !== false && !preg_match('/^-?\d+\.\d+,\s*-?\d+\.\d+$/', $fallback_raw)) {
        tig_mileage_persist_geocache_entries(array($key => $fallback_raw));
        return $fallback_raw;
    }

    $url = add_query_arg(
        array(
            'format'         => 'jsonv2',
            'lat'            => $lat_f,
            'lon'            => $lon_f,
            'zoom'           => 18,
            'addressdetails' => 1
        ),
        'https://nominatim.openstreetmap.org/reverse'
    );
    $resp = wp_remote_get(
        $url,
        array(
            'timeout' => 8,
            'headers' => array(
                'User-Agent' => 'TIG-Mileage-Tracker/' . TIG_MILEAGE_VERSION . ' (Homeextent Inc.; theinnovativegroup.ca)'
            )
        )
    );
    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (is_array($body) && !empty($body['display_name'])) {
            $addr = sanitize_text_field($body['display_name']);
            tig_mileage_persist_geocache_entries(array($key => $addr));
            return $addr;
        }
    }

    return $fallback_raw !== '' ? $fallback_raw : $key;
}

// Server-side mirror of CRA endpoint classification (for instant Pixel 9 / Android Auto feedback)
function tig_mileage_classify_endpoint_server($addr, $day, $year, $locations) {
    $addr = (string) $addr;
    $a_norm = trim(preg_replace('/\s+/', ' ', str_replace(',', ' ', strtolower($addr))));

    $out_of_region = array(
        'burlington', 'clarington', 'cobourg', 'cramahe', 'dunnville',
        'hamilton', 'kawartha lakes', 'mississauga', 'oakville', 'oshawa',
        'picton', 'port hope', 'quinte west', 'toronto', 'brighton', 'station road'
    );
    foreach ($out_of_region as $city) {
        if ($a_norm !== '' && strpos($a_norm, $city) !== false) {
            return array($addr, 'Personal', 'Out of Region');
        }
    }

    if ($a_norm !== '' && strpos($a_norm, 'ymca') !== false) {
        if ($day === 'Saturday' || $day === 'Sunday') {
            return array('YMCA Drive (Fit4Less)', 'Personal', 'Gym / Personal');
        }
        return array('Home Depot (20 YMCA Dr, St. Catharines)', 'Materials / Supplies', 'Home Depot - St. Catharines');
    }

    foreach ($locations as $loc) {
        $loc_year = $loc['year'] ?? 'ALL';
        if ($loc_year !== 'ALL' && $loc_year !== (string) $year) {
            continue;
        }
        $kw_list = array_filter(array_map('trim', explode(',', strtolower($loc['keywords'] ?? ''))));
        foreach ($kw_list as $kw) {
            if ($kw === '' || preg_match('/^\d+$/', $kw) || $kw === 'ontario') {
                continue;
            }
            if ($a_norm !== '' && strpos($a_norm, $kw) !== false) {
                return array(
                    $loc['name'],
                    $loc['purpose'] ?? 'Project Site Visit',
                    $loc['category'] ?? 'Project Site'
                );
            }
        }
    }

    return array($addr, 'Review', 'Uncategorized');
}

// Format a database trip row into enriched Toronto-timezone trip payload
function tig_mileage_format_live_trip_row($row, $locations = null) {
    if ($locations === null) {
        $locations = tig_mileage_get_locations_or_default();
    }
    $tz = new DateTimeZone('America/Toronto');
    $start_sec = (int) floor(((float) $row['start_ms']) / 1000);
    $end_sec = (int) floor(((float) $row['end_ms']) / 1000);
    if ($end_sec < $start_sec) {
        $end_sec = $start_sec;
    }

    $dt_start = new DateTime('@' . $start_sec);
    $dt_start->setTimezone($tz);
    $dt_end = new DateTime('@' . $end_sec);
    $dt_end->setTimezone($tz);

    $date_str = $dt_start->format('Y-m-d');
    $day_str = $dt_start->format('l');
    $dep_str = $dt_start->format('h:i A');
    $arr_str = $dt_end->format('h:i A');
    $year_str = $dt_start->format('Y');

    $orig_raw = !empty($row['origin_raw'])
        ? $row['origin_raw']
        : (($row['start_lat'] !== null && $row['start_lon'] !== null) ? sprintf('%.4f,%.4f', $row['start_lat'], $row['start_lon']) : '');
    $dest_raw = !empty($row['dest_raw'])
        ? $row['dest_raw']
        : (($row['end_lat'] !== null && $row['end_lon'] !== null) ? sprintf('%.4f,%.4f', $row['end_lat'], $row['end_lon']) : '');

    list($orig_clean) = tig_mileage_classify_endpoint_server($orig_raw, $day_str, $year_str, $locations);
    list($dest_clean, $purpose, $category) = tig_mileage_classify_endpoint_server($dest_raw, $day_str, $year_str, $locations);

    // Return-from-Personal rule
    foreach (array('Personal', 'ZEHRS', 'Fit4Less', 'Out of Region') as $p_kw) {
        if (strpos($orig_clean, $p_kw) !== false && $purpose === 'Return to Head Office / Dispatch') {
            $purpose = 'Personal';
            $category = 'Return from Personal';
            break;
        }
    }

    if (!empty($row['manual_purpose'])) {
        $purpose = $row['manual_purpose'];
        $category = !empty($row['manual_category'])
            ? $row['manual_category']
            : ($purpose === 'Personal' ? 'Personal (Pixel / Manual)' : $purpose);
    }

    return array(
        'id'             => (int) $row['id'],
        'trip_uid'       => $row['trip_uid'],
        'tax_year'       => $year_str,
        'start_ms'       => (int) $row['start_ms'],
        'end_ms'         => (int) $row['end_ms'],
        'date'           => $date_str,
        'day'            => $day_str,
        'departure'      => $dep_str,
        'arrival'        => $arr_str,
        'origin_raw'     => $orig_raw,
        'dest_raw'       => $dest_raw,
        'origin'         => $orig_clean,
        'destination'    => $dest_clean,
        'gps_km'         => round(floatval($row['gps_km']), 2),
        'trigger_source' => $row['trigger_source'],
        'purpose'        => $purpose,
        'category'       => $category,
        'manual_purpose' => $row['manual_purpose'],
        'manual_category'=> $row['manual_category']
    );
}

// Query live trips for a target tax year
function tig_mileage_fetch_live_trips($year = null, $limit = 5000) {
    global $wpdb;
    $table_name = $wpdb->prefix . TIG_MILEAGE_TABLE;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
        return array();
    }

    if ($year !== null && $year !== '') {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE tax_year = %s ORDER BY start_ms ASC LIMIT %d",
                (string) $year,
                (int) $limit
            ),
            ARRAY_A
        );
    } else {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY start_ms DESC LIMIT %d",
                (int) $limit
            ),
            ARRAY_A
        );
    }

    if (!is_array($rows)) {
        return array();
    }

    $locations = tig_mileage_get_locations_or_default();
    $out = array();
    foreach ($rows as $r) {
        $out[] = tig_mileage_format_live_trip_row($r, $locations);
    }
    return $out;
}

// ============================================================================
// REST API: /wp-json/tig-mileage/v1/ (Pixel 9 & Android Auto Companion)
// ============================================================================
add_action('rest_api_init', 'tig_mileage_register_rest_routes');
function tig_mileage_register_rest_routes() {
    $ns = 'tig-mileage/v1';

    register_rest_route($ns, '/status', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'tig_mileage_rest_get_status',
        'permission_callback' => 'tig_mileage_rest_permission'
    ));

    register_rest_route($ns, '/trips', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'tig_mileage_rest_list_trips',
            'permission_callback' => 'tig_mileage_rest_permission'
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'tig_mileage_rest_upsert_trip',
            'permission_callback' => 'tig_mileage_rest_permission'
        )
    ));

    register_rest_route($ns, '/trips/(?P<id>\d+)', array(
        'methods'             => 'PATCH, POST',
        'callback'            => 'tig_mileage_rest_update_trip',
        'permission_callback' => 'tig_mileage_rest_permission'
    ));

    register_rest_route($ns, '/locations', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'tig_mileage_rest_add_location',
        'permission_callback' => 'tig_mileage_rest_permission'
    ));

    register_rest_route($ns, '/anchors', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'tig_mileage_rest_update_anchor',
        'permission_callback' => 'tig_mileage_rest_permission'
    ));
}

function tig_mileage_rest_permission() {
    return current_user_can('manage_options');
}

function tig_mileage_rest_get_status(WP_REST_Request $request) {
    tig_mileage_maybe_migrate();
    $locations = tig_mileage_get_locations_or_default();
    $anchors = tig_mileage_get_anchors_or_default();
    $recent_trips = tig_mileage_fetch_live_trips(null, 25);

    return rest_ensure_response(array(
        'ok'             => true,
        'plugin'         => 'tig-mileage-tracker',
        'version'        => TIG_MILEAGE_VERSION,
        'site_name'      => get_bloginfo('name'),
        'geocache_count' => count(tig_mileage_load_merged_geocache()),
        'locations'      => array_values($locations),
        'anchors'        => $anchors,
        'recent_trips'   => $recent_trips
    ));
}

function tig_mileage_rest_list_trips(WP_REST_Request $request) {
    $year = sanitize_text_field((string) $request->get_param('year'));
    $trips = tig_mileage_fetch_live_trips($year !== '' ? $year : null, 5000);
    return rest_ensure_response(array(
        'ok'    => true,
        'count' => count($trips),
        'trips' => $trips
    ));
}

function tig_mileage_rest_upsert_trip(WP_REST_Request $request) {
    global $wpdb;
    tig_mileage_maybe_migrate();

    $params = $request->get_json_params();
    if (!is_array($params) || empty($params)) {
        $params = $request->get_body_params();
    }

    $start_ms = isset($params['start_ms']) ? (int) $params['start_ms'] : 0;
    $end_ms = isset($params['end_ms']) ? (int) $params['end_ms'] : $start_ms;
    if ($start_ms <= 0) {
        return new WP_Error('invalid_start_ms', 'Valid start_ms epoch milliseconds required.', array('status' => 400));
    }

    $trip_uid = sanitize_text_field((string) ($params['trip_uid'] ?? ('pixel_' . $start_ms)));
    $gps_km = round(max(0.0, floatval($params['gps_km'] ?? 0)), 2);
    $trigger_source = sanitize_text_field((string) ($params['trigger_source'] ?? 'Uconnect BT'));

    $start_lat = isset($params['start_lat']) && is_numeric($params['start_lat']) ? floatval($params['start_lat']) : null;
    $start_lon = isset($params['start_lon']) && is_numeric($params['start_lon']) ? floatval($params['start_lon']) : null;
    $end_lat = isset($params['end_lat']) && is_numeric($params['end_lat']) ? floatval($params['end_lat']) : null;
    $end_lon = isset($params['end_lon']) && is_numeric($params['end_lon']) ? floatval($params['end_lon']) : null;

    $origin_raw_in = sanitize_text_field((string) ($params['origin_raw'] ?? ''));
    $dest_raw_in = sanitize_text_field((string) ($params['dest_raw'] ?? ''));

    $origin_resolved = tig_mileage_resolve_coord_server($start_lat, $start_lon, $origin_raw_in);
    $dest_resolved = tig_mileage_resolve_coord_server($end_lat, $end_lon, $dest_raw_in);

    $manual_purpose = sanitize_text_field((string) ($params['manual_purpose'] ?? ''));
    $manual_category = sanitize_text_field((string) ($params['manual_category'] ?? ''));

    $tz = new DateTimeZone('America/Toronto');
    $dt_start = new DateTime('@' . (int) floor($start_ms / 1000));
    $dt_start->setTimezone($tz);
    $tax_year = $dt_start->format('Y');

    $table_name = $wpdb->prefix . TIG_MILEAGE_TABLE;
    $existing = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table_name} WHERE trip_uid = %s", $trip_uid),
        ARRAY_A
    );

    if ($existing) {
        $keep_purpose = $manual_purpose !== '' ? $manual_purpose : $existing['manual_purpose'];
        $keep_category = $manual_category !== '' ? $manual_category : $existing['manual_category'];
        $wpdb->update(
            $table_name,
            array(
                'tax_year'        => $tax_year,
                'start_ms'        => $start_ms,
                'end_ms'          => $end_ms,
                'start_lat'       => $start_lat,
                'start_lon'       => $start_lon,
                'end_lat'         => $end_lat,
                'end_lon'         => $end_lon,
                'gps_km'          => $gps_km,
                'trigger_source'  => $trigger_source,
                'origin_raw'      => $origin_resolved,
                'dest_raw'        => $dest_resolved,
                'manual_purpose'  => $keep_purpose,
                'manual_category' => $keep_category
            ),
            array('id' => (int) $existing['id'])
        );
        $trip_id = (int) $existing['id'];
    } else {
        $wpdb->insert(
            $table_name,
            array(
                'trip_uid'        => $trip_uid,
                'tax_year'        => $tax_year,
                'start_ms'        => $start_ms,
                'end_ms'          => $end_ms,
                'start_lat'       => $start_lat,
                'start_lon'       => $start_lon,
                'end_lat'         => $end_lat,
                'end_lon'         => $end_lon,
                'gps_km'          => $gps_km,
                'trigger_source'  => $trigger_source,
                'origin_raw'      => $origin_resolved,
                'dest_raw'        => $dest_resolved,
                'manual_purpose'  => $manual_purpose,
                'manual_category' => $manual_category
            )
        );
        $trip_id = (int) $wpdb->insert_id;
    }

    $saved_row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $trip_id),
        ARRAY_A
    );
    $formatted = tig_mileage_format_live_trip_row($saved_row);

    return rest_ensure_response(array_merge(array('ok' => true), $formatted));
}

function tig_mileage_rest_update_trip(WP_REST_Request $request) {
    global $wpdb;
    $id = (int) $request->get_param('id');
    $table_name = $wpdb->prefix . TIG_MILEAGE_TABLE;

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id),
        ARRAY_A
    );
    if (!$row) {
        return new WP_Error('not_found', 'Trip not found.', array('status' => 404));
    }

    $params = $request->get_json_params();
    if (!is_array($params) || empty($params)) {
        $params = $request->get_body_params();
    }

    $purpose = sanitize_text_field((string) ($params['purpose'] ?? $params['manual_purpose'] ?? ''));
    $category = sanitize_text_field((string) ($params['category'] ?? $params['manual_category'] ?? ''));
    $dest_override = sanitize_text_field((string) ($params['destination'] ?? ''));

    $update_data = array(
        'manual_purpose'  => $purpose,
        'manual_category' => $category
    );
    if ($dest_override !== '') {
        $update_data['dest_raw'] = $dest_override;
    }

    $wpdb->update($table_name, $update_data, array('id' => $id));

    $updated_row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id),
        ARRAY_A
    );
    $formatted = tig_mileage_format_live_trip_row($updated_row);

    return rest_ensure_response(array_merge(array('ok' => true), $formatted));
}

function tig_mileage_rest_add_location(WP_REST_Request $request) {
    $params = $request->get_json_params();
    if (!is_array($params) || empty($params)) {
        $params = $request->get_body_params();
    }

    $name = sanitize_text_field((string) ($params['name'] ?? ''));
    if ($name === '') {
        return new WP_Error('missing_name', 'Location display name is required.', array('status' => 400));
    }

    $raw_kw = (string) ($params['keywords'] ?? '');
    if (trim($raw_kw) === '') {
        $parts = array_map('trim', explode(',', strtolower($name)));
        $raw_kw = $parts[0] ?? strtolower($name);
    }
    $clean_kw = tig_mileage_sanitize_keywords($raw_kw);
    if ($clean_kw === '') {
        $clean_kw = strtolower(preg_replace('/[^a-z0-9\s]/i', '', $name));
    }

    $purpose = sanitize_text_field((string) ($params['purpose'] ?? 'Project Site Visit'));
    $category = sanitize_text_field((string) ($params['category'] ?? $purpose));
    $tz = new DateTimeZone('America/Toronto');
    $now = new DateTime('now', $tz);
    $year = sanitize_text_field((string) ($params['year'] ?? $now->format('Y')));

    $lat = isset($params['lat']) && is_numeric($params['lat']) ? floatval($params['lat']) : null;
    $lon = isset($params['lon']) && is_numeric($params['lon']) ? floatval($params['lon']) : null;
    if ($lat !== null && $lon !== null && ($lat != 0.0 || $lon != 0.0)) {
        $coord_key = sprintf('%.4f,%.4f', $lat, $lon);
        tig_mileage_persist_geocache_entries(array($coord_key => $name));
    }

    $locations = tig_mileage_get_locations_or_default();
    $new_rule = array(
        'id'       => 'loc_' . time() . '_' . wp_rand(100, 999),
        'name'     => $name,
        'keywords' => $clean_kw,
        'purpose'  => $purpose,
        'category' => $category,
        'year'     => $year
    );
    array_unshift($locations, $new_rule);
    update_option('tig_mileage_locations', $locations, false);

    return rest_ensure_response(array(
        'ok'        => true,
        'location'  => $new_rule,
        'locations' => array_values($locations)
    ));
}

function tig_mileage_rest_update_anchor(WP_REST_Request $request) {
    $params = $request->get_json_params();
    if (!is_array($params) || empty($params)) {
        $params = $request->get_body_params();
    }

    $odo = floatval($params['odo'] ?? 0);
    if ($odo <= 0) {
        return new WP_Error('invalid_odo', 'Positive odometer reading (km) is required.', array('status' => 400));
    }

    $tz = new DateTimeZone('America/Toronto');
    $now = new DateTime('now', $tz);
    $year = sanitize_text_field((string) ($params['year'] ?? $now->format('Y')));
    $date = sanitize_text_field((string) ($params['date'] ?? $now->format('Y-m-d')));
    $mode = sanitize_text_field((string) ($params['mode'] ?? ($year === '2026' ? 'pivot' : 'forward')));

    $anchors = tig_mileage_get_anchors_or_default();
    $anchors[$year] = array(
        'date' => $date,
        'odo'  => round($odo, 1),
        'mode' => $mode
    );
    update_option('tig_mileage_anchors', $anchors, false);

    $attachment_id = 0;
    $photo_url = '';
    if (!empty($params['photo_base64'])) {
        $b64 = (string) $params['photo_base64'];
        if (strpos($b64, 'base64,') !== false) {
            $b64 = substr($b64, strpos($b64, 'base64,') + 7);
        }
        $binary = base64_decode($b64, true);
        if ($binary !== false && strlen($binary) > 100) {
            $fname = sanitize_file_name('tig-odo-' . $date . '-' . round($odo, 1) . 'km.jpg');
            $upload = wp_upload_bits($fname, null, $binary);
            if (empty($upload['error']) && !empty($upload['file'])) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $filetype = wp_check_filetype($upload['file'], null);
                $att_data = array(
                    'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
                    'post_title'     => sprintf('RAM 1500 Rebel Odometer Check-In: %.1f km (%s)', $odo, $date),
                    'post_content'   => 'CRA Odometer Verification Photo uploaded via Pixel 9 Companion',
                    'post_status'    => 'inherit'
                );
                $attachment_id = (int) wp_insert_attachment($att_data, $upload['file']);
                if ($attachment_id > 0) {
                    $meta = wp_generate_attachment_metadata($attachment_id, $upload['file']);
                    wp_update_attachment_metadata($attachment_id, $meta);
                    $photo_url = wp_get_attachment_url($attachment_id);
                }
            }
        }
    }

    return rest_ensure_response(array(
        'ok'            => true,
        'anchors'       => $anchors,
        'attachment_id' => $attachment_id,
        'photo_url'     => $photo_url
    ));
}

// ============================================================================
// WP ADMIN AJAX HANDLERS
// ============================================================================

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
    $total = tig_mileage_persist_geocache_entries($entries);
    wp_send_json_success(array('total' => $total));
}

// AJAX: Fetch, Update, or Delete Live Pixel 9 Trips in WP Admin
add_action('wp_ajax_tig_live_trips_action', 'tig_mileage_ajax_live_trips_action');
function tig_mileage_ajax_live_trips_action() {
    global $wpdb;
    check_ajax_referer('tig_mileage_nonce', 'security');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $op = sanitize_text_field((string) ($_POST['op'] ?? 'list'));
    $table_name = $wpdb->prefix . TIG_MILEAGE_TABLE;

    if ($op === 'list') {
        $year = sanitize_text_field((string) ($_POST['year'] ?? ''));
        $trips = tig_mileage_fetch_live_trips($year !== '' ? $year : null, 5000);
        wp_send_json_success(array('trips' => $trips));
    } elseif ($op === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $purpose = sanitize_text_field((string) ($_POST['purpose'] ?? ''));
        $category = sanitize_text_field((string) ($_POST['category'] ?? ''));
        if ($id > 0) {
            $wpdb->update(
                $table_name,
                array('manual_purpose' => $purpose, 'manual_category' => $category),
                array('id' => $id)
            );
        }
        wp_send_json_success(array('message' => 'Live trip updated.'));
    } elseif ($op === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $wpdb->delete($table_name, array('id' => $id));
        }
        wp_send_json_success(array('message' => 'Live trip deleted.'));
    }

    wp_send_json_error('Unknown operation.');
}

function tig_mileage_tracker_admin_page() {
    global $wpdb;
    tig_mileage_maybe_migrate();

    $geo_cache = tig_mileage_load_merged_geocache();
    $locations = tig_mileage_get_locations_or_default();
    $anchors = tig_mileage_get_anchors_or_default();
    $nonce = wp_create_nonce('tig_mileage_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    $rest_base = rest_url('tig-mileage/v1');
    $bundled_exists = file_exists(TIG_MILEAGE_DIR . 'data/geo_cache.json');

    $table_name = $wpdb->prefix . TIG_MILEAGE_TABLE;
    $live_counts = array('2025' => 0, '2026' => 0, '2027' => 0, 'total' => 0);
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name) {
        $rows = $wpdb->get_results("SELECT tax_year, COUNT(*) AS cnt FROM {$table_name} GROUP BY tax_year", ARRAY_A);
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $yr = (string) $r['tax_year'];
                $c = (int) $r['cnt'];
                $live_counts[$yr] = $c;
                $live_counts['total'] += $c;
            }
        }
    }
    ?>
    <div class="wrap" style="max-width: 1240px; margin-top: 20px;">
        <div style="background: #1F497D; color: #fff; padding: 18px 24px; border-radius: 6px 6px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="color: #fff; margin: 0; font-size: 20px; font-weight: 600;">TIG Corporate Mileage Tracker</h1>
                <span style="font-size: 12px; opacity: 0.85;">Homeextent Inc. o/a The Innovative Group &bull; 2019 Ram 1500 Rebel (Uconnect BT + Android Auto Ready)</span>
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
                            <strong style="font-size: 14px; color: #1d2327;">1. Built-In Geo-Cache &amp; REST API Status</strong>
                            <span class="dashicons dashicons-yes-alt" style="color: #2e7d32;"></span>
                        </div>
                        <div id="status-cache" style="margin-top: 6px; font-size: 12px; color: #2e7d32; font-weight: 600;">
                            &#10003; <?php echo count($geo_cache); ?> coordinates loaded <?php echo $bundled_exists ? '(data/geo_cache.json + DB)' : '(DB)'; ?> &bull; <?php echo (int) $live_counts['total']; ?> Pixel 9 live trips stored
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
                    <h3 style="margin: 0; color: #1d2327;">Step 2: Select Trip Data Source</h3>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label for="select-year" style="font-size: 12px; font-weight: 600;">Target Tax Year:</label>
                        <select id="select-year" style="font-size: 13px;">
                            <option value="2027">2027 Tax Year (3-Month Sample or Full Year)</option>
                            <option value="2026" selected>2026 Full-Year (Base Year)</option>
                            <option value="2025">2025 Historical Baseline (Jun 8 – Dec 31)</option>
                        </select>
                    </div>
                </div>

                <!-- Dual Source Grid: Live Pixel 9 Collector vs Google Timeline JSON -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 12px;">
                    <div style="border: 2px solid #1F497D; border-radius: 6px; padding: 24px 20px; text-align: center; background: #f4f8fc;">
                        <span class="dashicons dashicons-smartphone" style="font-size: 34px; width: 34px; height: 34px; color: #1F497D;"></span>
                        <div style="font-size: 15px; font-weight: 600; color: #1d2327; margin-top: 8px;">Source A: Live Pixel 9 / Android Auto Trips</div>
                        <div id="live-source-subtitle" style="font-size: 12px; color: #50575e; margin-top: 4px;">
                            Zero-touch drives synced automatically from your 2019 Ram Rebel.
                        </div>
                        <div style="margin-top: 14px; display: flex; justify-content: center; gap: 8px; flex-wrap: wrap;">
                            <button type="button" id="btn-load-live-trips" class="button button-primary">Load Live Pixel 9 Trips</button>
                            <button type="button" id="btn-toggle-live-manager" class="button">Inspect / Edit Stored Trips</button>
                        </div>
                    </div>

                    <div id="box-timeline" style="border: 2px dashed #c3c4c7; border-radius: 6px; padding: 24px 20px; text-align: center; background: #f9f9f9; cursor: pointer;">
                        <span class="dashicons dashicons-cloud-upload" style="font-size: 34px; width: 34px; height: 34px; color: #1F497D;"></span>
                        <div style="font-size: 15px; font-weight: 600; color: #1d2327; margin-top: 8px;">Source B: Drop Google Timeline (.json)</div>
                        <div style="font-size: 12px; color: #646970; margin-top: 4px;">Historical 2025/2026 baseline import (supports Android <code>semanticSegments</code> &amp; iOS <code>timelineObjects</code>, 50MB+).</div>
                        <input type="file" id="input-timeline" accept=".json" style="display: none;" />
                    </div>
                </div>

                <div id="progress-wrap" style="display: none; max-width: 560px; margin: 10px auto 0;">
                    <div style="background: #e0e0e0; border-radius: 4px; height: 8px; overflow: hidden;">
                        <div id="progress-bar" style="width: 0%; height: 100%; background: #1F497D; transition: width 0.25s;"></div>
                    </div>
                </div>
                <div id="status-timeline" style="margin-top: 8px; text-align: center; font-size: 12px; font-weight: 600; color: #1F497D;"></div>

                <!-- Live Pixel 9 Trip Manager Drawer -->
                <div id="live-manager-section" style="display: none; margin-top: 18px; border: 1px solid #c3c4c7; background: #fafafa; border-radius: 6px; padding: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <strong style="color: #1F497D; font-size: 14px;">Stored Pixel 9 Trips (<span id="live-mgr-year">2026</span>)</strong>
                        <button type="button" id="btn-close-live-manager" class="button button-small">Hide</button>
                    </div>
                    <div style="max-height: 280px; overflow-y: auto; border: 1px solid #e5e5e5; background: #fff;">
                        <table class="widefat striped" style="border: none;">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Trigger</th>
                                    <th>Origin &rarr; Destination</th>
                                    <th>GPS KM</th>
                                    <th>Purpose</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="live-mgr-tbody"></tbody>
                        </table>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 26px; margin-bottom: 12px;">
                    <h3 style="margin: 0; color: #1d2327;">Step 3: Audit Summary &amp; Export</h3>
                    <span id="continuity-badge" style="display: none; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 4px; cursor: pointer;"></span>
                </div>

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

                <!-- Route Continuity Gaps Panel (Expandable when gaps exist) -->
                <div id="gaps-section" style="display: none; margin-bottom: 20px; border: 1px solid #d63638; background: #fcf0f1; border-radius: 6px; padding: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <strong style="color: #8a2424; font-size: 14px;">Route Continuity Discrepancies (<span id="gaps-count">0</span> Legs)</strong>
                        <span style="font-size: 12px; color: #50575e;">Trips where Origin differs from the immediately preceding Destination (e.g., GPS dropout or unclassified corner).</span>
                    </div>
                    <div style="max-height: 220px; overflow-y: auto; border: 1px solid #e5e5e5; background: #fff;">
                        <table class="widefat striped" style="border: none;">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Departure</th>
                                    <th>Prior Stop Destination</th>
                                    <th>Next Trip Origin</th>
                                    <th>Next Destination</th>
                                </tr>
                            </thead>
                            <tbody id="gaps-tbody"></tbody>
                        </table>
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
                <!-- Pixel 9 & Android Auto Connection Info Banner -->
                <div style="border: 1px solid #b8daff; background: #f0f7ff; border-radius: 6px; padding: 14px 18px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <strong style="color: #1F497D; font-size: 13px;">Pixel 9 &amp; Android Auto REST Endpoint:</strong>
                        <code style="margin-left: 6px; font-size: 12px; background: #fff; padding: 3px 6px; border: 1px solid #d0e3f7;"><?php echo esc_html($rest_base); ?></code>
                        <div style="font-size: 11px; color: #50575e; margin-top: 4px;">
                            Generate a WordPress Application Password under <strong>Users &rarr; Profile</strong> and enter it in the Pixel 9 app settings.
                        </div>
                    </div>
                    <a href="<?php echo esc_url(admin_url('profile.php#application-passwords-section')); ?>" class="button button-secondary button-small">Open Application Passwords &rarr;</a>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 24px;">
                    <!-- Add New Project / Visited Location Form -->
                    <div style="border: 1px solid #dcdcde; border-radius: 6px; padding: 18px; background: #fafafa;">
                        <h3 style="margin-top: 0; color: #1F497D;">Add 2027+ Project, Quote Address, or Pick-Up Counter</h3>
                        <p style="font-size: 12px; color: #50575e; margin-top: 0;">Add new build sites or client estimate addresses anytime during the year (or pin them with 1 tap from your Pixel 9 / Android Auto screen). Click <strong>Edit</strong> on any row below to update inline.</p>
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

                    <!-- Odometer Anchors & Full Backup -->
                    <div style="border: 1px solid #dcdcde; border-radius: 6px; padding: 18px; background: #fafafa;">
                        <h3 style="margin-top: 0; color: #1F497D;">Physical Odometer Anchors</h3>
                        <p style="font-size: 12px; color: #50575e; margin-top: 0;">Verified dash readings used to chain trip odometers:</p>
                        <div style="font-size: 12px; margin-bottom: 8px;">
                            <strong>2026 Pivot (<?php echo esc_html($anchors['2026']['date'] ?? '2026-11-10'); ?>):</strong>
                            <input type="number" step="0.1" id="anchor-2026-odo" value="<?php echo esc_attr($anchors['2026']['odo']); ?>" style="width: 100px; margin-left: 6px;" /> km
                        </div>
                        <div style="font-size: 12px; margin-bottom: 12px;">
                            <strong>2027 Anchor (<?php echo esc_html($anchors['2027']['date'] ?? '2027-01-01'); ?>):</strong>
                            <input type="number" step="0.1" id="anchor-2027-odo" value="<?php echo esc_attr($anchors['2027']['odo']); ?>" style="width: 100px; margin-left: 6px;" /> km
                        </div>
                        <button type="button" id="btn-save-anchors" class="button">Update Anchors</button>

                        <hr style="margin: 16px 0;" />
                        <strong style="font-size: 12px; display: block; margin-bottom: 6px;">Full Rulebook &amp; Geo-Cache Backup</strong>
                        <p style="font-size: 11px; color: #646970; margin: 0 0 8px 0;">Exports or restores all location rules, odometer anchors, and cached GPS coordinates in one JSON file.</p>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" id="btn-export-full-backup" class="button button-small">Download Full Backup (.json)</button>
                            <button type="button" id="btn-import-full-backup" class="button button-small">Restore Backup / Cache</button>
                            <input type="file" id="input-full-backup" accept=".json" style="display: none;" />
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; gap: 12px; flex-wrap: wrap;">
                    <h3 style="margin: 0; color: #1d2327;">Active Project Sites &amp; Approved Pick-Up Counters (<span id="loc-visible-count"><?php echo count($locations); ?></span> of <span id="loc-total-count"><?php echo count($locations); ?></span>)</h3>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="search" id="filter-loc-search" placeholder="Search name, keyword, or category..." style="width: 240px; font-size: 12px;" />
                        <select id="filter-loc-year" style="font-size: 12px;">
                            <option value="FILTER_ALL_RULES">All Rules</option>
                            <option value="ALL">Multi-Year (ALL)</option>
                            <option value="2027">2027 Only</option>
                            <option value="2026">2026 Only</option>
                            <option value="2025">2025 Only</option>
                        </select>
                    </div>
                </div>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 90px;">Tax Year</th>
                            <th>Clean Display Name</th>
                            <th>Match Keywords</th>
                            <th style="width: 185px;">CRA Purpose</th>
                            <th>Category</th>
                            <th style="width: 135px;">Actions</th>
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
        const PLUGIN_VERSION = <?php echo wp_json_encode(TIG_MILEAGE_VERSION); ?>;
        const LIVE_COUNTS = <?php echo wp_json_encode($live_counts); ?>;
        let geoCache = <?php echo wp_json_encode($geo_cache); ?> || {};
        let customLocations = <?php echo wp_json_encode($locations); ?> || [];
        let odoAnchors = <?php echo wp_json_encode($anchors); ?> || {};
        let txByDate = {};
        let processedTrips = [];
        let editingLocIndex = -1;

        const PURPOSE_OPTIONS = [
            'Project Site Visit',
            'Project Development',
            'Materials / Supplies',
            'Quote / Estimate',
            'Tools / Repair',
            'Vehicle Maintenance & Parts',
            'Return to Head Office / Dispatch',
            'Personal'
        ];

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

        const MONTH_LABELS = [
            ['01', 'January'], ['02', 'February'], ['03', 'March'],
            ['04', 'April'], ['05', 'May'], ['06', 'June'],
            ['07', 'July'], ['08', 'August'], ['09', 'September'],
            ['10', 'October'], ['11', 'November'], ['12', 'December']
        ];

        function escapeHtml(str) {
            return String(str === undefined || str === null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

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

        function updateLiveSourceBadge() {
            const yr = document.getElementById('select-year').value;
            const cnt = LIVE_COUNTS[yr] || 0;
            const sub = document.getElementById('live-source-subtitle');
            if (sub) {
                sub.innerHTML = `<strong>${cnt}</strong> live drive${cnt === 1 ? '' : 's'} stored for <strong>${yr}</strong> from Uconnect BT / Android Auto.`;
            }
        }
        updateLiveSourceBadge();

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

        function buildSelectOptions(optionsList, selectedVal) {
            return optionsList.map(opt => {
                const sel = opt === selectedVal ? ' selected' : '';
                return `<option value="${escapeHtml(opt)}"${sel}>${escapeHtml(opt)}</option>`;
            }).join('');
        }

        function renderLocationsTable() {
            const tbody = document.getElementById('locations-tbody');
            const searchQ = (document.getElementById('filter-loc-search').value || '').trim().toLowerCase();
            const yearFilter = document.getElementById('filter-loc-year').value;

            tbody.innerHTML = '';
            document.getElementById('loc-total-count').innerText = customLocations.length;
            let visibleCount = 0;

            customLocations.forEach((loc, idx) => {
                if (yearFilter !== 'FILTER_ALL_RULES' && loc.year !== yearFilter) {
                    return;
                }
                if (searchQ) {
                    const hay = `${loc.name} ${loc.keywords} ${loc.purpose} ${loc.category} ${loc.year}`.toLowerCase();
                    if (!hay.includes(searchQ)) return;
                }

                visibleCount++;
                const tr = document.createElement('tr');

                if (editingLocIndex === idx) {
                    const yrOpts = ['ALL', '2027', '2026', '2025'].map(y =>
                        `<option value="${y}"${loc.year === y ? ' selected' : ''}>${y}</option>`
                    ).join('');
                    tr.style.background = '#fffdf5';
                    tr.innerHTML = `
                        <td><select class="tig-edit-year" style="width:100%; font-size:12px;">${yrOpts}</select></td>
                        <td><input type="text" class="tig-edit-name" value="${escapeHtml(loc.name)}" style="width:100%; font-size:12px;" /></td>
                        <td><input type="text" class="tig-edit-keywords" value="${escapeHtml(loc.keywords)}" style="width:100%; font-size:12px;" /></td>
                        <td><select class="tig-edit-purpose" style="width:100%; font-size:12px;">${buildSelectOptions(PURPOSE_OPTIONS, loc.purpose)}</select></td>
                        <td><input type="text" class="tig-edit-category" value="${escapeHtml(loc.category)}" style="width:100%; font-size:12px;" /></td>
                        <td style="white-space:nowrap;">
                            <button type="button" class="button button-primary button-small tig-save-loc" data-idx="${idx}">Save</button>
                            <button type="button" class="button button-small tig-cancel-loc">Cancel</button>
                        </td>
                    `;
                } else {
                    tr.innerHTML = `
                        <td><strong>${escapeHtml(loc.year)}</strong></td>
                        <td>${escapeHtml(loc.name)}</td>
                        <td><code style="font-size:11px;">${escapeHtml(loc.keywords)}</code></td>
                        <td>${escapeHtml(loc.purpose)}</td>
                        <td>${escapeHtml(loc.category)}</td>
                        <td style="white-space:nowrap;">
                            <button type="button" class="button button-small tig-edit-loc" data-idx="${idx}">Edit</button>
                            <button type="button" class="button button-small tig-del-loc" data-idx="${idx}" style="color:#b32d2e;">Delete</button>
                        </td>
                    `;
                }
                tbody.appendChild(tr);
            });

            document.getElementById('loc-visible-count').innerText = visibleCount;

            document.querySelectorAll('.tig-edit-loc').forEach(btn => {
                btn.addEventListener('click', function() {
                    editingLocIndex = parseInt(this.dataset.idx, 10);
                    renderLocationsTable();
                });
            });

            document.querySelectorAll('.tig-cancel-loc').forEach(btn => {
                btn.addEventListener('click', function() {
                    editingLocIndex = -1;
                    renderLocationsTable();
                });
            });

            document.querySelectorAll('.tig-save-loc').forEach(btn => {
                btn.addEventListener('click', function() {
                    const i = parseInt(this.dataset.idx, 10);
                    const row = this.closest('tr');
                    const name = row.querySelector('.tig-edit-name').value.trim();
                    const keywords = cleanKeywordInput(row.querySelector('.tig-edit-keywords').value);
                    const purpose = row.querySelector('.tig-edit-purpose').value;
                    const category = row.querySelector('.tig-edit-category').value.trim() || purpose;
                    const year = row.querySelector('.tig-edit-year').value;

                    if (!name || !keywords) {
                        alert('Please enter both a Clean Display Name and valid Street Keywords.');
                        return;
                    }
                    customLocations[i] = {
                        ...customLocations[i],
                        name, keywords, purpose, category, year
                    };
                    editingLocIndex = -1;
                    persistSettings();
                    renderLocationsTable();
                });
            });

            document.querySelectorAll('.tig-del-loc').forEach(btn => {
                btn.addEventListener('click', function() {
                    const i = parseInt(this.dataset.idx, 10);
                    if (!confirm(`Delete rule "${customLocations[i].name}"?`)) return;
                    customLocations.splice(i, 1);
                    if (editingLocIndex === i) editingLocIndex = -1;
                    persistSettings();
                    renderLocationsTable();
                });
            });
        }

        document.getElementById('filter-loc-search').addEventListener('input', renderLocationsTable);
        document.getElementById('filter-loc-year').addEventListener('change', renderLocationsTable);
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
            editingLocIndex = -1;
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

        // Full Rulebook + Anchors + Geo-Cache Backup & Smart Restore
        document.getElementById('btn-export-full-backup').addEventListener('click', function() {
            const today = new Date().toISOString().slice(0, 10);
            const payload = {
                plugin: 'tig-mileage-tracker',
                version: PLUGIN_VERSION,
                exportedAt: new Date().toISOString(),
                locations: customLocations,
                anchors: odoAnchors,
                geoCache: geoCache
            };
            const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `tig-mileage-full-backup-${today}.json`;
            a.click();
        });

        document.getElementById('btn-import-full-backup').addEventListener('click', () => document.getElementById('input-full-backup').click());
        document.getElementById('input-full-backup').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = async function(ev) {
                try {
                    const parsed = JSON.parse(ev.target.result);
                    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                        throw new Error('JSON root must be an object.');
                    }

                    const isFullBundle = (parsed.plugin === 'tig-mileage-tracker') ||
                        Array.isArray(parsed.locations) ||
                        (parsed.geoCache && typeof parsed.geoCache === 'object');

                    if (isFullBundle) {
                        let restoredParts = [];
                        if (Array.isArray(parsed.locations) && parsed.locations.length > 0) {
                            customLocations = parsed.locations;
                            restoredParts.push(`${customLocations.length} location rules`);
                        }
                        if (parsed.anchors && typeof parsed.anchors === 'object') {
                            odoAnchors = Object.assign({}, odoAnchors, parsed.anchors);
                            if (odoAnchors['2026']) document.getElementById('anchor-2026-odo').value = odoAnchors['2026'].odo;
                            if (odoAnchors['2027']) document.getElementById('anchor-2027-odo').value = odoAnchors['2027'].odo;
                            restoredParts.push('odometer anchors');
                        }
                        await persistSettings();
                        editingLocIndex = -1;
                        renderLocationsTable();

                        if (parsed.geoCache && typeof parsed.geoCache === 'object') {
                            Object.assign(geoCache, parsed.geoCache);
                            const resp = await fetch(AJAX_URL, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    action: 'tig_save_geocache',
                                    security: NONCE,
                                    entries: JSON.stringify(parsed.geoCache)
                                })
                            });
                            if (resp.ok) {
                                const cnt = Object.keys(geoCache).length;
                                document.getElementById('status-cache').innerHTML = `&#10003; ${cnt} coordinates loaded &amp; saved permanently`;
                                restoredParts.push(`${Object.keys(parsed.geoCache).length} cached coordinates`);
                            }
                        }

                        showSaveToast(`✓ Restored ${restoredParts.join(', ')}`);
                        alert(`Full backup restored: ${restoredParts.join(', ')}.`);
                    } else {
                        Object.assign(geoCache, parsed);
                        const resp = await fetch(AJAX_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'tig_save_geocache',
                                security: NONCE,
                                entries: JSON.stringify(parsed)
                            })
                        });
                        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
                        const data = await resp.json();
                        if (!data || !data.success) throw new Error('Server rejected cache save');
                        const cnt = Object.keys(geoCache).length;
                        document.getElementById('status-cache').innerHTML = `&#10003; ${cnt} coordinates loaded &amp; saved permanently`;
                        showSaveToast(`✓ Imported ${Object.keys(parsed).length} coordinates`);
                        alert(`Imported ${Object.keys(parsed).length} locations into permanent plugin cache.`);
                    }
                } catch (err) {
                    showSaveToast('⚠ Backup restore failed: ' + err.message, true);
                    alert('Restore error: ' + err.message);
                } finally {
                    e.target.value = '';
                }
            };
            reader.readAsText(file);
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
            const aNorm = a.replace(/,/g, ' ').replace(/\s+/g, ' ').trim();

            if (OUT_OF_REGION.some(c => aNorm.includes(c))) {
                return [addr, 'Personal', 'Out of Region'];
            }
            if (aNorm.includes('ymca')) {
                if (day === 'Saturday' || day === 'Sunday') {
                    return ['YMCA Drive (Fit4Less)', 'Personal', 'Gym / Personal'];
                }
                return ['Home Depot (20 YMCA Dr, St. Catharines)', 'Materials / Supplies', 'Home Depot - St. Catharines'];
            }
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

        function detectRouteContinuityGaps(trips) {
            const gaps = [];
            for (let i = 0; i < trips.length; i++) {
                if (i === 0) {
                    trips[i].Continuity = 'OK';
                    continue;
                }
                const prevDest = trips[i - 1].Destination;
                const curOrig = trips[i].Origin;
                if (prevDest !== curOrig) {
                    trips[i].Continuity = `Gap: prior stop was ${prevDest}`;
                    gaps.push({
                        index: i,
                        date: trips[i].Date,
                        departure: trips[i].Departure,
                        prevDest: prevDest,
                        curOrig: curOrig,
                        curDest: trips[i].Destination
                    });
                } else {
                    trips[i].Continuity = 'OK';
                }
            }
            return gaps;
        }

        function chainOdometers(deduped, targetYear) {
            const anchorCfg = odoAnchors[targetYear] || { date: `${targetYear}-01-01`, odo: 136111.0, mode: 'forward' };
            if (!deduped.length) return;

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
                } else {
                    let cur = parseFloat(anchorCfg.odo);
                    for (let i = 0; i < deduped.length; i++) {
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

            const gaps = detectRouteContinuityGaps(processedTrips);
            const badge = document.getElementById('continuity-badge');
            const gapsSec = document.getElementById('gaps-section');
            if (processedTrips.length > 0) {
                badge.style.display = 'inline-block';
                if (gaps.length === 0) {
                    badge.style.background = '#e6f4ea';
                    badge.style.color = '#1e7e34';
                    badge.style.border = '1px solid #b7e1cd';
                    badge.innerText = '✓ Route Continuity: 0 Gaps';
                    gapsSec.style.display = 'none';
                } else {
                    badge.style.background = '#fcf0f1';
                    badge.style.color = '#8a2424';
                    badge.style.border = '1px solid #f5c2c7';
                    badge.innerText = `⚠ ${gaps.length} Route Continuity Gap${gaps.length === 1 ? '' : 's'} (Click to View)`;
                    document.getElementById('gaps-count').innerText = gaps.length;
                    const gBody = document.getElementById('gaps-tbody');
                    gBody.innerHTML = '';
                    gaps.forEach(g => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${escapeHtml(g.date)}</td>
                            <td>${escapeHtml(g.departure)}</td>
                            <td>${escapeHtml(g.prevDest)}</td>
                            <td>${escapeHtml(g.curOrig)}</td>
                            <td>${escapeHtml(g.curDest)}</td>
                        `;
                        gBody.appendChild(tr);
                    });
                }
            } else {
                badge.style.display = 'none';
                gapsSec.style.display = 'none';
            }

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
                        <td>${escapeHtml(r.Date)}</td>
                        <td>${escapeHtml(r.Destination)}</td>
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

        document.getElementById('continuity-badge').addEventListener('click', function() {
            const gapsSec = document.getElementById('gaps-section');
            if (document.getElementById('gaps-tbody').children.length === 0) return;
            gapsSec.style.display = (gapsSec.style.display === 'none') ? 'block' : 'none';
        });

        document.getElementById('box-qbo').addEventListener('click', () => document.getElementById('input-qbo').click());
        document.getElementById('box-timeline').addEventListener('click', () => document.getElementById('input-timeline').click());
        document.getElementById('select-year').addEventListener('change', function() {
            document.getElementById('btn-download-label').innerText = `Download ${this.value} CRA-Ready Logbook (.xlsx)`;
            updateLiveSourceBadge();
        });

        // Source A: Load Live Pixel 9 / Android Auto Trips from WordPress DB
        async function fetchLiveTripsForYear(targetYear) {
            const resp = await fetch(AJAX_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'tig_live_trips_action',
                    security: NONCE,
                    op: 'list',
                    year: targetYear
                })
            });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const data = await resp.json();
            if (!data || !data.success) throw new Error((data && data.data) ? data.data : 'Failed to load live trips');
            return data.data.trips || [];
        }

        document.getElementById('btn-load-live-trips').addEventListener('click', async function() {
            const targetYear = document.getElementById('select-year').value;
            setProgress(30, `Fetching live Pixel 9 drives for ${targetYear}...`);
            try {
                const liveRows = await fetchLiveTripsForYear(targetYear);
                if (!liveRows.length) {
                    setProgress(100, `No live Pixel 9 trips found for ${targetYear} yet.`);
                    document.getElementById('progress-bar').style.background = '#d97706';
                    return;
                }

                setProgress(70, 'Classifying endpoints, matching QBO receipts & chaining odometers...');
                const deduped = [];
                for (const t of liveRows) {
                    const [origClean] = classifyEndpoint(t.origin_raw || t.origin, t.day, targetYear);
                    let [destClean, purpose, category] = classifyEndpoint(t.dest_raw || t.destination, t.day, targetYear);
                    let qboNote = '';

                    if (['Personal', 'ZEHRS', 'Fit4Less', 'Out of Region'].some(k => origClean.includes(k)) &&
                        purpose === 'Return to Head Office / Dispatch') {
                        purpose = 'Personal';
                        category = 'Return from Personal';
                    }

                    if (t.manual_purpose) {
                        purpose = t.manual_purpose;
                        category = t.manual_category || (purpose === 'Personal' ? 'Personal (Pixel)' : purpose);
                    }

                    if (purpose === 'Review' || SUPPLIER_PURPOSES.includes(purpose)) {
                        const matchedTx = matchQboForTrip(t.date, destClean, category, purpose);
                        if (matchedTx) {
                            qboNote = `QBO Receipt: $${matchedTx.amt.toFixed(2)} (${matchedTx.name})`;
                            if (purpose === 'Review') {
                                purpose = 'Materials / Supplies';
                                category = `QBO Verified: ${matchedTx.name}`;
                            }
                        }
                    }

                    deduped.push({
                        Date: t.date,
                        Day: t.day,
                        Departure: t.departure,
                        Arrival: t.arrival,
                        Origin: origClean,
                        Destination: destClean,
                        Distance: parseFloat(t.gps_km) || 0,
                        OdoStart: 0,
                        OdoEnd: 0,
                        Purpose: purpose,
                        Category: category,
                        QBO: qboNote,
                        Continuity: 'OK'
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

                chainOdometers(deduped, targetYear);
                processedTrips = deduped;
                refreshSummaryAndReview();

                setProgress(100, 'Live Pixel 9 Trips Loaded');
                document.getElementById('progress-bar').style.background = '#2e7d32';
                document.getElementById('status-timeline').innerHTML = `<span style="color:#2e7d32;">&#10003; Loaded ${deduped.length} live Pixel 9 trips for ${targetYear}</span>`;
                const btn = document.getElementById('btn-download-xlsx');
                btn.disabled = false;
                btn.style.opacity = '1';
            } catch (err) {
                alert('Error loading live Pixel 9 trips: ' + err.message);
            }
        });

        async function renderLiveManagerTable() {
            const targetYear = document.getElementById('select-year').value;
            document.getElementById('live-mgr-year').innerText = targetYear;
            const tbody = document.getElementById('live-mgr-tbody');
            tbody.innerHTML = '<tr><td colspan="6">Loading stored trips...</td></tr>';

            try {
                const rows = await fetchLiveTripsForYear(targetYear);
                if (!rows.length) {
                    tbody.innerHTML = `<tr><td colspan="6">No live Pixel 9 drives recorded for ${escapeHtml(targetYear)} yet.</td></tr>`;
                    return;
                }
                tbody.innerHTML = '';
                rows.forEach(r => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${escapeHtml(r.date)}<br><small>${escapeHtml(r.departure)} &ndash; ${escapeHtml(r.arrival)}</small></td>
                        <td><span style="font-size:11px; background:#eef4fb; padding:2px 6px; border-radius:3px;">${escapeHtml(r.trigger_source)}</span></td>
                        <td><small>${escapeHtml(r.origin)}</small><br>&rarr; <strong>${escapeHtml(r.destination)}</strong></td>
                        <td>${Number(r.gps_km).toFixed(2)}</td>
                        <td>
                            <select class="tig-live-purpose" data-id="${r.id}" style="font-size:12px;">
                                ${buildSelectOptions(PURPOSE_OPTIONS.concat(['Review']), r.purpose)}
                            </select>
                        </td>
                        <td>
                            <button type="button" class="button button-small tig-live-del" data-id="${r.id}" style="color:#b32d2e;">Delete</button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });

                document.querySelectorAll('.tig-live-purpose').forEach(sel => {
                    sel.addEventListener('change', async function() {
                        const id = this.dataset.id;
                        const val = this.value === 'Review' ? '' : this.value;
                        await fetch(AJAX_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'tig_live_trips_action',
                                security: NONCE,
                                op: 'update',
                                id: id,
                                purpose: val,
                                category: val === 'Personal' ? 'Personal (Admin)' : val
                            })
                        });
                        showSaveToast('✓ Live trip purpose updated');
                    });
                });

                document.querySelectorAll('.tig-live-del').forEach(btn => {
                    btn.addEventListener('click', async function() {
                        if (!confirm('Delete this stored Pixel 9 trip?')) return;
                        const id = this.dataset.id;
                        await fetch(AJAX_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'tig_live_trips_action',
                                security: NONCE,
                                op: 'delete',
                                id: id
                            })
                        });
                        LIVE_COUNTS[targetYear] = Math.max(0, (LIVE_COUNTS[targetYear] || 1) - 1);
                        updateLiveSourceBadge();
                        renderLiveManagerTable();
                        showSaveToast('✓ Live trip removed');
                    });
                });
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="6" style="color:#b32d2e;">Error: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        document.getElementById('btn-toggle-live-manager').addEventListener('click', function() {
            const sec = document.getElementById('live-manager-section');
            if (sec.style.display === 'none') {
                sec.style.display = 'block';
                renderLiveManagerTable();
            } else {
                sec.style.display = 'none';
            }
        });
        document.getElementById('btn-close-live-manager').addEventListener('click', () => {
            document.getElementById('live-manager-section').style.display = 'none';
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

        // Source B: Google Timeline JSON Import
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
                                OdoStart: 0, OdoEnd: 0, Purpose: purpose, Category: category, QBO: qboNote,
                                Continuity: 'OK'
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

                        chainOdometers(deduped, targetYear);

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
                'Distance (km)', 'Odo Start', 'Odo End', 'Purpose', 'Category', 'QBO Audit Corroboration', 'Route Continuity'
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
                    r.Distance, r.OdoStart, r.OdoEnd, r.Purpose, r.Category, r.QBO, r.Continuity
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

        function buildMonthlySummarySheet(targetYear, ref, lastRow, totals) {
            const rows = [
                [`HOMEEXTENT INC. (o/a THE INNOVATIVE GROUP) — ${targetYear} MONTHLY & QUARTERLY BREAKDOWN`],
                ['Prepared for Quarterly HST Input Tax Credit (ITC) & Corporate Tax Reconciliation'],
                [''],
                ['Month', 'Trips Logged', 'Total KM', 'Commercial KM (Deductible)', 'Personal KM (Non-Deductible)', 'Commercial %']
            ];

            MONTH_LABELS.forEach(([mm, label], idx) => {
                const excelRow = 5 + idx;
                const prefix = `${targetYear}-${mm}`;
                const mTrips = processedTrips.filter(r => r.Date.startsWith(prefix));
                const mTotal = Math.round(mTrips.reduce((s, r) => s + r.Distance, 0) * 100) / 100;
                const mBiz = Math.round(mTrips.filter(r => !r.Purpose.toLowerCase().includes('personal')).reduce((s, r) => s + r.Distance, 0) * 100) / 100;
                const mPers = Math.round((mTotal - mBiz) * 100) / 100;
                const mRatio = mTotal > 0 ? mBiz / mTotal : 0;

                rows.push([
                    `${label} ${targetYear}`,
                    { t: 'n', v: mTrips.length, f: `COUNTIF(${ref}!$A$2:$A$${lastRow},"${prefix}-*")`, z: '#,##0' },
                    { t: 'n', v: mTotal, f: `SUMIF(${ref}!$A$2:$A$${lastRow},"${prefix}-*",${ref}!$G$2:$G$${lastRow})`, z: '#,##0.00' },
                    { t: 'n', v: mBiz, f: `SUMIFS(${ref}!$G$2:$G$${lastRow},${ref}!$A$2:$A$${lastRow},"${prefix}-*",${ref}!$J$2:$J$${lastRow},"<>Personal")`, z: '#,##0.00' },
                    { t: 'n', v: mPers, f: `SUMIFS(${ref}!$G$2:$G$${lastRow},${ref}!$A$2:$A$${lastRow},"${prefix}-*",${ref}!$J$2:$J$${lastRow},"Personal")`, z: '#,##0.00' },
                    { t: 'n', v: mRatio, f: `IF(C${excelRow}>0,D${excelRow}/C${excelRow},0)`, z: '0.00%' }
                ]);
            });

            rows.push([
                `TOTAL ${targetYear}`,
                { t: 'n', v: totals.n, f: 'SUM(B5:B16)', z: '#,##0' },
                { t: 'n', v: totals.totalKm, f: 'SUM(C5:C16)', z: '#,##0.00' },
                { t: 'n', v: totals.bizKm, f: 'SUM(D5:D16)', z: '#,##0.00' },
                { t: 'n', v: totals.persKm, f: 'SUM(E5:E16)', z: '#,##0.00' },
                { t: 'n', v: totals.bizRatio, f: 'IF(C17>0,D17/C17,0)', z: '0.00%' }
            ]);

            rows.push([]);
            rows.push(['Quarterly HST ITC Rollup', 'Trips Logged', 'Total KM', 'Commercial KM (Deductible)', 'Personal KM (Non-Deductible)', 'Commercial %']);

            const quarters = [
                ['Q1 (Jan – Mar)', 5, 7, '01', '02', '03'],
                ['Q2 (Apr – Jun)', 8, 10, '04', '05', '06'],
                ['Q3 (Jul – Sep)', 11, 13, '07', '08', '09'],
                ['Q4 (Oct – Dec)', 14, 16, '10', '11', '12']
            ];

            quarters.forEach(([qLabel, rStart, rEnd, m1, m2, m3], qIdx) => {
                const qRow = 20 + qIdx;
                const qTrips = processedTrips.filter(r => [m1, m2, m3].some(m => r.Date.startsWith(`${targetYear}-${m}`)));
                const qTotal = Math.round(qTrips.reduce((s, r) => s + r.Distance, 0) * 100) / 100;
                const qBiz = Math.round(qTrips.filter(r => !r.Purpose.toLowerCase().includes('personal')).reduce((s, r) => s + r.Distance, 0) * 100) / 100;
                const qPers = Math.round((qTotal - qBiz) * 100) / 100;
                const qRatio = qTotal > 0 ? qBiz / qTotal : 0;

                rows.push([
                    qLabel,
                    { t: 'n', v: qTrips.length, f: `SUM(B${rStart}:B${rEnd})`, z: '#,##0' },
                    { t: 'n', v: qTotal, f: `SUM(C${rStart}:C${rEnd})`, z: '#,##0.00' },
                    { t: 'n', v: qBiz, f: `SUM(D${rStart}:D${rEnd})`, z: '#,##0.00' },
                    { t: 'n', v: qPers, f: `SUM(E${rStart}:E${rEnd})`, z: '#,##0.00' },
                    { t: 'n', v: qRatio, f: `IF(C${qRow}>0,D${qRow}/C${qRow},0)`, z: '0.00%' }
                ]);
            });

            const wsMonth = XLSX.utils.aoa_to_sheet(rows);
            wsMonth['!cols'] = [
                { wch: 24 }, { wch: 15 }, { wch: 16 }, { wch: 26 }, { wch: 26 }, { wch: 16 }
            ];
            return wsMonth;
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

                const wsMonth = buildMonthlySummarySheet(targetYear, ref, lastRow, { n, totalKm, bizKm, persKm, bizRatio });
                XLSX.utils.book_append_sheet(wb, wsMonth, 'Monthly Summary');

                const headers = [
                    'Date', 'Day', 'Departure', 'Arrival', 'Origin', 'Destination',
                    'Distance (km)', 'Odo Start', 'Odo End', 'Purpose', 'Category', 'QBO Audit Corroboration', 'Route Continuity'
                ];
                const logRows = [headers];
                processedTrips.forEach(r => {
                    logRows.push([
                        r.Date, r.Day, r.Departure, r.Arrival, r.Origin, r.Destination,
                        r.Distance, r.OdoStart, r.OdoEnd, r.Purpose, r.Category, r.QBO, r.Continuity
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
                    { wch: 15 }, { wch: 13 }, { wch: 13 }, { wch: 32 }, { wch: 28 }, { wch: 34 }, { wch: 34 }
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
