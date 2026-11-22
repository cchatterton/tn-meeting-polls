<?php
if (!defined('ABSPATH')) {
    exit;
}

function tnmp_return_url() {
    $url = isset($_POST['tnmp_return']) ? esc_url_raw(wp_unslash($_POST['tnmp_return'])) : '';
    return wp_validate_redirect($url, admin_url('admin.php?page=tn-meeting-polls'));
}

function tnmp_redirect($code, $poll_id = 0) {
    $view = in_array($code, array('finalized', 'cancelled'), true) ? 'history' : ($code === 'created' ? 'open' : '');
    $url = add_query_arg(array('tnmp_notice' => $code, 'tnmp_poll' => (int) $poll_id), tnmp_return_url());
    if ($view !== '') { $url = add_query_arg('tnmp_view', $view, $url); }
    wp_safe_redirect($url);
    exit;
}

function tnmp_parse_local_datetime($value, $timezone) {
    try {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone($timezone));
        if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
            return false;
        }
        return $date->setTimezone(new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return false;
    }
}

function tnmp_valid_timezone($timezone) {
    return is_string($timezone) && (in_array($timezone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true) || (bool) preg_match('/^[+-](?:0\d|1[0-4]):[0-5]\d$/D', $timezone));
}

function tnmp_parse_proposed_slots($dates, $times, $duration, $timezone) {
    if (!is_array($dates) || !is_array($times) || !$dates || count($dates) > 10 || count($dates) !== count($times) || array_diff_key($dates, $times) || !is_scalar($duration) || !preg_match('/^\d+$/D', (string) $duration)) {
        return false;
    }
    $minutes = (int) $duration;
    if ($minutes < 5 || $minutes > 1440 || $minutes % 5 !== 0) { return false; }
    $slots = array();
    $seen = array();
    foreach ($dates as $index => $date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !is_array($times[$index]) || !$times[$index]) {
            return false;
        }
        foreach ($times[$index] as $time) {
            if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):[0-5]0$/D', $time)) {
                return false;
            }
            if (count($slots) >= 10) { return false; }
            $start = tnmp_parse_local_datetime($date . 'T' . $time, $timezone);
            if (!$start || $start->getTimestamp() < time() + 300 || isset($seen[$start->format('Y-m-d H:i:s')])) { return false; }
            $seen[$start->format('Y-m-d H:i:s')] = true;
            $end = $start->modify('+' . $minutes . ' minutes');
            $slots[] = array($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
        }
    }
    return $slots;
}

