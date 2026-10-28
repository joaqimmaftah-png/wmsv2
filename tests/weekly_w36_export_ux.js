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

// Excel content validity: a list validation written as a literal string is
// capped at 255 characters; the 36 approved sites make 342, which made Excel
// answer a successful download with "We found a problem with some content".
const w36 = src('application/libraries/W36_excel.php');
assert.ok(!w36.includes("implode(',', array_slice(array_values($sheet['validation']['list'])"),
  'LOCATION dropdown must not be a literal site list (Excel 255-char limit)');
assert.ok(w36.includes("'$A$1:$A$'"), 'LOCATION dropdown must reference the vocabulary range');
assert.ok(w36.includes('hideSheet()'), 'vocabulary sheet must be hidden');
assert.ok(helper.includes('public function hideSheet'), 'writer supports hidden sheets');
assert.ok(helper.includes('state="'), 'workbook must write the sheet state attribute');
assert.ok(helper.includes('sharedStringRefs'), 'sst count must count string references, not uniques');
assert.ok(helper.includes('uniqueCount'), 'sst uniqueCount stays the distinct-string count');

console.log('Weekly W36 export guard checks passed.');
