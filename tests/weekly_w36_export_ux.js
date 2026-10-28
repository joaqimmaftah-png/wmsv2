// Regression checks for the W36 weekly Excel download (blank-page failure).
// Run with: node tests/weekly_w36_export_ux.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const src = f => fs.readFileSync(path.join(root, f), 'utf8');
const controller = src('application/controllers/Reports.php');

const start = controller.indexOf('function export_weekly_w36_xlsx');
assert.ok(start > -1, 'Reports::export_weekly_w36_xlsx missing');
const block = controller.slice(start, start + 3200);

assert.ok(!block.includes("class_exists('ZipArchive')"),
  'export must not hard-block on the zip extension (stored-zip fallback covers it)');
const helper = src('application/helpers/xlsx_helper.php');
assert.ok(helper.includes('class XlsxStoredZip'), 'pure-PHP stored-zip fallback writer exists');
assert.ok(helper.includes('XlsxStoredZip') && helper.includes("!class_exists('ZipArchive')"),
  'writer falls back to stored-zip when ZipArchive is unavailable');
assert.ok(block.includes('is_writable'), 'export must verify uploads/tmp is writable up front');
assert.ok(block.includes('catch (Throwable'), 'writer failures must be caught, not an uncaught blank page');
assert.ok(block.includes("log_message('error'"), 'writer failures must be written to the application log');
assert.ok(block.includes('_send_file($tmp, $filename)'), 'successful path still streams the file');

console.log('Weekly W36 export guard checks passed.');
