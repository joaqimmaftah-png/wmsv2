<?php
/**
 * Render the real navigation shell to a standalone HTML fixture.
 *
 *     php tests/navigation_fixture.php <role_id> <uri> > /tmp/nav.html
 *
 * No database, web server or Composer is required. The fixture uses the same
 * header/sidebar/footer partials and navigation helper as MY_Controller::render().
 */

if (!defined('BASEPATH')) define('BASEPATH', TRUE);
if (!class_exists('CI_Controller', FALSE)) {
    class CI_Controller {}
}
require_once __DIR__ . '/../application/core/MY_Controller.php';
require_once __DIR__ . '/../application/helpers/navigation_helper.php';

$fixture_role = isset($argv[1]) ? (int)$argv[1] : 1;
$fixture_uri  = isset($argv[2]) ? (string)$argv[2] : 'dashboard';

function uri_string() {
    global $fixture_uri;
    return $fixture_uri;
}
function base_url($uri = '') {
    return 'http://wms.test/' . ltrim((string)$uri, '/');
}
function site_url($uri = '') {
    return base_url($uri);
}
function form_open($action = '', $attributes = array()) {
    return '<form method="post" action="' . htmlspecialchars((string)$action, ENT_QUOTES, 'UTF-8') . '">';
}
function form_close() {
    return '</form>';
}

class WmsNavigationFixtureView {
    public $session;

    public function __construct() {
        $this->session = new class {
            public function flashdata($key) { return ''; }
            public function userdata($key) { return 1; }
        };
    }

    public function render($role_id, $uri) {
        $role_id = (int)$role_id;
        $current_user = (object)array(
            'id' => 1, 'first_name' => 'Navigation', 'last_name' => 'Fixture',
            'email' => 'fixture@example.com', 'role_id' => $role_id,
        );
        $site_settings = array('company_name' => 'Workshop WMS');
        $page_title = 'Navigation fixture';
        $lang = 'en';
        $new_spares_alerts = 0;
        $security_events_open = 0;
        $operational_attention = array('groups' => array(), 'count' => 0);

        ob_start();
        include __DIR__ . '/../application/views/layouts/header.php';
        include __DIR__ . '/../application/views/layouts/sidebar.php';
        echo '<h1 class="main-page-title">Navigation fixture</h1>';
        include __DIR__ . '/../application/views/layouts/footer.php';
        $html = ob_get_clean();

        // Let a file:// fixture load the real local shell script.
        $asset_base = 'file://' . realpath(__DIR__ . '/../assets/js') . '/';
        return str_replace('http://wms.test/assets/js/', $asset_base, $html);
    }
}

$fixture_view = new WmsNavigationFixtureView();
echo $fixture_view->render($fixture_role, $fixture_uri);
