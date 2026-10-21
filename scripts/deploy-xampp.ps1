<#
.SYNOPSIS
    Automated deployment, database migration & health check for XAMPP (Windows).

.DESCRIPTION
    Pulls the latest branch from GitHub, auto-detects XAMPP on any drive letter
    (e.g. D:\xampp\htdocs\workshop -> D:\xampp\php\php.exe & http://127.0.0.1/workshop/login),
    applies any pending database migrations in `database/migrations/`, runs unit
    & schema checks, and verifies that Apache + MySQL serve `/login` with HTTP 200.

.PARAMETER Branch
    Git branch to track and deploy (default: main).

.PARAMETER InstallScheduledTask
    Registers a background Windows Scheduled Task ("WMSv2-AutoDeploy") that polls
    GitHub every 5 minutes and automatically deploys new commits + migrations into
    XAMPP without requiring any inbound firewall ports or manual commands.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\deploy-xampp.ps1
    powershell -ExecutionPolicy Bypass -File scripts\deploy-xampp.ps1 -InstallScheduledTask
#>
param(
    [string]$Branch = "main",
    [string]$XamppDir = "",
    [string]$AppUrl = "",
    [switch]$InstallScheduledTask
)

$ErrorActionPreference = "Stop"
$RepoRoot   = Split-Path -Parent $PSScriptRoot
$ParentDir  = Split-Path -Parent $RepoRoot
$AppFolder  = Split-Path -Leaf $RepoRoot

if ([string]::IsNullOrWhiteSpace($XamppDir)) {
    $CandidateXampp = Split-Path -Parent $ParentDir
    if ($CandidateXampp -and (Test-Path (Join-Path $CandidateXampp "php\php.exe"))) {
        $XamppDir = $CandidateXampp
    } elseif ($env:XAMPP_ROOT -and (Test-Path (Join-Path $env:XAMPP_ROOT "php\php.exe"))) {
        $XamppDir = $env:XAMPP_ROOT
    } elseif (Test-Path "D:\xampp\php\php.exe") {
        $XamppDir = "D:\xampp"
    } else {
        $XamppDir = "C:\xampp"
    }
}

if ([string]::IsNullOrWhiteSpace($AppUrl)) {
    $AppUrl = "http://127.0.0.1/$AppFolder/login"
}

$PhpBin = if (Test-Path "$XamppDir\php\php.exe") { "$XamppDir\php\php.exe" } else { "php" }

if ($InstallScheduledTask) {
    $TaskName = "WMSv2-AutoDeploy"
    $ScriptPath = Join-Path $PSScriptRoot "deploy-xampp.ps1"
    $Action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$ScriptPath`" -Branch `"$Branch`" -XamppDir `"$XamppDir`" -AppUrl `"$AppUrl`""
    $Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 3650)
    Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Description "Auto-pulls latest WMSv2 commits from GitHub and runs pending DB migrations on XAMPP" -Force | Out-Null
    Write-Host "[OK] Scheduled task '$TaskName' installed (runs every 5 minutes)." -ForegroundColor Green
    exit 0
}

Push-Location $RepoRoot
try {
    Write-Host "==> XAMPP PHP : $PhpBin" -ForegroundColor Cyan
    Write-Host "==> Target URL: $AppUrl" -ForegroundColor Cyan
    Write-Host "==> Checking Git upstream ($Branch)..." -ForegroundColor Cyan
    git fetch origin $Branch --quiet

    $LocalRev  = (git rev-parse HEAD).Trim()
    $RemoteRev = (git rev-parse "origin/$Branch").Trim()

    if ($LocalRev -ne $RemoteRev) {
        Write-Host "==> Updating working tree: $($LocalRev.Substring(0,7)) -> $($RemoteRev.Substring(0,7))" -ForegroundColor Yellow
        git pull --ff-only origin $Branch
    } else {
        Write-Host "==> Repository already at latest commit ($($LocalRev.Substring(0,7)))." -ForegroundColor Green
    }

    # Ensure writable upload/log directories exist
    foreach ($dir in @("application\logs", "application\cache", "uploads\photos", "uploads\signatures", "uploads\wo_attachments", "uploads\order_docs", "uploads\tmp")) {
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    }

    Write-Host "==> Running database migrations & verification..." -ForegroundColor Cyan
    & $PhpBin "$RepoRoot\scripts\migrate.php"
    if ($LASTEXITCODE -ne 0) { throw "Database migration failed." }

    & $PhpBin "$RepoRoot\scripts\migrate.php" --verify
    if ($LASTEXITCODE -ne 0) { throw "Database schema verification failed." }

    Write-Host "==> Running unit test suite..." -ForegroundColor Cyan
    & $PhpBin "$RepoRoot\tests\run.php"
    if ($LASTEXITCODE -ne 0) { throw "Test suite failed." }

    try {
        $resp = Invoke-WebRequest -Uri $AppUrl -UseBasicParsing -TimeoutSec 10
        Write-Host "==> Live HTTP check ($AppUrl): HTTP $($resp.StatusCode)" -ForegroundColor Green
    } catch {
        Write-Warning "Could not reach $AppUrl (ensure Apache is started in XAMPP Control Panel): $_"
    }

    Write-Host "[DONE] WMSv2 XAMPP deployment & database sync succeeded." -ForegroundColor Green
} finally {
    Pop-Location
}
