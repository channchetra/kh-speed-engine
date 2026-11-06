<#
.SYNOPSIS
One-command KH Speed Engine version sync and release build.

.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\bin\release.ps1

.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\bin\release.ps1 -Bump minor

.EXAMPLE
powershell -ExecutionPolicy Bypass -File .\bin\release.ps1 -Version 3.1.0 -Note "Release dashboard polish."
#>

[CmdletBinding()]
param(
    [string] $Version = '',
    [ValidateSet('major', 'minor', 'patch')]
    [string] $Bump = 'patch',
    [string] $Note = 'Release build.',
    [string] $OutDir = 'dist',
    [switch] $IncludeTests,
    [switch] $KeepStage,
    [switch] $SkipBunBuild
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$mainFile = Join-Path $root 'kh-speed-engine.php'
$buildScript = Join-Path $PSScriptRoot 'build-release.ps1'

function Write-Utf8NoBom {
    param(
        [string] $Path,
        [string] $Value
    )

    $encoding = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText([System.IO.Path]::GetFullPath($Path), $Value, $encoding)
}

function Get-Plugin-Version {
    param([string] $Path)

    $content = Get-Content -LiteralPath $Path -Raw

    if ($content -notmatch '(?m)^\s*\*\s*Version:\s*([^\r\n]+)\s*$') {
        throw "Cannot read plugin Version from $Path"
    }

    return $Matches[1].Trim()
}

function Bump-Version {
    param(
        [string] $VersionString,
        [string] $Component
    )

    $parts = $VersionString -split '\.'

    if ($parts.Count -lt 3) {
        throw "Version '$VersionString' must use MAJOR.MINOR.PATCH for automatic bumping."
    }

    $major = [int] $parts[0]
    $minor = [int] $parts[1]
    $patch = [int] $parts[2]

    switch ($Component) {
        'major' {
            $major++
            $minor = 0
            $patch = 0
        }
        'minor' {
            $minor++
            $patch = 0
        }
        'patch' {
            $patch++
        }
    }

    return "$major.$minor.$patch"
}

function Update-RegexFile {
    param(
        [string] $Path,
        [string] $Pattern,
        [scriptblock] $Replace,
        [string] $Label
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return
    }

    $content = Get-Content -LiteralPath $Path -Raw
    $regex = [regex] $Pattern

    if (-not $regex.IsMatch($content)) {
        Write-Warning "No version match in $Label ($Path)"
        return
    }

    $updated = $regex.Replace(
        $content,
        [System.Text.RegularExpressions.MatchEvaluator] {
            param($match)
            & $Replace $match
        }
    )

    if ($updated -ne $content) {
        Write-Utf8NoBom -Path $Path -Value $updated
        Write-Host "Updated $Label"
    }
}

function Update-FirstVersionFields {
    param(
        [string] $Path,
        [int] $Count,
        [string] $Version
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return
    }

    $content = Get-Content -LiteralPath $Path -Raw
    $seen = [ref] 0
    $updated = [regex]::Replace(
        $content,
        '("version"\s*:\s*")[^"]+(")',
        [System.Text.RegularExpressions.MatchEvaluator] {
            param($match)
            $seen.Value++
            if ($seen.Value -le $Count) {
                return $match.Groups[1].Value + $Version + $match.Groups[2].Value
            }

            return $match.Value
        }
    )

    if ($updated -ne $content) {
        Write-Utf8NoBom -Path $Path -Value $updated
        Write-Host "Updated $(Split-Path -Leaf $Path)"
    }
}

function Ensure-ChangelogSection {
    param(
        [string] $Path,
        [string] $Version,
        [string] $Note
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return
    }

    $content = Get-Content -LiteralPath $Path -Raw

    if ($content -match "(?m)^##\s+\[$([regex]::Escape($Version))\]") {
        Write-Host "Changelog already has $Version"
        return
    }

    $date = Get-Date -Format 'yyyy-MM-dd'
    $entry = "## [$Version] - $date`r`n`r`n### Changed`r`n- $Note`r`n`r`n"
    $heading = [regex] '(^#\s+Changelog\s*\r?\n\r?\n)'
    $updated = $heading.Replace(
        $content,
        [System.Text.RegularExpressions.MatchEvaluator] {
            param($match)
            return $match.Groups[1].Value + $entry
        },
        1
    )

    if ($updated -eq $content) {
        $updated = "# Changelog`r`n`r`n$entry$content"
    }

    Write-Utf8NoBom -Path $Path -Value $updated
    Write-Host "Added CHANGELOG.md section for $Version"
}

function Ensure-ReadmeChangelogSection {
    param(
        [string] $Path,
        [string] $Version,
        [string] $Note
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return
    }

    $content = Get-Content -LiteralPath $Path -Raw

    if ($content -match "(?m)^=\s*$([regex]::Escape($Version))\s*\(") {
        Write-Host "README.txt changelog already has $Version"
        return
    }

    $date = Get-Date -Format 'M/d/yyyy'
    $entry = "= $Version ($date) =`r`n`r`n* $Note`r`n`r`n"
    $heading = [regex] '(?m)^(== Changelog ==\r?\n\r?\n)'
    $updated = $heading.Replace(
        $content,
        [System.Text.RegularExpressions.MatchEvaluator] {
            param($match)
            return $match.Groups[1].Value + $entry
        },
        1
    )

    if ($updated -eq $content) {
        $updated = $content + "`r`n== Changelog ==`r`n`r`n$entry"
    }

    Write-Utf8NoBom -Path $Path -Value $updated
    Write-Host "Added README.txt changelog section for $Version"
}

if (-not (Test-Path -LiteralPath $mainFile)) {
    throw "Cannot find kh-speed-engine.php at $mainFile"
}

$currentVersion = Get-Plugin-Version -Path $mainFile

if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = Bump-Version -VersionString $currentVersion -Component $Bump
    Write-Host "Bumped $Bump : $currentVersion -> $Version"
} else {
    Write-Host "Using explicit version: $Version"
}

