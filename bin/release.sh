#!/usr/bin/env bash
#
# One-command KH Speed Engine version sync and release build.
# Bash port of bin/release.ps1 for Ubuntu/Linux/macOS/Git-Bash.
#
# Examples:
#   bash bin/release.sh
#   bash bin/release.sh --bump minor
#   bash bin/release.sh --version 3.1.0 --note "Release dashboard polish."

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
MAIN_FILE="$ROOT/kh-speed-engine.php"
BUILD_SCRIPT="$SCRIPT_DIR/build-release.sh"

VERSION=''
BUMP='patch'
NOTE='Release build.'
OUT_DIR='dist'
INCLUDE_TESTS=0
KEEP_STAGE=0
SKIP_BUN_BUILD=0

usage() {
    sed -n '3,10p' "${BASH_SOURCE[0]}"
    exit 0
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)        VERSION="$2"; shift 2 ;;
        --bump)           BUMP="$2"; shift 2 ;;
        --note)           NOTE="$2"; shift 2 ;;
        --out-dir)        OUT_DIR="$2"; shift 2 ;;
        --include-tests)  INCLUDE_TESTS=1; shift ;;
        --keep-stage)     KEEP_STAGE=1; shift ;;
        --skip-bun-build) SKIP_BUN_BUILD=1; shift ;;
        -h|--help)        usage ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

fail() {
    echo "Error: $*" >&2
    exit 1
}

[[ -f "$MAIN_FILE" ]] || fail "Cannot find kh-speed-engine.php at $MAIN_FILE"
[[ "$BUMP" =~ ^(major|minor|patch)$ ]] || fail "Invalid --bump '$BUMP'. Use major, minor, or patch."

CURRENT_VERSION="$(grep -Po '^\s*\*\s*Version:\s*\K[^\r\n]+' "$MAIN_FILE" | head -n 1 | tr -d '[:space:]')"
[[ -n "$CURRENT_VERSION" ]] || fail "Cannot read plugin Version from $MAIN_FILE"

if [[ -z "$VERSION" ]]; then
    IFS='.' read -r major minor patch _ <<< "$CURRENT_VERSION"

    [[ -n "${patch:-}" ]] || fail "Version '$CURRENT_VERSION' must use MAJOR.MINOR.PATCH for automatic bumping."

    case "$BUMP" in
        major) major=$((major + 1)); minor=0; patch=0 ;;
        minor) minor=$((minor + 1)); patch=0 ;;
        patch) patch=$((patch + 1)) ;;
    esac

    VERSION="$major.$minor.$patch"
    echo "Bumped $BUMP : $CURRENT_VERSION -> $VERSION"
else
    echo "Using explicit version: $VERSION"
fi

[[ "$VERSION" =~ ^[0-9A-Za-z._-]+$ ]] || fail "Invalid version '$VERSION'. Use numbers, letters, dot, underscore, or dash."

update_regex_file() {
    local path="$1" perl_expr="$2" label="$3"

    [[ -f "$path" ]] || return 0

    local before after matches
    before="$(cat "$path")"
    matches="$(KHSE_EXPR="$perl_expr" perl -0777 -ne 'my $count = 0; $count += eval $ENV{KHSE_EXPR}; print $count;' "$path" 2>/dev/null || echo 0)"

    if [[ "${matches:-0}" -eq 0 ]]; then
        echo "Warning: No version match in $label ($path)" >&2
        return 0
    fi

    perl -0777 -pi -e "$perl_expr" "$path"
    after="$(cat "$path")"

    if [[ "$before" != "$after" ]]; then
        echo "Updated $label"
    else
        echo "Already at $VERSION: $label"
    fi
}

# kh-speed-engine.php plugin header.
update_regex_file "$MAIN_FILE" \
    "s/^(\s*\*\s*Version:\s*)[^\r\n]+/\${1}$VERSION/m" \
    'kh-speed-engine.php plugin header'

# KHSE_PLUGIN_VERSION define. The line is indented inside the version guard.
update_regex_file "$MAIN_FILE" \
    "s/^(\s*define\(\s*'KHSE_PLUGIN_VERSION'\s*,\s*')[^']+('\s*\);)/\${1}$VERSION\${2}/m" \
    'KHSE_PLUGIN_VERSION'

