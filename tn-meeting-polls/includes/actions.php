<?php
if (!defined('ABSPATH')) {
    exit;
}

function tnmp_return_url() {
    $url = isset($_POST['tnmp_return']) ? esc_url_raw(wp_unslash($_POST['tnmp_return'])) : '';
    return wp_validate_redirect($url, admin_url('admin.php?page=tn-meeting-polls'));
}

function tnmp_redirect($code, $poll_id = 0) {
    $view = in_array($code, array('finalized', 'cancelled'), true) ? 'history' : (in_array($code, array('created', 'edited'), true) ? 'open' : '');
    if (strpos($code, 'settings_') === 0) { $view = 'settings'; }
    if (in_array($code, array('deleted', 'delete_unavailable'), true)) { $view = 'history'; }
    if (strpos($code, 'contact_') === 0) { $view = 'contacts'; }
    if (strpos($code, 'template_') === 0) { $view = 'templates'; }
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

function tnmp_parse_proposed_slots($dates, $times, $duration, $timezone, $hours = null, $existing_slots = array()) {
    if (!is_array($dates) || !is_array($times) || !$dates || count($dates) > 10 || count($dates) !== count($times) || array_diff_key($dates, $times) || !is_scalar($duration) || !preg_match('/^\d+$/D', (string) $duration)) {
        return false;
    }
    $minutes = (int) $duration;
    if ($minutes < 5 || $minutes > 1440 || $minutes % 5 !== 0) { return false; }
    $slots = array();
    $seen = array();
    $existing = array();
    foreach ($existing_slots as $slot) {
        $existing[$slot->starts_at . '|' . $slot->ends_at] = true;
    }
    foreach ($dates as $index => $date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !is_array($times[$index]) || !$times[$index]) {
            return false;
        }
        foreach ($times[$index] as $time) {
            if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time)) {
                return false;
            }
            if (count($slots) >= 10) { return false; }
            $start = tnmp_parse_local_datetime($date . 'T' . $time, $timezone);
            if (!$start || isset($seen[$start->format('Y-m-d H:i:s')])) { return false; }
            $seen[$start->format('Y-m-d H:i:s')] = true;
            $end = $start->modify('+' . $minutes . ' minutes');
            $unchanged = isset($existing[$start->format('Y-m-d H:i:s') . '|' . $end->format('Y-m-d H:i:s')]);
            if (!$unchanged && ((int) substr($time, 3, 2)) % 10 !== 0) { return false; }
            if (!$unchanged && $start->getTimestamp() < time() + 300) { return false; }
            if (!$unchanged && is_array($hours)) {
                $local_start = $start->setTimezone(new DateTimeZone($timezone));
                $local_end = $end->setTimezone(new DateTimeZone($timezone));
                $earliest = sprintf('%02d:00', $hours['earliest']);
                $latest = sprintf('%02d:00', $hours['latest']);
                $end_in_window = $hours['latest'] === 24
                    ? ($local_end->format('Y-m-d') === $date || ($local_end->format('Y-m-d H:i') === (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00'))
                    : ($local_end->format('Y-m-d') === $date && $local_end->format('H:i') <= $latest);
                if ($local_start->format('H:i') < $earliest || !$end_in_window) {
                    return false;
                }
            }
            $slots[] = array($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
        }
    }
    return $slots;
}

function tnmp_submitted_times() {
    $hours = isset($_POST['time_hours']) && is_array($_POST['time_hours']) ? wp_unslash($_POST['time_hours']) : null;
    $minutes = isset($_POST['time_minutes']) && is_array($_POST['time_minutes']) ? wp_unslash($_POST['time_minutes']) : null;
    if ($hours === null && $minutes === null) {
        return isset($_POST['times']) && is_array($_POST['times']) ? wp_unslash($_POST['times']) : false;
    }
    if (!is_array($hours) || !is_array($minutes) || array_keys($hours) !== array_keys($minutes)) { return false; }
    $times = array();
    foreach ($hours as $date_index => $hour_values) {
        if (!is_array($hour_values) || !isset($minutes[$date_index]) || !is_array($minutes[$date_index]) || count($hour_values) !== count($minutes[$date_index])) { return false; }
        foreach ($hour_values as $position => $hour) {
            $minute = $minutes[$date_index][$position] ?? null;
            if (!is_string($hour) || !is_string($minute) || !preg_match('/^(?:[01]\d|2[0-3])$/D', $hour) || !preg_match('/^[0-5]\d$/D', $minute)) { return false; }
            $times[$date_index][] = $hour . ':' . $minute;
        }
    }
    return $times;
}

function tnmp_submitted_attendees() {
    $emails = isset($_POST['emails']) && is_array($_POST['emails']) ? array_values(wp_unslash($_POST['emails'])) : array();
    $names = isset($_POST['names']) && is_array($_POST['names']) ? array_values(wp_unslash($_POST['names'])) : array();
    $holds = isset($_POST['holds']) && is_array($_POST['holds']) ? wp_unslash($_POST['holds']) : array();
    if (count($emails) > 50) { return false; }
    $attendees = array();
    $seen = array();
    foreach ($emails as $index => $raw_email) {
        if (!is_scalar($raw_email)) { return false; }
        if (trim((string) $raw_email) === '') { continue; }
        $email = sanitize_email($raw_email);
        if (!is_email($email) || strlen($email) > 190 || isset($seen[strtolower($email)])) { return false; }
        $seen[strtolower($email)] = true;
        $attendees[] = array('email' => $email, 'name' => sanitize_text_field($names[$index] ?? ''), 'holds' => !empty($holds[$index]) ? 1 : 0);
    }
    return $attendees ?: false;
}

function tnmp_save_settings() {
    if (!is_user_logged_in() || !current_user_can('read')) {
        wp_die('You must be logged in.', '', array('response' => 403));
    }
    check_admin_referer('tnmp_save_settings');
    $duration = wp_unslash($_POST['duration'] ?? '');
    $earliest = wp_unslash($_POST['earliest'] ?? '');
    $latest = wp_unslash($_POST['latest'] ?? '');
    $retention_days = wp_unslash($_POST['retention_days'] ?? '');
    if (!is_scalar($duration) || !is_scalar($earliest) || !is_scalar($latest) || !is_scalar($retention_days) || !preg_match('/^\d+$/D', (string) $duration) || !preg_match('/^\d+$/D', (string) $earliest) || !preg_match('/^\d+$/D', (string) $latest) || !preg_match('/^\d+$/D', (string) $retention_days)) {
        tnmp_redirect('settings_invalid');
    }
    $duration = (int) $duration;
    $earliest = (int) $earliest;
    $latest = (int) $latest;
    $retention_days = (int) $retention_days;
    if ($duration < 5 || $duration > 1440 || $duration % 5 !== 0 || $earliest < 0 || $earliest > 22 || $latest < 1 || $latest > 24 || $earliest >= $latest || $duration > ($latest - $earliest) * 60 || $retention_days < 1 || $retention_days > 3650) {
        tnmp_redirect('settings_invalid');
    }
    update_user_meta(get_current_user_id(), 'tnmp_settings', array('duration' => $duration, 'earliest' => $earliest, 'latest' => $latest, 'retention_days' => $retention_days));
    tnmp_redirect('settings_saved');
}

function tnmp_contact_action() {
    if (!is_user_logged_in() || !current_user_can('read')) {
        wp_die('You must be logged in.', '', array('response' => 403));
    }
    check_admin_referer('tnmp_contact');
    global $wpdb;
    $user_id = get_current_user_id();
    $id = absint($_POST['contact_id'] ?? 0);
    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
    $existing = $id ? tnmp_get_contact($id, $user_id) : null;
    if ($operation === 'delete') {
        if (!$existing) { tnmp_redirect('contact_invalid'); }
        $wpdb->delete(tnmp_table('contacts'), array('id' => $id, 'organiser_id' => $user_id));
        tnmp_redirect('contact_deleted');
    }
    if (!in_array($operation, array('add', 'edit'), true) || ($operation === 'edit' && !$existing)) {
        tnmp_redirect('contact_invalid');
    }
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    if ($name === '' || strlen($name) > 190 || !is_email($email) || strlen($email) > 190) {
        tnmp_redirect('contact_invalid');
    }
    $data = array('name' => $name, 'email' => $email);
    $saved = $operation === 'add'
        ? $wpdb->insert(tnmp_table('contacts'), array_merge(array('organiser_id' => $user_id), $data))
        : $wpdb->update(tnmp_table('contacts'), $data, array('id' => $id, 'organiser_id' => $user_id));
    tnmp_redirect($saved === false ? 'contact_duplicate' : 'contact_saved');
}

function tnmp_template_action() {
    if (!is_user_logged_in() || !current_user_can('read')) {
        wp_die('You must be logged in.', '', array('response' => 403));
    }
    check_admin_referer('tnmp_template');
    global $wpdb;
    $user_id = get_current_user_id();
    $id = absint($_POST['template_id'] ?? 0);
    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
    $existing = $id ? tnmp_get_template($id, $user_id) : null;
    if ($operation === 'delete') {
        if (!$existing) { tnmp_redirect('template_invalid'); }
        $deleted = $wpdb->delete(tnmp_table('templates'), array('id' => $id, 'organiser_id' => $user_id));
        tnmp_redirect($deleted ? 'template_deleted' : 'template_invalid');
    }
    if (!in_array($operation, array('add', 'edit'), true) || ($operation === 'edit' && !$existing)) {
        tnmp_redirect('template_invalid');
    }
    $title = wp_unslash($_POST['title'] ?? '');
    $description = wp_unslash($_POST['description'] ?? '');
    $duration = wp_unslash($_POST['duration'] ?? '');
    if (!is_scalar($title) || !is_scalar($description) || !is_scalar($duration) || !preg_match('/^\d+$/D', (string) $duration)) {
        tnmp_redirect('template_invalid');
    }
    $title = sanitize_text_field($title);
    $description = sanitize_textarea_field($description);
    $duration = (int) $duration;
    if ($title === '' || strlen($title) > 190 || $duration < 5 || $duration > 1440 || $duration % 5 !== 0) {
        tnmp_redirect('template_invalid');
    }
    $data = array('title' => $title, 'description' => $description, 'duration' => $duration);
    $saved = $operation === 'add'
        ? $wpdb->insert(tnmp_table('templates'), array_merge(array('organiser_id' => $user_id), $data))
        : $wpdb->update(tnmp_table('templates'), $data, array('id' => $id, 'organiser_id' => $user_id));
    tnmp_redirect($saved === false ? 'template_invalid' : 'template_saved');
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
    $times = tnmp_submitted_times();
    $duration = isset($_POST['duration']) ? wp_unslash($_POST['duration']) : '';
    if ($title === '' || strlen($title) > 190) {
        tnmp_redirect('invalid');
    }
    $slots = tnmp_parse_proposed_slots($dates, $times, $duration, $timezone, tnmp_user_settings(get_current_user_id()));
    if ($slots === false) { tnmp_redirect('invalid_time'); }
    $attendees = tnmp_submitted_attendees();
    if ($attendees === false) { tnmp_redirect('invalid_attendee'); }
    if (!$slots || !$attendees) {
        tnmp_redirect('invalid');
    }
    $wpdb->query('START TRANSACTION');
    $ok = $wpdb->insert(tnmp_table('polls'), array(
        'organiser_id' => get_current_user_id(), 'title' => $title, 'description' => $description,
        'timezone' => $timezone, 'status' => 'open', 'final_slot_id' => 0, 'created_at' => tnmp_now(), 'updated_at' => tnmp_now(),
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

function tnmp_edit() {
    if (!is_user_logged_in() || !current_user_can('read')) {
        wp_die('You must be logged in to edit a meeting poll.', '', array('response' => 403));
    }
    check_admin_referer('tnmp_edit');
    global $wpdb;
    $poll_id = absint($_POST['poll_id'] ?? 0);
    $poll = tnmp_get_poll($poll_id);
    if (!$poll || (int) $poll->organiser_id !== get_current_user_id() || $poll->status !== 'open') {
        wp_die('You cannot edit this poll.', '', array('response' => 403));
    }
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $timezone = sanitize_text_field(wp_unslash($_POST['timezone'] ?? ''));
    if ($title === '' || strlen($title) > 190 || !tnmp_valid_timezone($timezone)) { tnmp_redirect('invalid', $poll_id); }
    $dates = isset($_POST['dates']) && is_array($_POST['dates']) ? wp_unslash($_POST['dates']) : array();
    $times = tnmp_submitted_times();
    $duration = isset($_POST['duration']) ? wp_unslash($_POST['duration']) : '';
    $old_slots = tnmp_get_slots($poll_id);
    $old_attendees = tnmp_get_attendees($poll_id);
    $slots = tnmp_parse_proposed_slots($dates, $times, $duration, $timezone, tnmp_user_settings(get_current_user_id()), $old_slots);
    if ($slots === false) { tnmp_redirect('invalid_time', $poll_id); }
    $people = tnmp_submitted_attendees();
    if ($people === false) { tnmp_redirect('invalid_attendee', $poll_id); }

    $old_slot_keys = array();
    $new_slot_keys = array();
    foreach ($old_slots as $slot) { $old_slot_keys[$slot->starts_at . '|' . $slot->ends_at] = $slot; }
    foreach ($slots as $slot) { $new_slot_keys[$slot[0] . '|' . $slot[1]] = $slot; }
    $old_people = array();
    $new_people = array();
    foreach ($old_attendees as $attendee) { $old_people[strtolower($attendee->email)] = $attendee; }
    foreach ($people as $person) { $new_people[strtolower($person['email'])] = $person; }
    $removed_slots = array();
    $added_slot_ids = array();
    $removed_people = array();
    $added_person_ids = array();
    $holds_off = array();
    $holds_on = array();
    $metadata_changed = $title !== $poll->title || $description !== $poll->description || $timezone !== $poll->timezone;
    $old_slot_names = array_keys($old_slot_keys);
    $new_slot_names = array_keys($new_slot_keys);
    sort($old_slot_names);
    sort($new_slot_names);
    $options_changed = $old_slot_names !== $new_slot_names;

    $wpdb->query('START TRANSACTION');
    $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . tnmp_table('polls') . ' WHERE id = %d FOR UPDATE', $poll_id));
    if (!$locked || $locked->status !== 'open' || (int) $locked->organiser_id !== get_current_user_id()) {
        $wpdb->query('ROLLBACK');
        tnmp_redirect('already_closed', $poll_id);
    }
    $ok = $wpdb->update(tnmp_table('polls'), array('title' => $title, 'description' => $description, 'timezone' => $timezone, 'updated_at' => tnmp_now()), array('id' => $poll_id)) !== false;
    foreach ($old_slots as $slot) {
        if (!isset($new_slot_keys[$slot->starts_at . '|' . $slot->ends_at])) {
            $ok = $ok && $wpdb->update(tnmp_table('slots'), array('status' => 'removed'), array('id' => $slot->id)) !== false;
            $removed_slots[] = $slot;
        }
    }
    foreach ($slots as $slot) {
        if (!isset($old_slot_keys[$slot[0] . '|' . $slot[1]])) {
            $inserted = $wpdb->insert(tnmp_table('slots'), array('poll_id' => $poll_id, 'starts_at' => $slot[0], 'ends_at' => $slot[1], 'status' => 'open'));
            if (!$inserted) { $ok = false; break; }
            $added_slot_ids[] = (int) $wpdb->insert_id;
        }
    }
    foreach ($old_attendees as $attendee) {
        $key = strtolower($attendee->email);
        if (!isset($new_people[$key])) {
            $ok = $ok && $wpdb->update(tnmp_table('attendees'), array('invite_state' => 'removed', 'otp_hash' => '', 'session_hash' => ''), array('id' => $attendee->id)) !== false;
            $removed_people[] = $attendee;
        } else {
            $person = $new_people[$key];
            $ok = $ok && $wpdb->update(tnmp_table('attendees'), array('name' => $person['name'], 'holds' => $person['holds']), array('id' => $attendee->id)) !== false;
            if ($attendee->holds && !$person['holds']) { $holds_off[] = $attendee; }
            if (!$attendee->holds && $person['holds']) { $holds_on[] = $attendee; }
        }
    }
    $host = parse_url(home_url(), PHP_URL_HOST) ?: 'local';
    foreach ($people as $person) {
        if (isset($old_people[strtolower($person['email'])])) { continue; }
        $nonce = bin2hex(random_bytes(32));
        $token = tnmp_token_from_nonce($nonce);
        $inserted = $wpdb->insert(tnmp_table('attendees'), array(
            'poll_id' => $poll_id, 'name' => $person['name'], 'email' => $person['email'],
            'holds' => $person['holds'], 'invite_state' => 'queued',
            'token_nonce' => $nonce, 'token_hash' => hash('sha256', $token),
        ));
        if (!$inserted) { $ok = false; break; }
        $attendee_id = (int) $wpdb->insert_id;
        $added_person_ids[] = $attendee_id;
        foreach (tnmp_get_slots($poll_id) as $slot) {
            $inserted = $wpdb->insert(tnmp_table('events'), array('attendee_id' => $attendee_id, 'slot_id' => $slot->id, 'uid' => wp_generate_uuid4() . '@' . $host, 'sequence_no' => 0, 'sent_once' => 0, 'state' => 'none'));
            if (!$inserted) { $ok = false; break 2; }
        }
    }
    foreach ($old_attendees as $attendee) {
        if (isset($new_people[strtolower($attendee->email)])) {
            foreach ($added_slot_ids as $slot_id) {
                $inserted = $wpdb->insert(tnmp_table('events'), array('attendee_id' => $attendee->id, 'slot_id' => $slot_id, 'uid' => wp_generate_uuid4() . '@' . $host, 'sequence_no' => 0, 'sent_once' => 0, 'state' => 'none'));
                if (!$inserted) { $ok = false; break 2; }
            }
        }
    }
    if (!$ok) {
        $wpdb->query('ROLLBACK');
        tnmp_redirect('database', $poll_id);
    }
    $wpdb->query('COMMIT');

    foreach ($removed_slots as $slot) {
        foreach ($old_attendees as $attendee) { tnmp_queue_event($attendee, $slot, 'cancel'); }
    }
    foreach (array_merge($removed_people, $holds_off) as $attendee) {
        foreach ($old_slots as $slot) { tnmp_queue_event($attendee, $slot, 'cancel'); }
    }
    $current_slots = tnmp_get_slots($poll_id);
    foreach ($added_person_ids as $attendee_id) { tnmp_queue('invite', $poll_id, $attendee_id); }
    foreach ($old_attendees as $old_attendee) {
        if (!isset($new_people[strtolower($old_attendee->email)])) { continue; }
        $attendee = tnmp_get_attendee($old_attendee->id);
        if ($options_changed || $metadata_changed) {
            $wpdb->update(tnmp_table('attendees'), array('invite_state' => 'queued'), array('id' => $attendee->id));
            tnmp_queue('invite', $poll_id, $attendee->id);
        }
        if (!$attendee->holds) { continue; }
        foreach ($current_slots as $slot) {
            if ($slot->status !== 'open' || tnmp_vote_choice($attendee->id, $slot->id) === 'no') { continue; }
            if (in_array((int) $slot->id, $added_slot_ids, true) || in_array($old_attendee, $holds_on, true)) {
                tnmp_queue_event($attendee, $slot, 'hold');
            } elseif ($metadata_changed) {
                tnmp_queue_hold_update($attendee, $slot);
            }
        }
    }
    foreach ($added_person_ids as $attendee_id) {
        $attendee = tnmp_get_attendee($attendee_id);
        if (!$attendee->holds) { continue; }
        foreach ($current_slots as $slot) {
            if ($slot->status === 'open') { tnmp_queue_event($attendee, $slot, 'hold'); }
        }
    }
    tnmp_process_queue(3);
    tnmp_redirect('edited', $poll_id);
}

function tnmp_queue_hold_update($attendee, $slot) {
    global $wpdb;
    $event = tnmp_get_event($attendee->id, $slot->id);
    if ($event && $event->state === 'held') {
        $wpdb->update(tnmp_table('events'), array('state' => 'queued_hold'), array('id' => $event->id));
        tnmp_queue('hold', $attendee->poll_id, $attendee->id, $slot->id);
    }
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
    if ($action === 'delete') {
        tnmp_redirect(tnmp_delete_historical_poll($poll_id, get_current_user_id()) ? 'deleted' : 'delete_unavailable');
    }
    if ($action === 'retry') {
        $wpdb->update(tnmp_table('polls'), array('updated_at' => tnmp_now()), array('id' => $poll_id));
        $wpdb->query($wpdb->prepare("UPDATE " . tnmp_table('jobs') . " SET state = 'pending', attempts = 0, available_at = %s, last_error = '' WHERE poll_id = %d AND state = 'failed'", tnmp_now(), $poll_id));
        tnmp_process_queue(3);
        tnmp_redirect('retry_queued', $poll_id);
    }
    if ($poll->status !== 'open') {
        tnmp_redirect('already_closed', $poll_id);
    }
    if ($action === 'resend') {
        $attendee = tnmp_get_attendee(absint($_POST['attendee_id'] ?? 0));
        if (!$attendee || (int) $attendee->poll_id !== $poll_id || in_array($attendee->invite_state, array('queued', 'removed'), true)) {
            tnmp_redirect('invalid', $poll_id);
        }
        $nonce = bin2hex(random_bytes(32));
        $token = tnmp_token_from_nonce($nonce);
        $wpdb->update(tnmp_table('attendees'), array('token_nonce' => $nonce, 'token_hash' => hash('sha256', $token), 'invite_state' => 'queued'), array('id' => $attendee->id));
        $wpdb->update(tnmp_table('polls'), array('updated_at' => tnmp_now()), array('id' => $poll_id));
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
        $wpdb->update(tnmp_table('polls'), array('updated_at' => tnmp_now()), array('id' => $poll_id));
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
        $wpdb->update(tnmp_table('polls'), array('status' => 'final', 'final_slot_id' => $slot_id, 'updated_at' => tnmp_now()), array('id' => $poll_id));
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
        $wpdb->update(tnmp_table('polls'), array('status' => 'cancelled', 'updated_at' => tnmp_now()), array('id' => $poll_id));
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
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . tnmp_table('attendees') . " WHERE token_hash = %s AND invite_state <> 'removed'", hash('sha256', $token)));
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
    return 'You are verified. You can now vote.';
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
    if (!$slot || !$poll || $attendee->invite_state === 'removed' || (int) $slot->poll_id !== (int) $poll->id || $slot->status !== 'open' || $poll->status !== 'open' || !in_array($choice, array('prefer', 'yes', 'no'), true)) {
        return 'This time is no longer open for voting.';
    }
    $ok = $wpdb->replace(tnmp_table('votes'), array(
        'attendee_id' => $attendee->id, 'slot_id' => $slot_id, 'choice' => $choice, 'updated_at' => tnmp_now(),
    ));
    if (!$ok) {
        return 'Your vote could not be saved. Please try again.';
    }
    $wpdb->update(tnmp_table('polls'), array('updated_at' => tnmp_now()), array('id' => $poll->id));
    if ($attendee->holds) {
        tnmp_queue_event($attendee, $slot, $choice === 'no' ? 'cancel' : 'hold');
        tnmp_process_queue(3);
    }
    return 'Your vote was saved.';
}
