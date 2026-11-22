<?php
if (!defined('ABSPATH')) {
    exit;
}

function tnmp_vote_url($token) {
    return add_query_arg(array('tnmp_vote' => 1, 'token' => $token), home_url('/'));
}

function tnmp_token_from_nonce($nonce) {
    return hash_hmac('sha256', $nonce, wp_salt('auth'));
}

function tnmp_format_time($utc, $timezone) {
    try {
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone($timezone))->format('D j M Y, g:i A');
    } catch (Exception $e) {
        return $utc . ' UTC';
    }
}

function tnmp_ics_text($value) {
    return str_replace(array('\\', ';', ',', "\r\n", "\r", "\n"), array('\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'), (string) $value);
}

function tnmp_ics_fold($line) {
    $output = '';
    $length = 0;
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $character) {
        $bytes = strlen($character);
        if ($length + $bytes > 75) {
            $output .= "\r\n ";
            $length = 1;
        }
        $output .= $character;
        $length += $bytes;
    }
    return $output;
}

function tnmp_ics($poll, $slot, $attendee, $event, $kind) {
    $method = $kind === 'cancel' ? 'CANCEL' : 'REQUEST';
    $status = $kind === 'cancel' ? 'CANCELLED' : ($kind === 'final' ? 'CONFIRMED' : 'TENTATIVE');
    $summary = ($kind === 'hold' ? 'Hold: ' : '') . $poll->title;
    $organiser = get_userdata((int) $poll->organiser_id);
    $organiser_email = $organiser ? $organiser->user_email : get_option('admin_email');
    $sequence = (int) $event->sequence_no + ((int) $event->sent_once ? 1 : 0);
    $lines = array(
        'BEGIN:VCALENDAR',
        'PRODID:-//Techn//TN Meeting Polls 0.1//EN',
        'VERSION:2.0',
        'CALSCALE:GREGORIAN',
        'METHOD:' . $method,
        'BEGIN:VEVENT',
        'UID:' . $event->uid,
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . gmdate('Ymd\THis\Z', strtotime($slot->starts_at . ' UTC')),
        'DTEND:' . gmdate('Ymd\THis\Z', strtotime($slot->ends_at . ' UTC')),
        'SEQUENCE:' . $sequence,
        'SUMMARY:' . tnmp_ics_text($summary),
        'DESCRIPTION:' . tnmp_ics_text($poll->description),
        'STATUS:' . $status,
        'TRANSP:OPAQUE',
        'ORGANIZER:mailto:' . $organiser_email,
        'ATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=' . ($kind === 'cancel' ? 'FALSE' : 'TRUE') . ':mailto:' . $attendee->email,
        'END:VEVENT',
        'END:VCALENDAR',
    );
    return implode("\r\n", array_map('tnmp_ics_fold', $lines)) . "\r\n";
}

