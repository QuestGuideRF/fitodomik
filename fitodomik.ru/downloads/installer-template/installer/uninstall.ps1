param(
    [string]$ProjectDir = ""
)
if (-not $ProjectDir) {
    $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
    $ProjectDir = Resolve-Path (Join-Path $scriptDir "..")
}
$startup = Join-Path $env:APPDATA "Microsoft\Windows\Start Menu\Programs\Startup\FitoDomik.cmd"
$desktop = Join-Path $env:USERPROFILE "Desktop\FitoDomik.cmd"
if (Test-Path $startup) {
    Remove-Item -LiteralPath $startup -Force
    Write-Host "Autostart removed: $startup"
}
if (Test-Path $desktop) {
    Remove-Item -LiteralPath $desktop -Force
    Write-Host "Desktop shortcut removed: $desktop"
}
$backupAnswer = Read-Host "Save backup of settings.json, logs and analysis_results? [Y/n]"
if ($backupAnswer -notmatch "^[nN]") {
    $backup = Join-Path $env:USERPROFILE ("FitoDomik_backup_" + (Get-Date -Format "yyyyMMdd_HHmmss"))
    New-Item -ItemType Directory -Force -Path $backup | Out-Null
    foreach ($name in @("settings.json", "logs", "analysis_results")) {
        $path = Join-Path $ProjectDir $name
        if (Test-Path $path) {
            Copy-Item -LiteralPath $path -Destination $backup -Recurse -Force
        }
    }
    Write-Host "Backup saved: $backup"
}
$removeAnswer = Read-Host "Remove project folder $ProjectDir ? [y/N]"
if ($removeAnswer -match "^[yY]") {
    Remove-Item -LiteralPath $ProjectDir -Recurse -Force
    Write-Host "Project folder removed."
} else {
    Write-Host "Project folder kept: $ProjectDir"
}