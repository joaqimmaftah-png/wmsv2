<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$autoload['packages'] = array();
$autoload['libraries'] = array('database', 'session', 'form_validation', 'pagination');
$autoload['drivers'] = array();
// `download` is loaded here because Workorders::download_attachment() and
// Orders::download_document() call force_download() without loading it —
// every attachment/document download used to be a 500 (K-04).
$autoload['helper'] = array('url', 'form', 'date', 'file', 'string', 'download', 'wo', 'equip', 'kpi', 'csrf', 'tmp');
$autoload['config'] = array();
$autoload['language'] = array();
$autoload['model'] = array();
