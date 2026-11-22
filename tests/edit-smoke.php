<?php
// Run with php tests/edit-smoke.php from the repository root.
define('ABSPATH', __DIR__ . '/');
function is_user_logged_in() { return true; }
function current_user_can($capability) { return true; }
function get_current_user_id() { return 7; }
function check_admin_referer($action) { return true; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function absint($value) { return abs((int) $value); }
function wp_salt($scheme) { return 'test-salt'; }
function get_user_meta($user, $key, $single) { return array('duration' => 60, 'earliest' => 0, 'latest' => 24); }
function home_url() { return 'https://example.test/'; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function esc_url_raw($value) { return $value; }
function wp_validate_redirect($url, $fallback) { return $url ?: $fallback; }
function add_query_arg($args, $url) { return $url; }
function wp_safe_redirect($url) { throw new RuntimeException('redirect'); }
function wp_generate_uuid4() { return uniqid('event-', true); }
function wp_next_scheduled($hook) { return true; }
function wp_schedule_single_event($time, $hook) {}
function sanitize_key($value) { return strtolower((string) $value); }

class EditDb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $tables = array();
    private $next_id = 100;
    public function __construct($start, $end) {
        $this->tables['polls'][1] = (object) array('id' => 1, 'organiser_id' => 7, 'title' => 'Planning', 'description' => '', 'timezone' => 'UTC', 'status' => 'open');
        $this->tables['slots'][10] = (object) array('id' => 10, 'poll_id' => 1, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'open');
        $this->tables['slots'][11] = (object) array('id' => 11, 'poll_id' => 1, 'starts_at' => gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + 3600), 'ends_at' => gmdate('Y-m-d H:i:s', strtotime($end . ' UTC') + 3600), 'status' => 'open');
        $this->tables['attendees'][20] = (object) array('id' => 20, 'poll_id' => 1, 'name' => 'Ada', 'email' => 'ada@example.test', 'holds' => 1, 'invite_state' => 'sent');
        $this->tables['attendees'][21] = (object) array('id' => 21, 'poll_id' => 1, 'name' => 'Grace', 'email' => 'grace@example.test', 'holds' => 0, 'invite_state' => 'sent');
        foreach (array(20, 21) as $attendee_id) {
            foreach (array(10, 11) as $slot_id) {
                $id = ++$this->next_id;
                $this->tables['events'][$id] = (object) array('id' => $id, 'attendee_id' => $attendee_id, 'slot_id' => $slot_id, 'uid' => 'uid-' . $id, 'sequence_no' => 0, 'sent_once' => 1, 'state' => $attendee_id === 20 ? 'held' : 'none');
            }
        }
        $this->tables['votes']['20-10'] = (object) array('attendee_id' => 20, 'slot_id' => 10, 'choice' => 'yes');
    }
    public function prepare($query, ...$args) {
        foreach ($args as $arg) { $query = preg_replace('/%[ds]/', (string) $arg, $query, 1); }
        return $query;
    }
    public function query($query) { return 1; }
    private function table($query) {
        preg_match('/wp_tnmp_([a-z]+)/', $query, $match);
        return $match[1] ?? '';
    }
    public function get_row($query) {
        $table = $this->table($query);
        if ($table === 'events') {
            preg_match('/attendee_id = (\d+) AND slot_id = (\d+)/', $query, $match);
            foreach ($this->tables['events'] as $item) {
                if ($item->attendee_id == ($match[1] ?? -1) && $item->slot_id == ($match[2] ?? -1)) { return clone $item; }
            }
        }
        preg_match('/id = (\d+)/', $query, $match);
        return isset($this->tables[$table][(int) ($match[1] ?? 0)]) ? clone $this->tables[$table][(int) $match[1]] : null;
    }
    public function get_results($query) {
        $table = $this->table($query);
        if ($table === 'jobs') { return array(); }
        return array_values(array_map('clone', array_filter($this->tables[$table] ?? array(), function ($item) use ($table) {
            return $item->poll_id == 1 && ($table !== 'slots' || $item->status !== 'removed') && ($table !== 'attendees' || $item->invite_state !== 'removed');
        })));
    }
    public function get_var($query) {
        if (strpos($query, 'SELECT choice') !== false) {
            preg_match('/attendee_id = (\d+) AND slot_id = (\d+)/', $query, $match);
            return $this->tables['votes'][($match[1] ?? '') . '-' . ($match[2] ?? '')]->choice ?? '';
        }
        return 0;
    }
    public function update($table, $data, $where) {
        $name = substr($table, strlen('wp_tnmp_'));
        $id = (int) ($where['id'] ?? 0);
        if (!isset($this->tables[$name][$id])) { return false; }
        foreach ($data as $key => $value) { $this->tables[$name][$id]->$key = $value; }
        return 1;
    }
    public function insert($table, $data) {
        $name = substr($table, strlen('wp_tnmp_'));
        $this->insert_id = ++$this->next_id;
        $this->tables[$name][$this->insert_id] = (object) array_merge(array('id' => $this->insert_id), $data);
        return 1;
    }
}

$date = (new DateTimeImmutable('+30 days', new DateTimeZone('UTC')))->format('Y-m-d');
$start = $date . ' 09:00:00';
$end = $date . ' 10:00:00';
$wpdb = new EditDb($start, $end);
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/storage.php';
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/calendar.php';
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/actions.php';
$_POST = array('poll_id' => 1, 'title' => 'Planning', 'description' => '', 'timezone' => 'UTC', 'duration' => '60',
    'dates' => array(0 => $date), 'time_hours' => array(0 => array('09', '11')), 'time_minutes' => array(0 => array('00', '00')),
    'emails' => array('ada@example.test', 'new@example.test'), 'names' => array('Ada', 'New person'),
    'holds' => array(0 => '1', 1 => '1'));
try { tnmp_edit(); } catch (RuntimeException $error) { if ($error->getMessage() !== 'redirect') { throw $error; } }
function check_edit($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
check_edit($wpdb->tables['slots'][10]->status === 'open' && $wpdb->tables['slots'][11]->status === 'removed', 'unchanged slot kept and removed slot retired');
check_edit($wpdb->tables['attendees'][21]->invite_state === 'removed', 'removed attendee loses poll access');
check_edit($wpdb->tables['votes']['20-10']->choice === 'yes', 'existing vote remains');
$jobs = array_values($wpdb->tables['jobs']);
check_edit(count(array_filter($jobs, function ($job) { return $job->job_type === 'cancel' && $job->attendee_id === 20 && $job->slot_id === 11; })) === 1, 'old placeholder cancellation queued');
check_edit(count(array_filter($jobs, function ($job) { return $job->job_type === 'invite'; })) === 2, 'existing and new invitees receive revised poll');
check_edit(count(array_filter($jobs, function ($job) { return $job->job_type === 'hold'; })) === 3, 'new time and new attendee placeholders queued');
echo "Poll edit and calendar queue checks passed.\n";