function tnmp_create() {
    if (!is_user_logged_in() || !current_user_can('read')) {
        wp_die('You must be logged in to create a meeting poll.', '', array('response' => 403));
    }
    check_admin_referer('tnmp_create');
    global $wpdb;
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $timezone = sanitize_text_field(wp_unslash($_POST['timezone'] ?? ''));
    if (!tnmp_valid_timezone($timezone)) {
        $timezone = wp_timezone_string();
        if (!tnmp_valid_timezone($timezone)) { $timezone = 'UTC'; }
    }
    $dates = isset($_POST['dates']) && is_array($_POST['dates']) ? wp_unslash($_POST['dates']) : array();
    $times = isset($_POST['times']) && is_array($_POST['times']) ? wp_unslash($_POST['times']) : array();
    $duration = isset($_POST['duration']) ? wp_unslash($_POST['duration']) : '';
    $emails = isset($_POST['emails']) && is_array($_POST['emails']) ? array_values(wp_unslash($_POST['emails'])) : array();
    $names = isset($_POST['names']) && is_array($_POST['names']) ? array_values(wp_unslash($_POST['names'])) : array();
    $holds = isset($_POST['holds']) && is_array($_POST['holds']) ? wp_unslash($_POST['holds']) : array();
    if ($title === '' || strlen($title) > 190 || count($emails) > 50) {
        tnmp_redirect('invalid');
    }
    $slots = tnmp_parse_proposed_slots($dates, $times, $duration, $timezone);
    if ($slots === false) { tnmp_redirect('invalid_time'); }
    $attendees = array();
    $seen = array();
    foreach ($emails as $i => $raw_email) {
        if (trim((string) $raw_email) === '') {
            continue;
        }
        $email = sanitize_email($raw_email);
        if (!is_email($email) || strlen($email) > 190 || isset($seen[strtolower($email)])) {
            tnmp_redirect('invalid_attendee');
        }
        $seen[strtolower($email)] = true;
        $attendees[] = array(
            'email' => $email,
            'name' => sanitize_text_field($names[$i] ?? ''),
            'holds' => !empty($holds[$i]) ? 1 : 0,
        );
    }
    if (!$slots || !$attendees) {
        tnmp_redirect('invalid');
    }
    $wpdb->query('START TRANSACTION');
    $ok = $wpdb->insert(tnmp_table('polls'), array(
        'organiser_id' => get_current_user_id(), 'title' => $title, 'description' => $description,
        'timezone' => $timezone, 'status' => 'open', 'final_slot_id' => 0, 'created_at' => tnmp_now(),
    ));
    $poll_id = (int) $wpdb->insert_id;
    $slot_ids = array();
    if ($ok) {
        foreach ($slots as $slot) {
            $ok = $wpdb->insert(tnmp_table('slots'), array('poll_id' => $poll_id, 'starts_at' => $slot[0], 'ends_at' => $slot[1], 'status' => 'open'));
            if (!$ok) { break; }
            $slot_ids[] = (int) $wpdb->insert_id;
        }
    }
    $recipient_ids = array();
    if ($ok) {
        $host = parse_url(home_url(), PHP_URL_HOST) ?: 'local';
        foreach ($attendees as $person) {
            $nonce = bin2hex(random_bytes(32));
            $token = tnmp_token_from_nonce($nonce);
            $ok = $wpdb->insert(tnmp_table('attendees'), array(
                'poll_id' => $poll_id, 'name' => $person['name'], 'email' => $person['email'],
                'holds' => $person['holds'], 'invite_state' => 'queued',
                'token_nonce' => $nonce, 'token_hash' => hash('sha256', $token),
            ));
            if (!$ok) { break; }
            $attendee_id = (int) $wpdb->insert_id;
            $recipient_ids[] = $attendee_id;
            foreach ($slot_ids as $slot_id) {
                $ok = $wpdb->insert(tnmp_table('events'), array(
                    'attendee_id' => $attendee_id, 'slot_id' => $slot_id,
                    'uid' => wp_generate_uuid4() . '@' . $host, 'sequence_no' => 0, 'sent_once' => 0, 'state' => 'none',
                ));
                if (!$ok) { break 2; }
            }
        }
    }
    if (!$ok) {
        $wpdb->query('ROLLBACK');
        tnmp_redirect('database');
    }
    $wpdb->query('COMMIT');
    foreach ($recipient_ids as $attendee_id) {
        tnmp_queue('invite', $poll_id, $attendee_id);
    }
    foreach ($recipient_ids as $attendee_id) {
        $attendee = tnmp_get_attendee($attendee_id);
        if ($attendee->holds) {
            foreach (tnmp_get_slots($poll_id) as $slot) {
                tnmp_queue_event($attendee, $slot, 'hold');
            }
        }
    }
    tnmp_process_queue(3);
    tnmp_redirect('created', $poll_id);
}

