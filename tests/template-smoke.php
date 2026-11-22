<?php
// Run with php tests/template-smoke.php from the repository root.
define('ABSPATH', __DIR__ . '/');
function is_user_logged_in() { return true; }
function current_user_can($capability) { return true; }
function get_current_user_id() { return 7; }
function check_admin_referer($action) { return true; }
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return strtolower((string) $value); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function absint($value) { return abs((int) $value); }
function esc_url_raw($value) { return $value; }
function wp_validate_redirect($url, $fallback) { return $url ?: $fallback; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $value, $url = '') { return $url ?: $value; }
function wp_safe_redirect($url) { throw new RuntimeException('redirect'); }
function update_user_meta($user_id, $key, $value) { $GLOBALS['saved_settings'][$user_id] = $value; return true; }
function check_template($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

class TemplateDb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $rows = array();
    public function prepare($query, ...$args) {
        foreach ($args as $arg) { $query = preg_replace('/%d/', (string) (int) $arg, $query, 1); }
        return $query;
    }
    public function get_row($query) {
        preg_match('/id = (\d+) AND organiser_id = (\d+)/', $query, $match);
        $row = $this->rows[(int) ($match[1] ?? 0)] ?? null;
        return $row && $row->organiser_id === (int) ($match[2] ?? 0) ? $row : null;
    }
    public function insert($table, $data) {
        $this->insert_id++;
        $this->rows[$this->insert_id] = (object) array_merge(array('id' => $this->insert_id), $data);
        return 1;
    }
    public function update($table, $data, $where) {
        $row = $this->rows[$where['id']] ?? null;
        if (!$row || $row->organiser_id !== $where['organiser_id']) { return false; }
        foreach ($data as $key => $value) { $row->$key = $value; }
        return 1;
    }
    public function delete($table, $where) {
        $row = $this->rows[$where['id']] ?? null;
        if (!$row || $row->organiser_id !== $where['organiser_id']) { return false; }
        unset($this->rows[$where['id']]);
        return 1;
    }
}

$wpdb = new TemplateDb();
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/storage.php';
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/actions.php';
function submit_template($values) {
    $_POST = $values;
    try { tnmp_template_action(); } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'redirect') { throw $error; }
    }
}
submit_template(array('operation' => 'add', 'title' => 'Planning', 'description' => 'Team review', 'duration' => '60'));
check_template(count($wpdb->rows) === 1 && $wpdb->rows[1]->organiser_id === 7, 'template is saved for the current user');
submit_template(array('operation' => 'edit', 'template_id' => 1, 'title' => 'Weekly planning', 'description' => 'New agenda', 'duration' => '90'));
check_template($wpdb->rows[1]->title === 'Weekly planning' && $wpdb->rows[1]->duration === 90, 'owned template can be edited');
$wpdb->rows[2] = (object) array('id' => 2, 'organiser_id' => 8, 'title' => 'Other user', 'description' => '', 'duration' => 60);
submit_template(array('operation' => 'edit', 'template_id' => 2, 'title' => 'Changed', 'description' => '', 'duration' => '60'));
check_template($wpdb->rows[2]->title === 'Other user', 'another user’s template cannot be edited');
submit_template(array('operation' => 'edit', 'template_id' => 1, 'title' => 'Invalid', 'description' => '', 'duration' => '7'));
check_template($wpdb->rows[1]->title === 'Weekly planning', 'invalid duration does not change a template');
submit_template(array('operation' => 'delete', 'template_id' => 1));
check_template(!isset($wpdb->rows[1]) && isset($wpdb->rows[2]), 'owned template can be deleted without affecting another user');
$_POST = array('duration' => '60', 'earliest' => '8', 'latest' => '18', 'retention_days' => '45');
try { tnmp_save_settings(); } catch (RuntimeException $error) { if ($error->getMessage() !== 'redirect') { throw $error; } }
check_template(($GLOBALS['saved_settings'][7]['retention_days'] ?? 0) === 45, 'retention setting is saved for the current user');
$_POST['retention_days'] = '0';
try { tnmp_save_settings(); } catch (RuntimeException $error) { if ($error->getMessage() !== 'redirect') { throw $error; } }
check_template($GLOBALS['saved_settings'][7]['retention_days'] === 45, 'invalid retention days do not overwrite settings');
echo "Meeting template actions passed.\n";
