# Changelog

## [4.4.0] - 2026-11-10

### Added
- Continuous scheduled preload: a priority-first full-site queue warms 50 URLs per tick until the cache reaches Max cache entries, or until every published URL is covered when Max cache entries is 0.
- Internal request-driven runner with a WP-Cron backup. No WP-CLI or external cron dependency.
- Priority queue ordering: homepage, homepage links, edited pages, and media changes warm before the normal backlog.
- Keep-warm audit every 6 hours re-queues only missing or expired pages.
- Scheduled preload card with target, progress, next run, pause/resume, cancel, and rebuild controls.
- Cache-limit and TTL warnings before a long cycle.
- Media changes purge the homepage and attachment page and re-warm them as priority.
- Plugin/theme updates, activations, deactivations, settings saves, and clear-all rebuild the scheduled queue.

### Changed
- Cache clears and settings saves defer the full-site rebuild to the next admin or cron request so admin actions stay responsive.
- Background queue shows Scheduled/Quick mode and Priority badges.

## [4.3.2] - 2026-11-10

### Fixed
- Preload now fills the configured URL limit with unique pages. Homepage links that overlap the latest posts no longer leave the queue short; the builder pages through older posts until the limit is reached.
- The Scope copy now explains that the URL limit is a per-run maximum and does not warm every published post.

## [4.3.1] - 2026-11-10

### Fixed
- The Preload page no longer drops to an empty “Nothing queued” state after a successful run. The dashboard keeps the last run's total, dispatched, failed, and finish time until the next run starts.
- Background queue now shows Completed, Running, Paused, or Idle instead of always showing Running with zero counters.
- Successful preload pages stay visible after the queue drains when debug mode is on.
- A new run shows live counters again instead of inheriting the previous run's finished counters.

### Changed
- Background queue shows a last-run summary with dispatched pages, failures, finish time, and transport mode.
- Preloaded pages list includes the transport mode so blocking and non-blocking runs are distinguishable.

## [4.3.0] - 2026-11-10

### Added
- Background queue now shows the successful preload pages when debug mode is on, with time, URL, HTTP status, and duration.
- Current job count and a “showing X of Y” note make large pending queues easier to understand.

### Fixed
- Clearing the cache now builds and starts preload immediately on private/loopback hosts instead of waiting for WP-Cron or a loopback event.
- Dashboard status and System & Debug requests drain pending private-host preload queues inline, so a missing or blocked WP-Cron event no longer strands the queue.
- Preload page listings now include up to 50 current jobs.

## [4.2.2] - 2026-11-09

### Fixed
- Preload now treats HTTP 4xx responses (except 408 and 429) as permanent failures instead of retrying them three times. A 404 is reported as “the URL is not public or no longer exists”.
- HTTP 5xx, 408, and 429 responses remain retryable.
- Failure messages now include the HTTP status text and distinguish permanent client errors from temporary server errors.

## [4.2.1] - 2026-11-09

### Added
- Download diagnostics as a JSON file from Tools → System & debug for support and review.

### Changed
- Moved per-attempt preload history into the Background queue. Click any queue row to expand its last error, HTTP status, error code, transport mode, attempt time, and attempt history.
- Attempt history is hidden when debug mode is off, so production mode stays focused on the latest failure only.

### Fixed
- Scope chips no longer stretch into large circles when the post-type column is taller than the archive column.

## [4.2.0] - 2026-11-09

### Added
- Tools → System & debug with a debug-mode switch, grouped system configuration, proxy/topology detection, queue health, and per-attempt preload logs.
- Preload queue rows now store and display the last HTTP status, error code, transport mode, and attempt time. Failed rows expose a "Why it failed" panel.
- Debug records are bounded to 1,000 attempts and can be cleared independently or disabled and cleared for production.

### Changed
- Durable queue schema v2 adds diagnostic columns and a separate bounded preload log table.

## [4.1.1] - 2026-11-07

