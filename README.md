=== Local Image Premium Support ===
Contributors: localimage
Tags: hosting, support
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Premium hosting plugin for support and upgrades. Only at Local Image.

== Description ==

Premium hosting plugin for support and support desk integration. Only at Local Image.

== Changelog ==

= 1.0.14 =
* Show Title and Alt columns in the Media Library list view.

= 1.0.13 =
* Add an Image Alts option to replace existing alt text. It is off by default.

= 1.0.12 =
* Save generated image alts to the media library and detect more front-end images.
* Keep the last Image Alts scan on the page until you rescan.
* Add Plugins screen links for LI Tools settings and a manual update check.

= 1.0.11 =
* Fixed SmartCrawl focus keywords for Image Alts using correct meta keys and array formatting.
* Check for plugin updates from GitHub immediately on activation.

= 1.0.10 =
* Added Image Alts under Tools > LI Tools with alt builder, live preview, and render-time injection.
* Added content scanner for images on published pages and posts with configurable post types.
* Improved GitHub updater with optional private-repo token support and cron-friendly update checks.
* Fixed Plugin Check warnings by removing direct database queries from cache cleanup.

= 1.0.9 =
* Reorganized plugin file structure and moved GitHub updater into assets/inc.
* Fixed activation and deactivation hooks for search engine visibility checks.
* Hardened LI Tools llms.txt settings for Plugin Check security standards.

= 1.0.8 =
* Added virtual llms.txt management under Tools > LI Tools.
* Serve llms.txt dynamically with no physical file on disk.
* Branded LI Tools admin interface with tabbed settings.

= 1.0.7 =
* Maintenance release.
