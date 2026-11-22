<?php
// Run with php tests/views-smoke.php from the repository root.
define('ABSPATH', __DIR__ . '/');
define('TNMP_VERSION', '0.1.19');
function current_user_can($capability) { return true; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_unslash($value) { return $value; }
function absint($value) { return abs((int) $value); }
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
function get_user_meta($id, $key, $single) { return $GLOBALS['user_settings'] ?? array(); }
class FakeDb {
    public $query = '';
    public $prefix = 'wp_';
    public function prepare($query, $user_id) { return str_replace('%d', (string) $user_id, $query); }
    public function get_results($query) {
        $this->query = $query;
        if (!empty($GLOBALS['edit_poll']) && strpos($query, 'tnmp_slots') !== false) { return $GLOBALS['edit_slots']; }
        if (!empty($GLOBALS['edit_poll']) && strpos($query, 'tnmp_attendees') !== false) { return $GLOBALS['edit_attendees']; }
        if (strpos($query, 'tnmp_contacts') !== false) {
            return array((object) array('id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test'), (object) array('id' => 2, 'name' => 'Grace Hopper', 'email' => 'grace@example.test'));
        }
        if (strpos($query, 'tnmp_templates') !== false) {
            return array((object) array('id' => 5, 'title' => 'Planning template', 'description' => 'Review agenda', 'duration' => 75));
        }
        return array();
    }
    public function get_row($query) {
        if (strpos($query, 'tnmp_templates') !== false) { return strpos($query, 'id = 5') !== false ? (object) array('id' => 5, 'title' => 'Planning template', 'description' => 'Review agenda', 'duration' => 75) : null; }
        return $GLOBALS['edit_poll'] ?? null;
    }
}
$wpdb = new FakeDb();
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/storage.php';
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/actions.php';
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/views.php';

function check_view($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$_GET['tnmp_view'] = 'create';
$create = tnmp_render_app();
check_view(strpos($create, 'name="duration"') !== false && substr_count($create, 'name="duration"') === 1 && strpos($create, 'name="dates[0]"') !== false && strpos($create, 'name="times[0][]"') !== false && strpos($create, 'name="durations[') === false, 'create view sets one duration before dates and times');
check_view(strpos($create, 'name="duration"') < strpos($create, 'name="dates[0]"') && strpos($create, 'name="dates[0]"') < strpos($create, 'name="times[0][]"'), 'create fields follow duration, date, time order');
check_view(strpos($create, 'name="times[0][]" step="600"') !== false, 'native start time picker is restored with 10-minute steps');
check_view(substr_count($create, 'name="emails[]"') === 1, 'create view starts with one attendee slot');
check_view(strpos($create, 'id="tnmp-contact-search"') !== false && strpos($create, 'data-email="ada@example.test"') !== false && strpos($create, 'tnmp-pick-contact') !== false, 'create form offers searchable saved contacts');
check_view(strpos($create, 'data-earliest="0" data-latest="24"') !== false, 'create form preserves unrestricted meeting hours until settings are saved');
check_view(strpos($create, 'value="Australia/Sydney" selected') !== false, 'WordPress named timezone is selected by default');
check_view(strpos($create, 'id="tnmp-results"') === false, 'create view has no live results');
check_view(strpos($create, '>Create/Edit</a>') !== false && strpos($create, '>Open/In-Progress</a>') !== false && strpos($create, '>Contacts</a>') !== false && strpos($create, '>Templates</a>') !== false, 'navigation uses requested tab names');
$GLOBALS['site_timezone'] = '+10:00';
$offset_create = tnmp_render_app();
check_view(strpos($offset_create, 'value="+10:00" selected') !== false, 'WordPress UTC-offset timezone remains selectable and selected');

$_GET['tnmp_view'] = 'open';
$open = tnmp_render_app();
check_view(strpos($open, 'id="tnmp-results"') !== false && strpos($wpdb->query, "status = 'open'") !== false, 'open view shows only open polls with live results');

$_GET['tnmp_view'] = 'history';
$history = tnmp_render_app();
check_view(strpos($history, 'id="tnmp-results"') === false && strpos($wpdb->query, "status IN ('final','cancelled')") !== false, 'history view shows closed polls without live refresh');
$_GET['tnmp_view'] = 'contacts';
$contacts = tnmp_render_app();
check_view(strpos($contacts, 'Ada Lovelace') < strpos($contacts, 'Grace Hopper') && strpos($wpdb->query, 'ORDER BY name ASC, email ASC') !== false, 'contacts are shown alphabetically');
check_view(strpos($contacts, 'tnmp-edit-contact') !== false && strpos($contacts, 'value="delete"') !== false && strpos($contacts, 'value="add"') !== false, 'contacts can be added, edited and removed');
check_view(strpos($contacts, 'data-confirm') === false, 'removing a contact has no browser confirmation');
check_view(strpos($contacts, '<table class="tnmp-table tnmp-record-table tnmp-contact-table">') !== false && substr_count($contacts, '<tbody><tr>') === 1 && substr_count($contacts, '</td></tr>') === 2, 'saved contacts are table rows');
check_view((bool) preg_match('/<tr><td>.*?name="tnmp_contacts\[\]" value="1".*?<\/td><td>Ada Lovelace<\/td><td>ada@example\.test<\/td><td>.*?data-contact-id="1".*?Edit<\/button>.*?Delete<\/button>.*?<\/td><\/tr>/s', $contacts), 'contact records show values and actions in one row');
check_view(strpos($contacts, 'id="tnmp-invite-selected"') !== false && strpos($contacts, 'form="tnmp-invite-selected" class="button button-primary">Invite selected</button>') !== false, 'selected contacts can open create poll');
check_view(strpos($contacts, '<dialog id="tnmp-contact-dialog"') !== false && strpos($contacts, 'id="tnmp-open-contact-dialog">Add Contact</button>') !== false && strpos($contacts, 'name="operation" value="add"') !== false && strpos($contacts, 'name="contact_id" value="0"') !== false, 'add and edit contact use one modal');
$_GET['tnmp_view'] = 'create';
$_GET['tnmp_contacts'] = array('2', '1', '1', '999');
$selected_create = tnmp_render_app();
check_view(substr_count($selected_create, 'class="tnmp-row tnmp-attendee-row"') === 2 && strpos($selected_create, 'value="ada@example.test"') !== false && strpos($selected_create, 'value="grace@example.test"') !== false, 'selected saved contacts prefill create poll without duplicates or unknown IDs');
unset($_GET['tnmp_contacts']);
$_GET['tnmp_view'] = 'templates';
$templates = tnmp_render_app();
check_view(strpos($templates, '<h2>Templates</h2>') !== false && strpos($templates, 'Planning template') !== false && strpos($templates, 'Review agenda') !== false && strpos($templates, '75 mins') !== false, 'templates table shows saved values');
check_view(strpos($templates, 'data-template-id="5"') !== false && strpos($templates, 'value="delete"') !== false && strpos($templates, 'tnmp_template=5') !== false && strpos($templates, '<dialog id="tnmp-template-dialog"') !== false, 'template actions include edit, delete, open and modal');
$_GET['tnmp_view'] = 'create';
$_GET['tnmp_template'] = 5;
$template_create = tnmp_render_app();
check_view(strpos($template_create, 'name="title" maxlength="190" value="Planning template"') !== false && strpos($template_create, '<textarea name="description" rows="3" placeholder="Purpose, location, or meeting link">Review agenda</textarea>') !== false && strpos($template_create, 'name="duration" min="5" max="1440" step="5" value="75"') !== false, 'opening a template prefills the create form');
unset($_GET['tnmp_template']);
ob_start();
tnmp_action_form(42, 3, 'close', 'Close time');
$action_form = ob_get_clean();
check_view(strpos($action_form, 'name="tnmp_action" value="close"') !== false && strpos($action_form, 'data-confirm') === false, 'poll actions submit without browser confirmation');
$_GET['tnmp_view'] = 'settings';
$settings = tnmp_render_app();
check_view(strpos($settings, 'Default meeting length') !== false && strpos($settings, 'Earliest meeting hour') !== false && strpos($settings, 'Latest meeting hour') !== false, 'settings tab exposes requested preferences');
$GLOBALS['user_settings'] = array('duration' => 90, 'earliest' => 8, 'latest' => 18);
$_GET['tnmp_view'] = 'create';
$preset_create = tnmp_render_app();
check_view(strpos($preset_create, 'name="duration" min="5" max="1440" step="5" value="90"') !== false && strpos($preset_create, 'data-earliest="8" data-latest="18"') !== false, 'create form applies organiser presets');
$GLOBALS['edit_poll'] = (object) array('id' => 42, 'organiser_id' => 7, 'status' => 'open', 'title' => 'Planning', 'description' => 'Project review', 'timezone' => 'Australia/Sydney');
$GLOBALS['edit_slots'] = array((object) array('id' => 3, 'starts_at' => '2026-11-25 09:00:00', 'ends_at' => '2026-11-25 10:30:00', 'status' => 'open'));
$GLOBALS['edit_attendees'] = array((object) array('id' => 8, 'email' => 'ada@example.test', 'name' => 'Ada Lovelace', 'holds' => 1));
$_GET['tnmp_edit'] = 42;
$edit = tnmp_render_app();
check_view(strpos($edit, 'Edit meeting poll') !== false && strpos($edit, 'name="action" value="tnmp_edit"') !== false && strpos($edit, 'name="poll_id" value="42"') !== false, 'edit opens create tab with authorised poll');
check_view(strpos($edit, 'value="Planning"') !== false && strpos($edit, 'value="2026-11-25"') !== false && strpos($edit, 'value="20:00"') !== false && strpos($edit, 'value="ada@example.test"') !== false, 'edit form preloads title, local time, and attendee');
check_view(strpos($edit, 'tnmp-remove-time') !== false && strpos($edit, 'tnmp-remove-attendee') !== false && strpos($edit, 'Save poll and send updates') !== false, 'edit form allows changes and submission');
unset($_GET['tnmp_edit']);
echo "Poll views and creation fields passed.\n";
