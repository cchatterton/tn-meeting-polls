<?php
// Run with php tests/views-smoke.php from the repository root.
define('ABSPATH', __DIR__ . '/');
define('TNMP_VERSION', '0.1.4');
function current_user_can($capability) { return true; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_unslash($value) { return $value; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . rawurlencode($key) . '=' . rawurlencode($value); }
function is_admin() { return true; }
function wp_doing_ajax() { return false; }
function wp_timezone_string() { return $GLOBALS['site_timezone'] ?? 'Australia/Sydney'; }
function selected($actual, $expected, $echo) { return $actual === $expected ? ' selected' : ''; }
function wp_nonce_field($action) { echo '<input type="hidden" value="nonce">'; }
function get_current_user_id() { return 7; }
function tnmp_table($name) { return 'wp_tnmp_' . $name; }
class FakeDb {
    public $query = '';
    public function prepare($query, $user_id) { return str_replace('%d', (string) $user_id, $query); }
    public function get_results($query) { $this->query = $query; return array(); }
}
$wpdb = new FakeDb();
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/actions.php';
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/views.php';

function check_view($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$_GET['tnmp_view'] = 'create';
$create = tnmp_render_app();
check_view(strpos($create, 'name="duration"') !== false && substr_count($create, 'name="duration"') === 1 && strpos($create, 'name="dates[0]"') !== false && strpos($create, 'name="times[0][]"') !== false && strpos($create, 'name="durations[') === false, 'create view sets one duration before dates and times');
check_view(strpos($create, 'name="duration"') < strpos($create, 'name="dates[0]"') && strpos($create, 'name="dates[0]"') < strpos($create, 'name="times[0][]"'), 'create fields follow duration, date, time order');
check_view(substr_count($create, 'name="emails[]"') === 1, 'create view starts with one attendee slot');
check_view(strpos($create, 'value="Australia/Sydney" selected') !== false, 'WordPress named timezone is selected by default');
check_view(strpos($create, 'id="tnmp-results"') === false, 'create view has no live results');
$GLOBALS['site_timezone'] = '+10:00';
$offset_create = tnmp_render_app();
check_view(strpos($offset_create, 'value="+10:00" selected') !== false, 'WordPress UTC-offset timezone remains selectable and selected');

$_GET['tnmp_view'] = 'open';
$open = tnmp_render_app();
check_view(strpos($open, 'id="tnmp-results"') !== false && strpos($wpdb->query, "status = 'open'") !== false, 'open view shows only open polls with live results');

$_GET['tnmp_view'] = 'history';
$history = tnmp_render_app();
check_view(strpos($history, 'id="tnmp-results"') === false && strpos($wpdb->query, "status IN ('final','cancelled')") !== false, 'history view shows closed polls without live refresh');
echo "Poll views and creation fields passed.\n";
