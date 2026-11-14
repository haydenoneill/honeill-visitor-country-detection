<?php
/**
 * Plugin Name: Honeill Visitor Country Detection
 * Description: Show the right content to the right country. Detects a visitor's country from their IP using MaxMind GeoLite2 — auto-downloaded and kept updated with your own licence key — and exposes it through a filter and a REST endpoint.
 * Version: 1.4.0
 * Author: Hayden O'Neill
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: honeill-visitor-country-detection
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * This product includes GeoLite2 Data created by MaxMind, available from https://www.maxmind.com.
 * Bundled library: maxmind-db/reader (Apache-2.0).
 *
 * Honeill Visitor Country Detection is free software: you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation, either version 2 of the
 * License, or (at your option) any later version.
 */

if (!defined('ABSPATH')) {
    exit;
}

// MaxMind DB reader (pure-PHP, no C extension required) — shipped in /vendor.
$honeill_visitor_country_detection_autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
if (is_readable($honeill_visitor_country_detection_autoload)) {
    require_once $honeill_visitor_country_detection_autoload;
}
unset($honeill_visitor_country_detection_autoload);

require_once __DIR__ . '/includes/telemetry.php';
Honeill_Visitor_Country_Detection_Telemetry::boot(__FILE__, '1.4.0', array('settings_page_honeill-visitor-country-detection'));

/* ---------------------------------------------------------------------------
 * Storage paths + credentials
 * ------------------------------------------------------------------------- */

/** The pre-1.3.0 storage folder — a fixed, guessable name, kept only to migrate away from it. */
function honeill_visitor_country_detection_legacy_dir() {
    $uploads = wp_upload_dir(null, false); // false: don't create this month's uploads folder on every request
    return trailingslashit($uploads['basedir']) . 'honeill-visitor-country-detection';
}

/**
 * Where the downloaded database lives — in uploads, NOT the plugin folder (survives updates).
 * The folder name carries a random suffix so the licensed file has no guessable URL: the .htaccess
 * block only works on Apache, and nginx (including nginx serving static files in front of Apache)
 * ignores it. Until the one-time migration has run, the pre-1.3.0 folder is used.
 */
function honeill_visitor_country_detection_dir() {
    $uploads = wp_upload_dir(null, false);
    $folder = (string) get_option('honeill_visitor_country_detection_folder', '');

    if (preg_match('/^honeill-visitor-country-detection-[a-z0-9]{20}$/', $folder)) {
        return trailingslashit($uploads['basedir']) . $folder;
    }

    return honeill_visitor_country_detection_legacy_dir();
}

/**
 * Give the storage folder — and the database file inside it — random names, moving an existing
 * pre-1.3.x folder/file across. The random file name matters on servers that list directory
 * contents: the uploads listing can reveal the folder, but the folder's own index.html hides the
 * file. Runs from admin_init, activation and before a download — never on a visitor request.
 * add_option() only succeeds once, so two simultaneous requests can't each pick a different name.
 */
function honeill_visitor_country_detection_migrate_dir() {
    if (!preg_match('/^honeill-visitor-country-detection-[a-z0-9]{20}$/', (string) get_option('honeill_visitor_country_detection_folder', ''))) {
        delete_option('honeill_visitor_country_detection_folder'); // clear an invalid value so add_option() can claim the name
        $folder = 'honeill-visitor-country-detection-' . strtolower(wp_generate_password(20, false, false));
        if (!add_option('honeill_visitor_country_detection_folder', $folder)) {
            return;
        }

        $uploads = wp_upload_dir(null, false);
        $legacy = honeill_visitor_country_detection_legacy_dir();
        $target = trailingslashit($uploads['basedir']) . $folder;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- moving our own uploads subfolder.
        if (is_dir($legacy) && !@rename($legacy, $target)) {
            delete_option('honeill_visitor_country_detection_folder'); // keep using the old folder; retried on the next admin load
            return;
        }
    }

    if (!preg_match('/^GeoLite2-Country-[a-z0-9]{20}\.mmdb$/', (string) get_option('honeill_visitor_country_detection_file', ''))) {
        $dir = honeill_visitor_country_detection_dir();
        $old = $dir . '/GeoLite2-Country.mmdb';
        delete_option('honeill_visitor_country_detection_file');
        $file = 'GeoLite2-Country-' . strtolower(wp_generate_password(20, false, false)) . '.mmdb';
        if (!add_option('honeill_visitor_country_detection_file', $file)) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- renaming our own file inside our uploads subfolder.
        if (file_exists($old) && !@rename($old, $dir . '/' . $file)) {
            delete_option('honeill_visitor_country_detection_file'); // keep the old name; retried on the next admin load
        }
    }
}

function honeill_visitor_country_detection_db_path() {
    $file = (string) get_option('honeill_visitor_country_detection_file', '');
    if (!preg_match('/^GeoLite2-Country-[a-z0-9]{20}\.mmdb$/', $file)) {
        $file = 'GeoLite2-Country.mmdb'; // until the one-time migration has run
    }
    return honeill_visitor_country_detection_dir() . '/' . $file;
}