### Fixed
- Process the durable queue from admin and AJAX shutdown even when the legacy remaining counter is stale; the queue table is now the source of truth.
- Heal stale queue counters when the dashboard reads queue status.

### Added
- WP-CLI queue controls: `wp khse preload status`, `run`, `build`, `clear`, `pause`, `resume`, and `endpoint` for Docker and container hosts.

## [4.1.0] - 2026-11-07

### Changed
- Preload now stores its queue in a plugin-owned table with per-URL status, attempts, retry backoff, and stale-worker recovery, so a cache flush or deploy cannot lose pending URLs.
- The transient queue remains as a fallback when the table cannot be created and as a small backward-compatibility mirror.
- Homepage priority URLs stay first in the queue, in the same order as before.
- Preload failures retry up to three times by default; `khse_preload_max_attempts` and `khse_preload_retry_delay` control the policy.
- `khse_preload_use_durable_queue` can disable the table and return to the transient queue on hosts that forbid `CREATE TABLE`.
- Added the Background queue panel with pending, running, and failed counts, a live job table, and pause, resume, retry, and cancel controls.
- Pausing background work keeps cache invalidation, purging, and post-edit cache safety running.

## [4.0.0] - 2026-11-06

### Changed
- Rebranded the plugin as KH Speed Engine (KHSE), with the admin menu label "KH Speed". Renamed the plugin folder, main file, text domain, functions, constants, classes, options, AJAX actions, nonces, CSS classes, asset handles, and language files.
- Added a one-time migration for legacy `scm_*` options, cache/uploads, and the Expert Mode runtime directory.
- Rebuilt the admin console as five workspaces (Dashboard, Caching, Optimization, Tools, About) with a status-first dashboard, grouped settings, CSS charts, and consistent settings panels.
- Added a contextual save bar with unsaved-change tracking and discard, plus a compact Quick actions menu.
- Added a cache eligibility URL checker and an on-demand Nginx direct-cache snippet generator.
- Self-hosted the Google Sans variable font for the admin UI.
- Removed the WooCommerce integration.
- Preload now builds immediately when enabled or changed and drains through admin/AJAX shutdown plus an internal token endpoint, so it no longer depends on WP-Cron or front-end traffic.
- Private-network and loopback preload targets dispatch non-blocking to avoid deadlocking FPM on loopback requests and skip internal self-signed TLS; public hosts keep blocking, certificate-verified preloading.
- Added `khse_preload_blocking`, `khse_preload_treat_private_as_local`, and `khse_preload_skip_private_tls` filters so each hosting topology can override the preload transport and TLS policy without code changes.
- Post publish/edit/delete preload work drains on the same admin request.
- Refresh status now updates data only: it no longer shows a "Settings saved" toast or overwrites unsaved edits.
- Fixed fragment-only homepage links being queued as fake preload URLs.
- Provision the optimizer workspace on activation/admin and keep the requirement check read-only.

## [3.1.5] - 2026-11-04

### Changed
- Fix query-string cache poisoning by gating on the full request URI; fail closed on unparseable URIs and raw fragments. Adds cache-identity regression tests.

## [3.1.4] - 2026-07-28

### Changed
- Release build.

## [3.1.3] - 2026-07-28

### Changed
- Release build.

## [3.1.2] - 2026-07-23

### Added
- Cross-platform release tooling: `bin/release.sh` and `bin/build-release.sh` bash ports for Ubuntu/Linux/macOS with `bun`/`pnpm`/`npm` and `zip`/`python3` fallbacks, plus matching `release:sh*` package scripts.
- Preload queue stall recovery: `init` hook reschedules `khse_preload_queue_event` when the queue transient survives but its cron event was lost (object cache flush, deploy mid-run).
- PHPUnit coverage for preload queue recovery (`tests/test-2-preload-recovery.php`).

### Changed
- Release packaging now excludes composer dev dependencies (`vendor/phpunit`, `vendor/yoast`, etc.), `.ua/`, and `pnpm-lock.yaml` in both PowerShell and bash build scripts.

