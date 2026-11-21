param(
    [string]$ProjectDir = ""
)
if (-not $ProjectDir) {
    $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
    $ProjectDir = Resolve-Path (Join-Path $scriptDir "..")
}
Set-Location $ProjectDir
$python = ".\.venv\Scripts\python.exe"
if (-not (Test-Path $python)) {
    $python = "python"
}
Write-Host "== System =="
Write-Host "OS: $([System.Environment]::OSVersion.VersionString)"
Write-Host "Architecture: $env:PROCESSOR_ARCHITECTURE"
Write-Host "`n== Python =="
& $python --version
& $python -m pip --version
Write-Host "`n== Imports =="
$modules = @("tkinter", "serial", "PIL", "matplotlib", "requests", "cv2", "qrcode")
foreach ($module in $modules) {
    & $python -B -c "import $module; print('$module OK')" 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "$module FAIL"
    }
}
Write-Host "`n== Serial ports =="
& $python -B -c "from serial.tools import list_ports; print('\n'.join(p.device for p in list_ports.comports()) or 'not found')" 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "pyserial list_ports unavailable"
}
Write-Host "`n== Project files =="
Write-Host "main.py: $(Test-Path main.py)"
Write-Host "settings.json: $(Test-Path settings.json)"
Write-Host "fitodomik.ino: $(Test-Path fitodomik.ino)"
Write-Host "libraries: $(Test-Path libraries)"
Write-Host "`n== Camera =="
& $python -B -c "import cv2; cap=cv2.VideoCapture(0); ok, frame=cap.read(); cap.release(); print(bool(ok))"
Write-Host "`n== Site =="
try {
    $response = Invoke-WebRequest -Uri "https://fitodomik.ru" -Method Head -TimeoutSec 10
    Write-Host "fitodomik.ru: $($response.StatusCode)"
} catch {
    Write-Host "fitodomik.ru unavailable: $($_.Exception.Message)"
}