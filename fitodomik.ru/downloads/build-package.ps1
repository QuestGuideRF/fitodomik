param(
    [string]$SourceDir = "",
    [string]$TemplateDir = "",
    [string]$PackageDir = "",
    [string]$ZipPath = "",
    [string]$Version = "1.0.1",
    [string[]]$Changelog = @()
)
$ErrorActionPreference = "Stop"
if (-not $SourceDir) {
    $SourceDir = Join-Path $PSScriptRoot "..\..\fitodomik"
}
if (-not $TemplateDir) {
    $TemplateDir = Join-Path $PSScriptRoot "installer-template"
}
if (-not $PackageDir) {
    $PackageDir = Join-Path $PSScriptRoot "fitodomik-desktop"
}
if (-not $ZipPath) {
    $ZipPath = Join-Path $PSScriptRoot "fitodomik-desktop.zip"
}
$SourceDir = (Resolve-Path $SourceDir).Path
$TemplateDir = (Resolve-Path $TemplateDir).Path
$DownloadsRoot = [System.IO.Path]::GetFullPath($PSScriptRoot).TrimEnd('\')
$PackageDir = [System.IO.Path]::GetFullPath($PackageDir).TrimEnd('\')
$ZipPath = [System.IO.Path]::GetFullPath($ZipPath)
if (-not $PackageDir.StartsWith($DownloadsRoot + '\', [System.StringComparison]::OrdinalIgnoreCase) -or
    -not $ZipPath.StartsWith($DownloadsRoot + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Package and ZIP targets must be inside the downloads directory'
}
if (Test-Path $PackageDir) {
    Remove-Item -LiteralPath $PackageDir -Recurse -Force
}
New-Item -ItemType Directory -Force -Path $PackageDir | Out-Null
$ExcludedSourceDirs = @(
    ".git",
    ".venv",
    ".ruff_cache",
    "__pycache__",
    "logs",
    "analysis_results",
    "diagnostics_reports",
    "data",
    "photos",
    "updates",
    (Join-Path $SourceDir "firmware_backups"),
    (Join-Path $SourceDir "arduino_user_libraries"),
    (Join-Path $SourceDir "sensor_profiles")
)
robocopy $SourceDir $PackageDir /E /XD $ExcludedSourceDirs /XF settings.json *.pyc *.prof *.log .env .env.* *.bak *.backup | Out-Null
if ($LASTEXITCODE -gt 7) {
    throw "robocopy failed with code $LASTEXITCODE"
}
$WorkspaceReadme = Join-Path (Split-Path -Parent $SourceDir) "README.md"
if ((Test-Path $WorkspaceReadme) -and -not (Test-Path (Join-Path $PackageDir "README.md"))) {
    Copy-Item -LiteralPath $WorkspaceReadme -Destination (Join-Path $PackageDir "README.md") -Force
}
robocopy $TemplateDir $PackageDir /E /XD __pycache__ /XF *.pyc | Out-Null
if ($LASTEXITCODE -gt 7) {
    throw "robocopy template failed with code $LASTEXITCODE"
}
if (Test-Path $ZipPath) {
    Remove-Item -LiteralPath $ZipPath -Force
}
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($ZipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $basePath = (Resolve-Path $PackageDir).Path
    if (-not $basePath.EndsWith([System.IO.Path]::DirectorySeparatorChar)) {
        $basePath += [System.IO.Path]::DirectorySeparatorChar
    }
    Get-ChildItem -LiteralPath $PackageDir -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($basePath.Length).Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip,
            $_.FullName,
            $relative,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
} finally {
    $zip.Dispose()
}
& python (Join-Path $PSScriptRoot 'verify-package.py') $ZipPath
if ($LASTEXITCODE -ne 0) { throw 'Package verification failed; release metadata not updated' }
$hash = Get-FileHash -LiteralPath $ZipPath -Algorithm SHA256
"$($hash.Hash.ToLowerInvariant())  fitodomik-desktop.zip" |
    Set-Content -LiteralPath "$ZipPath.sha256" -Encoding ascii
if (-not $Changelog -or $Changelog.Count -eq 0) {
    $Changelog = @(
        "Explicit relay SET/ACK and pump fault handling",
        "Durable device-scoped queues and command delivery journal"
    )
}
$latest = [ordered]@{
    version = $Version
    date = (Get-Date).ToString("yyyy-MM-dd")
    package_url = "https://fitodomik.ru/downloads/fitodomik-desktop.zip"
    sha256_url = "https://fitodomik.ru/downloads/fitodomik-desktop.zip.sha256"
    changelog = $Changelog
    min_supported_version = "1.0.1"
}
$latestPath = Join-Path (Split-Path -Parent $ZipPath) "latest.json"
$latest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $latestPath -Encoding UTF8
Write-Host "Package: $ZipPath"
Write-Host "SHA256: $ZipPath.sha256"
Write-Host "Latest: $latestPath"