## [3.1.1] - 2026-07-22

### Changed
- Release build.

## [3.1.0] - 2026-07-20

### Changed
- Release build.

## [3.0.10] - 2026-07-20

### Changed
- Release build.

## [3.0.9] - 2026-07-10

### Changed
- Release build.

## [3.0.8] - 2026-07-09

### Changed
- Release build.

## [3.0.7] - 2026-07-09

### Changed
- Release build.

## [3.0.6] - 2026-06-24

### Changed
- Release build.

## [3.0.5] - 2026-05-25

### Changed
- Release build.

## Unreleased

### Changed
- Replace the optional Sharp image conversion path with Bun Image for local WebP generation and native placeholder metadata.
- Add Bun path and Image placeholders controls to the React Performance Image settings.
- Update image optimizer requirements so selected formats pass only when Bun or the WordPress image editor can actually create them.
- Route JS Analysis through Bun so the optimizer pipeline no longer needs a separate JS runtime.
- Remove secondary image output support and force image optimization to WebP only.
- Switch package and release commands to Bun with Bun lockfile flow.

### Fixed
- Fix a blank Performance Images tab caused by a stale built React bundle calling a removed list component.

### Security
- Keep Bun image conversion constrained to real upload-directory paths and restrict generated placeholder data URLs before they are written into cached HTML.

## [3.0.4] - 2026-05-23

### Changed
- Release build.

## [3.0.3] - 2026-05-23

### Changed
- Release build.

## [3.0.2] - 2026-05-23

### Changed
- Release build.

## [3.0.1] - 2026-05-23

### Added
- Add `bin/release.ps1` for one-command version sync, automatic patch/minor/major bumping, changelog entry creation, asset build, and release zip generation.

### Fixed
- Fix automatic upload image optimization for AJAX and metadata-update upload paths by registering core upload hooks before the AJAX branch.
- Add a `wp_update_attachment_metadata` fallback so the selected primary WebP upload format is applied before late offload listeners sync files.
- Add bottom dashboard spacer below the global save bar so the last card keeps visual breathing room above the WordPress footer.

## [3.0.0] - 2026-05-23

### Added
- Add upload-only single image optimization retry queue so new uploads can retry conversion without entering the manual batch queue.
- Add cache driver connection test action in the React console with extension readiness feedback.
- Add MongoDB driver fields for user, password, database, and collection.

### Changed
- Run upload-time image optimization before Advanced Media Offloader and hand optimized attachments back to offloading after retry.
- Keep Performance reports and summary cards inside the Overview tab only.
- Stretch dashboard cards consistently, make Expert Mode a 30/70 layout, and make the Expert Mode configure block click-to-copy.

### Fixed
- Prevent automatic upload optimization from filling the manual image batch queue.
- Keep compatible offload metadata timing for Advanced Media Offloader and WP Offload Media.
- Move WordPress admin footer below the KH Speed Engine console and preserve rounded card bottoms.

## [2.8.1] - 2026-05-22

### Added
- Rebuild the KH Speed Engine admin console with React, HeroUI components, lucide icons, and Tailwind CSS 4.3.
- Add a compact React settings payload and authenticated AJAX save bridge for cache, preload, performance, rules, statistics, and benchmark settings.
- Add React driver cards, preload option groups, Expert Mode code preview, and primary upload image format selection.

### Changed
- Replace the previous Vue console shell with a single React mount and Vite React entry.
- Move optional `sharp` image conversion to optional dependencies so release installs can avoid shipping dependency folders.
- Add `--Bump` (major/minor/patch) and `--SetVersion` flags to `bin/build-release.ps1` with automatic plugin-header version update and README.txt Stable tag sync.
- Exclude agent files, local graph output, design notes, source admin assets, and scraped audit JSON from release packaging.