/** Licence key: a wp-config constant wins (keeps it out of the DB); otherwise the saved option. */
function honeill_visitor_country_detection_license_key() {
    if (defined('HONEILL_VISITOR_COUNTRY_DETECTION_LICENSE_KEY') && HONEILL_VISITOR_COUNTRY_DETECTION_LICENSE_KEY) {
        return (string) HONEILL_VISITOR_COUNTRY_DETECTION_LICENSE_KEY;
    }
    return (string) get_option('honeill_visitor_country_detection_license_key', '');
}

/** MaxMind account ID (needed for MaxMind's current download endpoint). A wp-config constant wins. */
function honeill_visitor_country_detection_account_id() {
    if (defined('HONEILL_VISITOR_COUNTRY_DETECTION_ACCOUNT_ID') && HONEILL_VISITOR_COUNTRY_DETECTION_ACCOUNT_ID) {
        return preg_replace('/\D/', '', (string) HONEILL_VISITOR_COUNTRY_DETECTION_ACCOUNT_ID);
    }
    return preg_replace('/\D/', '', (string) get_option('honeill_visitor_country_detection_account_id', ''));
}

/* ---------------------------------------------------------------------------
 * Public API: visitor country via the `honeill_visitor_country_detection_country_code` filter
 * ------------------------------------------------------------------------- */

/**
 * Cloudflare's published edge ranges (cloudflare.com/ips, last changed 28 Sep 2023). Requests
 * relayed by one of these carry the real visitor in X-Forwarded-For and a trustworthy
 * CF-IPCountry header.
 */
function honeill_visitor_country_detection_cloudflare_ranges() {
    return array(
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    );
}

/** True when $ip (v4 or v6) falls inside any of the CIDR $ranges. */
function honeill_visitor_country_detection_ip_in_ranges($ip, array $ranges) {
    $packed = @inet_pton($ip); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
    if ($packed === false) {
        return false;
    }

    foreach ($ranges as $range) {
        $parts = explode('/', (string) $range, 2);
        $subnet = @inet_pton($parts[0]); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
        if ($subnet === false || strlen($subnet) !== strlen($packed)) {
            continue;
        }

        $max_bits = strlen($packed) * 8;
        if (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > $max_bits)) {
            continue; // malformed prefix length — never widen it into "trust everything"
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : $max_bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (substr($packed, 0, $bytes) !== substr($subnet, 0, $bytes)) {
            continue;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = 0xff << (8 - $remainder) & 0xff;
        if ((ord($packed[$bytes]) & $mask) === (ord($subnet[$bytes]) & $mask)) {
            return true;
        }
    }

    return false;
}

/**
 * Work out who is really connecting. REMOTE_ADDR is the machine that opened the connection and
 * can't be faked. X-Forwarded-For can be — a client may send any value — but every proxy appends
 * the address it received the request from. So the chain (X-Forwarded-For, then REMOTE_ADDR) is
 * read from the right, stepping past hops we trust to have appended honestly: private/loopback
 * addresses (the host's own reverse proxy), Cloudflare's edge, and anything added with the
 * `honeill_visitor_country_detection_trusted_proxies` filter (CIDR strings). The first hop that
 * isn't trusted is the visitor; anything further left is unverifiable and ignored.
 *
 * Returns array( 'ip' => string, 'cloudflare' => bool ) — cloudflare is true when the request
 * genuinely passed through Cloudflare's edge, which makes its CF-IPCountry header trustworthy.
 */
function honeill_visitor_country_detection_resolve() {
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $resolved = array('ip' => '', 'cloudflare' => false);
    $remote = isset($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '';

    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        return $resolved;
    }

    $chain = array();
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $chain = array_map('honeill_visitor_country_detection_normalise_hop', explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))));
    }
    $chain[] = $remote;

    $cloudflare = honeill_visitor_country_detection_cloudflare_ranges();
    $trusted = array_filter((array) apply_filters('honeill_visitor_country_detection_trusted_proxies', array()), 'is_string');

    for ($i = count($chain) - 1; $i >= 0; $i--) {
        $hop = $chain[$i];

        if (!filter_var($hop, FILTER_VALIDATE_IP)) {
            break; // garbage in the chain — nothing to its left can be trusted
        }
        if (!filter_var($hop, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            continue; // the host's own proxy
        }
        if (honeill_visitor_country_detection_ip_in_ranges($hop, $cloudflare)) {
            $resolved['cloudflare'] = true;
            continue;
        }
        if ($trusted && honeill_visitor_country_detection_ip_in_ranges($hop, $trusted)) {
            continue;
        }

        $resolved['ip'] = $hop;
        break;
    }

    return $resolved;
}

/**
 * One X-Forwarded-For entry as a bare IP. Some proxies (Azure App Service, IIS ARR) append
 * "203.0.113.7:51234" or "[2001:db8::1]:443"; the port is dropped so the address still validates.
 */
