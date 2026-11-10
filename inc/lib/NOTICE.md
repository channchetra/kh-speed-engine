# Third-Party Libraries

KH Speed Engine vendors the following MIT-licensed libraries in this directory. They
were copied from the Composer packages (`vendor/`) so that KH Speed Engine can
maintain and patch them in-repo instead of relying on `composer install` at
runtime.

## Shieldon Simple Cache 1.3.4

- License: MIT
- Source: https://github.com/terrylinooo/simple-cache
- Location: `inc/lib/simple-cache/`
- License text: `inc/lib/simple-cache/LICENSE`

The local `File` and `Redis` drivers include the compression and TTL fixes that
were previously maintained as copies in `patches/`. Those patch files duplicated
the driver sources and have been removed now that the fixed drivers live here.

## PSR Simple Cache 1.0.1

- License: MIT
- Source: https://github.com/php-fig/simple-cache
- Location: `inc/lib/psr-simple-cache/`
- License text: `inc/lib/psr-simple-cache/LICENSE.md`

## Loading

`inc/lib/autoload.php` registers a PSR-4 style autoloader for the
`Shieldon\SimpleCache\` and `Psr\SimpleCache\` namespaces. The original
namespaces are preserved, so plugin code and tests are unaffected.

## Updating

When upgrading either library, copy the new `src/` contents over the matching
subdirectory, keep the license file, re-apply any KHSE-specific driver fixes, and
update the version numbers above.

## MatthiasMullie Minify / PathConverter

- License: MIT
- Source: https://github.com/matthiasmullie/minify
- Locations: `inc/lib/matthiasmullie-minify/`, `inc/lib/matthiasmullie-path-converter/`
- Used by the signed local JavaScript minifier endpoint.

## Sabberworm PHP-CSS-Parser

- License: MIT
- Source: https://github.com/sabberworm/PHP-CSS-Parser
- Location: `inc/lib/php-css-parser/`
- The vendored copy had the generated `thecodingmachine/safe` function imports
  removed so it runs without that optional runtime dependency. Native PHP
  `preg_*`, `iconv`, and `class_alias` functions are used instead. Parse
  failures are caught by the CSS optimizer and fail open to the original page.

## Symfony CSS Selector

- License: MIT
- Source: https://github.com/symfony/css-selector
- Location: `inc/lib/symfony-css-selector/`
- Used for selector matching in the unused-CSS pruner.

## GT Performance optimization code

- License: GPL-2.0-or-later
- Source: https://github.com/gauravtiwari/gt-performance (1.3.6)
- Locations: `inc/optimizer/gt/`, `assets/delay.js`
- The CSS pruning/collection and JavaScript deferral/minification classes are
  adapted from GT Performance. They were converted to KHSE settings, storage,
  and synchronous cache-write generation, and stripped of GT's queue, reports,
  loopback generator, and admin modules.
