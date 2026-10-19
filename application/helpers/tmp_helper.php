<?php
/**
 * Private temporary files for generated downloads.
 *
 * The report/forecast/backlog exports used to write to a predictable name in
 * the system temp directory (`sys_get_temp_dir().'/'.$filename`, where the
 * filename came from the week label, a date or a report name). Two people
 * exporting the same week at the same moment shared one path: the second write
 * truncated the file the first was already streaming, so a download could
 * arrive corrupt — or, on a busy host, contain another user's workbook.
 *
 * This helper keeps the caller's filename (so the browser's suggested name and
 * any debugging stay readable) but prefixes it with a random token, making
 * every write private to one request.
 */

if (!function_exists('wms_temp_path')) {
    /**
     * @param  string $filename the name the file will be offered under
     * @return string an absolute path in the system temp directory
     */
    function wms_temp_path($filename)
    {
        $base = basename((string)$filename);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        $base = trim($base, '._-');
        if ($base === '') {
            $base = 'export';
        }

        // random_bytes() is available from PHP 7.0, the version this codebase
        // already requires; the fallback keeps an old host working rather than
        // fatal-erroring on an export.
        $token = function_exists('random_bytes')
            ? bin2hex(random_bytes(8))
            : str_replace('.', '', uniqid('', true));

        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
             . DIRECTORY_SEPARATOR . 'wms_' . $token . '_' . $base;
    }
}