### Fixed
- Generate image variants during upload before offload plugins read attachment metadata, and promote the chosen primary variant to the attachment file for new uploads.
- Persist generated variant metadata into WordPress attachment `sources` so HTML rewriting and compatible offload plugins can discover the new files.
- Restore full-width dashboard alignment, reduce KHSE sidebar height so the WordPress menu stays normal, and rebuild Cache, Preload, Expert Mode, Benchmark, and About surfaces around card layouts.
- Remove the obsolete Vue fallback script and table-transformer bridge from the runtime.
- Fix README.txt stable-tag syncing in the release script.

## [2.8.0] - 2026-05-21

### Added
- Add Vite build tooling with bundled Vue 3, Tailwind CSS 4.3 admin entry points, generated build manifest support, and release-script enforcement for built assets.
- Add optional `sharp` image optimizer fallback for image variant generation, with a server-side image-check verification command.
- Add External UCSS Generation for same-site local stylesheet files, with max-file-size guardrails, relative asset URL rewriting, PurgeCSS safelist support, and fail-open behavior that keeps the original stylesheet when optimization fails.
- Add Image Optimization settings for image variant generation, upload-time queueing, background batch processing, and safe WordPress attachment HTML rewriting through generated variant metadata.
- Add a safe cached-HTML image rewrite pass for raw `<img>` tags that WordPress can map back to attachments with generated variants.
- Add Performance dashboard cards for External UCSS savings and image optimizer queue/status.
- Add Queue Images dashboard action that queues the 200 newest JPEG/PNG/WebP attachments for background optimization.

### Security
- Keep image optimization constrained to validated source and output paths inside the WordPress uploads directory before invoking the local optimizer.
- Restrict image optimizer writes to existing files inside the WordPress uploads directory, preserve original files, skip unsupported file types, cap queued attachment work, and keep remote/offloaded URL rewriting disabled unless explicitly enabled.
- Skip External UCSS for cross-origin, SRI, crossorigin, disabled, preload, alternate, and importing stylesheets.

### Fixed
- Add horizontal Performance tabs for Overview, Requirements, and Optimization settings.
- Redesign the Rules console into tabbed URL/request/cookie panels and reshape Settings API rows into card/list blocks instead of table-style fields.
- Redesign Benchmark display choices as card selectors for Text, Icon, and Both modes.
- Restyle frontend benchmark footer and widget output with Khmer metric labels and compact card/pill layouts.
- Clamp page optimization report savings to zero when inline optimization grows cached HTML, show the growth separately, and stop replacing inline CSS blocks when minification or Local UCSS output is larger than the original.
- Protect WordPress dropdown menus, Slider Revolution, Slick, Swiper, and common checkout scripts from automatic defer/JS Analysis, and keep their dynamic CSS states in the UCSS safelist for better guest frontend compatibility.
- Keep Expert Mode compatible with early `wp-config.php` loading by delaying WordPress hook registration until `add_filter()` and `add_action()` exist.
- Align dashboard toolbar icons and move secondary actions to compact icon buttons.
- Restore Dashicons after the dashboard font override and add icons to Performance summary cards.
- Center console navigation icons, flatten progress bars, align Statistics row actions, and expand the About page to a full-width card layout.
- Redesign the About page with a WordPress-style hero, section navigation, feature cards, and credits while keeping the `DESIGN.md` dashboard system.

### Changed
- Load Vite-built admin CSS/JS when `inc/assets/build/.vite/manifest.json` is present, with the legacy admin assets as a fallback.
- Re-skin the admin console with the `DESIGN.md` dashboard system: narrow icon sidebar, card-based surfaces, DM Sans/Outfit typography, tokenized colors, pill controls, and hatched progress bars.

## [2.7.2] - 2026-05-15

### Added
- Add exact Local UCSS saved-byte and JS Analysis deferred/analyzed summary cards on the Performance page.

### Changed
- Replace admin table components with list-based blocks matching the Latest Page Report design.

## [2.7.1] - 2026-05-15

