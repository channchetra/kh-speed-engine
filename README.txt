=== KH Speed Engine ===

Contributors: chetrachann
Tags: cache, redis, mongodb, memcached, apc, apcu
Requires at least: 5.8
Tested up to: 6.9.4
Stable tag: 4.4.3
Requires PHP: 7.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl.html

== Description ==

KH Speed Engine is an extremely lightweight, high-performance cache plugin that speeds up your WordPress sites on the fly. The core of KH Speed Engine is driven by Shieldon Simple Cache, a PSR-16 simple cache library.

Core library:

- [terrylinooo/simple-cache](https://github.com/terrylinooo/simple-cache) (Simple Cache library)

First release date: October, 1, 2020

== Features ==

* Extremely lightweight and high-performance.
* Support up to 10 cache drivers such as File, Redis, Memcache, Memcached, APC, APCu, WinCache, MySQL, SQLite, and MongoDB.
* Provide detailed cache statistics, easy to manage.
* Vite-built React admin console with a self-hosted Google Sans design system, live status, a URL cache checker, requirement checks, and one-click actions.
* Optional Redis and File cache compression.
* Optional Nginx direct static cache for the File driver.
* Optional page optimization before cache storage: HTML minify, inline/external UCSS generation, JS analysis, lazy media, LCP image priority, Google Fonts preconnect, and guarded JavaScript defer.
* Admin bar purge controls for clearing all cache or only the current page.
* And more...

== Nginx Direct Static Cache ==

When using the File driver, KH Speed Engine can write a raw HTML mirror that Nginx may serve before PHP or WordPress runs. Enable it in the Performance workspace, review the requirement checks, copy the generated snippet into your Nginx server block, run nginx -t, then reload Nginx.

== Page Optimization ==

KH Speed Engine can optimize cacheable guest HTML before it is saved to File, Redis, Memcached, APCu, or another selected driver. Enable it in the Performance workspace. The pipeline includes HTML/comment cleanup, inline CSS minify, conservative PHP UCSS, hashed CSS assets for eligible same-site stylesheets, native lazy loading for media, first-image LCP priority/preload, Google Fonts preconnect, bounded PHP JS analysis, and optional safe JavaScript defer with exclusions.

External UCSS reads only same-site .css files under the WordPress root, skips SRI/crossorigin/alternate/preload/importing stylesheets, obeys the configured max file size, rewrites relative url() assets before publishing a hashed CSS file, and keeps the original link if the PHP engine produces no smaller output.

Large sites can preload up to 1000 URLs. KH Speed Engine queues preload work in small batches so dashboard actions do not have to wait for every URL in one request.

== Vite and Google Sans Admin Console ==

The KH Speed Engine admin console uses native WordPress admin screens, Vite-built assets that bundle React, and a self-hosted Google Sans variable font. The console is organized into five workspaces: Dashboard, Caching (cache, preload, exclusion rules), Optimization (performance, statistics), Tools (expert mode, benchmark), and About. It includes a cache eligibility URL checker, an on-demand Nginx snippet generator, and AJAX controls for refresh, preload, homepage purge, image queueing, and full cache clear.

Build assets with:

`bun install`
`bun run build`

Build a release zip with:

`bun run build:release`

One-command version bump and release:

`bun run release`
`bun run release:minor`
`powershell -ExecutionPolicy Bypass -File .\bin\release.ps1 -Version 3.1.0 -Note "Release dashboard polish."`

`bin/release.ps1` defaults to a patch bump. It syncs `kh-speed-engine.php`, `KHSE_PLUGIN_VERSION`, `README.txt` stable tag, `package.json`, and `CHANGELOG.md`; the About page reads the version from the localized payload, then calls `bin/build-release.ps1`. The release script runs Bun install/build and verifies the Vite manifest before packaging.

The Performance view records recent cache writes and shows what actually happened on each page: bytes before/after, total bytes saved, Local UCSS and External UCSS bytes removed, JS Analysis deferred/analyzed counts, feature-level states such as Applied, No change, Disabled, or Failed. Older reports created before the local engines existed may still show Pending engine.

All settings forms save in place through authenticated WordPress AJAX requests with nonce and capability checks. The React console writes the same WordPress options used by the cache runtime, so existing option update hooks still run.

Built assets are required for the React console. The release script verifies the Vite manifest before packaging.

== Local UCSS, External UCSS, and JS Analysis ==

KH Speed Engine uses built-in PHP engines for Local UCSS, External UCSS, and JS Analysis. Production does not require Bun, Node, PurgeCSS, Composer, or shell_exec. The legacy executable fields remain only for saved-setting compatibility. Bun and frontend packages are build-machine dependencies; run `bun install --frozen-lockfile` and `bun run build` before packaging. Optimization workspaces and runtime config use a private temp directory outside public uploads when available.

Use the UCSS safelist for dynamic classes JavaScript adds after load. Local UCSS targets inline page CSS. External UCSS targets eligible same-site stylesheet files and inlines optimized output into cached guest HTML. If one CSS block or stylesheet is malformed, KH Speed Engine keeps that block or stylesheet raw and still optimizes valid items.

Notice:

Before you install and use this plugin, please read the following notices carefully:

- Logged-in users will not trigger the caching process.
- A debug message will be appended to the end of the page's source code: `<!-- This page is cached by the KH Speed Engine plugin. //-->`. This is intended for debugging purposes only, confirming that the page is being cached. This message can be disabled in the Settings page.

If you encounter issues with your website when using KH Speed Engine, list all plugins installed on your website when reporting the problem.

== Installation ==

- Upload the plugin files to the /wp-content/plugins/kh-speed-engine directory, or install the plugin directly through the WordPress plugins screen.
- Activate the plugin via the 'Plugins' screen in WordPress.
- Navigate to the KH Speed Engine menu in the Plugins section and configure your options.

== Frequently Asked Questions ==

= Should I disable other cache plugins? =

KH Speed Engine caches entire webpages into static HTML files. Therefore, it is recommended to disable other similar cache plugins that perform the same function. However, it can function alongside object cache plugins without issue.

= How can I determine whether the caching is working or not? =

Be aware that logged-in users will not trigger the caching process. To verify caching, use incognito mode or a different browser to revisit the same webpage, and then you can check the cache status in one of the following ways:

- View the source code of the webpage and check the debug message as shown in screenshot (L).
- Enable the benchmark information in the footer section as shown in screenshot (K), where you can see the cache status.

If there is no debug message in the source code, or if the cache status consistently displays 'No', there might be a plugin conflict with KH Speed Engine that prevents it from working correctly.

== Screenshots ==

1. Setting page - Basic.
2. Setting page - Basic (Bottom).
3. Setting page - Advanced.
4. Setting page - Perferences.
5. Setting page - Benchmark.
6. Setting page - Exclusion.
7. Setting page - Expert mode.
8. Setting page - Cache statistics.
9. Setting page - About author.
10. Front page - Benchmark (footer text)
11. Debug message (Normal mode)
12. Debug message (Expert mode)

== Translation ==

Japanese (ja_JP) by [Colocal](https://colocal.com).

== Changelog ==

= 4.4.3 (11/10/2026) =

* Admin page loads process a scheduled run inline when the background runner is unreachable.
* Front-end requests process a small fallback batch inline.
* “Run preload” processes a full scheduled run inline.
* Add runner diagnostics and a no-progress warning.

= 4.4.2 (11/10/2026) =

* Process the full URLs-per-run size (75) per run instead of stopping at the internal 25-row batch.
* Continue immediately while work remains instead of waiting for the fallback interval.
* Add an internal background runner that processes runs without delaying page requests.
* Add a “Process on page requests” setting, off by default.
* Show “Ready” when the next run is due.
* Preserve an in-progress scheduled queue across patch upgrades.

= 4.4.1 (11/10/2026) =

* Fix the scheduled runner stalling when the next-run timestamp is zero.
* Scheduled mode replaces a stale quick-preload queue with the full-site queue.
* Clear legacy quick-preload cron events in scheduled mode.
* Warn when the active queue is a quick preload.

= 4.4.0 (11/10/2026) =

* Continuous scheduled preload warms 50 URLs per tick until Max cache entries or every published URL is covered.
* Priority-first queue ordering for the homepage, homepage links, edited pages, and media changes.
* Internal request-driven runner and WP-Cron backup; no WP-CLI dependency.
* Keep-warm audit every 6 hours re-queues only missing or expired pages.
* Scheduled preload card with target, progress, next run, pause/resume, cancel, and rebuild.
* Cache-limit and TTL warnings before a long cycle.
* Settings saves, clear-all, and plugin/theme changes rebuild the scheduled queue.

= 4.3.2 (11/10/2026) =

* Preload now fills the configured URL limit with unique pages instead of stopping when homepage links overlap the latest posts.
* Scope copy explains that the per-run limit does not warm every published post.

= 4.3.1 (11/10/2026) =

* Keep the last preload run visible after the queue finishes.
* Show Completed, Running, Paused, or Idle queue state.
* Keep successful pages visible after the queue drains when debug mode is on.
* Show the last-run page count, failures, finish time, and transport mode.
* Show transport mode in the preloaded pages list.

= 4.3.0 (11/10/2026) =

* Clearing cache on a private/container host now builds and starts preload immediately.
* Dashboard and System & Debug requests drain pending private-host queues inline.
* Background queue shows successful preload pages when debug mode is on.
* Show up to 50 current jobs with a “showing X of Y” note.

= 4.2.2 (11/9/2026) =

* Preload 404s and other permanent client errors now fail once instead of retrying three times.
* 5xx, 408, and 429 responses remain retryable.
* Failure details now distinguish permanent client errors from temporary server errors.

= 4.2.1 (11/9/2026) =

* Download system and debug diagnostics as JSON.
* Expand Background queue rows for last error, HTTP status, transport, and attempt history.
* Hide attempt history when debug mode is off.
* Fix Scope chips stretching into circles when post types wrap.

= 4.2.0 (11/9/2026) =

* Add Tools → System & debug with system configuration, proxy detection, queue health, and preload attempt logs.
* Show the last error, HTTP status, transport mode, and attempt time on preload queue rows.
* Add debug disable/clear and production cleanup controls.

= 4.1.1 (11/7/2026) =

* Process the background queue from admin and AJAX shutdown even when the legacy remaining counter is stale.
* Heal stale queue counters when the dashboard reads queue status.
* Add WP-CLI queue controls for Docker and container hosts.

= 4.1.0 (11/7/2026) =

* Move the preload queue to a durable per-URL table with retries and crash recovery.
* Add the Background queue panel with pause, resume, retry, and cancel controls.
* Preserve homepage priority ordering and the existing preload transport/TLS filters.
* Import existing transient queues automatically and keep the transient as a fallback.

= 4.0.0 (11/5/2026) =

* Rebrand the plugin as KH Speed Engine (KHSE) with the admin menu label "KH Speed".
* Rebuild the admin console as five workspaces: Dashboard, Caching, Optimization, Tools, and About.
* Add a status-first dashboard, contextual save bar, cache eligibility URL checker, and Nginx snippet generator.
* Self-host the Google Sans variable font for the admin UI.
* Remove the WooCommerce integration.

= 3.0.1 (5/23/2026) =

* Fix automatic upload image optimization on AJAX and metadata-update upload paths.
* Register upload/offload hooks during AJAX bootstrap without loading the full frontend cache runtime.
* Add spacing below the global Save Changes bar so the last card and WordPress footer no longer touch.

= 3.0.0 (5/23/2026) =

* Add upload-only single image optimization retry queue so new uploads can convert automatically without touching the manual batch queue.
* Hand deferred upload retries back to Advanced Media Offloader and keep normal wp_update_attachment_metadata flow for WP Offload Media.
* Add cache driver connection tests plus restored MongoDB user, password, database, and collection settings.
* Move Performance reports back into the Overview tab with summary cards.
* Improve dashboard alignment, stretched panels, footer spacing, Expert Mode 30/70 layout, and click-to-copy configuration code.

= 2.8.0 (5/21/2026) =

* Add External UCSS Generation for same-site local stylesheet files with max-size guardrails, PurgeCSS safelist support, and fail-open behavior.
* Add Performance dashboard cards for External UCSS savings and image optimizer queue/status.
* Restrict image writes to uploads, preserve originals, skip unsupported types, and keep remote/offloaded URL rewriting disabled unless explicitly enabled.

= 2.7.0 (5/15/2026) =

* Add real Local UCSS Generation for inline page CSS using PurgeCSS with configurable safelist support.
* Add real JS Analysis engine using local Bun to defer only readable same-site scripts classified safe.
* Add writable optimizer workspace checks and runtime report states for applied, no change, or failed engine jobs.

= 2.6.6 (5/15/2026) =

* Show optimization report sizes with human-readable units instead of raw bytes.
* Decode displayed report URLs for easier reading.
* Humanize old stored report byte strings at render time so existing reports improve without waiting for recache.

= 2.6.5 (5/15/2026) =

* Load only five recent page optimization reports by default and add click-to-load pagination in five-row batches.
* Clarify that Local UCSS Generation and JS Analysis are still requirement checks until their engines write changed cached HTML.
* Make guest visibility rows explicit read-only badges, improve Statistics setting layout, and normalize switch labels to `Enable`, `Disable`, `Yes`, and `No`.

= 2.6.4 (5/15/2026) =

* Rebuild checkbox options into consistent switch controls for Preload, Performance, and other settings tabs.
* Improve Advanced driver connection choices, including readable disabled TCP / Unix Socket controls.
* Replace legacy Statistics layout with a searchable live table and per-row clear-cache actions.
* Broaden settings form enhancement so Rules, Statistics, Expert Mode, and Benchmark forms receive modern controls after tab changes.

= 2.6.3 (5/15/2026) =

* Fix AJAX settings saves by using the form action attribute instead of the shadowed `action` form field.
* Replace binary Enable/Disable and Yes/No radio groups with switch-style controls.
* Refresh settings and dashboard tables with cleaner table styling and remove the leftover settings-row fill and separators.

= 2.6.2 (5/15/2026) =

* Rebuild radio groups into clean segmented controls instead of styling malformed legacy labels in place.
* Fix segmented-control layout so help text, inputs, and TTL examples no longer collapse into one horizontal row.
* Tighten settings padding, align advanced driver fields, improve TTL example layout, and wrap long requirement output cleanly.

= 2.6.1 (5/15/2026) =

* Make all console settings forms save through AJAX without reloading the admin page.
* Redesign settings forms with modern section cards, segmented radio groups, refined inputs, compact status feedback, and consistent console styling.
* Keep WordPress Settings API forms under the hood for nonce handling, capability checks, and existing option hooks.

= 2.6.0 (5/15/2026) =

* Rebuild KH Speed Engine admin as one Vue console with side navigation across cache, preload, performance, advanced, rules, statistics, expert, benchmark, and about views.
* Add recent page optimization reports with bytes before/after and feature-level Applied, No change, Disabled, or Pending engine states.
* Restyle the admin with compact design tokens, modern surfaces, status pills, and report tables inspired by current Vue dashboard patterns.
* Keep native WordPress Settings API forms underneath the Vue shell so existing settings continue to save normally.

= 2.5.1 (5/15/2026) =

* Change dashboard preload actions to queue batched background work instead of blocking the whole AJAX request.
* Add homepage priority fallback from enabled post types when homepage crawling cannot find enough internal links.
* Show preload queue total, processed, and remaining counts on the dashboard.
* Allow preload limits up to 1000 URLs while dispatching in smaller batches.

= 2.5.0 (5/15/2026) =

* Add NPM-less Vue 3 dashboard loaded from CDN inside native WordPress admin screens.
* Add live status cards for cache health, optimization, server requirements, and preload progress.
* Add dashboard AJAX actions for refresh, run preload, purge homepage, and clear all cache.
* Refresh admin styling for a compact interactive cache console.

= 2.4.3 (5/14/2026) =

* Hide Clear Cache from the frontend admin bar; keep only Purge Current Page there.

= 2.4.2 (5/14/2026) =

* Load KH Speed Engine admin bar controls on frontend for logged-in users.
* Make homepage-discovered preload URLs run first with blocking requests before the rest of the preload queue.
* Warm homepage-discovered URLs immediately after cache clear and preload setting changes.

= 2.4.1 (5/14/2026) =

* Make critical homepage preload blocking after cache clear so Homepage stats repopulate immediately.
* Add Purge Current Page admin bar action for Administrators and Editors.
* Improve Bun and PurgeCSS checks by using common web-user PATH locations and failing command-not-found output correctly.

= 2.4.0 (5/14/2026) =

* Enforce guest-only cache serving in Normal Mode and Expert Mode.
* Add stricter cacheable document checks to avoid caching query URLs, static assets, and logged-in requests.
* Normalize cache keys and de-duplicate stats rows for the same URI.
* Add homepage-first preload crawling for same-site homepage links.
* Add Local UCSS Generation and JS Analysis options with Bun, PurgeCSS, and shell_exec requirement checks.
* Add scoped homepage/archive purge when posts are published or deleted.

= 2.3.0 (5/14/2026) =

* Add page optimization settings before cache storage.
* Add HTML/comment cleanup, inline CSS minify, lazy media, first-image LCP priority/preload, Google Fonts preconnect, and guarded JavaScript defer.
* Clear and warm cache when page optimization settings change.

= 1.0.0 (10/01/2020) =

* First release on WordPress plugin directory.

= 1.2.0 (10/06/2020) =

* Add "Expert Mode".

= 1.2.1 (10/07/2020) =

* Improve debug message.
* New setting option for Expert Mode.
* Add warning message if a user use a plugin having conflicts with KH Speed Engine.

= 1.2.2 (10/07/2020) =

* Improve debug message - Add SQL query numbers.

= 1.3.0 (10/08/2020)

* Add setting option - Visibility of cache for logged-in users.
* Add setting option - Archive pages: category, tag, date and author.
* Add setting option - Homepage.
* Fix some small issues.

= 1.4.0 (10/09/2020) =

* Add setting page - Cache statistics.
* Improve code - Prevent conflicts with others plugins.

= 1.4.1 (10/16/2020) =

* Add feature - Automatic installation of Expert Mode code. (Removed in later versions)

= 1.5.1 (10/17/2020) =

* Add setting page - Benchmark settings.
* Add feature - Benchmark information in widget or footer area.
* Fix some small issues.

= 1.5.2 (10/17/2020) =

* Add a Clear Cache button on admin bar.

= 2.0.0 (10/27/2020) =

* Add setting pages - Exclusion,  Advanced settings.
* Add an option - HTML debug comment.
* Improve cache statistic page.
* Update translation strings for zh_TW, zh_CN.
* Fix issues.

= 2.0.1 (10/27/2020) =

* Fix SQLite driver error after performing a new installation.

= 2.0.3 (10/31/2020) =

* Support to WP-CLI

= 2.1.0 (11/15/2020) =

* Add options - Now, you can use unix socket or TCP in Redis, MongoDB and Memcaced Drivers.
* Update core library to 1.3.1
* Add unit tests and run the tests before releasing new updates.
* Fix issues.

= 2.1.1 (10/31/2021) =

* Fix type hint.
* Driver will fall back to File driver if current driver is unavailable.
* Test up to PHP 8.0
* Test up to WordPress 5.8.1

= 2.1.2 (8/28/2022) =

* Fix a type hint issue that occurs a PHP 8 fatal error.
* Test up to PHP 8.0
* Test up to WordPress 6.0.1
* Fix coding style to fit the WordPress coding standard.

= 2.1.3 (5/29/2023) =

* Test up to PHP 8.2.5
* Test up to WordPress 6.2.2
* Add Japanese translation.

= 2.2.0 (5/13/2026) =

* Add an option to disable cache expiring mechanism.
* Add Redis database selector for DB0 through DB15.
* Add cache key prefix option for shared cache stores.
* Add cache preload option to warm selected pages after cache clears.
* Add custom post type and custom post type archive cache support.
* Improve Expert Mode snippet and runtime config sync.
* Fix advanced driver field rendering and selected driver status.
* Fix single excluded URL entry persistence.
