<?php
/**
 * Opt-in usage statistics for Honeill Visitor Country Detection.
 *
 * Nothing is sent unless an administrator explicitly opts in. Once they do, the plugin sends a
 * small report once a week (site address, WordPress/PHP/database versions, language, multisite,
 * user/post/page/media counts, web server, theme name, memory limit). Opting out — or deleting
 * the plugin — asks the server to erase everything stored for this site.
 * What is sent and why: https://honeill-plugin-telemetry.vercel.app/privacy
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Honeill_Visitor_Country_Detection_Telemetry
{
    const ENDPOINT = 'https://honeill-plugin-telemetry.vercel.app/api/v1/';
    const PRIVACY_URL = 'https://honeill-plugin-telemetry.vercel.app/privacy';
    const SLUG = 'honeill-visitor-country-detection';
    const OPTION = 'honeill_visitor_country_detection_telemetry';        // '' = not asked yet, 'yes', 'no'
    const OPTION_ID = 'honeill_visitor_country_detection_install_id';    // random UUID, created on opt-in
    const CRON = 'honeill_visitor_country_detection_telemetry_send';
    const ACTION = 'honeill_visitor_country_detection_telemetry';

    /** @var string */
    private static $version = '';
    /** @var string Admin screen IDs where the one-time question may appear. */
    private static $screens = array();

    public static function boot(string $plugin_file, string $version, array $own_screens): void
    {
        self::$version = $version;
        self::$screens = array_merge(array('dashboard', 'plugins'), $own_screens);

        add_action(self::CRON, array(__CLASS__, 'send'));
        add_action('admin_init', array(__CLASS__, 'sync_schedule'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        register_deactivation_hook($plugin_file, array(__CLASS__, 'unschedule'));
    }

    public static function opted_in(): bool
    {
        return get_option(self::OPTION, '') === 'yes';
    }

    /** Keep the weekly event in step with the choice (also restores it after reactivation). */
    public static function sync_schedule(): void
    {
        $scheduled = wp_next_scheduled(self::CRON);
        if (self::opted_in() && !$scheduled) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', self::CRON);
        } elseif (!self::opted_in() && $scheduled) {
            self::unschedule();
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    /** The one-time question: only for administrators, only on a few screens, until answered or dismissed. */
    public static function notice(): void
    {
        if (get_option(self::OPTION, '') !== '' || !current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, self::$screens, true)) {
            return;
        }

        echo '<div class="notice notice-info"><p><strong>' . esc_html__('Help improve Honeill Visitor Country Detection?', 'honeill-visitor-country-detection') . '</strong> ';
        echo esc_html__('Allow it to send basic, non-personal site information to its author once a week: your site address, WordPress, PHP and database versions, language, theme, web server, memory limit, and how many users, posts, pages and media items the site has. No content or personal data is sent. You can turn this off at any time in the plugin settings, and your data is then deleted.', 'honeill-visitor-country-detection');
        echo ' <a href="' . esc_url(self::PRIVACY_URL) . '" target="_blank" rel="noopener">' . esc_html__('What is sent and why', 'honeill-visitor-country-detection') . '</a></p><p>';
        self::button('yes', __('Allow', 'honeill-visitor-country-detection'), 'button-primary');
        echo ' ';
        self::button('no', __('No thanks', 'honeill-visitor-country-detection'), 'button-secondary');
        echo '</p></div>';
    }

    /** The same choice on the plugin's own settings screen, so it can be changed later. */
    public static function render_setting(): void
    {
        echo '<p>';
        if (self::opted_in()) {
            echo esc_html__('Usage statistics: on. Basic, non-personal site information is sent once a week.', 'honeill-visitor-country-detection') . ' ';
            self::button('no', __('Turn off and delete my data', 'honeill-visitor-country-detection'), 'button-secondary');
        } else {
            echo esc_html__('Usage statistics: off. Nothing is sent.', 'honeill-visitor-country-detection') . ' ';
            self::button('yes', __('Turn on', 'honeill-visitor-country-detection'), 'button-secondary');
        }
        echo ' <a href="' . esc_url(self::PRIVACY_URL) . '" target="_blank" rel="noopener">' . esc_html__('What is sent and why', 'honeill-visitor-country-detection') . '</a></p>';
    }

    private static function button(string $choice, string $label, string $class): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="choice" value="' . esc_attr($choice) . '">';
        echo '<button type="submit" class="button ' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'honeill-visitor-country-detection'));
        }
        check_admin_referer(self::ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
        $choice = isset($_POST['choice']) && sanitize_key(wp_unslash($_POST['choice'])) === 'yes' ? 'yes' : 'no';

        if ($choice === 'yes') {
            if (!preg_match('/^[0-9a-f-]{36}$/', (string) get_option(self::OPTION_ID, ''))) {
                update_option(self::OPTION_ID, wp_generate_uuid4(), false);
            }
            update_option(self::OPTION, 'yes', false);
            self::sync_schedule();
            wp_schedule_single_event(time() + 30, self::CRON); // first report shortly, off this request
        } else {
            $was_in = self::opted_in();
            update_option(self::OPTION, 'no', false);
            self::unschedule();
            if ($was_in) {
                self::forget();
            }
            delete_option(self::OPTION_ID);
        }

        $back = wp_get_referer();
        wp_safe_redirect($back ? $back : admin_url());
        exit;
    }

    /** @return array<string,mixed> */
    public static function payload(): array
    {
        global $wpdb;

        $posts = wp_count_posts('post');
        $pages = wp_count_posts('page');
        $media = wp_count_posts('attachment');
        $users = function_exists('get_user_count') ? get_user_count() : count_users()['total_users'];
        $server = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';

        return array(
            'install_id'      => (string) get_option(self::OPTION_ID, ''),
            'plugin'          => self::SLUG,
            'plugin_version'  => self::$version,
            'site_url'        => home_url('/'),
            'wp_version'      => get_bloginfo('version'),
            'php_version'     => PHP_VERSION,
            'mysql_version'   => method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '',
            'locale'          => get_locale(),
            'multisite'       => is_multisite(),
            'users'           => (int) $users,
            'posts'           => isset($posts->publish) ? (int) $posts->publish : 0,
            'pages'           => isset($pages->publish) ? (int) $pages->publish : 0,
            'media'           => isset($media->inherit) ? (int) $media->inherit : 0,
            'server_software' => substr($server, 0, 128),
            'theme'           => (string) wp_get_theme()->get('Name'),
            'memory_limit'    => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : (string) ini_get('memory_limit'),
        );
    }

    /** Weekly (and once after opting in). Does nothing unless opted in. */
    public static function send(): void
    {
        if (!self::opted_in() || get_option(self::OPTION_ID, '') === '') {
            return;
        }
        wp_safe_remote_post(self::ENDPOINT . 'report', array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(self::payload()),
        ));
    }

    /** Ask the server to erase this site's data. Used on opt-out (uninstall.php does the same). */
    public static function forget(): void
    {
        $id = (string) get_option(self::OPTION_ID, '');
        if ($id === '') {
            return;
        }
        wp_safe_remote_post(self::ENDPOINT . 'forget', array(
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => array('Content-Type' => 'application/json'),
            'body'     => wp_json_encode(array('install_id' => $id, 'plugin' => self::SLUG)),
        ));
    }
}