if ($Version -notmatch '^[0-9A-Za-z._-]+$') {
    throw "Invalid version '$Version'. Use numbers, letters, dot, underscore, or dash."
}

Update-RegexFile `
    -Path $mainFile `
    -Pattern '(?m)^(\s*\*\s*Version:\s*)[^\r\n]+(\s*)$' `
    -Label 'kh-speed-engine.php plugin header' `
    -Replace { param($match) $match.Groups[1].Value + $Version + $match.Groups[2].Value }

Update-RegexFile `
    -Path $mainFile `
    -Pattern "(?m)^(\s*define\(\s*'KHSE_PLUGIN_VERSION'\s*,\s*')[^']+('\s*\);\s*)$" `
    -Label 'KHSE_PLUGIN_VERSION' `
    -Replace { param($match) $match.Groups[1].Value + $Version + $match.Groups[2].Value }

Update-RegexFile `
    -Path (Join-Path $root 'inc/expert-mode.php') `
    -Pattern "(?m)^(\s*[$]version\s*=\s*')[^']+(';)" `
    -Label 'inc/expert-mode.php fallback version' `
    -Replace { param($match) $match.Groups[1].Value + $Version + $match.Groups[2].Value }

Update-RegexFile `
    -Path (Join-Path $root 'assets/src/views/about.jsx') `
    -Pattern "(?m)^(const VERSION = window\.khseDashboard\?\.version \|\| ')[^']+(';)" `
    -Label 'assets/src/views/about.jsx version' `
    -Replace { param($match) $match.Groups[1].Value + $Version + $match.Groups[2].Value }

Update-RegexFile `
    -Path (Join-Path $root 'assets/src/views/about.jsx') `
    -Pattern '(?m)^(\s*<Card title="What''s new in )[^"]+(")' `
    -Label 'assets/src/views/about.jsx release heading' `
    -Replace { param($match) $match.Groups[1].Value + $Version + $match.Groups[2].Value }

Update-RegexFile `
    -Path (Join-Path $root 'README.txt') `
    -Pattern '(?m)^(\s*Stable tag:\s*)[^\r\n]+(\s*)$' `
    -Label 'README.txt Stable tag' `
    -Replace { param($match) $match.Groups[1].Value + $Version + $match.Groups[2].Value }

Update-FirstVersionFields -Path (Join-Path $root 'package.json') -Count 1 -Version $Version

Ensure-ChangelogSection -Path (Join-Path $root 'CHANGELOG.md') -Version $Version -Note $Note
Ensure-ReadmeChangelogSection -Path (Join-Path $root 'README.txt') -Version $Version -Note $Note

$buildArgs = @(
    '-ExecutionPolicy', 'Bypass',
    '-File', $buildScript,
    '-Version', $Version,
    '-SetVersion',
    '-OutDir', $OutDir
)

if ($IncludeTests) {
    $buildArgs += '-IncludeTests'
}

if ($KeepStage) {
    $buildArgs += '-KeepStage'
}

if ($SkipBunBuild) {
    $buildArgs += '-SkipBunBuild'
}

$shell = if (Get-Command pwsh -ErrorAction SilentlyContinue) { 'pwsh' } else { 'powershell' }
& $shell @buildArgs

if ($LASTEXITCODE -ne 0) {
    throw "build-release.ps1 failed with exit code $LASTEXITCODE."
}
