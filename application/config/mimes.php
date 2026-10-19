<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------
| MIME TYPES
| -------------------------------------------------------------------
| Used by the Upload library to validate uploaded files.
|
| NOTE: this file was missing from the project.  Without it CodeIgniter
| reports "The filetype you are attempting to upload is not allowed" for
| EVERY upload (equipment photos, signatures, order documents, ...),
| because the allowed-types check falls back to an empty mime map.
|
| Every extension listed in an Upload::allowed_types setting must be
| present here, otherwise the upload is rejected.  Entries may be a
| single mime string or an array of accepted alternatives.
|
| IMPORTANT: CodeIgniter loads this file with
|     $_mimes = include(APPPATH.'config/mimes.php');
| so the array MUST be returned.  Assigning it to a variable would leave
| the upload mime map empty and reject every upload.
*/

return array(

    /* ── spreadsheets (asset import/export) ── */
    'xlsx' => array(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',                 // some browsers/OS report a zip
        'application/octet-stream',        // generic binary (Windows/Edge)
        'application/vnd.ms-excel',        // occasional mis-detection
    ),
    'xls'  => array('application/vnd.ms-excel', 'application/msexcel',
                    'application/x-msexcel', 'application/octet-stream'),
    'csv'  => array('text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'),
    'ods'  => array('application/vnd.oasis.opendocument.spreadsheet',
                    'application/zip', 'application/octet-stream'),

    /* ── images (equipment photos, avatars, signatures) ── */
    'png'  => array('image/png', 'image/x-png'),
    'gif'  => 'image/gif',
    'jpg'  => array('image/jpeg', 'image/pjpeg', 'image/jpg'),
    'jpeg' => array('image/jpeg', 'image/pjpeg', 'image/jpg'),
    'jpe'  => array('image/jpeg', 'image/pjpeg'),
    'webp' => 'image/webp',
    'bmp'  => array('image/bmp', 'image/x-bmp'),
    'svg'  => 'image/svg+xml',
    'ico'  => array('image/x-icon', 'image/vnd.microsoft.icon'),

    /* ── documents (order documents, manuals, certificates) ── */
    'pdf'  => array('application/pdf', 'application/x-pdf'),
    'doc'  => array('application/msword', 'application/vnd.ms-word', 'application/x-ole-storage'),
    'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/zip', 'application/octet-stream',   // same alternatives as stock CI
                    'application/msword'),
    'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
    'txt'  => 'text/plain',
    'rtf'  => 'application/rtf',

    /* ── archives / misc ── */
    'zip'  => array('application/zip', 'application/x-zip', 'application/x-zip-compressed'),
    'rar'  => array('application/x-rar-compressed', 'application/octet-stream'),
    '7z'   => 'application/x-7z-compressed',

);
