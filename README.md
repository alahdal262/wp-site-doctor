# WP Site Doctor

**Comprehensive WordPress site health scanner, conflict resolver, and auto-repair engine.**

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.1-orange.svg)]()

WP Site Doctor scans your WordPress site across **11 categories**, calculates a weighted **health score out of 100**, identifies conflicts between plugins, and offers safe **one-click auto-repairs** with rollback support.

---

## Screenshots

### Dashboard & Health Score
The main dashboard shows an animated circular gauge with your composite health score (0-100), color-coded by grade. Summary stats show total issues, critical count, warnings, and info/pass items at a glance.

![Dashboard](assets/screenshots/screenshot-dashboard.png)

### Scan Results by Category
Results are organized into 11 tabbed categories with severity badges (Critical, Warning, Info, Pass). Each issue includes a description, recommendation, and a "Fix Now" button for auto-repairable items.

![Scan Results](assets/screenshots/screenshot-scan-results.png)

### Plugin X-Ray
A sortable table analyzing every active plugin: load impact rating, hook count, asset count, external HTTP calls, WP.org compatibility, and abandonment detection.

![Plugin X-Ray](assets/screenshots/screenshot-plugin-xray.png)

### Auto-Repair
Select repairs from a checklist with clear descriptions. Irreversible actions are labeled. A confirmation checkbox and progress bar ensure safe execution.

![Auto-Repair](assets/screenshots/screenshot-auto-repair.png)

### Repair Log with Rollback
Full audit trail of all repair actions with timestamps, user attribution, status badges, and inline rollback buttons for reversible actions.

![Repair Log](assets/screenshots/screenshot-repair-log.png)

### Reports & Scan History
Send HTML email reports to admin or developer. Scan history table tracks score trends over time.

![Reports](assets/screenshots/screenshot-reports.png)

---

## Features

### 11 Diagnostic Scanners

| Scanner | What It Checks |
|---------|---------------|
| **Security** | Core file integrity, SSL, debug mode, XML-RPC, REST API user enumeration, security headers, wp-config permissions |
| **Performance** | Object cache, Gzip/Brotli compression, autoload bloat, plugin count, PHP memory usage, HTTP/2 |
| **Database** | Size analysis, post revisions, orphaned metadata, expired transients, spam comments, table engines |
| **Cache** | Page cache detection, object cache, browser caching headers, CDN detection (Cloudflare, Sucuri, CloudFront, Fastly) |
| **Plugin Conflicts** | Duplicate plugins in same category (caching, SEO, security, etc.) with intelligent recommendations |
| **Plugin X-Ray** | Per-plugin analysis: hooks, assets, HTTP calls, WP.org data, abandonment detection, load impact scoring |
| **File Permissions** | Critical file/directory permissions, PHP files in uploads directory (hack indicator) |
| **Cron Jobs** | Orphaned events, duplicates, missed events, configuration checks |
| **SEO** | SEO plugin presence, search visibility, permalinks, robots.txt, XML sitemap, meta descriptions |
| **Images** | Missing alt text, unoptimized files >500KB, lazy loading, broken file detection |
| **Server Environment** | PHP/MySQL versions, required extensions, memory limits, upload limits |

### Health Score

Weighted composite score (0-100) with color-coded gauge:

- **90-100** Green "Excellent"
- **70-89** Blue "Good"
- **50-69** Orange "Needs Attention"
- **0-49** Red "Critical"

Scanner weights: Security 20%, Performance 15%, Database 12%, Plugin Conflicts 12%, Server Environment 10%, File Permissions 8%, Cache 7%, Images 5%, SEO 5%, Cron 3%, Plugin X-Ray 3%.

### 15 Auto-Repair Actions

All with restore points and rollback support:

- Delete expired transients
- Delete post revisions (keeps last 5 per post)
- Delete auto-drafts, trash, spam comments
- Delete orphaned postmeta / commentmeta
- Convert MyISAM tables to InnoDB
- Delete orphaned cron events
- Disable XML-RPC (via mu-plugin)
- Block REST API user enumeration
- Add security headers to .htaccess
- Fix wp-config.php / .htaccess permissions
- Disable dashboard file editing
- Disable debug display
- Auto-fill missing image alt text from filenames

