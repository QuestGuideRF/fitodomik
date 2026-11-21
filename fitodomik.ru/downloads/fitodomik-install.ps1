param(
    [string]$SiteBaseUrl = "https://fitodomik.ru",
    [string]$InstallDir = "$env:USERPROFILE\FitoDomik"
)
$ErrorActionPreference = "Stop"
$PackageUrl = "$SiteBaseUrl/downloads/fitodomik-desktop.zip"
$TempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("fitodomik_bootstrap_" + [System.Guid]::NewGuid().ToString("N"))
$BootstrapTempBase = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\')
$TempRoot = [System.IO.Path]::GetFullPath($TempRoot)
if (-not $TempRoot.StartsWith($BootstrapTempBase + '\', [System.StringComparison]::OrdinalIgnoreCase) -or
    [System.IO.Path]::GetFileName($TempRoot) -notmatch '^fitodomik_bootstrap_[a-f0-9]{32}$') {
    throw 'Unsafe bootstrap temporary directory'
}
$PackagePath = Join-Path $TempRoot "fitodomik-desktop.zip"
$ExtractDir = Join-Path $TempRoot "extracted"
function Write-Step($Message) {
    Write-Host ("[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message)
}
function Find-Python {
    $candidates = @()
    $pythonCommand = Get-Command python -ErrorAction SilentlyContinue
    if ($pythonCommand) { $candidates += $pythonCommand.Source }
    $pyCommand = Get-Command py -ErrorAction SilentlyContinue
    if ($pyCommand) { $candidates += $pyCommand.Source }
    $candidates += @(
        "$env:LOCALAPPDATA\Programs\Python\Python312\python.exe",
        "$env:LOCALAPPDATA\Programs\Python\Python311\python.exe",
        "$env:ProgramFiles\Python312\python.exe",
        "$env:ProgramFiles\Python311\python.exe"
    )
    foreach ($candidate in $candidates | Where-Object { $_ } | Select-Object -Unique) {
        try {
            if (($candidate -eq "py") -or (Test-Path $candidate) -or (Get-Command $candidate -ErrorAction SilentlyContinue)) {
                & $candidate --version *> $null
                if ($LASTEXITCODE -eq 0) {
                    return $candidate
                }
            }
        } catch {
        }
    }
    return $null
}
function Ensure-Python {
    $python = Find-Python
    if ($python) {
        Write-Step "Python found: $python"
        return $python
    }
    $winget = Get-Command winget -ErrorAction SilentlyContinue
    if (-not $winget) {
        throw "Python is not installed and winget is unavailable. Install Python 3 and run this command again."
    }
    Write-Step "Python not found, installing Python 3.12 via winget"
    & $winget.Source install --id Python.Python.3.12 -e --source winget --accept-package-agreements --accept-source-agreements
    if ($LASTEXITCODE -ne 0) {
        throw "winget failed to install Python"
    }
    $python = Find-Python
    if (-not $python) {
        throw "Python was installed, but python.exe was not found in the current session. Reopen PowerShell and run the command again."
    }
    return $python
}
try {
    New-Item -ItemType Directory -Force -Path $TempRoot, $ExtractDir | Out-Null
    Write-Host "FitoDomik Windows bootstrap installer"
    Write-Host "Package: $PackageUrl"
    Write-Host "Install dir: $InstallDir"
    Write-Step "Downloading package"
    Invoke-WebRequest -Uri $PackageUrl -OutFile $PackagePath -UseBasicParsing
    $HashPath = Join-Path $TempRoot "fitodomik-desktop.zip.sha256"
    $HashDownloaded = $false
    try {
        Invoke-WebRequest -Uri "$PackageUrl.sha256" -OutFile $HashPath -UseBasicParsing
        $HashDownloaded = $true
    } catch {
        throw "SHA256 file unavailable: installation rejected"
    }
    if ($HashDownloaded) {
        $ExpectedHash = ((Get-Content -LiteralPath $HashPath -Raw).Trim() -split "\s+")[0].ToUpperInvariant()
        if ($ExpectedHash -notmatch '^[A-F0-9]{64}$') { throw "Invalid SHA256" }
        $ActualHash = (Get-FileHash -LiteralPath $PackagePath -Algorithm SHA256).Hash.ToUpperInvariant()
        if ($ExpectedHash -ne $ActualHash) {
            throw "SHA256 mismatch"
        }
        Write-Step "SHA256 verified"
    }
    Write-Step "Extracting package"
    Expand-Archive -LiteralPath $PackagePath -DestinationPath $ExtractDir -Force
    $MainFile = Get-ChildItem -LiteralPath $ExtractDir -Recurse -Filter "main.py" | Select-Object -First 1
    if (-not $MainFile) {
        throw "main.py not found in package"
    }
    $SourceDir = $MainFile.Directory.FullName
    if (Test-Path $InstallDir) {
        $existing = @(Get-ChildItem -LiteralPath $InstallDir -Force -ErrorAction SilentlyContinue)
        if ($existing.Count -gt 0) {
            Write-Host ""
            Write-Host "Folder already exists: $InstallDir"
            Write-Host "1. Update files and keep user data"
            Write-Host "2. Backup folder and install from scratch"
            Write-Host "3. Cancel"
            $choice = Read-Host "Choose [1/2/3]"
            if (-not $choice) { $choice = "1" }
            switch ($choice) {
                "1" { Write-Step "Updating files without deleting user data" }
                "2" {
                    $backup = "$InstallDir.backup.$(Get-Date -Format 'yyyyMMdd_HHmmss')"
                    Move-Item -LiteralPath $InstallDir -Destination $backup
                    Write-Step "Backup saved: $backup"
                    New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
                }
                default { throw "Installation cancelled" }
            }
        }
    } else {
        New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
    }
    Write-Step "Copying project files"
    Get-ChildItem -LiteralPath $SourceDir -Force |
        Where-Object { $_.Name -notin @(".git", ".venv", "__pycache__", "settings.json", "logs", "analysis_results") } |
        Copy-Item -Destination $InstallDir -Recurse -Force
    Set-Location $InstallDir
    $PythonCommand = Ensure-Python
    if (Test-Path "installer\installer.py") {
        Write-Step "Starting setup wizard"
        & $PythonCommand installer\installer.py
    } elseif (Test-Path "installer.py") {
        Write-Step "Starting setup wizard"
        & $PythonCommand installer.py
    } else {
        throw "installer.py not found"
    }
} finally {
    if (Test-Path $TempRoot) {
        Remove-Item -LiteralPath $TempRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
