# Create WordPress Plugin ZIP with UNIX-compatible paths
# This script creates a production-ready ZIP file for WordPress plugin distribution

$ErrorActionPreference = "Stop"

# Get plugin info from current directory
$pluginDir = Get-Location
$pluginName = Split-Path -Leaf $pluginDir

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

# Patterns to exclude
$excludePatterns = @(
    "^\.",               # Root files/folders starting with '.'
    "(^|[\\/])\.",       # Hidden files/folders anywhere in path (covers .git, .claude, .vscode, .gitignore)
    "^node_modules",
    "^src-",             # All folders starting with 'src-' (covers src-svelte)
    "(^|[\\/])svelte-",  # All folders starting with 'svelte-' anywhere in path (covers lib/wpea/svelte-*)
    "^plugin[\\/]",
    "^marketing-docs[\\/]",  # Marketing materials folder
    "\.md$",             # All markdown files (covers CLAUDE.md, CHANGELOG.md, CHANGELOG_USER.md, CHANGELOG_VERSION.md, etc.)
    "^CHANGELOG_(USER|VERSION)\.html$",  # User-facing changelog HTML companions — meant for external web-page consumption, not the plugin ZIP
    "\.log$",
    "^vite\.config",
    "^tsconfig",
    "^svelte\.config",
    "^package\.json$",
    "^package-lock\.json$",
    "^composer\.json$",
    "^composer\.lock$",
    "^phpcs\.xml",
    "^phpunit\.xml",
    "^create-plugin-zip\.ps1$",
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