### Fixed
- Keep malformed inline CSS blocks raw when PurgeCSS cannot parse them, while still optimizing valid blocks on the same page.
- Surface useful PurgeCSS parse messages instead of only the first stack-trace file path.

## [2.7.0] - 2026-05-15

### Added
- Real Local UCSS Generation for inline page CSS through PurgeCSS, with configurable safelist support.
- Real JS Analysis engine through local JS analysis tooling that defers only readable same-site scripts classified safe.
- Writable local optimizer workspace requirement check and runtime feature reporting for applied, no change, or failed jobs.

## [2.6.6] - 2026-05-15

### Changed
- Show optimization report sizes with human-readable units instead of raw bytes.
- Decode displayed optimization report URLs for easier reading.
- Humanize old stored report byte strings at render time, so existing reports improve before recache.

## [2.6.5] - 2026-05-15

### Changed
- Load only five recent page optimization reports by default, with click-to-load pagination in five-row batches.
- Explain that Local UCSS Generation and JS Analysis remain requirement checks until their engines produce changed cached HTML.
- Convert guest visibility rows into explicit read-only badges and normalize switch labels to `Enable`, `Disable`, `Yes`, and `No`.

### Fixed
- Tighten Statistics setting card layout so its switch and save button no longer stretch awkwardly.

## [2.6.4] - 2026-05-15

### Changed
- Rebuild checkbox settings into consistent switch controls across Preload, Performance, and remaining console tabs.
- Improve Advanced driver connection controls, including disabled TCP / Unix Socket states.
- Replace the old Statistics split view with a searchable live table and per-row clear-cache actions.

### Fixed
- Re-run settings enhancement across hidden and newly opened console tabs so Rules, Statistics, Expert Mode, and Benchmark no longer keep legacy controls.

## [2.6.3] - 2026-05-15

### Fixed
- Restore AJAX settings saves by reading the literal form `action` attribute instead of the form field named `action`.

### Changed
- Replace binary Enable/Disable and Yes/No radios with switch-style controls.
- Refresh settings and dashboard tables with cleaner neutral styling and remove leftover settings-row backgrounds and separators.

## [2.6.2] - 2026-05-15

### Changed

- Radio groups are rebuilt into clean segmented controls instead of styling malformed legacy label markup in place.
- Settings spacing, advanced driver field alignment, TTL example layout, and long requirement output wrapping are tightened.

### Fixed

- Segmented controls no longer apply flex layout to whole settings cells, which had pulled help text, inputs, and example tables into one row.

## [2.6.1] - 2026-05-15

### Added

- AJAX settings saves across all Vue console settings views.
- Modern settings form styling with section cards, segmented radio groups, refined field surfaces, and per-form save status.

### Changed

- Existing Settings API forms now submit without a full admin-page reload while keeping their native nonce, capability, and update-hook behavior.

## [2.6.0] - 2026-05-15

### Added

- Unified Vue admin console covering cache, preload, performance, advanced, rules, statistics, expert, benchmark, and about views.
- Page optimization reports that record bytes before/after, saved bytes, and feature-level result states for recent cached pages.
- Performance workspace with requirement checks, recent page reports, and detailed latest-page feature diagnostics.

### Changed

- Existing WordPress admin pages now resolve into one Vue console while preserving native Settings API saves.
- Admin styling now uses compact design tokens, modern surfaces, side navigation, and status pills.

## [2.5.1] - 2026-05-15

### Added

- Batched preload queue with dashboard totals for queued, processed, and remaining URLs.
- Homepage priority fallback from enabled post types when the homepage crawler cannot find enough internal links.

### Changed

- Dashboard Run Preload and Purge Homepage actions now start queue work instead of blocking the full AJAX request.
- Preload limit now supports up to 1000 URLs, dispatched in smaller batches.
- Priority URL count now reflects planned homepage-priority URLs instead of only the previous run count.

## [2.5.0] - 2026-05-15

### Added

