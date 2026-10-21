@echo off
REM Windows XAMPP / Command Prompt migration runner for WMSv2
REM Automatically locates php.exe on ANY drive (e.g. D:\xampp\htdocs\workshop -> D:\xampp\php\php.exe,
REM %XAMPP_ROOT%\php\php.exe, C:\xampp\php\php.exe, D:\xampp\php\php.exe, or PATH).
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

"%PHP_BIN%" "%ROOT_DIR%\scripts\migrate.php" %*
exit /b %ERRORLEVEL%
