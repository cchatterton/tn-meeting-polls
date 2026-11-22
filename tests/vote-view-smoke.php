<?php
// Run with php tests/vote-view-smoke.php from the repository root.
define('ABSPATH', __DIR__ . '/');
define('TNMP_VERSION', '0.1.8');
define('TNMP_URL', 'https://example.test/wp-content/plugins/tn-meeting-polls/');
function nocache_headers() {}
function wp_enqueue_style($handle, $url, $deps, $version) {}
function language_attributes() { echo 'lang="en"'; }
function bloginfo($field) { echo 'UTF-8'; }
function wp_head() {}
function wp_footer() {}
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function wp_create_nonce($action) { return 'nonce'; }
function tnmp_has_session($attendee) { return true; }
function tnmp_get_votes($poll_id) { return array((object) array('attendee_id' => 3, 'slot_id' => 10, 'choice' => 'yes')); }
function tnmp_get_slots($poll_id) {
    return array(
        (object) array('id' => 10, 'starts_at' => '2026-11-24 08:00:00', 'ends_at' => '2026-11-24 09:00:00', 'status' => 'open'),
        (object) array('id' => 11, 'starts_at' => '2026-11-24 09:00:00', 'ends_at' => '2026-11-24 10:00:00', 'status' => 'open'),
        (object) array('id' => 12, 'starts_at' => '2026-11-25 09:00:00', 'ends_at' => '2026-11-25 10:00:00', 'status' => 'open'),
    );
}
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/views.php';
$poll = (object) array('id' => 1, 'title' => 'Planning', 'description' => '', 'timezone' => 'Australia/Sydney', 'status' => 'open');
$attendee = (object) array('id' => 3, 'email' => 'person@example.test', 'holds' => 1);
ob_start();
tnmp_render_vote_page($poll, $attendee);
$html = ob_get_clean();
function check_vote_view($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
check_vote_view(substr_count($html, 'Tue 24 Nov 2026') === 1 && substr_count($html, 'Wed 25 Nov 2026') === 1, 'each local date is shown once');
check_vote_view(strpos($html, '<h4>7:00 PM</h4>') !== false && strpos($html, '<h4>8:00 PM</h4>') !== false, 'multiple start times appear under one date');
check_vote_view(strpos($html, 'Until 8:00 PM') !== false && strpos($html, 'Until Tue 24 Nov 2026') === false, 'same-day end times do not repeat the date');
check_vote_view(substr_count($html, 'name="tnmp_action" value="vote"') === 9 && strpos($html, 'aria-pressed="true"') !== false, 'vote buttons and selected vote remain available');
echo "Grouped vote dates and controls passed.\n";
