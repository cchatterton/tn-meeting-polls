<?php
// Run with php tests/accordion-smoke.php from the repository root.
define('ABSPATH', __DIR__ . '/');
define('TNMP_VERSION', '0.1.18');
function current_user_can($capability) { return true; }
function get_current_user_id() { return 7; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function is_admin() { return true; }
function wp_doing_ajax() { return false; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_nonce" value="test">'; }
function tnmp_table($name) { return 'wp_tnmp_' . $name; }
function tnmp_get_slots($poll_id) { return array((object) array('id' => $poll_id * 10, 'starts_at' => '2026-11-25 09:00:00', 'ends_at' => '2026-11-25 10:00:00', 'status' => 'closed')); }
function tnmp_get_attendees($poll_id) { return array((object) array('id' => $poll_id * 10, 'name' => 'Ada', 'email' => 'ada@example.test', 'holds' => 0, 'invite_state' => 'sent')); }
function tnmp_get_votes($poll_id) { return array(); }
function tnmp_pending_jobs($poll_id) { return 0; }
function tnmp_format_time($utc, $timezone) { return $utc; }
class AccordionDb {
    public function prepare($query, ...$args) {
        foreach ($args as $arg) { $query = preg_replace('/%d/', (string) $arg, $query, 1); }
        return $query;
    }
    public function get_results($query) {
        return array(
            (object) array('id' => 2, 'title' => 'Recent poll', 'description' => '', 'status' => 'cancelled', 'timezone' => 'UTC', 'final_slot_id' => 0),
            (object) array('id' => 1, 'title' => 'Older poll', 'description' => '', 'status' => 'final', 'timezone' => 'UTC', 'final_slot_id' => 10),
        );
    }
    public function get_var($query) { return 0; }
}
$wpdb = new AccordionDb();
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/views.php';
ob_start();
tnmp_render_dashboard('history');
$html = ob_get_clean();
function check_accordion($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
check_accordion(substr_count($html, 'name="tnmp-polls"') === 2 && substr_count($html, 'name="tnmp-polls" data-poll-id="2" open') === 1, 'only the first poll starts open in one named accordion group');
check_accordion(strpos($html, '<summary class="tnmp-poll-head">') !== false && strpos($html, '<h3>Recent poll</h3>') !== false && strpos($html, '<h3>Older poll</h3>') !== false, 'each poll title is an accordion header');
check_accordion(substr_count($html, '<details class="tnmp-individual-votes">') === 2 && substr_count($html, 'value="delete"') === 2, 'individual votes and delete actions remain inside both polls');
echo "Poll accordion rendering passed.\n";
