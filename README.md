# KH Speed Engine - WordPress Cache Plugin

KH Speed Engine is an extremely lightweight, high-performance cache plugin that speeds up WordPress sites on the fly. The core cache is driven by Shieldon [Simple Cache](https://github.com/terrylinooo/simple-cache), a PSR-16 simple cache library.

## Requirements

- PHP 8.1 or later
- WordPress 6.2 or later
- Tested up to WordPress 7.1.4
- Tested up to PHP 8.3

The cache and preload runtime works on older PHP/WordPress versions, but the CSS and JavaScript optimizers need PHP 8.1+ and WordPress 6.2+.

## Features

- Extremely lightweight and high-performance page cache.
- Up to 10 cache drivers: File, Redis, Memcache, Memcached, APC, APCu, WinCache, MySQL, SQLite, and MongoDB.
- Guest-only page caching with configurable TTL and max-entry limits.
- Redis and File cache compression.
- Nginx direct static cache for the File driver.
- Continuous scheduled preload with priority-first warming.
- Server-side unused CSS removal with file, inline, and hybrid delivery.
- Dependency-aware JavaScript deferral, selected third-party delay, and local JavaScript minification.
- HTML minification, comment cleanup, lazy media, LCP image priority, and Google Fonts preconnect.
- Vite-built React admin console with a self-hosted Google Sans design system.
- Cache eligibility URL checker, requirement checks, and one-click actions.
- Admin bar purge controls for the whole cache or the current page.
- Tools → System & debug with debug mode, system configuration, proxy/topology detection, queue health, runner diagnostics, and bounded preload logs.
- Background queue with pause, retry, cancel, and per-attempt details.

## Cache

Cacheable guest HTML is stored in the selected PSR-16 driver and served before WordPress renders the page again. Logged-in users are not cached by default.

The File driver can also publish a raw HTML mirror for Nginx. Enable it in the Caching workspace, copy the generated snippet into the Nginx `server` block, run `nginx -t`, then reload Nginx.

## Scheduled preload

Preload builds a priority-first queue from the homepage, homepage-discovered links, enabled post types, and selected archives.

- Each scheduled run processes the configured number of URLs, for example 50.
- Work continues while the queue has pages, until the cache reaches **Max cache entries** or every published URL is covered when the limit is `0`.
- The internal runner advances on site/admin requests, with a single-event WP-Cron backup.
- Admin page loads and the **Run preload** button process a full run inline when needed.
- A keep-warm audit every six hours re-queues only missing or expired pages.
- Post, page, and media changes purge the homepage, the changed URL, and related archives, then re-warm them as priority.
- Clear-all, settings saves, and plugin/theme changes rebuild the scheduled queue.

No WP-CLI or external cron service is required. If the host blocks loopback admin-ajax requests, the inline page-load fallback keeps preload moving.

## Page optimization

Enable optimization in the Optimization workspace. Cacheable guest HTML is transformed before it is written to the cache.

### Unused CSS

- Server-side selector pruning adapted from GT Performance.
- Generated-file, inline, and hybrid delivery.
- Selector safelist and excluded stylesheets.
- Hover, focus, and other dynamic states preserved.
- Staged rollout: 0/10/25/50/100%.
- 3-second per-page budget; original CSS is returned if parsing or pruning fails.

Generated CSS is written under:

```text
wp-content/uploads/kh-speed-engine/<site>/optimized_css/
```

### JavaScript

- Dependency-aware deferral using the WordPress script registry.
- Selected third-party scripts delayed until interaction or five seconds.
- Local JavaScript minified through a signed endpoint.
- Cart, checkout, and payment scripts are protected automatically.
- Script exclusions and delay patterns are configurable.

The optimizer code is adapted from **GT Performance 1.3.6** (GPL-2.0-or-later). The bundled CSS parser, selector library, and JavaScript minifier are MIT-licensed. See `inc/lib/NOTICE.md`.

## Admin console

The console uses native WordPress admin pages plus Vite-built React assets and a self-hosted Google Sans variable font. It is organized into five workspaces:

- Dashboard
- Caching: Cache, Preload, Exclusion rules
- Optimization: Performance, Statistics
- Tools: Expert mode, Benchmark, System & debug
- About

Settings save in place through authenticated WordPress AJAX requests with nonce and capability checks. The React console writes the same WordPress options used by the cache runtime.

## Architecture

| Module | Purpose |
|--------|---------|
| `cache/cache-core.php` | Stable cache include aggregator |
| `cache/cache-identity.php` | Site directory hash and blog identity |
| `cache/cache-settings.php` | Canonical option schema and typed accessors |
| `cache/cache-storage.php` | Upload/runtime paths and config persistence |
| `cache/cache-stats.php` | Cache statistics index and type catalog |
| `cache/cache-policy.php` | URI normalization, cacheability, keys, read path |
| `cache/cache-driver.php` | Driver factory, availability, entry limits |
| `cache/cache-purger.php` | Statistics and purge operations |
| `nginx/cache-nginx.php` | Nginx static cache write/delete/clear |
| `nginx/cache-nginx-config.php` | Nginx requirements and snippet generation |
| `optimizer/settings.php` | Optimization defaults, runtime state, requirements |
| `optimizer/report.php` | Report build, storage, and summaries |
| `optimizer/dom.php` | Shared HTML attribute helpers |
| `optimizer/html.php` | HTML minify, comments, media, fonts |
| `optimizer/css-gt.php` | Synchronous unused-CSS bridge |
| `optimizer/js-gt.php` | JavaScript optimizer and minifier bridge |
| `optimizer/pipeline.php` | Optimization pipeline orchestration |
| `optimizer/gt/Settings.php` | Optimizer settings bridge and autoloader |
| `optimizer/gt/ScriptPlan.php` | Dependency-aware defer/delay decisions |
| `optimizer/gt/JavaScriptOptimizer.php` | HTML script rewriting and delay loader |
| `optimizer/gt/JavaScriptMinifier.php` | Signed local JS minifier endpoint |
| `optimizer/gt/BufferedAssets.php` | WordPress asset delivery |
| `optimizer/gt/Css/` | CSS pruner, collector, rollout, artifacts, optimizer |
| `preload/cache-preload.php` | Preload package loader |
| `preload/queue.php` | Queue state, locks, scheduling, batch processing |
| `preload/queue-store.php` | Durable queue table and priority ordering |
| `preload/queue-admin.php` | Queue rows and controls |
| `preload/queue-log.php` | Bounded per-attempt preload log |
| `preload/crawl.php` | Homepage discovery and URL generation |
| `preload/runner.php` | URL dispatch and critical re-warm |
| `preload/schedule.php` | Scheduled queue build and internal runner |
| `preload/debug.php` | Debug mode helpers |
| `preload/cli.php` | Optional WP-CLI diagnostics |
| `admin/dashboard-data.php` | Dashboard DTO and status payloads |
| `admin/driver-tools.php` | Driver probing and connection tests |
| `admin/expert-mode-snippet.php` | Expert mode code and readiness |
| `admin/url-policy.php` | URL cache eligibility checks |
| `admin/system-debug.php` | System/debug report |
| `admin/settings-save.php` | Settings persistence |
| `inc/lib/` | Vendored cache, minifier, CSS parser, and selector libraries |

## Development

```bash
bun install
bun run build
bun run test:js
composer test
bun run test:e2e
```

`bun run test:e2e` uses the local Chrome channel. Admin console specs run when `WP_ADMIN_USER` and `WP_ADMIN_PASS` are set; REST checks run when `WP_APP_PASSWORD` is set.

## Release

```bash
bash bin/release.sh --version 4.5.0 --note "Release description."
bash bin/build-release.sh --skip-bun-build   # package only, using existing built assets
```

The release scripts sync `kh-speed-engine.php`, `KHSE_PLUGIN_VERSION`, `README.txt`, `package.json`, the About page version, and `CHANGELOG.md`, then build Vite assets and create the zip in `dist/`.

## Notes

- Logged-in users do not trigger the cache by default.
- The debug HTML comment can be disabled in the Caching settings.
- If a page is not cached, check the debug comment, the URL checker, and the exclusion rules before reporting a conflict.
- When reporting an issue, list the active plugins and the system debug report from Tools → System & debug.
