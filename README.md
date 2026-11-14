# Honeill Visitor Country Detection

Detect a visitor's country (ISO 3166-1 alpha-2, e.g. `GB`) from their IP address, using MaxMind GeoLite2 or Cloudflare's country header when available. The result is exposed through one filter and a REST endpoint.

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/honeill-visitor-country-detection?label=WordPress.org)](https://wordpress.org/plugins/honeill-visitor-country-detection/)
![Requires WordPress 5.6+](https://img.shields.io/badge/WordPress-5.6%2B-21759b)
![Requires PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License GPLv2+](https://img.shields.io/badge/license-GPLv2%2B-blue)

**Install it from WordPress.org:** <https://wordpress.org/plugins/honeill-visitor-country-detection/>

## Usage

```php
$country = apply_filters( 'honeill_visitor_country_detection_country_code', '' );

if ( $country === 'GB' ) {
    // visitor is in the United Kingdom
}
```

It returns a two-letter code, or `''` when the country can't be determined (local IP, no database yet, and so on). Your code decides the fallback. Results are cached per IP in the object cache.

REST:

```
GET /wp-json/honeill-visitor-country-detection/v1/country   →   { "country": "GB" }
```

The response is sent with no-cache headers, so a CDN or page cache never shares one visitor's result with another.

## Setup

1. Create a free MaxMind account and a licence key: <https://www.maxmind.com/en/geolite2/signup>
2. Go to **Settings → Honeill Visitor Country Detection**, enter your account ID and licence key, and click **Save & download database**.
3. The database downloads straight away and refreshes weekly through WP-Cron.

You can keep the credentials out of the database by defining them in `wp-config.php`:

```php
define( 'HONEILL_VISITOR_COUNTRY_DETECTION_ACCOUNT_ID', '123456' );
define( 'HONEILL_VISITOR_COUNTRY_DETECTION_LICENSE_KEY', 'your-key' );
```

The GeoLite2 database is **not** bundled, because MaxMind's licence doesn't allow redistribution. Each site downloads its own copy with its own key.

## Security model

- **Visitor IP:** `X-Forwarded-For` is read from the right, and only hops from trusted proxies are skipped: private/loopback addresses, Cloudflare's published ranges, and anything you add. Entries a visitor adds themselves are ignored. Cloudflare's `CF-IPCountry` is only believed when the request really came through Cloudflare.
  - Add your own CDN or load balancer: `honeill_visitor_country_detection_trusted_proxies` (array of CIDR strings)
  - Replace the IP entirely: `honeill_visitor_country_detection_client_ip`
- **Licensed file:** it is stored in `wp-content/uploads` under a random folder *and* file name, and blocked by `.htaccess` on Apache. It has no guessable URL, even on servers that list folder contents.
- **Downloads:** MaxMind's current endpoint is used with HTTP Basic auth, so the key is never put in a URL. The storage redirect is followed without credentials. Size and unpacked size are capped. The archive is parsed without Phar. Every download is opened and checked to be a Country database before it atomically replaces the current one.
- The licence key is write-only in the admin and scrubbed from every error message.

Use the country for content, never for access control: a VPN changes it.

## Usage statistics (opt-in)

The plugin can send basic, non-personal site information (site address, WordPress/PHP/database versions, language, theme, web server, memory limit, and user/post/page/media counts) to its author once a week, **only if an administrator opts in**. It's off by default, can be turned off at any time in the plugin settings, and opting out or deleting the plugin erases the site's data. Details: <https://honeill-plugin-telemetry.vercel.app/privacy>. The code is in `includes/telemetry.php`.

## Development

This repository mirrors the plugin as published on WordPress.org (`trunk` and tags in [plugins.svn.wordpress.org](https://plugins.svn.wordpress.org/honeill-visitor-country-detection/)). `vendor/` is committed on purpose, because the plugin ships the pure-PHP [maxmind-db/reader](https://github.com/maxmind/MaxMind-DB-Reader-php) library (Apache-2.0). To update it, run `composer update`.

```
honeill-visitor-country-detection.php   main plugin file
includes/telemetry.php                  opt-in usage statistics
uninstall.php                           removes options, schedule and database (every site on multisite)
vendor/                                 maxmind-db/reader + Composer autoloader
readme.txt                              WordPress.org readme (changelog lives here)
```

## Licence

GPLv2 or later. See [LICENSE](LICENSE). This product includes GeoLite2 Data created by MaxMind, available from <https://www.maxmind.com>. It is not affiliated with MaxMind, Inc.