function honeill_visitor_country_detection_normalise_hop($hop) {
    $hop = trim((string) $hop);
    if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $hop, $m)) {
        return $m[1];
    }
    if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $hop, $m)) {
        return $m[1];
    }
    return $hop;
}

/**
 * Real visitor IP (see honeill_visitor_country_detection_resolve()). Region-only — do NOT use
 * for auth/security. Override with the `honeill_visitor_country_detection_client_ip` filter.
 */
function honeill_visitor_country_detection_client_ip() {
    $resolved = honeill_visitor_country_detection_resolve();
    return (string) apply_filters('honeill_visitor_country_detection_client_ip', $resolved['ip']);
}

/**
 * Visitor country (ISO-3166-1 alpha-2), exposed via `honeill_visitor_country_detection_country_code`.
 * A value already supplied by an earlier callback is kept. Cached per IP (including negative
 * results) in the object cache — never the database, because the IP can come from a header and
 * per-IP DB rows could be grown without bound. Non-persistent cache = per-request memo;
 * Redis/Memcached = shared, TTL'd and evicted. Returns '' when undetermined — the caller decides
 * the fallback.
 */
add_filter('honeill_visitor_country_detection_country_code', 'honeill_visitor_country_detection_detect_country');
function honeill_visitor_country_detection_detect_country($cc = '') {
    if (is_string($cc) && $cc !== '') {
        return $cc;
    }

    // Behind Cloudflare its own country header is exact and costs nothing (XX = unknown, T1 = Tor).
    $resolved = honeill_visitor_country_detection_resolve();
    if ($resolved['cloudflare'] && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
        $code = strtoupper(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'])));
        if (preg_match('/^[A-Z]{2}$/', $code) && !in_array($code, array('XX', 'T1'), true)) {
            return $code;
        }
    }

    $ip = honeill_visitor_country_detection_client_ip();

    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ''; // local/dev/private — nothing to resolve
    }

    $cache_key = md5($ip);
    $cached = wp_cache_get($cache_key, 'honeill_visitor_country_detection');
    if (is_string($cached)) {
        return $cached;
    }

    $result = '';
    $reader = honeill_visitor_country_detection_reader();

    if ($reader) {
        try {
            $record = $reader->get($ip);
            // A few networks (anycast, some mobile/satellite ranges) only carry the country the block is registered to.
            if (isset($record['country']['iso_code'])) {
                $code = strtoupper((string) $record['country']['iso_code']);
            } elseif (isset($record['registered_country']['iso_code'])) {
                $code = strtoupper((string) $record['registered_country']['iso_code']);
            } else {
                $code = '';
            }
            if (ctype_alpha($code) && strlen($code) === 2) {
                $result = $code;
            }
        } catch (\Throwable $e) {
            $result = ''; // bad IP / corrupt DB — fail soft
        }
    }

    wp_cache_set($cache_key, $result, 'honeill_visitor_country_detection', 10 * MINUTE_IN_SECONDS);
    return $result;
}

/** One reader per request (opening it parses the database metadata). False when unavailable. */
function honeill_visitor_country_detection_reader() {
    static $reader = null;

    if ($reader === null) {
        $reader = false;
        $db = honeill_visitor_country_detection_db_path();
        if (class_exists('MaxMind\\Db\\Reader') && is_readable($db)) {
            try {
                $reader = new \MaxMind\Db\Reader($db);
            } catch (\Throwable $e) {
                $reader = false;
            }
        }
    }

    return $reader;
}

/**
 * REST: GET /wp-json/honeill-visitor-country-detection/v1/country  ->  { "country": "GB" }
 * Returns the caller's own country code. Public — it only ever exposes the requester's own country.
 */
add_action('rest_api_init', 'honeill_visitor_country_detection_register_rest');
function honeill_visitor_country_detection_register_rest() {
    register_rest_route('honeill-visitor-country-detection/v1', '/country', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'honeill_visitor_country_detection_rest_country',
        'permission_callback' => '__return_true',
    ));
}
function honeill_visitor_country_detection_rest_country() {
    // Result varies per visitor IP — never let a CDN/page cache share it between visitors.
    $response = new WP_REST_Response(array('country' => apply_filters('honeill_visitor_country_detection_country_code', '')));
    foreach (wp_get_nocache_headers() as $name => $value) {
        $response->header($name, $value);
    }
    return $response;
}

/** Size ceilings for the download (~5 MB today) and for the unpacked archive (~10 MB today). */
const HONEILL_VISITOR_COUNTRY_DETECTION_MAX_DOWNLOAD = 64 * 1024 * 1024;
const HONEILL_VISITOR_COUNTRY_DETECTION_MAX_UNPACKED = 256 * 1024 * 1024;

/* ---------------------------------------------------------------------------
 * Download / auto-update the database (the same routine runs on save, on demand and on cron)
 * ------------------------------------------------------------------------- */