function tnmp_manage() {
    if (!is_user_logged_in() || !current_user_can('read')) {
        wp_die('You must be logged in.', '', array('response' => 403));
    }
    check_admin_referer('tnmp_manage');
    global $wpdb;
    $poll_id = absint($_POST['poll_id'] ?? 0);
    $slot_id = absint($_POST['slot_id'] ?? 0);
    $action = sanitize_key(wp_unslash($_POST['tnmp_action'] ?? ''));
    $poll = tnmp_get_poll($poll_id);
    if (!$poll || (int) $poll->organiser_id !== get_current_user_id()) {
        wp_die('You cannot manage this poll.', '', array('response' => 403));
    }
    if ($action === 'retry') {
        $wpdb->query($wpdb->prepare("UPDATE " . tnmp_table('jobs') . " SET state = 'pending', attempts = 0, available_at = %s, last_error = '' WHERE poll_id = %d AND state = 'failed'", tnmp_now(), $poll_id));
        tnmp_process_queue(3);
        tnmp_redirect('retry_queued', $poll_id);
    }
    if ($poll->status !== 'open') {
        tnmp_redirect('already_closed', $poll_id);
    }
    if ($action === 'resend') {
        $attendee = tnmp_get_attendee(absint($_POST['attendee_id'] ?? 0));
        if (!$attendee || (int) $attendee->poll_id !== $poll_id || $attendee->invite_state === 'queued') {
            tnmp_redirect('invalid', $poll_id);
        }
        $nonce = bin2hex(random_bytes(32));
        $token = tnmp_token_from_nonce($nonce);
        $wpdb->update(tnmp_table('attendees'), array('token_nonce' => $nonce, 'token_hash' => hash('sha256', $token), 'invite_state' => 'queued'), array('id' => $attendee->id));
        tnmp_queue('invite', $poll_id, $attendee->id);
        tnmp_process_queue(3);
        tnmp_redirect('resent', $poll_id);
    }
    if ($action === 'close') {
        $slot = tnmp_get_slot($slot_id);
        if (!$slot || (int) $slot->poll_id !== $poll_id || $slot->status !== 'open') {
            tnmp_redirect('invalid', $poll_id);
        }
        $wpdb->update(tnmp_table('slots'), array('status' => 'closed'), array('id' => $slot_id));
        foreach (tnmp_get_attendees($poll_id) as $attendee) {
            tnmp_queue_event($attendee, $slot, 'cancel');
        }
        tnmp_process_queue(3);
        tnmp_redirect('slot_closed', $poll_id);
    }
    if ($action === 'finalize') {
        $slot = tnmp_get_slot($slot_id);
        if (!$slot || (int) $slot->poll_id !== $poll_id || $slot->status !== 'open') {
            tnmp_redirect('invalid', $poll_id);
        }
        $wpdb->update(tnmp_table('polls'), array('status' => 'final', 'final_slot_id' => $slot_id), array('id' => $poll_id));
        foreach (tnmp_get_attendees($poll_id) as $attendee) {
            foreach (tnmp_get_slots($poll_id) as $item) {
                if ((int) $item->id === $slot_id && tnmp_vote_choice($attendee->id, $slot_id) !== 'no') {
                    tnmp_queue_event($attendee, $item, 'final');
                } else {
                    tnmp_queue_event($attendee, $item, 'cancel');
                }
            }
        }
        tnmp_process_queue(3);
        tnmp_redirect('finalized', $poll_id);
    }
    if ($action === 'cancel') {
        $wpdb->update(tnmp_table('polls'), array('status' => 'cancelled'), array('id' => $poll_id));
        foreach (tnmp_get_attendees($poll_id) as $attendee) {
            foreach (tnmp_get_slots($poll_id) as $slot) {
                tnmp_queue_event($attendee, $slot, 'cancel');
            }
        }
        tnmp_process_queue(3);
        tnmp_redirect('cancelled', $poll_id);
    }
    tnmp_redirect('invalid', $poll_id);
}

function tnmp_attendee_by_token($token) {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . tnmp_table('attendees') . ' WHERE token_hash = %s', hash('sha256', $token)));
}

function tnmp_has_session($attendee) {
    $cookie = $_COOKIE['tnmp_session_' . $attendee->id] ?? '';
    return is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie)
        && $attendee->session_hash !== ''
        && hash_equals($attendee->session_hash, hash('sha256', $cookie))
        && $attendee->session_expires && strtotime($attendee->session_expires . ' UTC') > time();
}

