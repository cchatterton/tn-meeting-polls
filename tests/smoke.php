<?php
// Standalone checks for calendar payloads and local-time parsing. Run with php tn-meeting-polls-tests/smoke.php.
define('ABSPATH', __DIR__ . '/');
function get_userdata($id) { return (object) array('user_email' => 'organiser@example.com'); }
function get_option($name) { return 'admin@example.com'; }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$GLOBALS['tnmp_mail_hook'] = null;
function add_action($name, $callback) { if ($name === 'phpmailer_init') { $GLOBALS['tnmp_mail_hook'] = $callback; } }
function remove_action($name, $callback) { if ($name === 'phpmailer_init') { $GLOBALS['tnmp_mail_hook'] = null; } }
function wp_mail($to, $subject, $body, $headers, $attachments = array()) {
    $mailer = new class { public $Ical = ''; public $AltBody = ''; public $Body = ''; public $ContentType = 'text/plain'; public $CharSet = ''; public function isHTML($enabled) { $this->ContentType = $enabled ? 'text/html' : 'text/plain'; } };
    $GLOBALS['tnmp_mail_hook']($mailer);
    $GLOBALS['tnmp_sent_mail'] = array('to' => $to, 'subject' => $subject, 'headers' => $headers, 'attachments' => $attachments, 'body' => $mailer->Body, 'content_type' => $mailer->ContentType, 'charset' => $mailer->CharSet);
    return true;
}
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
check(strpos($hold, 'ATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:person@example.com') !== false, 'calendar invite requests RSVP');
check(tnmp_mail_calendar($attendee, $poll, $slot, $event, 'hold') === true, 'calendar invite sent');
check($GLOBALS['tnmp_sent_mail']['attachments'] === array() && $GLOBALS['tnmp_sent_mail']['content_type'] === 'text/calendar; method=REQUEST' && $GLOBALS['tnmp_sent_mail']['charset'] === 'UTF-8' && strpos($GLOBALS['tnmp_sent_mail']['body'], 'METHOD:REQUEST') !== false, 'meeting request is the primary calendar MIME body without an attachment');
$event->sent_once = 1;
$cancel = tnmp_ics($poll, $slot, $attendee, $event, 'cancel');
check(strpos($cancel, "METHOD:CANCEL\r\n") !== false, 'cancel method');
check(strpos($cancel, "SEQUENCE:1\r\n") !== false, 'cancel increments sequence');
check(tnmp_mail_calendar($attendee, $poll, $slot, $event, 'cancel') === true && $GLOBALS['tnmp_sent_mail']['content_type'] === 'text/calendar; method=CANCEL', 'cancellation is a calendar MIME message');
foreach (explode("\r\n", $cancel) as $line) {
    check(strlen($line) <= 75, 'calendar line length');
}
check(tnmp_parse_local_datetime('2026-10-04T02:30', 'Australia/Sydney') === false, 'nonexistent DST time rejected');
check(tnmp_parse_local_datetime('2026-11-25T20:00', 'Australia/Sydney')->format('Y-m-d H:i:s') === '2026-11-25 09:00:00', 'Sydney local time converts to UTC');
$future = new DateTimeImmutable('+30 days', new DateTimeZone('Australia/Sydney'));
$first_date = $future->format('Y-m-d');
$second_date = $future->modify('+1 day')->format('Y-m-d');
$slots = tnmp_parse_proposed_slots(array(0 => $first_date, 1 => $second_date), array(0 => array('09:00', '14:30'), 1 => array('10:00')), '90', 'Australia/Sydney');
check(is_array($slots) && count($slots) === 3 && strtotime($slots[0][1]) - strtotime($slots[0][0]) === 5400 && strtotime($slots[1][1]) - strtotime($slots[1][0]) === 5400, 'one duration applies to every time on all dates');
check(tnmp_parse_proposed_slots(array(0 => $first_date), array(0 => array('09:00', '09:00')), '60', 'Australia/Sydney') === false, 'duplicate proposed starts rejected');
check(tnmp_parse_proposed_slots(array(0 => $first_date), array(0 => array('09:35')), '60', 'Australia/Sydney') === false, 'start time outside 10-minute steps rejected');
check(tnmp_parse_proposed_slots(array(0 => $first_date), array(0 => array('09:00')), '0', 'Australia/Sydney') === false, 'invalid duration rejected');
check(tnmp_valid_timezone('+10:00') && tnmp_parse_proposed_slots(array(0 => $first_date), array(0 => array('09:00')), '60', '+10:00') !== false, 'WordPress UTC-offset timezone is accepted for scheduling');
echo "Calendar and time checks passed.\n";
