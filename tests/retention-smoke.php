<?php
// Run with php tests/retention-smoke.php from the repository root.
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
function get_user_meta($user_id, $key, $single) { return array('retention_days' => $user_id === 7 ? 30 : 90); }
function check_retention($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

class RetentionDb {
    public $prefix = 'wp_';
    public $polls = array();
    public $slots = array();
    public $attendees = array();
    public $votes = array();
    public $events = array();
    public $jobs = array();
    public $contacts = array(1 => 'saved contact');
    public $templates = array(1 => 'saved template');
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
        }
        return $sql;
    }
    public function get_col($sql) {
        if (strpos($sql, 'SELECT DISTINCT organiser_id') !== false) {
            return array(7, 8);
        }
        preg_match('/p\.organiser_id = (\d+)/', $sql, $owner);
        preg_match("/p\.updated_at < '([^']+)'/", $sql, $cutoff);
        preg_match('/LIMIT (\d+)/', $sql, $limit);
        $ids = array();
        foreach ($this->polls as $poll) {
            if ($poll->organiser_id !== (int) ($owner[1] ?? 0) || !in_array($poll->status, array('final', 'cancelled'), true) || $poll->updated_at >= ($cutoff[1] ?? '') || $this->pending($poll->id)) { continue; }
            $ids[] = $poll->id;
        }
        return array_slice($ids, 0, (int) ($limit[1] ?? 0));
    }
    public function get_row($sql) {
        preg_match('/WHERE id = (\d+)/', $sql, $match);
        return $this->polls[(int) ($match[1] ?? 0)] ?? null;
    }
    public function get_var($sql) {
        preg_match('/WHERE poll_id = (\d+)/', $sql, $match);
        return $this->pending((int) ($match[1] ?? 0));
    }
    private function pending($poll_id) {
        return count(array_filter($this->jobs, function ($job) use ($poll_id) { return $job->poll_id === $poll_id && in_array($job->state, array('pending', 'running'), true); }));
    }
    public function query($sql) {
        if (in_array($sql, array('START TRANSACTION', 'COMMIT', 'ROLLBACK'), true)) { return 1; }
        preg_match('/DELETE FROM wp_tnmp_(\w+)/', $sql, $table);
        preg_match('/(?:poll_id|id) = (\d+)/', $sql, $id);
        $name = $table[1] ?? '';
        $poll_id = (int) ($id[1] ?? 0);
        if (!in_array($name, array('votes', 'events', 'jobs', 'attendees', 'slots', 'polls'), true)) { return false; }
        if ($name === 'polls') { unset($this->polls[$poll_id]); return 1; }
        foreach ($this->$name as $key => $row) {
            $owner = $row->poll_id ?? ($this->attendees[$row->attendee_id]->poll_id ?? 0);
            if ($owner === $poll_id) { unset($this->{$name}[$key]); }
        }
        return 1;
    }
}

$wpdb = new RetentionDb();
foreach (array(
    1 => array(7, 'final', 40),
    2 => array(7, 'cancelled', 10),
    3 => array(8, 'final', 40),
    4 => array(7, 'open', 100),
    5 => array(7, 'final', 40),
    6 => array(8, 'cancelled', 100),
) as $id => $values) {
    $wpdb->polls[$id] = (object) array('id' => $id, 'organiser_id' => $values[0], 'status' => $values[1], 'updated_at' => gmdate('Y-m-d H:i:s', time() - $values[2] * DAY_IN_SECONDS));
    $wpdb->slots[$id] = (object) array('poll_id' => $id);
    $wpdb->attendees[$id] = (object) array('id' => $id, 'poll_id' => $id);
    $wpdb->votes[$id] = (object) array('attendee_id' => $id);
    $wpdb->events[$id] = (object) array('attendee_id' => $id);
    $wpdb->jobs[$id] = (object) array('poll_id' => $id, 'state' => $id === 5 ? 'pending' : 'sent');
}
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/storage.php';
check_retention(tnmp_user_settings(7)['retention_days'] === 30 && tnmp_user_settings(8)['retention_days'] === 90, 'each organiser keeps their own retention period');
tnmp_cleanup_history();
check_retention(!isset($wpdb->polls[1]) && !isset($wpdb->polls[6]) && isset($wpdb->polls[2], $wpdb->polls[3], $wpdb->polls[4], $wpdb->polls[5]), 'only old historical polls without queued jobs are removed');
check_retention(!isset($wpdb->slots[1]) && !isset($wpdb->attendees[1]) && !isset($wpdb->votes[1]) && !isset($wpdb->events[1]) && !isset($wpdb->jobs[1]), 'all related records are removed');
check_retention(isset($wpdb->contacts[1], $wpdb->templates[1]), 'contacts and templates are retained');
check_retention(!tnmp_delete_historical_poll(2, 8) && !tnmp_delete_historical_poll(4, 7) && !tnmp_delete_historical_poll(5, 7), 'manual delete respects owner, open status and queued delivery');
$wpdb->jobs[5]->state = 'sent';
check_retention(tnmp_delete_historical_poll(5, 7) && !isset($wpdb->polls[5]), 'manual deletion removes a historical poll after mail finishes');
echo "Historical poll retention and deletion passed.\n";