function tnmp_vote_page() {
    if (!get_query_var('tnmp_vote')) {
        return;
    }
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    $attendee = tnmp_attendee_by_token($token);
    if (!$attendee) {
        status_header(404);
        tnmp_render_vote_page(null, null, 'This invitation link is invalid.');
        exit;
    }
    $poll = tnmp_get_poll($attendee->poll_id);
    if (!$poll) {
        status_header(404);
        tnmp_render_vote_page(null, null, 'This poll is unavailable.');
        exit;
    }
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nonce = sanitize_text_field(wp_unslash($_POST['_tnmp_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'tnmp_vote_' . $attendee->id)) {
            $message = 'Please reload the page and try again.';
        } else {
            $action = sanitize_key(wp_unslash($_POST['tnmp_action'] ?? ''));
            if ($action === 'send_code') {
                $message = tnmp_send_code($attendee);
            } elseif ($action === 'verify') {
                $message = tnmp_verify_code($attendee);
                $attendee = tnmp_get_attendee($attendee->id);
            } elseif ($action === 'vote') {
                $message = tnmp_save_vote($attendee);
            }
        }
    }
    tnmp_render_vote_page($poll, $attendee, $message);
    exit;
}

function tnmp_send_code($attendee) {
    global $wpdb;
    $last = $attendee->otp_last_sent ? strtotime($attendee->otp_last_sent . ' UTC') : 0;
    if ($last > time() - 60) {
        return 'Please wait a minute before requesting another code.';
    }
    $rate_key = 'tnmp_otp_' . $attendee->id;
    $sent_count = (int) get_transient($rate_key);
    if ($sent_count >= 5) {
        return 'Too many codes requested. Please try again in an hour.';
    }
    $code = (string) random_int(100000, 999999);
    $wpdb->update(tnmp_table('attendees'), array(
        'otp_hash' => wp_hash_password($code),
        'otp_expires' => gmdate('Y-m-d H:i:s', time() + 600),
        'otp_last_sent' => tnmp_now(),
        'otp_attempts' => 0,
    ), array('id' => $attendee->id));
    set_transient($rate_key, $sent_count + 1, HOUR_IN_SECONDS);
    $sent = wp_mail($attendee->email, 'Your meeting poll verification code', "Your one time code is: $code\n\nIt expires in 10 minutes. If you did not request it, you can ignore this email.\n", array('Content-Type: text/plain; charset=UTF-8'));
    return $sent ? 'A verification code has been sent to your email.' : 'The code could not be sent. Please contact the organiser.';
}

function tnmp_verify_code($attendee) {
    global $wpdb;
    $code = preg_replace('/\D/', '', (string) wp_unslash($_POST['code'] ?? ''));
    if ($attendee->otp_hash === '' || !$attendee->otp_expires || strtotime($attendee->otp_expires . ' UTC') < time() || (int) $attendee->otp_attempts >= 5) {
        return 'The code has expired or too many attempts were made. Request a new code.';
    }
    if (!preg_match('/^[0-9]{6}$/', $code) || !wp_check_password($code, $attendee->otp_hash)) {
        $wpdb->update(tnmp_table('attendees'), array('otp_attempts' => (int) $attendee->otp_attempts + 1), array('id' => $attendee->id));
        return 'That code is incorrect.';
    }
    $session = bin2hex(random_bytes(32));
    $wpdb->update(tnmp_table('attendees'), array(
        'otp_hash' => '', 'otp_expires' => null, 'otp_attempts' => 0,
        'session_hash' => hash('sha256', $session),
        'session_expires' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
    ), array('id' => $attendee->id));
    setcookie('tnmp_session_' . $attendee->id, $session, array(
        'expires' => time() + DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl() || strpos(home_url('/'), 'https://') === 0,
        'httponly' => true, 'samesite' => 'Lax',
    ));
    $_COOKIE['tnmp_session_' . $attendee->id] = $session;
    return 'Email verified. You can now vote.';
}

function tnmp_save_vote($attendee) {
    global $wpdb;
    if (!tnmp_has_session($attendee)) {
        return 'Please verify your email before voting.';
    }
    $slot_id = absint($_POST['slot_id'] ?? 0);
    $choice = sanitize_key(wp_unslash($_POST['choice'] ?? ''));
    $slot = tnmp_get_slot($slot_id);
    $poll = tnmp_get_poll($attendee->poll_id);
    if (!$slot || !$poll || (int) $slot->poll_id !== (int) $poll->id || $slot->status !== 'open' || $poll->status !== 'open' || !in_array($choice, array('prefer', 'yes', 'no'), true)) {
        return 'This time is no longer open for voting.';
    }
    $ok = $wpdb->replace(tnmp_table('votes'), array(
        'attendee_id' => $attendee->id, 'slot_id' => $slot_id, 'choice' => $choice, 'updated_at' => tnmp_now(),
    ));
    if (!$ok) {
        return 'Your vote could not be saved. Please try again.';
    }
    if ($attendee->holds) {
        tnmp_queue_event($attendee, $slot, $choice === 'no' ? 'cancel' : 'hold');
        tnmp_process_queue(3);
    }
    return 'Your vote was saved.';
}