- NPM-less Vue 3 dashboard loaded from CDN inside native WordPress admin screens.
- Live dashboard status cards for cache health, page optimization, server requirements, and preload progress.
- Dashboard AJAX actions for refresh, run preload, purge homepage, and clear all cache.
- Cache footprint and optimization requirement summaries on the main KH Speed Engine page.

### Changed

- KH Speed Engine now opens to a dedicated Dashboard page, with Settings, Expert Mode, Statistics, and About as secondary pages.
- Admin styling refreshed for a more compact, interactive cache console.

## [2.4.3] - 2026-05-14

### Changed

- Clear Cache is now hidden from the frontend admin bar; Purge Current Page remains available there.

## [2.4.2] - 2026-05-14

### Changed

- KH Speed Engine admin bar controls now load on frontend for logged-in users.
- Homepage-discovered preload URLs now run first with blocking requests before the rest of the preload queue.
- Cache clear and preload setting changes now immediately warm homepage-discovered URLs when preload is enabled.

## [2.4.1] - 2026-05-14

### Added

- Purge Current Page admin bar action for Administrators and Editors.

### Changed

- Critical homepage preload now runs blocking after cache clear so Homepage stats repopulate immediately.
- Local optimizer and PurgeCSS checks now use common web-user PATH locations and fail command-not-found output correctly.

## [2.4.0] - 2026-05-14

### Added

- Local UCSS Generation and JS Analysis options with local optimizer, PurgeCSS, and `shell_exec` requirement checks.
- Homepage-first preload crawling that extracts same-site links from homepage HTML before falling back to configured post/archive URL lists.
- Scoped homepage/archive purge for published or deleted posts.

### Changed

- Cache serving is now guest-only in both Normal Mode and Expert Mode.
- Cache identity is normalized before key generation.
- Statistics now de-duplicate rows that point to the same URI.
- Frontend cache ignores query-string requests, static asset paths, auth-cookie requests, REST, admin, login, and XML-RPC paths.

## [2.3.0] - 2026-05-14

### Added

- Page Optimization settings tab.
- Cache-time HTML optimization before File, Redis, Memcached, APCu, and other driver storage.
- HTML minification, safe comment removal, and inline CSS minification.
- Native lazy loading for images and iframes with keyword exclusions.
- First-image LCP priority/preload controls.
- Google Fonts preconnect hints.
- Optional JavaScript defer with exclusion list.
- Requirement checks for page optimization.

### Changed

- Updating page optimization settings now clears cache and starts configured preload/critical warmup.

## [2.2.0] - 2026-05-13

### Added

- Redis database selector for DB0 through DB15.
- Redis database selection support in bundled Shieldon Simple Cache Redis driver.
- Redis HTML payload compression options.
- File driver compression options.
- Maximum cache entries option that prunes oldest known cache rows.
- Cache key prefix option for shared Redis, Memcached, APC, APCu, WinCache, MongoDB, and MySQL stores.
- Cache preload option that warms selected homepage, post type, and archive URLs.
- Immediate critical preload for homepage and archive URLs after cache clear or published post save.
- Nginx direct static cache option for File driver with server requirement checks and generated Nginx snippet.
- Custom post type and custom post type archive cache support.

### Changed

- Expert Mode snippet now explains that runtime settings are read from `config.json`.
- Expert Mode snippet now emits normalized paths for Windows/local development.
- Frontend benchmark JavaScript no longer depends on jQuery.
- Shared-store cache clearing deletes known KH Speed Engine keys instead of flushing entire shared stores.
- Plugin branding changed to KH Speed Engine.
- WordPress compatibility metadata tested up to 6.9.4.
- Preload loopback timeout increased for more reliable non-blocking warm requests.
- Cache statistics now keep URI metadata while remaining compatible with older size-only stat files.

### Fixed

- Redis advanced settings remain editable when Redis is the active driver.
- Redis, Memcached, and MongoDB advanced field values now render correctly.
- Driver status view now marks the selected active driver.
- Single excluded URL entries persist correctly.
