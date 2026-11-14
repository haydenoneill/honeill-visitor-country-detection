<?php
/**
 * Runs when the plugin is deleted. Removes its options, its scheduled update and the downloaded
 * database — on every site of a multisite network.
 * (Country lookups live in the object cache, admin notices are 1-minute transients — both expire on their own.)
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function honeill_visitor_country_detection_uninstall_site()
{
    $uploads = wp_upload_dir(null, false);
    $folders = array('honeill-visitor-country-detection');
    $folder = (string) get_option('honeill_visitor_country_detection_folder', '');

    // Only ever touch folders whose names this plugin itself creates.
    if (preg_match('/^honeill-visitor-country-detection-[a-z0-9]{20}$/', $folder)) {
        $folders[] = $folder;
    }

    foreach ($folders as $name) {
        $dir = trailingslashit($uploads['basedir']) . $name;
        if (!is_dir($dir)) {
            continue;
        }
        $files = array_merge(
            (array) glob($dir . '/GeoLite2-Country*.mmdb'),
            (array) glob($dir . '/GeoLite2-Country*.tmp'),
            array($dir . '/.htaccess', $dir . '/index.html')
        );
        foreach ($files as $file) {
            if (is_string($file) && is_file($file)) {
                wp_delete_file($file);
            }
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- folder may be non-empty/already gone; failure is harmless
    }

    // If this site opted in to usage statistics, ask for its data to be erased.
    $install_id = (string) get_option('honeill_visitor_country_detection_install_id', '');
    if ($install_id !== '' && get_option('honeill_visitor_country_detection_telemetry') === 'yes') {
        wp_safe_remote_post('https://honeill-plugin-telemetry.vercel.app/api/v1/forget', array(
            'timeout' => 5,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('install_id' => $install_id, 'plugin' => 'honeill-visitor-country-detection')),
        ));
    }

    foreach (array('license_key', 'account_id', 'last_updated', 'last_error', 'folder', 'file', 'version', 'telemetry', 'install_id') as $option) {
        delete_option('honeill_visitor_country_detection_' . $option);
    }

    wp_clear_scheduled_hook('honeill_visitor_country_detection_refresh');
    wp_clear_scheduled_hook('honeill_visitor_country_detection_telemetry_send');
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $honeill_visitor_country_detection_site_id) {
        switch_to_blog((int) $honeill_visitor_country_detection_site_id);
        honeill_visitor_country_detection_uninstall_site();
        restore_current_blog();
    }
} else {
    honeill_visitor_country_detection_uninstall_site();
}
