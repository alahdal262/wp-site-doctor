=== WP Site Doctor ===
Contributors: noorwebltd
Donate link: https://noorweb.uk/donate
Tags: site health, security, performance, database, plugin conflicts
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Comprehensive WordPress site health scanner, conflict resolver, and auto-repair engine. Diagnoses performance, security, caching, database, image, SEO, and plugin issues with one-click fixes.

== Description ==

WP Site Doctor is the most thorough WordPress health diagnostic tool available. It scans your site across 11 categories, calculates a weighted health score out of 100, identifies conflicts between plugins, and offers safe one-click auto-repairs with rollback support.

**11 Diagnostic Scanners:**

* **Security** — Core file integrity, SSL, debug mode exposure, XML-RPC, REST API user enumeration, security headers, file permissions
* **Performance** — Object cache, compression, autoload bloat, plugin count, PHP memory usage, HTTP/2 detection
* **Database** — Size analysis, post revisions, orphaned metadata, expired transients, spam comments, table engine checks
* **Cache** — Page cache detection, object cache, browser caching headers, CDN detection (Cloudflare, Sucuri, CloudFront, Fastly), transient bloat
* **Plugin Conflicts** — Detects duplicate plugins in the same category (caching, SEO, security, etc.) with intelligent recommendations for which to keep
* **Plugin X-Ray** — Deep analysis of every active plugin: hook count, asset loading, external HTTP calls, WP.org compatibility, abandonment detection, load impact scoring
* **File Permissions** — Critical file/directory permission checks, detection of suspicious PHP files in uploads
* **Cron Jobs** — Orphaned events from deactivated plugins, duplicates, missed events, configuration checks
* **SEO** — SEO plugin presence, search visibility, permalink structure, robots.txt, XML sitemap, meta descriptions
* **Images** — Missing alt text, unoptimized large images, lazy loading, broken file detection
* **Server Environment** — PHP/MySQL versions, required extensions, memory limits, upload limits

**Key Features:**

* Weighted health score (0-100) with animated circular gauge
* 15 safe auto-repair actions with restore points and rollback
* Plugin conflict detection with cross-category overlap awareness
* HTML email reports for admin and developer
* Scheduled daily/weekly scans with score-drop alerts
* Zero front-end impact — runs only in admin, only when triggered
* Multisite compatible with network-level capability checks
* No external CSS/JS frameworks — uses native WordPress admin styles
* Translation-ready with full i18n support

== Installation ==

1. Upload the `wp-site-doctor` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to **Site Doctor** in the admin sidebar.
4. Click **Run Full Scan** to perform your first health assessment.
5. Review the results and use the **Auto-Repair** page to fix issues with one click.

== Frequently Asked Questions ==

= Does this plugin slow down my site? =

No. WP Site Doctor has zero front-end impact. It loads no scripts or styles on the public-facing side of your site. Admin assets are loaded only on the plugin's own pages, not across the entire admin dashboard. Scans run only when you trigger them or via a scheduled cron event.

= Is it safe to use the auto-repair feature? =

Yes. Every repair action creates a restore point before making changes. Actions that cannot be reversed (like deleting post revisions) are clearly marked as "Irreversible" in the interface. You are always shown exactly what will change before confirming, and we strongly recommend taking a backup first.

= Can I rollback a repair action? =

Yes, for reversible actions. Visit the **Repair Log** page to see all past repairs. Completed actions with restore data show a "Rollback" button that reverses the change. Irreversible actions (bulk deletions) are clearly labeled and cannot be rolled back.

= Does the Plugin Conflict Scanner work with premium plugins? =

The conflict scanner uses folder slug matching against an internal taxonomy of known plugin categories. It works with any plugin — free or premium — as long as the folder slug is in its database. The taxonomy is filterable via `wpsd_plugin_categories` so developers can extend it. For WP.org-hosted plugins, it also fetches active install counts and update dates to make recommendations.

= Can I exclude specific scanners from running? =

Yes. Go to **Site Doctor > Settings > Exclusions** and check the scanners you want to skip. Excluded scanners will not run during manual or scheduled scans, and their weight is redistributed proportionally to the remaining scanners for accurate scoring.

= Does it work on WordPress Multisite? =

Yes. When activated on a multisite network, the plugin requires `manage_network` capability instead of `manage_options`. Scans are scoped to the individual site (using `$wpdb->prefix`), not the entire network. Each site on the network can be scanned independently.

= How do I generate the translation .pot file? =

Run `composer make-pot` from the plugin directory. This requires WP-CLI to be installed. The command executes `wp i18n make-pot . languages/wp-site-doctor.pot`.

== Screenshots ==

1. **Dashboard** — Main dashboard with animated health score gauge and scan summary statistics.
2. **Scan Results** — Categorized scan results with severity badges (Critical, Warning, Info, Pass) and one-click fix buttons.
3. **Plugin X-Ray** — Sortable table showing every active plugin with load impact, last update date, active installs, and compatibility status.
4. **Auto-Repair** — Repair confirmation page with checkboxes, irreversible action warnings, and progress tracking.
5. **Repair Log** — Full audit trail of all repair actions with status, timestamps, and inline rollback buttons.
6. **Reports** — Send diagnostic reports to admin or developer email, with scan history trend chart.

== Changelog ==

= 1.0.0 =
* Initial release
* 11 scanner modules: Security, Performance, Database, Cache, Plugin Conflicts, Plugin X-Ray, File Permissions, Cron, SEO, Images, Server Environment
* Weighted health score calculation (0-100) with animated gauge
* 15 auto-repair actions with restore points and rollback support
* Plugin conflict detection with cross-category overlap awareness
* HTML email reports (summary and developer)
* Scheduled daily/weekly scans via WP-Cron
* Health score drop alerts (threshold and 10+ point drops)
* SVG-based scan history trend chart
* Multisite support with network capability checks
* Full i18n support with text domain wp-site-doctor

= 1.0.1 =
* Fixed: SPL autoloader now correctly resolves Abstract_Scanner and Scanner_Interface classes
* Fixed: Image scanner wp_count_attachments() compatibility with PHP 8.3
* Fixed: Health gauge no longer stalls when requestAnimationFrame is throttled in background tabs
* Changed: Gauge initializes synchronously on page load for instant rendering

== Upgrade Notice ==

= 1.0.1 =
Bug fix release. Fixes autoloader issue that prevented scanners from running, image scanner PHP 8.3 compatibility, and health gauge animation reliability.

= 1.0.0 =
Initial release. Install and run your first scan to get a comprehensive health assessment of your WordPress site.