# Expert Mode's fallback version. It normally reads the header, but keeping the
# fallback in sync avoids a stale version when the header cannot be read.
update_regex_file "$ROOT/inc/expert-mode.php" \
    "s/(\\\$version\s*=\s*')[^']+(';)/\${1}$VERSION\${2}/" \
    'inc/expert-mode.php fallback version'

# About page version and release heading.
update_regex_file "$ROOT/assets/src/views/about.jsx" \
    "s/(const VERSION = window\.khseDashboard\?\.version \|\| ')[^']+(';)/\${1}$VERSION\${2}/" \
    'assets/src/views/about.jsx version'

update_regex_file "$ROOT/assets/src/views/about.jsx" \
    "s/(Card title=\"What's new in )[^\"]+(\")/\${1}$VERSION\${2}/" \
    'assets/src/views/about.jsx release heading'

# README.txt stable tag.
update_regex_file "$ROOT/README.txt" \
    "s/^(\s*Stable tag:\s*)[^\r\n]+/\${1}$VERSION/m" \
    'README.txt Stable tag'

# package.json first "version" field.
if [[ -f "$ROOT/package.json" ]]; then
    PACKAGE_BEFORE="$(cat "$ROOT/package.json")"
    perl -0777 -pi -e "my \$done = 0; s/(\"version\"\s*:\s*\")[^\"]+(\")/\$done++ ? \$& : \$1 . '$VERSION' . \$2/ge" "$ROOT/package.json"
    PACKAGE_AFTER="$(cat "$ROOT/package.json")"

    if [[ "$PACKAGE_BEFORE" != "$PACKAGE_AFTER" ]]; then
        echo 'Updated package.json'
    else
        echo "Already at $VERSION: package.json"
    fi
fi

# CHANGELOG.md section.
CHANGELOG="$ROOT/CHANGELOG.md"

if [[ -f "$CHANGELOG" ]]; then
    if grep -Eq "^##[[:space:]]+\[$(printf '%s' "$VERSION" | sed 's/[.[\*^$()+?{|]/\\&/g')\]" "$CHANGELOG"; then
        echo "Changelog already has $VERSION"
    else
        DATE="$(date +%Y-%m-%d)"
        ENTRY="## [$VERSION] - $DATE

### Changed
- $NOTE
"
        KHSE_ENTRY="$ENTRY" perl -0777 -pi -e '
            my $entry = $ENV{KHSE_ENTRY} . "\n";
            unless (s/(\A#\s+Changelog\s*\r?\n\r?\n)/$1$entry/) {
                $_ = "# Changelog\n\n" . $entry . $_;
            }
        ' "$CHANGELOG"
        echo "Added CHANGELOG.md section for $VERSION"
    fi
fi

# WordPress.org readme changelog entry.
README_FILE="$ROOT/README.txt"

if [[ -f "$README_FILE" ]]; then
    if grep -Eq "^=[[:space:]]*$VERSION[[:space:]]*\(" "$README_FILE"; then
        echo "README.txt changelog already has $VERSION"
    else
        README_MONTH="$(date +%m)"
        README_DAY="$(date +%d)"
        README_YEAR="$(date +%Y)"
        README_DATE="$((10#$README_MONTH))/$((10#$README_DAY))/$README_YEAR"
        README_ENTRY="= $VERSION ($README_DATE) =

* $NOTE

"
        KHSE_README_ENTRY="$README_ENTRY" perl -0777 -pi -e '
            my $entry = $ENV{KHSE_README_ENTRY};
            unless (s/(== Changelog ==\r?\n\r?\n)/$1$entry/) {
                $_ .= "\n== Changelog ==\n\n" . $entry;
            }
        ' "$README_FILE"
        echo "Added README.txt changelog section for $VERSION"
    fi
fi

BUILD_ARGS=(--version "$VERSION" --set-version --out-dir "$OUT_DIR")

[[ $INCLUDE_TESTS -eq 1 ]] && BUILD_ARGS+=(--include-tests)
[[ $KEEP_STAGE -eq 1 ]] && BUILD_ARGS+=(--keep-stage)
[[ $SKIP_BUN_BUILD -eq 1 ]] && BUILD_ARGS+=(--skip-bun-build)

bash "$BUILD_SCRIPT" "${BUILD_ARGS[@]}"
