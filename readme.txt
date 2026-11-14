=== Honeill Visitor Country Detection ===
Contributors: honeill
Tags: geolocation, country, ip address, geotargeting, visitor country
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visitor country from their IP via MaxMind GeoLite2 (or Cloudflare's header when available), exposed through a filter and a REST endpoint.

== Description ==

Honeill Visitor Country Detection detects a visitor's country (ISO 3166-1 alpha-2, e.g. `GB`) from their IP address using MaxMind's GeoLite2 database, and exposes it through a single WordPress filter so your theme or plugin can use it with no hard dependency.

The GeoLite2 database is **not** bundled — MaxMind's licence does not allow redistribution. Instead you enter your own free MaxMind account ID and licence key once on the settings screen. The plugin then:

* downloads the GeoLite2 country database immediately,
* stores it in `wp-content/uploads` (so it survives plugin updates),
* and refreshes it automatically every week via WP-Cron, checking every download before it replaces the current database.

You never download or upload a database by hand.

If your site is behind Cloudflare, the plugin uses Cloudflare's own country header for visitors who come through it — exact, and no lookup needed — and only falls back to the database otherwise.

= Use it in your code =

`$country = apply_filters( 'honeill_visitor_country_detection_country_code', '' );`

Returns a two-letter country code, or an empty string when it can't be determined (local IP, missing database, etc.) — your code decides the fallback. Results are cached per IP, so it's cheap to call on every request.

Prefer REST? `GET /wp-json/honeill-visitor-country-detection/v1/country` returns `{ "country": "GB" }` for the caller.

This product includes GeoLite2 Data created by MaxMind, available from https://www.maxmind.com. This plugin is an independent project and is not affiliated with, endorsed, or sponsored by MaxMind, Inc. GeoIP and GeoLite2 are trademarks of MaxMind, Inc.

== External services ==

This plugin connects to MaxMind to download the GeoLite2 country database, which it needs to resolve countries. The database is not shipped with the plugin because MaxMind's licence prohibits redistribution.

* **Service:** MaxMind GeoLite2 database download (`https://download.maxmind.com/geoip/databases/GeoLite2-Country/download`). MaxMind redirects the download to its own file storage, which is fetched without your credentials. If no account ID is set, MaxMind's older endpoint (`https://download.maxmind.com/app/geoip_download`) is used instead.
* **When it connects:** when you save your licence key, when you click "Update database now", and automatically once per week.
* **What is sent:** your MaxMind account ID and licence key (as HTTP Basic authentication, or as a request parameter on the older endpoint) and, by nature of any outbound HTTPS request, your server's IP address. No website visitor data is ever sent to MaxMind or anywhere else — every lookup happens on your own server.

By using this plugin you agree to MaxMind's GeoLite2 End User License Agreement and Privacy Policy:

* Terms: https://www.maxmind.com/en/geolite2/eula
* Privacy: https://www.maxmind.com/en/privacy_policy

Optional usage statistics:

* **Service:** Honeill plugin usage statistics (`https://honeill-plugin-telemetry.vercel.app/api/v1/report` and `/api/v1/forget`), run by the plugin's author.
* **Only if you opt in.** Honeill Visitor Country Detection sends nothing until an administrator clicks "Allow" on the one-time notice (or turns it on in the plugin settings). It is off by default and can be turned off at any time.
* **When it connects:** once shortly after you opt in, then once a week. When you opt out or delete the plugin, it asks for your site's data to be erased.
* **What is sent:** the plugin name and version, a random ID created for your site, your site address, WordPress/PHP/database versions, site language, whether it is a multisite, the number of users, published posts, published pages and media items, the web server software, the active theme's name and the PHP memory limit. No personal data or content is sent.
* **Privacy and terms:** https://honeill-plugin-telemetry.vercel.app/privacy

== Installation ==

1. Upload the `honeill-visitor-country-detection` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Create a free MaxMind account and generate a licence key (Account → Manage License Keys — your account ID is shown on the same page): https://www.maxmind.com/en/geolite2/signup
4. Go to **Settings → Honeill Visitor Country Detection**, enter your account ID and licence key, and click **Save & download database**.
5. That's it — the database downloads now and auto-updates weekly.

Advanced: instead of the settings fields you can define both in `wp-config.php` to keep them out of the database:

`define( 'HONEILL_VISITOR_COUNTRY_DETECTION_ACCOUNT_ID', '123456' );`
`define( 'HONEILL_VISITOR_COUNTRY_DETECTION_LICENSE_KEY', 'your-key' );`

== Frequently Asked Questions ==

= Is it allowed to use GeoLite2 this way? =

Yes. You use your own licence key to download your own copy of the database — nothing is redistributed, which is what MaxMind's licence restricts. Attribution to MaxMind is included as required.

= Where is the database stored? =

In a folder inside `wp-content/uploads/` named `honeill-visitor-country-detection-` plus a random suffix, in a file that also has a random name. It is kept out of the plugin folder so plugin updates don't wipe it. The random names mean the licensed file has no guessable web address on any server — even one that lists folder contents; on Apache an `.htaccess` file blocks direct access as well. Sites upgrading from an earlier version have their folder and file renamed automatically.

= Can it detect city or region, not just country? =

It ships with the lighter GeoLite2-Country database. City is a small change (the edition id and filename) but needs more disk space and exposes additional fields.

= My site is behind Cloudflare / a load balancer. Will it still work? =

Yes. The plugin reads the connection's real address and only trusts `X-Forwarded-For` entries added by proxies it can vouch for: your host's own private-network proxy and Cloudflare's published IP ranges. Visitors coming through Cloudflare get Cloudflare's own country header. If you use another CDN or load balancer with public IPs, add its ranges with the `honeill_visitor_country_detection_trusted_proxies` filter (an array of CIDR strings, e.g. `'203.0.113.0/24'`), or replace the detected IP entirely with the `honeill_visitor_country_detection_client_ip` filter.

= Can a visitor fake their country? =

Not by sending headers. `X-Forwarded-For` entries a visitor adds themselves are ignored, and Cloudflare's country header is only believed when the request really came from Cloudflare. A visitor can still change their apparent country with a VPN, as with any IP-based lookup — so use the country for content, never for access control.

= Downloads fail. Why? =

Most often an incorrect account ID or licence key (MaxMind returns 401/403), or the PHP `zlib` extension is missing (needed to unpack the download). The settings screen's status table shows both, along with the reason for the last failed update — including automatic weekly ones. A failed download never replaces a working database.

== Changelog ==

= 1.4.0 =
* New: optional, opt-in usage statistics (off by default). A one-time notice asks administrators whether to share basic, non-personal site information weekly; it can be changed under Settings → Honeill Visitor Country Detection. Opting out or deleting the plugin erases this site's data. See "External services". No visitor data is ever included.

= 1.3.0 =
* Security: the database is now stored in a folder and file with random names, so it has no guessable web address on servers that ignore `.htaccess` (nginx), even where folder listings are switched on. Existing installs are moved automatically.
* Security: the visitor IP is now resolved by trusting only known proxies (the host's private network and Cloudflare's published ranges). Previously the left-most `X-Forwarded-For` entry was used, which a visitor could set to anything.
* Security: downloads use MaxMind's current endpoint with HTTP Basic authentication when an account ID is set, so the licence key no longer travels in a URL. The redirect to MaxMind's storage is followed without credentials.
* Security: archives are unpacked with plain gzip and tar parsing instead of PharData, and every download is opened and checked to be a Country database before it replaces the current one.
* Security: the licence key is scrubbed from any error message before it is shown or stored.
* Security: download and unpacked sizes are capped, and each update writes to its own temporary file, so overlapping updates (cron and the button) can never swap in a half-written database.
* Security: a malformed entry in the trusted-proxies filter is ignored instead of matching more addresses than intended.
* New: visitors coming through Cloudflare get Cloudflare's own country header, with no database lookup.
* New: `honeill_visitor_country_detection_trusted_proxies` and `honeill_visitor_country_detection_client_ip` filters.
* New: the reason for the last failed update (including automatic weekly ones) is shown on the settings screen.
* New: account ID setting and `HONEILL_VISITOR_COUNTRY_DETECTION_ACCOUNT_ID` constant.
* Improved: settings no longer load on every page request; the database is opened once per request; memory is raised for unpacking during scheduled updates.
* Improved: a country code supplied by an earlier filter callback is now kept.
* Fixed: proxies that append a port to `X-Forwarded-For` entries (Azure App Service, IIS ARR) are understood.
* Fixed: addresses whose record only carries the country the network is registered to now resolve.
* Improved: visitor requests no longer create this month's uploads folder. Uninstall cleans up every site on a multisite network.
* Changed: the PHP Phar extension is no longer required; zlib is.

= 1.2.4 =
* Per-IP lookup results are now cached with the object cache API instead of transients. Spoofed X-Forwarded-For values could previously create unbounded per-IP transient rows in the options table.

= 1.2.3 =
* Renamed the plugin to "Honeill Visitor Country Detection" (slug `honeill-visitor-country-detection`). No functional changes; filter, REST namespace, options, and constant names now use the new prefix.

= 1.2.2 =
* Tidied the plugin description (now mentions the REST endpoint).

= 1.2.1 =
* "Remove key" is now a button instead of a checkbox. The settings screen also documents the REST endpoint.

= 1.2.0 =
* Licence key field is now write-only and masked — the saved key is never rendered back to the browser. Added a "Remove the saved key" option.
* REST endpoint now sends no-cache headers so a per-visitor result is never cached by a CDN or page cache.

= 1.1.0 =
* Added a REST endpoint: GET /wp-json/honeill-visitor-country-detection/v1/country returns the caller's country code.

= 1.0.0 =
* Initial release: country detection via the `honeill_visitor_country_detection_country_code` filter, licence-key-based download, weekly auto-update, and a settings screen.

== Upgrade Notice ==

= 1.4.0 =
Adds optional, opt-in usage statistics. Nothing is sent unless an administrator chooses to allow it.

= 1.3.0 =
Security release. Hardens visitor IP detection, hides the database behind a random folder name and switches to MaxMind's current download method. Add your MaxMind account ID on the settings screen after updating.
