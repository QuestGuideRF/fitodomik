param(
    [string]$InstallDir = "$env:USERPROFILE\FitoDomik"
)
$ErrorActionPreference = "Stop"
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectDir = Resolve-Path (Join-Path $scriptDir "..")
if (-not (Test-Path (Join-Path $projectDir "main.py"))) {
    $projectDir = $scriptDir
}
Write-Host "FitoDomik Windows installer"
Write-Host "Source: $projectDir"
Write-Host "Target: $InstallDir"
if (-not (Test-Path $InstallDir)) {
    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
}
$hasProject = Test-Path (Join-Path $InstallDir "main.py")
$existingEntries = @(Get-ChildItem -LiteralPath $InstallDir -Force -ErrorAction SilentlyContinue)
if (($existingEntries.Count -gt 0) -and (-not $hasProject)) {
    $backup = "$InstallDir.backup.$(Get-Date -Format 'yyyyMMdd_HHmmss')"
    Move-Item -LiteralPath $InstallDir -Destination $backup
    Write-Host "Existing non-project folder backed up: $backup"
    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
}
if (-not $hasProject) {
    Get-ChildItem -LiteralPath $projectDir -Force |
        Where-Object { $_.Name -notin @(".git", ".venv", "__pycache__") } |
        Copy-Item -Destination $InstallDir -Recurse -Force
}
Set-Location $InstallDir
if (-not (Test-Path ".venv\Scripts\python.exe")) {
    python -m venv .venv
}
.\.venv\Scripts\python.exe -m pip install --upgrade pip setuptools wheel
if (Test-Path "requirements.txt") {
    $filteredRequirements = ".fitodomik_requirements_install.txt"
    Get-Content "requirements.txt" |
        Where-Object { $_ -notmatch '^\s*arduino-cli([<=>~! ].*)?$' } |
        Set-Content -LiteralPath $filteredRequirements -Encoding UTF8
    .\.venv\Scripts\python.exe -m pip install -r $filteredRequirements
} else {
    .\.venv\Scripts\python.exe -m pip install pyserial Pillow matplotlib numpy requests opencv-python urllib3 "qrcode[pil]"
}
if (-not (Get-Command arduino-cli -ErrorAction SilentlyContinue)) {
    Write-Warning "arduino-cli>=1.4.1 не найден в PATH. Установите Arduino CLI отдельно для сборки и загрузки прошивки."
} else {
    arduino-cli version
}
.\.venv\Scripts\python.exe -m compileall -q main.py fitodomik_app
Write-Host "Done. Run: $InstallDir\installer\run.bat"