/**
 * Download the newest GeoLite2-Country DB and atomically swap it in. Records the outcome
 * (last updated / last error) so a failing weekly update is visible on the settings screen.
 * Returns true on success or a WP_Error describing what went wrong.
 */
function honeill_visitor_country_detection_update_database() {
    $result = honeill_visitor_country_detection_download_database();

    if (is_wp_error($result)) {
        // Never let the key reach the screen or the database via an error message.
        $key = honeill_visitor_country_detection_license_key();
        $message = $key !== '' ? str_replace(array($key, rawurlencode($key)), '••••', $result->get_error_message()) : $result->get_error_message();
        $result = new WP_Error($result->get_error_code(), $message);

        update_option('honeill_visitor_country_detection_last_error', array('time' => time(), 'message' => $result->get_error_message()), false);
    } else {
        update_option('honeill_visitor_country_detection_last_updated', time(), false);
        delete_option('honeill_visitor_country_detection_last_error');
    }

    return $result;
}

function honeill_visitor_country_detection_download_database() {
    $key = honeill_visitor_country_detection_license_key();
    if ($key === '') {
        return new WP_Error('honeill_visitor_country_detection_no_key', __('No MaxMind licence key is set.', 'honeill-visitor-country-detection'));
    }
    if (!function_exists('gzdecode')) {
        return new WP_Error('honeill_visitor_country_detection_no_zlib', __('The PHP zlib extension is required to unpack the database. Ask your host to enable it.', 'honeill-visitor-country-detection'));
    }
    if (!class_exists('MaxMind\\Db\\Reader')) {
        return new WP_Error('honeill_visitor_country_detection_no_reader', __('The bundled MaxMind reader library is missing. Reinstall the plugin.', 'honeill-visitor-country-detection'));
    }

    wp_raise_memory_limit('admin'); // the archive is unpacked in memory; cron runs with the front-end limit
    honeill_visitor_country_detection_migrate_dir();

    $dir = honeill_visitor_country_detection_dir();
    if (!wp_mkdir_p($dir)) {
        return new WP_Error('honeill_visitor_country_detection_no_dir', __('Could not create the storage folder inside wp-content/uploads.', 'honeill-visitor-country-detection'));
    }
    honeill_visitor_country_detection_protect_dir($dir);

    $archive = honeill_visitor_country_detection_fetch_archive($key, honeill_visitor_country_detection_account_id());
    if (is_wp_error($archive)) {
        return $archive;
    }

    $mmdb = honeill_visitor_country_detection_extract_mmdb($archive);
    unset($archive);

    if ($mmdb === '') {
        return new WP_Error('honeill_visitor_country_detection_extract', __('The download wasn\'t a valid GeoLite2 archive. Check the licence key and account ID, then try again.', 'honeill-visitor-country-detection'));
    }

    $dest = honeill_visitor_country_detection_db_path();
    // Unique per run: two overlapping updates (cron + the button) never share a half-written file.
    $tmp = $dest . '.' . strtolower(wp_generate_password(8, false, false)) . '.tmp';

    // phpcs:disable WordPress.WP.AlternativeFunctions -- direct fs writes are the practical way to atomically swap a binary file; WP_Filesystem has no atomic rename. All writes stay inside our uploads subfolder.
    if (file_put_contents($tmp, $mmdb) === false) {
        return new WP_Error('honeill_visitor_country_detection_write', __('The database downloaded but could not be saved. Check that wp-content/uploads is writable.', 'honeill-visitor-country-detection'));
    }
    unset($mmdb);

    // Only swap in a file the reader can open and that really is a Country database.
    $valid = false;
    try {
        $check = new \MaxMind\Db\Reader($tmp);
        $valid = stripos((string) $check->metadata()->databaseType, 'Country') !== false;
        $check->close();
    } catch (\Throwable $e) {
        $valid = false;
    }

    if (!$valid) {
        wp_delete_file($tmp);
        return new WP_Error('honeill_visitor_country_detection_invalid', __('The downloaded database failed its integrity check, so the existing one was kept.', 'honeill-visitor-country-detection'));
    }

    if (!rename($tmp, $dest)) { // atomic swap — a half-written file is never read
        wp_delete_file($tmp);
        return new WP_Error('honeill_visitor_country_detection_write', __('The database downloaded but could not be saved. Check that wp-content/uploads is writable.', 'honeill-visitor-country-detection'));
    }
    // phpcs:enable WordPress.WP.AlternativeFunctions

    return true;
}

/**
 * Fetch the GeoLite2-Country .tar.gz and return its raw bytes (or a WP_Error).
 *
 * With an account ID this uses MaxMind's documented endpoint and HTTP Basic auth, so the key never
 * appears in a URL (and so never in proxy or server logs). MaxMind answers with a redirect to
 * pre-signed storage; that redirect is followed separately, without the credentials, because the
 * storage host rejects a second auth method and must never see the key anyway. Without an account
 * ID it falls back to the legacy query-string endpoint, which the settings screen flags.
 */
