@echo off
REM Windows XAMPP wrapper for scripts\recover-workshop-data.php (auto-detects php.exe on C:\, D:\, etc.)
setlocal

set "ROOT_DIR=%~dp0.."
set "PHP_BIN=php"

if exist "%~dp0..\..\..\php\php.exe" (
    set "PHP_BIN=%~dp0..\..\..\php\php.exe"
) else if defined XAMPP_ROOT (
    if exist "%XAMPP_ROOT%\php\php.exe" set "PHP_BIN=%XAMPP_ROOT%\php\php.exe"
) else if exist "C:\xampp\php\php.exe" (
    set "PHP_BIN=C:\xampp\php\php.exe"
) else if exist "D:\xampp\php\php.exe" (
    set "PHP_BIN=D:\xampp\php\php.exe"
) else if exist "E:\xampp\php\php.exe" (
    set "PHP_BIN=E:\xampp\php\php.exe"
)

"%PHP_BIN%" "%ROOT_DIR%\scripts\recover-workshop-data.php" %*
exit /b %ERRORLEVEL%