### Reports & Alerts

- **Summary Report** HTML email with health score, issue breakdown by category
- **Developer Report** Includes server environment, active plugins list, debug.log excerpt
- **Scheduled Scans** Daily or weekly via WP-Cron
- **Score Drop Alerts** Email notification when score drops below threshold or by 10+ points

---

## Installation

1. Download the latest release ZIP from [Releases](../../releases)
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Upload the ZIP file and activate
4. Navigate to **Site Doctor** in the admin sidebar
5. Click **Run Full Scan**

Or via WP-CLI:

```bash
wp plugin install wp-site-doctor.zip --activate
```

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later (8.1+ recommended)
- MySQL 5.7+ or MariaDB 10.3+

## Architecture

```
wp-site-doctor/
├── wp-site-doctor.php          # Bootstrap, autoloader, activation hooks
├── uninstall.php               # Clean removal
├── readme.txt                  # WP.org readme
├── composer.json               # Dev tooling (WPCS, make-pot)
├── includes/                   # Core classes (13 files)
│   ├── interface-scanner.php   # Scanner contract
│   ├── abstract-scanner.php    # Base scanner with utilities
│   ├── class-scanner-engine.php
│   ├── class-admin-menu.php
│   ├── class-ajax-handler.php
│   ├── class-auto-repair.php
│   ├── class-cron-manager.php
│   ├── class-database.php
│   ├── class-health-score.php
│   ├── class-plugin-loader.php
│   ├── class-repair-logger.php
│   ├── class-report-generator.php
│   ├── class-restore-point.php
│   └── class-settings.php
├── scanners/                   # 11 scanner modules
├── templates/                  # Admin page templates (6 files)
├── assets/
│   ├── css/                    # Admin styles (3 files)
│   ├── js/                     # Dashboard JS (5 files)
│   └── images/                 # Logo SVG
└── languages/                  # i18n (.pot via composer make-pot)
```

**44 files total.** Zero external dependencies at runtime.

## Technical Highlights

- **Zero front-end impact** — no scripts, styles, or hooks on the public site
- **AJAX-driven scanning** — each scanner runs as a separate request to prevent timeouts
- **Transient locks** — prevents concurrent scans and repairs
- **Per-action restore points** — rollback any reversible repair
- **24-hour API cache** — Plugin X-Ray caches WP.org responses in transients
- **100-file cap** — X-Ray scans at most 100 PHP files per plugin
- **Multisite aware** — uses `manage_network` capability when network-activated
- **WPCS compliant** — nonces on all endpoints, all output escaped, all queries prepared
- **Translation-ready** — all strings use `__()` with `wp-site-doctor` text domain

## Development

```bash
# Install dev dependencies
composer install

# Run PHPCS
composer phpcs

# Generate translation file
composer make-pot
```

## Database

Creates 3 custom tables on activation (removed on uninstall):

| Table | Purpose |
|-------|---------|
| `{prefix}wpsd_scan_results` | Per-scanner results with JSON issues |
| `{prefix}wpsd_scan_history` | Aggregate health scores over time |
| `{prefix}wpsd_repair_log` | Repair actions with restore data |

## Changelog

### 1.0.1
- Fixed: SPL autoloader now correctly resolves `Abstract_Scanner` and `Scanner_Interface` classes
- Fixed: Image scanner `wp_count_attachments()` property access for PHP 8.3 compatibility
- Fixed: Health gauge no longer stalls when `requestAnimationFrame` is throttled in background tabs
- Changed: Gauge initializes synchronously on page load (animation only on scan completion)

### 1.0.0
- Initial release with 11 scanners, 15 auto-repair actions, weighted health scoring, HTML email reports, scheduled scans, and rollback support

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

**[Noor Web Limited](https://noorweb.uk)**