function honeill_visitor_country_detection_fetch_archive($key, $account_id) {
    if ($account_id !== '') {
        $response = wp_safe_remote_get('https://download.maxmind.com/geoip/databases/GeoLite2-Country/download?suffix=tar.gz', array(
            'timeout'     => 60,
            'limit_response_size' => HONEILL_VISITOR_COUNTRY_DETECTION_MAX_DOWNLOAD,
            'redirection' => 0,
            'headers'     => array(
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth requires base64.
                'Authorization' => 'Basic ' . base64_encode($account_id . ':' . $key),
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $location = (string) wp_remote_retrieve_header($response, 'location');

        if ($code >= 300 && $code < 400 && strpos($location, 'https://') === 0) {
            $response = wp_safe_remote_get($location, array('timeout' => 300, 'limit_response_size' => HONEILL_VISITOR_COUNTRY_DETECTION_MAX_DOWNLOAD));
            if (is_wp_error($response)) {
                return $response;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
        }
    } else {
        $response = wp_safe_remote_get(add_query_arg(
            array(
                'edition_id'  => 'GeoLite2-Country',
                'license_key' => rawurlencode($key),
                'suffix'      => 'tar.gz',
            ),
            'https://download.maxmind.com/app/geoip_download'
        ), array('timeout' => 300, 'limit_response_size' => HONEILL_VISITOR_COUNTRY_DETECTION_MAX_DOWNLOAD));

        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
    }

    if ($code === 401 || $code === 403) {
        return new WP_Error('honeill_visitor_country_detection_auth', __('MaxMind rejected the licence key or account ID (HTTP 401/403).', 'honeill-visitor-country-detection'));
    }
    if ($code !== 200) {
        /* translators: %d: HTTP status code */
        return new WP_Error('honeill_visitor_country_detection_http', sprintf(__('MaxMind returned HTTP %d. Try again later.', 'honeill-visitor-country-detection'), $code));
    }

    return (string) wp_remote_retrieve_body($response);
}

/**
 * Pull the .mmdb out of a .tar.gz held in memory, without PharData: opening an archive with Phar
 * can unserialise metadata embedded in it on older PHP versions, and plain gzip + tar parsing
 * needs nothing beyond zlib. Only regular-file entries ending in .mmdb are considered; nothing is
 * ever written using a name from the archive. Returns '' when no database is found.
 */
function honeill_visitor_country_detection_extract_mmdb($archive) {
    // Capped: a hostile or corrupt archive can't inflate without limit (the real one unpacks to ~10 MB).
    $tar = function_exists('gzdecode') ? @gzdecode($archive, HONEILL_VISITOR_COUNTRY_DETECTION_MAX_UNPACKED) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false; the warning adds nothing.
    if (!is_string($tar)) {
        return '';
    }

    $length = strlen($tar);
    $offset = 0;

    while ($offset + 512 <= $length) {
        $header = substr($tar, $offset, 512);
        if (trim($header, "\0") === '') {
            break; // end-of-archive marker
        }

        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");
        $size = octdec(trim(substr($header, 124, 12), "\0 "));
        $type = $header[156];
        $offset += 512;

        if (($type === '0' || $type === "\0") && substr($prefix . '/' . $name, -5) === '.mmdb' && $offset + $size <= $length) {
            return substr($tar, $offset, (int) $size);
        }

        $offset += (int) ceil($size / 512) * 512;
    }

    return '';
}

/** Best-effort block on web access, as a second layer behind the random folder name (Apache only). */
function honeill_visitor_country_detection_protect_dir($dir) {
    // phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- best-effort protective files in our own uploads subfolder; a failure here is non-fatal.
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $index = $dir . '/index.html';
    if (!file_exists($index)) {
        @file_put_contents($index, '');
    }
    // phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
}

/* ---------------------------------------------------------------------------
 * Auto-update schedule (WP-Cron — no server cron needed)
 * ------------------------------------------------------------------------- */

add_filter('cron_schedules', 'honeill_visitor_country_detection_cron_schedules');
function honeill_visitor_country_detection_cron_schedules($schedules) {
    if (!isset($schedules['honeill_visitor_country_detection_weekly'])) {
        $schedules['honeill_visitor_country_detection_weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => __('Once weekly (Honeill Visitor Country Detection)', 'honeill-visitor-country-detection'),
        );
    }
    return $schedules;
}

add_action('honeill_visitor_country_detection_refresh', 'honeill_visitor_country_detection_update_database');

function honeill_visitor_country_detection_maybe_schedule() {
    if (honeill_visitor_country_detection_license_key() !== '' && !wp_next_scheduled('honeill_visitor_country_detection_refresh')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'honeill_visitor_country_detection_weekly', 'honeill_visitor_country_detection_refresh');
    }
}

function honeill_visitor_country_detection_unschedule() {
    $timestamp = wp_next_scheduled('honeill_visitor_country_detection_refresh');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'honeill_visitor_country_detection_refresh');
    }
}

// Keep the schedule alive whenever a key is present (e.g. set via constant).
add_action('admin_init', 'honeill_visitor_country_detection_maybe_schedule');

/**
 * One-time upgrade to 1.3.0: stop the settings loading on every front-end request, and move the
 * database out of the old guessable folder.
 */
add_action('admin_init', 'honeill_visitor_country_detection_upgrade');
function honeill_visitor_country_detection_upgrade() {
    honeill_visitor_country_detection_migrate_dir(); // one get_option() once done; retries a folder move that failed

    if (get_option('honeill_visitor_country_detection_version') === '1.3.0') {
        return;
    }

    if (function_exists('wp_set_option_autoload_values')) {
        wp_set_option_autoload_values(array(
            'honeill_visitor_country_detection_license_key'  => false,
            'honeill_visitor_country_detection_last_updated' => false,
        ));
    }

    update_option('honeill_visitor_country_detection_version', '1.3.0', false);
}

register_activation_hook(__FILE__, 'honeill_visitor_country_detection_activate');
function honeill_visitor_country_detection_activate() {
    honeill_visitor_country_detection_migrate_dir();
    $dir = honeill_visitor_country_detection_dir();
    wp_mkdir_p($dir);
    honeill_visitor_country_detection_protect_dir($dir);
    honeill_visitor_country_detection_maybe_schedule();
}

register_deactivation_hook(__FILE__, 'honeill_visitor_country_detection_unschedule');

/* ---------------------------------------------------------------------------
 * Settings screen (Settings → Honeill Visitor Country Detection)
 * ------------------------------------------------------------------------- */

add_action('admin_menu', 'honeill_visitor_country_detection_menu');
function honeill_visitor_country_detection_menu() {
    add_options_page(
        __('Honeill Visitor Country Detection', 'honeill-visitor-country-detection'),
        __('Honeill Visitor Country Detection', 'honeill-visitor-country-detection'),
        'manage_options',
        'honeill-visitor-country-detection',
        'honeill_visitor_country_detection_settings_page'
    );
}

function honeill_visitor_country_detection_settings_url() {
    return admin_url('options-general.php?page=honeill-visitor-country-detection');
}

/** Stash a one-shot admin notice (true = success, WP_Error = failure, 'saved' = info). */
function honeill_visitor_country_detection_set_notice($result) {
    if ($result === true) {
        $notice = array('type' => 'success', 'msg' => __('Database downloaded and updated successfully.', 'honeill-visitor-country-detection'));
    } elseif (is_wp_error($result)) {
        $notice = array('type' => 'error', 'msg' => $result->get_error_message());
    } else {
        $notice = array('type' => 'info', 'msg' => __('Settings saved.', 'honeill-visitor-country-detection'));
    }
    set_transient('honeill_visitor_country_detection_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
}

add_action('admin_post_honeill_visitor_country_detection_save', 'honeill_visitor_country_detection_handle_save');
function honeill_visitor_country_detection_handle_save() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'honeill-visitor-country-detection'));
    }
    check_admin_referer('honeill_visitor_country_detection_save');

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by check_admin_referer() above
    $submitted = isset($_POST['honeill_visitor_country_detection_license_key']) ? sanitize_text_field(wp_unslash($_POST['honeill_visitor_country_detection_license_key'])) : '';
    $account_id = isset($_POST['honeill_visitor_country_detection_account_id']) ? preg_replace('/\D/', '', sanitize_text_field(wp_unslash($_POST['honeill_visitor_country_detection_account_id']))) : null;
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    if ($account_id !== null) {
        update_option('honeill_visitor_country_detection_account_id', $account_id, false);
    }

    if ($submitted !== '') {
        update_option('honeill_visitor_country_detection_license_key', $submitted, false);
    }

    if (honeill_visitor_country_detection_license_key() !== '' && ($submitted !== '' || $account_id !== null)) {
        honeill_visitor_country_detection_maybe_schedule();
        honeill_visitor_country_detection_set_notice(honeill_visitor_country_detection_update_database()); // pull the DB right now
    } else {
        // Key left blank and nothing else to try — keep the existing key untouched (write-only field).
        honeill_visitor_country_detection_set_notice('saved');
    }

    wp_safe_redirect(honeill_visitor_country_detection_settings_url());
    exit;
}

