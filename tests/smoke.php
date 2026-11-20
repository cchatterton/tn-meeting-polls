<?php
// Standalone checks for calendar payloads and local-time parsing. Run with php tn-meeting-polls-tests/smoke.php.
define('ABSPATH', __DIR__ . '/');
function get_userdata($id) { return (object) array('user_email' => 'organiser@example.com'); }
function get_option($name) { return 'admin@example.com'; }
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/calendar.php';
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/actions.php';

function check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$poll = (object) array('organiser_id' => 1, 'title' => 'Planning, review; café', 'description' => "First line\nSecond line", 'timezone' => 'Australia/Sydney');
$slot = (object) array('starts_at' => '2026-11-25 09:00:00', 'ends_at' => '2026-11-25 10:00:00');
$attendee = (object) array('email' => 'person@example.com');
$event = (object) array('uid' => 'abc@example.com', 'sequence_no' => 0, 'sent_once' => 0);
$hold = tnmp_ics($poll, $slot, $attendee, $event, 'hold');
check(strpos($hold, "METHOD:REQUEST\r\n") !== false, 'hold request method');
check(strpos($hold, "UID:abc@example.com\r\n") !== false, 'event UID');
check(strpos($hold, "SEQUENCE:0\r\n") !== false, 'initial sequence');
check(strpos($hold, 'SUMMARY:Hold: Planning\\, review\\; café') !== false, 'escaped summary');
check(strpos($hold, 'DESCRIPTION:First line\\nSecond line') !== false, 'escaped line break');
check(strpos($hold, "DTSTART:20261125T090000Z\r\n") !== false, 'UTC start');
$event->sent_once = 1;
$cancel = tnmp_ics($poll, $slot, $attendee, $event, 'cancel');
check(strpos($cancel, "METHOD:CANCEL\r\n") !== false, 'cancel method');
check(strpos($cancel, "SEQUENCE:1\r\n") !== false, 'cancel increments sequence');
foreach (explode("\r\n", $cancel) as $line) {
    check(strlen($line) <= 75, 'calendar line length');
}
check(tnmp_parse_local_datetime('2026-10-04T02:30', 'Australia/Sydney') === false, 'nonexistent DST time rejected');
check(tnmp_parse_local_datetime('2026-11-25T20:00', 'Australia/Sydney')->format('Y-m-d H:i:s') === '2026-11-25 09:00:00', 'Sydney local time converts to UTC');
echo "Calendar and time checks passed.\n";