function tnmp_mail_calendar($attendee, $poll, $slot, $event, $kind) {
    $ical = tnmp_ics($poll, $slot, $attendee, $event, $kind);
    $when = tnmp_format_time($slot->starts_at, $poll->timezone) . ' (' . $poll->timezone . ')';
    if ($kind === 'cancel') {
        $subject = 'Cancelled: ' . $poll->title . ' — ' . $when;
        $body = "The calendar placeholder for this proposed time has been cancelled.\n\n";
    } elseif ($kind === 'final') {
        $subject = 'Confirmed: ' . $poll->title . ' — ' . $when;
        $body = "This is the confirmed meeting time. Please respond to this calendar invitation.\n\n";
    } else {
        $subject = 'Placeholder: ' . $poll->title . ' — ' . $when;
        $body = "This is a tentative calendar placeholder for one proposed time. Please vote using the separate poll email.\n\n";
    }
    $body .= "Time: $when\n\n";
    if ($poll->description !== '') {
        $body .= $poll->description . "\n\n";
    }
    $body .= $kind === 'cancel' ? "The calendar invitation has been cancelled.\n" : "Accept or decline this calendar invitation in your email or calendar app.\n";
    $calendar_part = function ($mailer) use ($ical, $body) {
        if (property_exists($mailer, 'Ical')) {
            $mailer->Ical = $ical;
            $mailer->AltBody = $body;
            $mailer->isHTML(true);
            $mailer->Body = '<p>' . nl2br(esc_html($body)) . '</p>';
        }
    };
    add_action('phpmailer_init', $calendar_part, 999);
    $sent = wp_mail($attendee->email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    remove_action('phpmailer_init', $calendar_part, 999);
    if (isset($GLOBALS['phpmailer']) && property_exists($GLOBALS['phpmailer'], 'Ical')) {
        $GLOBALS['phpmailer']->Ical = '';
        $GLOBALS['phpmailer']->AltBody = '';
    }
    return $sent;
}

function tnmp_mail_invitation($poll, $attendee, $token) {
    $body = "Hello " . ($attendee->name ?: 'there') . ",\n\n";
    $body .= "Please choose Prefer, Yes, or No for each proposed time for: " . $poll->title . "\n\n";
    if ($poll->description !== '') {
        $body .= $poll->description . "\n\n";
    }
    foreach (tnmp_get_slots($poll->id) as $slot) {
        $body .= "• " . tnmp_format_time($slot->starts_at, $poll->timezone) . ' to ' . tnmp_format_time($slot->ends_at, $poll->timezone) . ' (' . $poll->timezone . ")\n";
    }
    $body .= "\nVote here: " . tnmp_vote_url($token) . "\n\n";
    $body .= "The page will ask you to request a one time email code before you vote.\n";
    if ($attendee->holds) {
        $body .= "You will also receive separate calendar placeholders for the proposed times. Voting No for a time cancels its placeholder.\n";
    }
    return wp_mail($attendee->email, 'Choose a meeting time: ' . $poll->title, $body, array('Content-Type: text/plain; charset=UTF-8'));
}

function tnmp_queue_event($attendee, $slot, $kind) {
    global $wpdb;
    $event = tnmp_get_event($attendee->id, $slot->id);
    if (!$event) {
        return;
    }
    if ($kind === 'hold') {
        if ($event->state === 'queued_cancel') {
            $wpdb->update(tnmp_table('events'), array('state' => 'held'), array('id' => $event->id));
            return;
        }
        if (!in_array($event->state, array('none', 'cancelled'), true)) {
            return;
        }
        $state = 'queued_hold';
    } elseif ($kind === 'final') {
        if ($event->state === 'final' || $event->state === 'queued_final') {
            return;
        }
        $state = 'queued_final';
    } else {
        if (in_array($event->state, array('none', 'cancelled', 'queued_cancel'), true)) {
            return;
        }
        if ($event->state === 'queued_hold') {
            $wpdb->update(tnmp_table('events'), array('state' => 'none'), array('id' => $event->id));
            return;
        }
        $state = 'queued_cancel';
    }
    $wpdb->update(tnmp_table('events'), array('state' => $state), array('id' => $event->id));
    tnmp_queue($kind, $attendee->poll_id, $attendee->id, $slot->id);
}

function tnmp_process_queue($limit = 20) {
    global $wpdb;
    $jobs_table = tnmp_table('jobs');
    $limit = max(1, min(20, (int) $limit));
    // A worker interrupted during mail handoff can be retried after ten minutes.
    $wpdb->query($wpdb->prepare("UPDATE $jobs_table SET state = 'pending' WHERE state = 'running' AND available_at <= %s", tnmp_now()));
    $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $jobs_table WHERE state = 'pending' AND available_at <= %s ORDER BY id LIMIT %d", tnmp_now(), $limit));
    foreach ($jobs as $job) {
        $claimed = $wpdb->query($wpdb->prepare("UPDATE $jobs_table SET state = 'running', available_at = %s WHERE id = %d AND state = 'pending'", gmdate('Y-m-d H:i:s', time() + 600), $job->id));
        if (!$claimed) {
            continue;
        }
        $result = tnmp_run_job($job);
        if ($result === true || $result === 'skip') {
            $wpdb->update($jobs_table, array('state' => 'done', 'last_error' => ''), array('id' => $job->id));
        } else {
            $attempts = (int) $job->attempts + 1;
            $wpdb->update($jobs_table, array(
                'state' => $attempts >= 3 ? 'failed' : 'pending',
                'attempts' => $attempts,
                'available_at' => gmdate('Y-m-d H:i:s', time() + min(300, 60 * $attempts)),
                'last_error' => 'wp_mail could not hand off the message',
            ), array('id' => $job->id));
            if ($attempts >= 3 && $job->job_type === 'invite') {
                $wpdb->update(tnmp_table('attendees'), array('invite_state' => 'failed'), array('id' => $job->attendee_id));
            }
        }
    }
    $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $jobs_table WHERE state = 'pending'");
    if ($pending && !wp_next_scheduled('tnmp_run_queue')) {
        wp_schedule_single_event(time() + 60, 'tnmp_run_queue');
    }
}

function tnmp_run_job($job) {
    global $wpdb;
    $poll = tnmp_get_poll($job->poll_id);
    $attendee = tnmp_get_attendee($job->attendee_id);
    if (!$poll || !$attendee || (int) $attendee->poll_id !== (int) $poll->id) {
        return 'skip';
    }
    if ($job->job_type === 'invite') {
        if ($poll->status !== 'open' || !in_array($attendee->invite_state, array('queued', 'failed'), true)) {
            return 'skip';
        }
        $token = tnmp_token_from_nonce($attendee->token_nonce);
        if (!hash_equals($attendee->token_hash, hash('sha256', $token))) {
            return false;
        }
        $sent = tnmp_mail_invitation($poll, $attendee, $token);
        if ($sent) {
            $wpdb->update(tnmp_table('attendees'), array('invite_state' => 'sent'), array('id' => $attendee->id));
        }
        return $sent;
    }
    $slot = tnmp_get_slot($job->slot_id);
    $event = tnmp_get_event($attendee->id, $job->slot_id);
    if (!$slot || !$event || (int) $slot->poll_id !== (int) $poll->id) {
        return 'skip';
    }
    $expected = array('hold' => 'queued_hold', 'cancel' => 'queued_cancel', 'final' => 'queued_final');
    if (!isset($expected[$job->job_type]) || $event->state !== $expected[$job->job_type]) {
        return 'skip';
    }
    if ($job->job_type === 'hold' && ($poll->status !== 'open' || $slot->status !== 'open' || tnmp_vote_choice($attendee->id, $slot->id) === 'no')) {
        $wpdb->update(tnmp_table('events'), array('state' => 'none'), array('id' => $event->id));
        return 'skip';
    }
    if ($job->job_type === 'final' && ($poll->status !== 'final' || (int) $poll->final_slot_id !== (int) $slot->id || tnmp_vote_choice($attendee->id, $slot->id) === 'no')) {
        return 'skip';
    }
    if (!tnmp_mail_calendar($attendee, $poll, $slot, $event, $job->job_type)) {
        return false;
    }
    $increment = (int) $event->sent_once ? 1 : 0;
    $wpdb->update(tnmp_table('events'), array(
        'state' => array('hold' => 'held', 'cancel' => 'cancelled', 'final' => 'final')[$job->job_type],
        'sequence_no' => (int) $event->sequence_no + ($increment ? 1 : 0),
        'sent_once' => 1,
    ), array('id' => $event->id));
    return true;
}

function tnmp_vote_choice($attendee_id, $slot_id) {
    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare('SELECT choice FROM ' . tnmp_table('votes') . ' WHERE attendee_id = %d AND slot_id = %d', $attendee_id, $slot_id));
}
