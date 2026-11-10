# KH Speed Engine - WordPress Cache Plugin

KH Speed Engine is an extremely lightweight, high-performance cache plugin that speeds up your WordPress sites on the fly. The core of KH Speed Engine is driven by Shieldon [Simple Cache](https://github.com/terrylinooo/simple-cache), a PSR-16 simple cache library.


## Requirement

* PHP version 8.1 or later
* WordPress version 6.2 or later
* Tested up to WordPress 6.9.4
* Tested up to PHP 8.2.5

## Features

* Extremely lightweight and high-performance.
* Support up to 10 cache drivers such as File, Redis, Memcache, Memcached, APC, APCu, WinCache, MySQL, SQLite, and MongoDB.
* Provide detailed cache statistics, easy to manage.
* Vite-built React admin console with a self-hosted Google Sans design system, live status, a URL cache checker, requirement checks, and one-click actions.
* Optional Redis and File cache compression.
* Optional Nginx direct static cache for the File driver.
* Optional page optimization before cache storage: HTML minify, inline/external UCSS generation, JS analysis, lazy media, LCP image priority, Google Fonts preconnect, and guarded JavaScript defer.
* Admin bar purge controls for clearing all cache or only the current page.
* Tools → System & debug with a debug-mode switch, grouped system configuration, proxy/topology detection, queue health, and bounded per-attempt preload logs.
* And more...

## Modular Architecture

As of v3.0.8, the plugin's helper functions have been split from a single monolithic `helpers.php` (179 KB) into focused modules loaded through `bootstrap.php` and its cache aggregator:

| Module | Purpose |
|--------|---------|
| `cache/cache-core.php` | Stable include aggregator; loads identity, settings, storage, and policy |
| `cache/cache-identity.php` | Site directory hash and blog identity |
| `cache/cache-settings.php` | Canonical option schema, defaults, and typed setting accessors |
| `cache/cache-storage.php` | Upload/runtime paths, config persistence, sync |
| `cache/cache-stats.php` | Cache statistics index and cache-type catalog |
| `cache/cache-driver-settings.php` | Driver defaults, advanced option mapping, connection types |
| `cache/cache-policy.php` | URI normalization, cacheability/exclusion rules, cache keys, read path |
| `cache/cache-driver.php` | Driver factory, availability checks, cache entry limits |
| `cache/cache-view.php` | SVG icons, benchmark footer markup/JS |
| `nginx/cache-nginx.php` | Nginx static cache write/delete/clear |
| `nginx/cache-nginx-config.php` | Nginx requirements check + config snippet generation |
| `cache/cache-purger.php` | Cache statistics + purge operations |
| `optimizer/settings.php` | Optimization defaults, runtime state, workspace helpers |
| `optimizer/report.php` | Optimization report build, storage, pruning, and summaries |
| `optimizer/dom.php` | Shared HTML attribute helpers for the optimizer modules |
| `optimizer/html.php` | HTML minification, media/LCP tags, font preconnect, deferral |
| `optimizer/css.php` | CSS minify, conservative purge, local/external UCSS |
| `optimizer/js.php` | JavaScript safety analysis and deferral |
| `optimizer/pipeline.php` | Pipeline orchestration and requirement checks |
| `preload/cache-preload.php` | Preload queue, homepage crawling, scheduled warming |
| `cache/brand-migration.php` | One-time legacy option and storage migration |
| `widgets.php` | Benchmark widget registration |

The wp-admin support layer is split into `admin/dashboard-data.php` (DTO/status payloads), `admin/driver-tools.php` (driver probing and connection tests), `admin/expert-mode-snippet.php` (code generation and readiness checks), and `admin/url-policy.php` (cache eligibility checks for the URL tester). Menu, AJAX, lifecycle, and settings hooks remain in `admin/`.

## Testing

```bash
composer test                 # PHPUnit
bun run test:js               # Vitest
bun run test:e2e              # Playwright front-end and (credential-gated) admin checks
```

Playwright uses the local Chrome channel and ignores self-signed certificates. Admin console specs run when `WP_ADMIN_USER` and `WP_ADMIN_PASS` are set; REST checks run when `WP_APP_PASSWORD` is set.

## Nginx Direct Static Cache

When using the File driver, KH Speed Engine can write a raw HTML mirror that Nginx may serve before PHP or WordPress runs. Enable it in the Performance workspace, review the requirement checks, copy the generated snippet into your Nginx `server` block, run `nginx -t`, then reload Nginx. The generated rule serves the same cached page for Facebook/Meta and UTM tracking variables (`fbclid`, `mibextid`, `rdid`, `shbid`, `shbv`, and standard UTM keys) while bypassing functional or unknown query variables.

## Page Optimization

KH Speed Engine can optimize cacheable guest HTML before it is saved to File, Redis, Memcached, APCu, or another selected driver. Enable it in the Performance workspace. The pipeline includes HTML/comment cleanup, inline CSS minify, conservative PHP UCSS for local styles, hashed CSS assets for eligible same-site stylesheets, native lazy loading for media, first-image LCP priority/preload, Google Fonts preconnect, bounded PHP JS analysis, and optional safe JavaScript defer with exclusions.

External UCSS is intentionally conservative. It reads only same-site `.css` files that resolve under the WordPress root, skips SRI/crossorigin/alternate/preload/importing stylesheets, obeys the configured max file size, rewrites relative `url()` assets before publishing a content-addressed CSS file, and keeps the original `<link>` when the PHP engine produces no smaller output.

## Preloading

Preloading warms the homepage first (with a blocking request), then same-site links discovered on the homepage, before moving to configured post types and archives. Enable Preload in thedashboard to start queueing immediately, or use the "Run Preload" button to trigger warming on demand.

Large sites can preload up to 1000 URLs. KH Speed Engine queues preload work in small batches so dashboard actions do not have to wait for every URL in one request. The Background queue panel shows pending, running, and failed jobs with pause, retry, and cancel controls. Failed rows expose the last HTTP status, transport mode, and error message; enable debug mode in Tools → System & debug to keep a bounded per-attempt log.

## Vite and Google Sans Admin Console

KH Speed Engine uses native WordPress admin pages plus a Vite-built admin asset pipeline that bundles React and a self-hosted Google Sans variable font. The console is organized into five workspaces: Dashboard, Caching (cache, preload, exclusion rules), Optimization (performance, statistics), Tools (expert mode, benchmark), and About. It includes a cache eligibility URL checker, an on-demand Nginx snippet generator, and AJAX controls for refresh, preload, homepage purge, and full cache clear.

Development build:

```bash
bun install
bun run build
```

Release build:

```powershell
bun run build:release
```

One-command version bump and release (Windows PowerShell):

```powershell
bun run release
bun run release:minor
powershell -ExecutionPolicy Bypass -File .\bin\release.ps1 -Version 3.1.0 -Note "Release dashboard polish."
```

One-command version bump and release (Ubuntu/Linux/macOS):

```bash
bash bin/release.sh
bash bin/release.sh --bump minor
bash bin/release.sh --version 3.1.0 --note "Release dashboard polish."
bash bin/build-release.sh --skip-bun-build   # package only, using existing built assets
```

`bin/release.ps1` (Windows) and `bin/release.sh` (Ubuntu/Linux/macOS) default to a patch bump. They sync `kh-speed-engine.php`, `KHSE_PLUGIN_VERSION`, `README.txt` stable tag, `package.json`, React about-page labels, and `CHANGELOG.md`, then call the matching build script (`bin/build-release.ps1` / `bin/build-release.sh`). The build script runs the JS build (`bun`, falling back to `pnpm` or `npm` on the bash version), builds Vite assets into `inc/assets/build`, verifies the Vite manifest, then creates the WordPress zip in `dist/`. Pass `-SkipBunBuild` / `--skip-bun-build` only when built assets already exist.

The Performance view records recent cache writes and shows what actually happened on each page: bytes before/after, total bytes saved, Local UCSS and External UCSS bytes removed, JS Analysis deferred/analyzed counts, and feature-level states such as Applied, No change, Disabled, or Failed. Older reports created before the local engines existed may still show Pending engine. It loads five reports first and fetches more only when requested.

All settings forms save in place through authenticated WordPress AJAX requests with nonce and capability checks. The React console writes the same WordPress options used by the cache runtime, so existing option update hooks still run.

The console normalizes legacy controls into switch-style binary toggles or segmented multi-choice controls, keeps dense settings content aligned for faster scanning, and provides live Statistics lists with per-row cache clearing.

`Applied` in a page report means that transform changed cached output. Local UCSS uses conservative PHP selector matching and preserves dynamic/unsupported rules. External UCSS publishes optimized hashed CSS assets. Both keep a configurable safelist for dynamic classes. JS Analysis uses bounded PHP heuristics and defers only readable same-site scripts classified safe. Report sizes use human-readable units and displayed URLs are decoded for easier review.

Built assets are required for the React console. The release script verifies the Vite manifest before packaging.

## Local UCSS, External UCSS, and JS Analysis

KH Speed Engine uses built-in PHP engines for Local UCSS, External UCSS, and JS Analysis. Production does not require Bun, Node, PurgeCSS, Composer, or `shell_exec`. The legacy executable fields remain only so older saved settings and dashboards continue to load.

Bun and the frontend package toolchain are build-machine dependencies only. Run `bun install --frozen-lockfile` and `bun run build` before packaging the React console.

Windows:

```powershell
where bun
where purgecss
```

On Windows, the optional legacy Bun/PurgeCSS fields can be left empty. Optimization workspaces and runtime config are stored outside public uploads when the host provides a private temp directory.

The preloader uses loopback HTTP requests to warm cached pages. On local development environments with self-signed SSL certificates, SSL verification is automatically disabled for preload requests. This behavior can be overridden via the `khse_preload_sslverify` filter.
