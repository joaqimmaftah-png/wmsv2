<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$route['default_controller'] = 'dashboard';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;

// Auth
$route['login'] = 'auth/login';
$route['logout'] = 'auth/logout';

// Dashboard
$route['dashboard'] = 'dashboard/index';

// Global Find (read-only JSON; role and technician scoping in Search_model)
$route['search'] = 'search/index';

// Team Management
$route['team'] = 'team/index';
$route['team/(:any)'] = 'team/$1';

// Attendance punches (QR + GPS check-in / check-out, ATTENDANCE_WORKFLOW.md)
$route['attendance'] = 'attendance/index';
$route['attendance/(:any)'] = 'attendance/$1';

// NOTE: there is no `Maintenance` controller — `views/maintenance/` is used by
// workorders/equipment/pm. The old `maintenance/*` routes (and
// `dashboard/kpi_data`, a method that does not exist) were dead: every request
// to them was a 404 through a route that resolved to nothing (K-07).

// Work Orders
$route['workorders'] = 'workorders/index';
$route['workorders/(:any)'] = 'workorders/$1';

// PM Planner
$route['pm'] = 'pm/index';
$route['pm/(:any)'] = 'pm/$1';

// Inspection Forms
$route['inspections'] = 'inspections/index';
$route['inspections/(:any)'] = 'inspections/$1';

// Equipment
$route['equipment'] = 'equipment/index';
$route['equipment/(:any)'] = 'equipment/$1';

// Lubricants
$route['lubricants'] = 'lubricants/index';
$route['lubricants/(:any)'] = 'lubricants/$1';
$route['lubricants/(:any)/(:any)'] = 'lubricants/$1/$2';

// Backlog / defect register
$route['backlog'] = 'backlog/index';
$route['backlog/(:any)'] = 'backlog/$1';

// Planned availability forecast (tasks F5-F7, F10, F11)
$route['forecast']              = 'forecast/index';
$route['forecast/(:any)']       = 'forecast/$1';
// (:any) is [^/]+ (system/core/Router.php), so it cannot carry the export's
// second segment — forecast/export/rolling needs its own rule, and neither does
// forecast/parts/12, which carries the PM schedule's id.
$route['forecast/(:any)/(:any)'] = 'forecast/$1/$2';

// Reports
$route['reports'] = 'reports/index';
$route['reports/fleet_availability'] = 'reports/fleet_availability';
$route['reports/downtime_log'] = 'reports/downtime_log';
$route['reports/(:any)'] = 'reports/$1';

// Settings
$route['settings'] = 'settings/index';
$route['settings/(:any)'] = 'settings/$1';

// User Access Management
$route['users'] = 'users/index';
$route['users/(:any)'] = 'users/$1';

// Inventory / Materials
$route['materials']            = 'materials/index';
$route['materials/(:any)']     = 'materials/$1';
$route['materials/(:any)/(:any)'] = 'materials/$1/$2';
$route['spareparts']                = 'spareparts/index';
$route['spareparts/(:any)']         = 'spareparts/$1';
$route['spareparts/(:any)/(:any)']  = 'spareparts/$1/$2';
$route['suppliers']                      = 'suppliers/index';
$route['suppliers/(:any)']               = 'suppliers/$1';
$route['suppliers/(:any)/(:any)']        = 'suppliers/$1/$2';
$route['suppliers/(:any)/(:any)/(:any)'] = 'suppliers/$1/$2/$3';
$route['orders']                    = 'orders/index';
$route['orders/(:any)']             = 'orders/$1';
$route['orders/(:any)/(:any)']      = 'orders/$1/$2';
$route['orders/(:any)/(:any)/(:any)'] = 'orders/$1/$2/$3';