add_action('admin_post_honeill_visitor_country_detection_remove', 'honeill_visitor_country_detection_handle_remove');
function honeill_visitor_country_detection_handle_remove() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'honeill-visitor-country-detection'));
    }
    check_admin_referer('honeill_visitor_country_detection_remove');

    delete_option('honeill_visitor_country_detection_license_key');
    honeill_visitor_country_detection_unschedule();
    honeill_visitor_country_detection_set_notice('saved');

    wp_safe_redirect(honeill_visitor_country_detection_settings_url());
    exit;
}

add_action('admin_post_honeill_visitor_country_detection_update', 'honeill_visitor_country_detection_handle_update');
function honeill_visitor_country_detection_handle_update() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'honeill-visitor-country-detection'));
    }
    check_admin_referer('honeill_visitor_country_detection_update');

    honeill_visitor_country_detection_set_notice(honeill_visitor_country_detection_update_database());
    wp_safe_redirect(honeill_visitor_country_detection_settings_url());
    exit;
}

function honeill_visitor_country_detection_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'honeill-visitor-country-detection'));
    }

    $key         = honeill_visitor_country_detection_license_key();
    $account_id  = honeill_visitor_country_detection_account_id();
    $by_const    = defined('HONEILL_VISITOR_COUNTRY_DETECTION_LICENSE_KEY') && HONEILL_VISITOR_COUNTRY_DETECTION_LICENSE_KEY;
    $id_by_const = defined('HONEILL_VISITOR_COUNTRY_DETECTION_ACCOUNT_ID') && HONEILL_VISITOR_COUNTRY_DETECTION_ACCOUNT_ID;
    $db          = honeill_visitor_country_detection_db_path();
    $has_db      = is_readable($db);
    $updated     = (int) get_option('honeill_visitor_country_detection_last_updated', 0);
    $last_error  = get_option('honeill_visitor_country_detection_last_error', array());
    $next        = wp_next_scheduled('honeill_visitor_country_detection_refresh');

    $notice = get_transient('honeill_visitor_country_detection_notice_' . get_current_user_id());
    if ($notice) {
        delete_transient('honeill_visitor_country_detection_notice_' . get_current_user_id());
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Honeill Visitor Country Detection', 'honeill-visitor-country-detection') . '</h1>';

    if (is_array($notice)) {
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($notice['type']),
            esc_html($notice['msg'])
        );
    }

    if ($key !== '' && $account_id === '') {
        echo '<div class="notice notice-warning"><p>' . esc_html__('Add your MaxMind account ID below. Without it the plugin uses MaxMind\'s older download method, which sends the licence key in the URL and may stop working.', 'honeill-visitor-country-detection') . '</p></div>';
    }

    // Intro + how to get a key.
    echo '<p>' . esc_html__('Enter your MaxMind account ID and licence key. The plugin downloads the GeoLite2 country database immediately and refreshes it automatically every week — you never download or upload anything by hand.', 'honeill-visitor-country-detection') . '</p>';
    echo '<p>' . wp_kses_post(sprintf(
        /* translators: %s: URL to MaxMind sign-up page */
        __('No key yet? Create a free MaxMind account, then generate a licence key under Account → Manage License Keys (your account ID is shown on the same page): <a href="%s" target="_blank" rel="noopener">maxmind.com</a>.', 'honeill-visitor-country-detection'),
        'https://www.maxmind.com/en/geolite2/signup'
    )) . '</p>';

    // Credentials form.
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('honeill_visitor_country_detection_save');
    echo '<input type="hidden" name="action" value="honeill_visitor_country_detection_save">';
    echo '<table class="form-table" role="presentation">';

    echo '<tr><th scope="row"><label for="honeill_visitor_country_detection_account_id">' . esc_html__('MaxMind account ID', 'honeill-visitor-country-detection') . '</label></th><td>';
    if ($id_by_const) {
        echo '<p><code>' . esc_html__('Set via the HONEILL_VISITOR_COUNTRY_DETECTION_ACCOUNT_ID constant in wp-config.php.', 'honeill-visitor-country-detection') . '</code></p>';
    } else {
        echo '<input name="honeill_visitor_country_detection_account_id" id="honeill_visitor_country_detection_account_id" type="text" inputmode="numeric" class="regular-text" autocomplete="off" value="' . esc_attr($account_id) . '">';
    }
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="honeill_visitor_country_detection_license_key">' . esc_html__('MaxMind licence key', 'honeill-visitor-country-detection') . '</label></th><td>';
    if ($by_const) {
        echo '<p><code>' . esc_html__('Set via the HONEILL_VISITOR_COUNTRY_DETECTION_LICENSE_KEY constant in wp-config.php.', 'honeill-visitor-country-detection') . '</code></p>';
    } else {
        $has_key = $key !== '';
        // Write-only: never render the saved key back to the browser; show a masked hint only.
        $placeholder = $has_key ? '••••••••' . substr($key, -4) : '';
        echo '<input name="honeill_visitor_country_detection_license_key" id="honeill_visitor_country_detection_license_key" type="password" class="regular-text" autocomplete="off" value="" placeholder="' . esc_attr($placeholder) . '">';
        if ($has_key) {
            echo '<p class="description">' . esc_html__('A key is saved. Leave blank to keep it, or paste a new key to replace it.', 'honeill-visitor-country-detection') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Saving a key downloads the database right away.', 'honeill-visitor-country-detection') . '</p>';
        }
    }
    echo '</td></tr></table>';
    if (!$by_const || !$id_by_const) {
        submit_button(__('Save & download database', 'honeill-visitor-country-detection'));
    }
    echo '</form>';

    // Manual refresh + remove buttons.
    if ($key !== '') {
        echo '<p class="submit" style="margin-top:-10px">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field('honeill_visitor_country_detection_update');
        echo '<input type="hidden" name="action" value="honeill_visitor_country_detection_update">';
        submit_button(__('Update database now', 'honeill-visitor-country-detection'), 'secondary', 'submit', false);
        echo '</form>';

        if (!$by_const) {
            echo ' <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
            wp_nonce_field('honeill_visitor_country_detection_remove');
            echo '<input type="hidden" name="action" value="honeill_visitor_country_detection_remove">';
            submit_button(__('Remove key', 'honeill-visitor-country-detection'), 'delete', 'submit', false);
            echo '</form>';
        }

        echo '</p>';
    }

    // Status table.
    echo '<h2>' . esc_html__('Status', 'honeill-visitor-country-detection') . '</h2>';
    echo '<table class="widefat striped" style="max-width:640px"><tbody>';

    honeill_visitor_country_detection_status_row(
        __('Account ID', 'honeill-visitor-country-detection'),
        $account_id !== '' ? __('Set', 'honeill-visitor-country-detection') : __('Not set (using the older download method)', 'honeill-visitor-country-detection')
    );
    honeill_visitor_country_detection_status_row(
        __('Licence key', 'honeill-visitor-country-detection'),
        $key !== '' ? __('Set', 'honeill-visitor-country-detection') : __('Not set', 'honeill-visitor-country-detection')
    );

    if ($has_db) {
        $size = size_format((int) filesize($db));
        honeill_visitor_country_detection_status_row(__('Database', 'honeill-visitor-country-detection'), esc_html__('Installed', 'honeill-visitor-country-detection') . ' (' . esc_html($size) . ')', true);
        $when = $updated ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $updated) : '';
        if ($when) {
            honeill_visitor_country_detection_status_row(__('Last updated', 'honeill-visitor-country-detection'), $when);
        }
    } else {
        honeill_visitor_country_detection_status_row(__('Database', 'honeill-visitor-country-detection'), __('Not downloaded yet', 'honeill-visitor-country-detection'));
    }

    if (is_array($last_error) && !empty($last_error['message'])) {
        honeill_visitor_country_detection_status_row(
            __('Last update failed', 'honeill-visitor-country-detection'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last_error['time']) . ' — ' . $last_error['message']
        );
    }

    honeill_visitor_country_detection_status_row(
        __('Next automatic update', 'honeill-visitor-country-detection'),
        $next ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next) : __('Not scheduled', 'honeill-visitor-country-detection')
    );
    honeill_visitor_country_detection_status_row(
        __('zlib extension', 'honeill-visitor-country-detection'),
        function_exists('gzdecode') ? __('Available', 'honeill-visitor-country-detection') : __('Missing (downloads will fail)', 'honeill-visitor-country-detection')
    );
    honeill_visitor_country_detection_status_row(
        __('Reader library', 'honeill-visitor-country-detection'),
        class_exists('MaxMind\\Db\\Reader') ? __('Loaded', 'honeill-visitor-country-detection') : __('Missing', 'honeill-visitor-country-detection')
    );

    echo '</tbody></table>';

    // Developer usage.
    echo '<h2>' . esc_html__('Use it in your code', 'honeill-visitor-country-detection') . '</h2>';
    echo '<p>' . esc_html__('Read the visitor country (ISO 3166-1 alpha-2, e.g. GB) anywhere in your theme or plugin:', 'honeill-visitor-country-detection') . '</p>';
    echo '<pre style="background:#fff;border:1px solid #dcdcde;padding:12px;max-width:640px;overflow:auto"><code>'
        . esc_html("\$country = apply_filters( 'honeill_visitor_country_detection_country_code', '' );\n\nif ( \$country === 'GB' ) {\n    // visitor is in the United Kingdom\n}")
        . '</code></pre>';

    echo '<p>' . esc_html__('Or fetch the caller\'s country over the REST API (handy for front-end JavaScript):', 'honeill-visitor-country-detection') . '</p>';
    echo '<pre style="background:#fff;border:1px solid #dcdcde;padding:12px;max-width:640px;overflow:auto"><code>'
        . esc_html('GET ' . rest_url('honeill-visitor-country-detection/v1/country') . "\n\u{2192} { \"country\": \"GB\" }")
        . '</code></pre>';

    echo '<h2>' . esc_html__('Usage statistics', 'honeill-visitor-country-detection') . '</h2>';
    Honeill_Visitor_Country_Detection_Telemetry::render_setting();

    echo '<p class="description">' . esc_html__('This product includes GeoLite2 Data created by MaxMind, available from https://www.maxmind.com.', 'honeill-visitor-country-detection') . '</p>';
    echo '</div>';
}

/** One status row. $value is escaped here unless $raw (caller already escaped HTML in it). */
function honeill_visitor_country_detection_status_row($label, $value, $raw = false) {
    echo '<tr><td style="width:200px"><strong>' . esc_html($label) . '</strong></td><td>' . ($raw ? wp_kses_post($value) : esc_html($value)) . '</td></tr>';
}
