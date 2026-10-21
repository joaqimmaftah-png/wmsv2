@echo off
REM Windows XAMPP / Command Prompt migration runner for WMSv2
REM Automatically locates PHP in C:\xampp\php\php.exe (or PATH) and runs pending migrations.
setlocal

set "ROOT_DIR=%~dp0.."
set "PHP_BIN=php"

if exist "C:\xampp\php\php.exe" (
    set "PHP_BIN=C:\xampp\php\php.exe"
) else if defined XAMPP_ROOT (
    if exist "%XAMPP_ROOT%\php\php.exe" set "PHP_BIN=%XAMPP_ROOT%\php\php.exe"
)

"%PHP_BIN%" "%ROOT_DIR%\scripts\migrate.php" %*
exit /b %ERRORLEVEL%
