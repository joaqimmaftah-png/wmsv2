<?php
/**
 * Installs local Git hooks (`post-merge` and `post-checkout`) so every `git pull`
 * on XAMPP (any drive: C:\xampp, D:\xampp, etc.), Linux, macOS, or a server
 * automatically applies pending database migrations (`scripts/migrate.php`)
 * and runs `tests/run.php`.
 *
 * Usage:
 *   php scripts/install-git-hooks.php
 */

if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] !== '') exit(1);

$rootDir  = realpath(__DIR__ . '/..');
$hooksDir = $rootDir . '/.git/hooks';
if (!is_dir($hooksDir)) {
    fwrite(STDERR, "ERROR: .git/hooks directory not found.\n");
    exit(1);
}

$installedPhp = str_replace('\\', '/', PHP_BINARY);

$hookBody = <<<SH
#!/bin/sh
# Auto-installed by scripts/install-git-hooks.php
set -e
REPO_ROOT="\$(git rev-parse --show-toplevel)"
PHP_BIN="php"
if [ -x "\$REPO_ROOT/../../php/php.exe" ]; then
    PHP_BIN="\$REPO_ROOT/../../php/php.exe"
elif [ -x "{$installedPhp}" ]; then
    PHP_BIN="{$installedPhp}"
elif [ -x "/c/xampp/php/php.exe" ]; then
    PHP_BIN="/c/xampp/php/php.exe"
elif [ -x "/d/xampp/php/php.exe" ]; then
    PHP_BIN="/d/xampp/php/php.exe"
elif [ -x "/opt/lampp/bin/php" ]; then
    PHP_BIN="/opt/lampp/bin/php"
fi

echo "[wmsv2 hook] Running pending database migrations..."
"\$PHP_BIN" "\$REPO_ROOT/scripts/migrate.php"
echo "[wmsv2 hook] Running regression tests..."
"\$PHP_BIN" "\$REPO_ROOT/tests/run.php"
SH;

foreach (array('post-merge', 'post-checkout') as $hook) {
    $path = $hooksDir . '/' . $hook;
    file_put_contents($path, $hookBody . "\n");
    @chmod($path, 0755);
    echo "Installed Git hook: .git/hooks/{$hook}\n";
}
