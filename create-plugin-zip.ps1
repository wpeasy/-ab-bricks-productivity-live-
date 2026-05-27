# Create WordPress Plugin ZIP with UNIX-compatible paths.
#
# Adapted from the parent plugin's (ab-bricks-productivity) script. BRXProd
# Live is pure PHP with a Composer autoloader — no Vite, no Svelte, no
# node_modules — so the file set is much smaller. The CRITICAL difference
# vs the parent is the composer-install step BEFORE zipping: /vendor/ is
# gitignored, so a fresh git clone has no autoloader and the whole plugin
# silently fails on activation. This script regenerates a production
# vendor folder every run.

$ErrorActionPreference = "Stop"

# Get plugin info from current directory
$pluginDir = Get-Location
$pluginName = Split-Path -Leaf $pluginDir

# ---------------------------------------------------------------------
# Step 1 — refresh /vendor/ with production-only dependencies + an
# optimized autoload. Without this, the ZIP would either include a stale
# dev vendor or no vendor at all (when run on a fresh clone). Skipping
# this is the #1 cause of "plugin appears active but does nothing" — the
# class autoloader never registers, so SnippetLoader / RestController /
# the admin page are all unreachable.
# ---------------------------------------------------------------------

$composer = Get-Command composer -ErrorAction SilentlyContinue
if (-not $composer) {
    Write-Host "ERROR: 'composer' not found on PATH. Install Composer and retry." -ForegroundColor Red
    exit 1
}

if (Test-Path (Join-Path $pluginDir "composer.json")) {
    Write-Host "Running composer install --no-dev --optimize-autoloader..." -ForegroundColor Cyan
    & composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | ForEach-Object { Write-Host "  $_" }
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: composer install failed (exit $LASTEXITCODE). Aborting ZIP." -ForegroundColor Red
        exit 1
    }
} else {
    Write-Host "No composer.json found — skipping vendor refresh." -ForegroundColor Yellow
}

# Get version from main plugin file
$mainPluginFile = Get-ChildItem -Path $pluginDir -Filter "*.php" | Where-Object {
    $content = Get-Content $_.FullName -Raw -ErrorAction SilentlyContinue
    $content -match "Plugin Name:"
} | Select-Object -First 1

if ($mainPluginFile) {
    $content = Get-Content $mainPluginFile.FullName -Raw
    if ($content -match "Version:\s*([\d.][\d.a-zA-Z-]*)") {
        $version = $matches[1]
    } else {
        $version = "1.0.0"
    }
} else {
    $version = "1.0.0"
}

# Create plugin subfolder if needed
$outputDir = Join-Path $pluginDir "plugin"
if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

# Remove old ZIP files
Get-ChildItem -Path $outputDir -Filter "*.zip" | Remove-Item -Force

# ZIP file path
$zipName = "$pluginName-$version.zip"
$zipPath = Join-Path $outputDir $zipName

Write-Host "Creating $zipName..." -ForegroundColor Cyan

# Patterns to exclude. CRITICALLY: /vendor/ is NOT excluded — it's the
# whole reason for the composer install step above.
$excludePatterns = @(
    "^\.",                                   # Root dot-files / dot-folders
    "(^|[\\/])\.",                           # Hidden files/folders anywhere (.git, .claude, .vscode, .gitignore)
    "^plugin[\\/]",                          # ZIP output dir
    "\.md$",                                 # All markdown docs (CLAUDE.md, README.md, CODE_STANDARDS.md, CHANGELOG.md)
    "^CHANGELOG_(USER|VERSION)\.html$",      # User-facing changelog HTML companions
    "\.log$",
    "^composer\.json$",                      # composer.json/lock not needed at runtime
    "^composer\.lock$",
    "^phpcs\.xml",
    "^phpunit\.xml",
    "^create-plugin-zip\.ps1$",              # This script itself
    "^(con|prn|aux|nul|com[0-9]|lpt[0-9])$"  # Windows reserved device names
)

# Get all files, excluding patterns
$files = Get-ChildItem -Path $pluginDir -Recurse -File | Where-Object {
    $relativePath = $_.FullName.Substring($pluginDir.Path.Length + 1)
    $exclude = $false
    foreach ($pattern in $excludePatterns) {
        if ($relativePath -match $pattern) {
            $exclude = $true
            break
        }
    }
    -not $exclude
}

# Create ZIP using .NET with forward slashes
Add-Type -AssemblyName System.IO.Compression.FileSystem

# Remove existing zip if exists
if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

# Create new ZIP archive
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')

foreach ($file in $files) {
    $relativePath = $file.FullName.Substring($pluginDir.Path.Length + 1)
    # Convert to forward slashes and add plugin folder prefix
    $entryPath = "$pluginName/" + ($relativePath -replace '\\', '/')

    try {
        # Use FileStream to avoid device name interpretation issues
        $fileStream = [System.IO.File]::OpenRead($file.FullName)
        $entry = $zip.CreateEntry($entryPath, [System.IO.Compression.CompressionLevel]::Optimal)
        $entryStream = $entry.Open()
        $fileStream.CopyTo($entryStream)
        $entryStream.Close()
        $fileStream.Close()
    } catch {
        Write-Host "Warning: Skipped $relativePath - $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

$zip.Dispose()

$zipSize = [math]::Round((Get-Item $zipPath).Length / 1KB, 2)
Write-Host "Created: plugin/$zipName ($zipSize KB)" -ForegroundColor Green
