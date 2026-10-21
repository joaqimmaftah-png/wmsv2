@echo off
REM Double-clickable Windows XAMPP Pull + Migrate + Verify script
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy-xampp.ps1" %*
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo [ERROR] Deployment failed with code %ERRORLEVEL%.
    pause
    exit /b %ERRORLEVEL%
)
