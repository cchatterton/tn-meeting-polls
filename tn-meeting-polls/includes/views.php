<?php
if (!defined('ABSPATH')) {
    exit;
}

function tnmp_notice_text($code) {
    $notices = array(
        'created' => 'Poll created. Invitation and calendar emails are being sent.',
        'invalid' => 'Please complete the meeting title, at least one valid time, and at least one attendee.',
        'invalid_time' => 'Check the proposed dates, start times and duration. Start times must use 10-minute steps and be in the future.',
        'invalid_attendee' => 'Check attendee email addresses and remove duplicates.',
        'database' => 'The poll could not be saved. Please try again.',
        'slot_closed' => 'That proposed time is closed. Placeholder cancellations have been queued.',
        'finalized' => 'The final time has been selected. Calendar updates have been queued.',
        'cancelled' => 'The poll is cancelled. Placeholder cancellations have been queued.',
        'already_closed' => 'This poll is no longer open.',
        'resent' => 'A new voting link was queued. The earlier link has been replaced.',
        'retry_queued' => 'Failed email messages have been queued for another attempt.',
        'settings_saved' => 'Meeting defaults saved.',
        'settings_invalid' => 'Check the duration and meeting hours. The duration must fit within the hours.',
        'contact_saved' => 'Contact saved.',
        'contact_deleted' => 'Contact removed. Existing polls are unchanged.',
        'contact_invalid' => 'Enter a name and valid email address for this contact.',
        'contact_duplicate' => 'That email address is already in your contacts.',
        'edited' => 'Poll updated. Invitations and placeholder changes have been queued.',
    );
    return $notices[$code] ?? '';
}

function tnmp_admin_page() {
    echo '<div class="wrap tnmp-wrap"><h1 class="screen-reader-text">TN Meeting Polls</h1>';
    echo tnmp_render_app(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '</div>';
}

function tnmp_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="tnmp-wrap"><p>Please log in to create or manage meeting polls.</p></div>';
    }
    return '<div class="tnmp-wrap">' . tnmp_render_app() . '</div>';
}

function tnmp_render_app() {
    if (!current_user_can('read')) {
        return '<p>You cannot create meeting polls.</p>';
    }
    $code = sanitize_key(wp_unslash($_GET['tnmp_notice'] ?? ''));
    $notice = tnmp_notice_text($code);
    $view = sanitize_key(wp_unslash($_GET['tnmp_view'] ?? 'open'));
    if (!in_array($view, array('create', 'open', 'history', 'settings', 'contacts'), true)) { $view = 'open'; }
    ob_start();
    if ($notice) {
        echo '<div class="notice notice-info tnmp-notice" role="status"><p>' . esc_html($notice) . '</p></div>';
    }
    echo '<header class="tnmp-hero"><div><p class="tnmp-hero-eyebrow">PLAN. VOTE. MEET.</p><h2>Meeting Polls</h2><p>Find a time that works, with verified votes and optional calendar holds.</p></div><span class="tnmp-version" aria-label="' . esc_attr('Version ' . TNMP_VERSION) . '">v' . esc_html(TNMP_VERSION) . '</span></header>';
    echo '<nav class="tnmp-view-nav" aria-label="Meeting poll views">';
    foreach (array('create' => 'Create a poll', 'open' => 'Open polls', 'history' => 'History', 'contacts' => 'Attendees', 'settings' => 'Settings') as $key => $label) {
        echo '<a href="' . esc_url(tnmp_view_url($key)) . '"' . ($view === $key ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
    }
    echo '</nav>';
    if ($view === 'create') {
        $edit_id = absint($_GET['tnmp_edit'] ?? 0);
        $edit_poll = $edit_id ? tnmp_get_poll($edit_id) : null;
        if ($edit_id && (!$edit_poll || (int) $edit_poll->organiser_id !== get_current_user_id() || $edit_poll->status !== 'open')) {
            echo '<p class="tnmp-notice">This poll is not available to edit.</p>';
        } else {
            echo '<h2>' . ($edit_poll ? 'Edit meeting poll' : 'Create a meeting poll') . '</h2>';
            tnmp_render_create_form($edit_poll);
        }
    } elseif ($view === 'contacts') {
        echo '<h2>Attendees</h2>';
        tnmp_render_contacts();
    } elseif ($view === 'settings') {
        echo '<h2>Settings</h2>';
        tnmp_render_settings();
    } else {
        echo '<h2>' . ($view === 'open' ? 'Open polls' : 'History') . '</h2>';
        if ($view === 'open') { echo '<p id="tnmp-refresh-status" class="tnmp-refresh-status" role="status" aria-live="polite"></p>'; }
        echo '<div' . ($view === 'open' ? ' id="tnmp-results"' : '') . '>';
        tnmp_render_dashboard($view);
        echo '</div>';
    }
    return ob_get_clean();
}

function tnmp_view_url($view) {
    $base = is_admin() ? admin_url('admin.php?page=tn-meeting-polls') : get_permalink();
    return add_query_arg('tnmp_view', $view, $base);
}

function tnmp_return_field() {
    if (wp_doing_ajax() && isset($_POST['return_url'])) {
        $url = wp_validate_redirect(esc_url_raw(wp_unslash($_POST['return_url'])), admin_url('admin.php?page=tn-meeting-polls'));
    } else {
        $url = is_admin() ? admin_url('admin.php?page=tn-meeting-polls') : get_permalink();
    }
    echo '<input type="hidden" name="tnmp_return" value="' . esc_url($url) . '">';
}

function tnmp_render_create_form($poll = null) {
    $settings = tnmp_user_settings(get_current_user_id());
    $zone = $poll ? $poll->timezone : (wp_timezone_string() ?: 'UTC');
    if (!tnmp_valid_timezone($zone)) { $zone = 'UTC'; }
    $groups = array();
    $poll_attendees = array();
    $mixed_duration = false;
    if ($poll) {
        $poll_attendees = tnmp_get_attendees($poll->id);
        foreach (tnmp_get_slots($poll->id) as $slot) {
            $start = (new DateTimeImmutable($slot->starts_at, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone));
            $groups[$start->format('Y-m-d')][] = array('time' => $start->format('H:i'), 'closed' => $slot->status === 'closed');
            $slot_duration = (int) ((strtotime($slot->ends_at . ' UTC') - strtotime($slot->starts_at . ' UTC')) / 60);
            if (!isset($duration)) { $duration = $slot_duration; }
            elseif ($slot_duration !== $duration) { $mixed_duration = true; }
        }
    }
    $duration = $duration ?? $settings['duration'];
    $timezone_options = timezone_identifiers_list();
    echo '<form class="tnmp-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="' . ($poll ? 'tnmp_edit' : 'tnmp_create') . '">';
    if ($poll) { echo '<input type="hidden" name="poll_id" value="' . (int) $poll->id . '">'; }
    wp_nonce_field($poll ? 'tnmp_edit' : 'tnmp_create');
    tnmp_return_field();
    echo '<label>Meeting title <input type="text" name="title" maxlength="190" value="' . esc_attr($poll ? $poll->title : '') . '" required></label>';
    echo '<label>Details <textarea name="description" rows="3" placeholder="Purpose, location, or meeting link">' . esc_html($poll ? $poll->description : '') . '</textarea></label>';
    echo '<label>Time zone (defaults to the WordPress site setting) <select name="timezone" required>';
    if (!in_array($zone, $timezone_options, true)) {
        echo '<option value="' . esc_attr($zone) . '" selected>' . esc_html($zone) . ' (site setting)</option>';
    }
    foreach ($timezone_options as $timezone) {
        echo '<option value="' . esc_attr($timezone) . '"' . selected($timezone, $zone, false) . '>' . esc_html($timezone) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Meeting duration (minutes) <input type="number" name="duration" min="5" max="1440" step="5" value="' . (int) $duration . '" required></label>';
    if ($mixed_duration) { echo '<p class="tnmp-notice">This older poll has different durations. Saving will apply the duration above to every proposed time and replace times whose duration changes.</p>'; }
    echo '<p class="tnmp-muted">Your meeting hours are ' . esc_html(tnmp_hour_label($settings['earliest'])) . ' to ' . esc_html(tnmp_hour_label($settings['latest'])) . '. Change them in Settings.</p>';
    echo '<h3>Proposed dates and times</h3><p>Pick a date, then add one or more start times in 10-minute steps. The duration applies to every proposed time. Up to 10 options total.</p>';
    echo '<div id="tnmp-dates" data-earliest="' . (int) $settings['earliest'] . '" data-latest="' . (int) $settings['latest'] . '" data-editing="' . ($poll ? '1' : '0') . '">';
    if ($groups) {
        $index = 0;
        foreach ($groups as $date => $items) { tnmp_date_group($index++, $date, $items); }
    } else { tnmp_date_group(0); }
    echo '</div><button type="button" class="button" id="tnmp-add-date">Add date</button>';
    echo '<h3>Attendees</h3><p>Select “Send placeholders” only for people who should receive one tentative calendar invitation per proposed time.</p>';
    $contacts = tnmp_get_contacts(get_current_user_id());
    if ($contacts) {
        echo '<div class="tnmp-contact-picker"><label for="tnmp-contact-search">Find an attendee in your contacts</label><input id="tnmp-contact-search" type="search" autocomplete="off" placeholder="Type a name or email" aria-controls="tnmp-contact-matches">';
        echo '<div id="tnmp-contact-matches" class="tnmp-contact-matches" aria-live="polite">';
        foreach ($contacts as $contact) {
            echo '<div class="tnmp-contact-match" data-name="' . esc_attr($contact->name) . '" data-email="' . esc_attr($contact->email) . '" hidden><span>' . esc_html($contact->name) . ' <small>' . esc_html($contact->email) . '</small></span><button type="button" class="button tnmp-pick-contact">Add</button></div>';
        }
        echo '</div></div>';
    } else {
        echo '<p class="tnmp-muted">Add people in the Attendees tab to select them here, or enter an email below.</p>';
    }
    echo '<div id="tnmp-attendees">';
    if ($poll_attendees) {
        foreach ($poll_attendees as $index => $attendee) { tnmp_attendee_row($index, $index === 0, $attendee); }
    } else { tnmp_attendee_row(0, true); }
    echo '</div><button type="button" class="button tnmp-add" data-target="tnmp-attendees" data-limit="50">Add attendee</button>';
    if ($poll) { echo '<p class="tnmp-muted">Removing a time or attendee cancels their outstanding placeholders. Existing votes for unchanged times are kept. New options are sent to current attendees.</p>'; }
    echo '<p class="tnmp-submit"><button type="submit" class="button button-primary">' . ($poll ? 'Save poll and send updates' : 'Create poll and send invitations') . '</button></p>';
    echo '</form>';
}

function tnmp_hour_label($hour) {
    return sprintf('%02d:00', $hour);
}

function tnmp_render_settings() {
    $settings = tnmp_user_settings(get_current_user_id());
    echo '<form class="tnmp-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tnmp_save_settings">';
    wp_nonce_field('tnmp_save_settings');
    tnmp_return_field();
    echo '<p>These defaults are saved for your account. Proposed meetings must start at or after the earliest hour and finish by the latest hour.</p>';
    echo '<label>Default meeting length (minutes) <input type="number" name="duration" min="5" max="1440" step="5" value="' . (int) $settings['duration'] . '" required></label>';
    foreach (array('earliest' => 'Earliest meeting hour', 'latest' => 'Latest meeting hour') as $field => $label) {
        echo '<label>' . esc_html($label) . '<select name="' . esc_attr($field) . '">';
        for ($hour = $field === 'earliest' ? 0 : 1; $hour <= ($field === 'earliest' ? 22 : 24); $hour++) {
            echo '<option value="' . (int) $hour . '"' . selected($hour, $settings[$field], false) . '>' . esc_html(tnmp_hour_label($hour)) . '</option>';
        }
        echo '</select></label>';
    }
    echo '<p class="tnmp-submit"><button type="submit" class="button button-primary">Save settings</button></p></form>';
}

function tnmp_render_contacts() {
    $contacts = tnmp_get_contacts(get_current_user_id());
    echo '<form class="tnmp-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tnmp_contact"><input type="hidden" name="operation" value="add">';
    wp_nonce_field('tnmp_contact');
    tnmp_return_field();
    echo '<h3>Add attendee</h3><div class="tnmp-row"><label>Name <input type="text" name="name" maxlength="190" required></label><label>Email <input type="email" name="email" maxlength="190" required></label></div><button type="submit" class="button button-primary">Add to contacts</button></form>';
    if (!$contacts) { echo '<p>No attendees in your contacts yet.</p>'; return; }
    echo '<div class="tnmp-scroll"><table class="tnmp-contact-table"><thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Actions</th></tr></thead><tbody>';
    foreach ($contacts as $contact) {
        $form_id = 'tnmp-edit-contact-' . (int) $contact->id;
        echo '<tr><td><label class="tnmp-visually-hidden" for="tnmp-contact-name-' . (int) $contact->id . '">Name</label><input id="tnmp-contact-name-' . (int) $contact->id . '" form="' . esc_attr($form_id) . '" type="text" name="name" maxlength="190" value="' . esc_attr($contact->name) . '" required></td>';
        echo '<td><label class="tnmp-visually-hidden" for="tnmp-contact-email-' . (int) $contact->id . '">Email</label><input id="tnmp-contact-email-' . (int) $contact->id . '" form="' . esc_attr($form_id) . '" type="email" name="email" maxlength="190" value="' . esc_attr($contact->email) . '" required></td>';
        echo '<td><div class="tnmp-contact-actions"><form id="' . esc_attr($form_id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tnmp_contact"><input type="hidden" name="operation" value="edit"><input type="hidden" name="contact_id" value="' . (int) $contact->id . '">';
        wp_nonce_field('tnmp_contact');
        tnmp_return_field();
        echo '<button type="submit" class="button">Save</button></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-confirm="Remove this attendee from your contacts? Existing polls will be unchanged."><input type="hidden" name="action" value="tnmp_contact"><input type="hidden" name="operation" value="delete"><input type="hidden" name="contact_id" value="' . (int) $contact->id . '">';
        wp_nonce_field('tnmp_contact');
        tnmp_return_field();
        echo '<button type="submit" class="button tnmp-remove-contact">Remove</button></form></div></td></tr>';
    }
    echo '</tbody></table></div>';
}

function tnmp_date_group($index, $date = '', $items = array()) {
    echo '<fieldset class="tnmp-date-group" data-date-index="' . (int) $index . '"><legend>Proposed date</legend>';
    echo '<label>Date <input type="date" name="dates[' . (int) $index . ']" value="' . esc_attr($date) . '" required></label>';
    echo '<div class="tnmp-times">';
    if ($items) {
        foreach ($items as $item) { tnmp_time_row($index, $item['time'], $item['closed']); }
    } else { tnmp_time_row($index); }
    echo '</div><button type="button" class="button tnmp-add-time">Add time on this date</button> <button type="button" class="button tnmp-remove-date">Remove date</button></fieldset>';
}

function tnmp_time_row($index, $time = '', $closed = false) {
    $selected_hour = $time !== '' ? substr($time, 0, 2) : '';
    $selected_minute = $time !== '' ? substr($time, 3, 2) : '';
    echo '<div class="tnmp-row tnmp-time-row"><label>Start hour <select class="tnmp-time-hour" name="time_hours[' . (int) $index . '][]" required><option value="">Hour</option>';
    for ($hour = 0; $hour < 24; $hour++) {
        $value = sprintf('%02d', $hour);
        $label = sprintf('%02d', $hour % 12 ?: 12) . ($hour < 12 ? ' am' : ' pm');
        echo '<option value="' . esc_attr($value) . '"' . ($value === $selected_hour ? ' selected' : '') . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label><label>Minutes <select class="tnmp-time-minute" name="time_minutes[' . (int) $index . '][]" required><option value="">Min</option>';
    for ($minute = 0; $minute < 60; $minute += 10) {
        $value = sprintf('%02d', $minute);
        echo '<option value="' . esc_attr($value) . '"' . ($value === $selected_minute ? ' selected' : '') . '>' . esc_html($value) . '</option>';
    }
    if ($selected_minute !== '' && (int) $selected_minute % 10 !== 0) {
        echo '<option value="' . esc_attr($selected_minute) . '" selected data-legacy="1">' . esc_html($selected_minute) . ' (existing)</option>';
    }
    echo '</select></label>';
    if ($closed) { echo '<span class="tnmp-closed">Closed</span>'; }
    echo '<button type="button" class="button tnmp-remove-time">Remove time</button></div>';
}

function tnmp_attendee_row($index, $required = false, $attendee = null) {
    echo '<div class="tnmp-row tnmp-attendee-row">';
    echo '<label>Email <input type="email" name="emails[]" value="' . esc_attr($attendee ? $attendee->email : '') . '"' . ($required ? ' required' : '') . '></label>';
    echo '<label>Name <input type="text" name="names[]" value="' . esc_attr($attendee ? $attendee->name : '') . '"></label>';
    echo '<label class="tnmp-check"><input type="checkbox" name="holds[' . (int) $index . ']" value="1"' . ($attendee && $attendee->holds ? ' checked' : '') . '> Send placeholders</label>';
    echo '<button type="button" class="button tnmp-remove-attendee">Remove</button>';
    echo '</div>';
}

function tnmp_render_dashboard($view = 'open') {
    global $wpdb;
    $condition = $view === 'history' ? "status IN ('final','cancelled')" : "status = 'open'";
    $polls = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . tnmp_table('polls') . ' WHERE organiser_id = %d AND ' . $condition . ' ORDER BY id DESC LIMIT 30', get_current_user_id()));
    if (!$polls) {
        echo '<p>' . ($view === 'history' ? 'No historical polls yet.' : 'No open polls yet.') . '</p>';
        return;
    }
    foreach ($polls as $poll) {
        tnmp_render_poll_card($poll);
    }
}

function tnmp_render_poll_card($poll) {
    global $wpdb;
    $slots = tnmp_get_slots($poll->id);
    $attendees = tnmp_get_attendees($poll->id);
    $votes = tnmp_get_votes($poll->id);
    $by_attendee = array();
    $counts = array();
    foreach ($votes as $vote) {
        $by_attendee[$vote->attendee_id][$vote->slot_id] = $vote->choice;
        $counts[$vote->slot_id][$vote->choice] = ($counts[$vote->slot_id][$vote->choice] ?? 0) + 1;
    }
    $failed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . tnmp_table('jobs') . " WHERE poll_id = %d AND state = 'failed'", $poll->id));
    $mail_error = $wpdb->get_var($wpdb->prepare("SELECT last_error FROM " . tnmp_table('jobs') . " WHERE poll_id = %d AND state IN ('pending','failed') AND last_error <> '' ORDER BY id DESC LIMIT 1", $poll->id));
    echo '<section class="tnmp-card tnmp-poll" data-poll-id="' . (int) $poll->id . '">';
    echo '<div class="tnmp-poll-head"><h3>' . esc_html($poll->title) . '</h3><span class="tnmp-status">' . esc_html(ucfirst($poll->status)) . '</span></div>';
    if ($poll->status === 'open') { echo '<p><a class="button" href="' . esc_url(add_query_arg('tnmp_edit', (int) $poll->id, tnmp_view_url('create'))) . '">Edit poll</a></p>'; }
    if ($poll->description !== '') {
        echo '<p>' . nl2br(esc_html($poll->description)) . '</p>';
    }
    echo '<p class="tnmp-muted">Time zone: ' . esc_html($poll->timezone) . ' · ' . count($attendees) . ' attendee(s) · ' . tnmp_pending_jobs($poll->id) . ' queued email message(s)';
    if ($failed) {
        echo ' · <strong>' . $failed . ' failed email message(s)</strong>';
    }
    echo '</p>';
    if ($mail_error) { echo '<p class="tnmp-notice" role="status">Calendar or invitation delivery issue: ' . esc_html($mail_error) . '</p>'; }
    if ($failed) {
        tnmp_action_form($poll->id, 0, 'retry', 'Retry failed emails', 'Retry failed email messages for this poll?');
    }
    echo '<div class="tnmp-scroll"><table class="tnmp-table"><thead><tr><th>Proposed time</th><th>Prefer</th><th>Yes</th><th>No</th><th>No vote</th><th>Action</th></tr></thead><tbody>';
    foreach ($slots as $slot) {
        $no_vote = 0;
        foreach ($attendees as $attendee) {
            if (empty($by_attendee[$attendee->id][$slot->id])) { $no_vote++; }
        }
        echo '<tr><td><strong>' . esc_html(tnmp_format_time($slot->starts_at, $poll->timezone)) . '</strong><br><span class="tnmp-muted">to ' . esc_html(tnmp_format_time($slot->ends_at, $poll->timezone)) . '</span>';
        if ($slot->status === 'closed') { echo '<br><span class="tnmp-closed">Closed</span>'; }
        if ((int) $poll->final_slot_id === (int) $slot->id) { echo '<br><span class="tnmp-final">Selected</span>'; }
        echo '</td><td>' . (int) ($counts[$slot->id]['prefer'] ?? 0) . '</td><td>' . (int) ($counts[$slot->id]['yes'] ?? 0) . '</td><td>' . (int) ($counts[$slot->id]['no'] ?? 0) . '</td><td>' . $no_vote . '</td><td>';
        if ($poll->status === 'open' && $slot->status === 'open') {
            tnmp_action_form($poll->id, $slot->id, 'finalize', 'Choose final time', 'Send the final invitation to everyone who has not voted No for this time? Other placeholders will be cancelled.');
            tnmp_action_form($poll->id, $slot->id, 'close', 'Close time', 'Close this time and cancel its placeholders for all attendees?');
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<details><summary>Individual votes</summary><div class="tnmp-scroll"><table class="tnmp-table"><thead><tr><th>Attendee</th>';
    foreach ($slots as $slot) {
        echo '<th>' . esc_html(tnmp_format_time($slot->starts_at, $poll->timezone)) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($attendees as $attendee) {
        echo '<tr><th scope="row">' . esc_html($attendee->name ?: $attendee->email) . '<br><span class="tnmp-muted">' . esc_html($attendee->email) . ($attendee->holds ? ' · placeholders' : '') . ' · invitation ' . esc_html($attendee->invite_state) . '</span>';
        if ($poll->status === 'open' && $attendee->invite_state !== 'queued') {
            echo '<br>';
            tnmp_action_form($poll->id, 0, 'resend', 'Resend voting link', 'Send a new voting link to this attendee? The old link will stop working.', $attendee->id);
        }
        echo '</th>';
        foreach ($slots as $slot) {
            echo '<td>' . esc_html(ucfirst($by_attendee[$attendee->id][$slot->id] ?? '—')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></details>';
    if ($poll->status === 'open') {
        tnmp_action_form($poll->id, 0, 'cancel', 'Cancel poll', 'Cancel this poll and all outstanding calendar placeholders?');
    }
    echo '</section>';
}

function tnmp_action_form($poll_id, $slot_id, $action, $label, $confirm, $attendee_id = 0) {
    echo '<form class="tnmp-inline" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-confirm="' . esc_attr($confirm) . '">';
    echo '<input type="hidden" name="action" value="tnmp_manage"><input type="hidden" name="tnmp_action" value="' . esc_attr($action) . '"><input type="hidden" name="poll_id" value="' . (int) $poll_id . '"><input type="hidden" name="slot_id" value="' . (int) $slot_id . '"><input type="hidden" name="attendee_id" value="' . (int) $attendee_id . '">';
    wp_nonce_field('tnmp_manage');
    tnmp_return_field();
    echo '<button type="submit" class="button">' . esc_html($label) . '</button></form>';
}

function tnmp_ajax_results() {
    if (!is_user_logged_in() || !current_user_can('read') || !check_ajax_referer('tnmp_results', 'nonce', false)) {
        wp_send_json_error('Not authorised', 403);
    }
    ob_start();
    tnmp_render_dashboard('open');
    wp_send_json_success(array('html' => ob_get_clean()));
}

function tnmp_render_vote_page($poll, $attendee, $message = '') {
    nocache_headers();
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow', true);
    wp_enqueue_style('tnmp', TNMP_URL . 'assets/meeting-polls.css', array(), TNMP_VERSION);
    ?><!doctype html>
    <html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?>
    <title><?php echo esc_html($poll ? 'Vote: ' . $poll->title : 'Meeting poll'); ?></title></head><body class="tnmp-vote-body"><main class="tnmp-vote-shell">
    <?php
    if (!$poll || !$attendee) {
        echo '<h1>Meeting poll</h1><p>' . esc_html($message) . '</p>';
    } else {
        $verified = tnmp_has_session($attendee);
        $slots = tnmp_get_slots($poll->id);
        $durations = array();
        foreach ($slots as $slot) {
            $durations[] = (int) ((strtotime($slot->ends_at . ' UTC') - strtotime($slot->starts_at . ' UTC')) / 60);
        }
        $durations = array_values(array_unique($durations));
        sort($durations);
        echo '<header class="tnmp-vote-header"><h1>' . esc_html($poll->title);
        if ($durations) { echo ' <span class="tnmp-title-duration">(' . esc_html(implode(', ', $durations)) . ' mins)</span>'; }
        echo '</h1>';
        if ($poll->description !== '') { echo '<p class="tnmp-vote-description">' . nl2br(esc_html($poll->description)) . '</p>'; }
        echo '</header>';
        $voter_meta = '<p class="tnmp-muted tnmp-voter-meta">Voting as ' . esc_html($attendee->email) . ' · Times shown in ' . esc_html($poll->timezone) . '</p>';
        if ($verified || $poll->status !== 'open') { echo $voter_meta; }
        if ($message !== '') { echo '<div class="tnmp-notice" role="status">' . esc_html($message) . '</div>'; }
        if (!$verified && $poll->status === 'open') {
            echo '<section class="tnmp-card">' . $voter_meta . '<h2>Verify yourself to vote</h2><div class="tnmp-verify-row">';
            echo '<form method="post">';
            echo '<input type="hidden" name="tnmp_action" value="send_code">';
            echo '<input type="hidden" name="_tnmp_nonce" value="' . esc_attr(wp_create_nonce('tnmp_vote_' . $attendee->id)) . '">';
            echo '<button type="submit">Send code</button></form>';
            echo '<form method="post" class="tnmp-code-form"><input type="hidden" name="tnmp_action" value="verify"><input type="hidden" name="_tnmp_nonce" value="' . esc_attr(wp_create_nonce('tnmp_vote_' . $attendee->id)) . '">';
            echo '<label class="tnmp-code-input"><span class="tnmp-visually-hidden">Six digit code</span><input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="Enter code" required></label><button type="submit">Verify</button></form></div></section>';
        }
        if ($poll->status !== 'open') { echo '<div class="tnmp-notice">This poll is ' . esc_html($poll->status === 'final' ? 'finalised' : 'cancelled') . '. Voting is closed.</div>'; }
        echo '<h2>Vote for preferred time</h2>';
        $votes = tnmp_get_votes($poll->id);
        $counts = array();
        $mine = array();
        foreach ($votes as $vote) {
            $counts[$vote->slot_id][$vote->choice] = ($counts[$vote->slot_id][$vote->choice] ?? 0) + 1;
            if ((int) $vote->attendee_id === (int) $attendee->id) { $mine[$vote->slot_id] = $vote->choice; }
        }
        $current_day = '';
        $vote_timezone = new DateTimeZone($poll->timezone);
        foreach ($slots as $slot) {
            $start = (new DateTimeImmutable($slot->starts_at, new DateTimeZone('UTC')))->setTimezone($vote_timezone);
            $day = $start->format('Y-m-d');
            if ($day !== $current_day) {
                if ($current_day !== '') { echo '</section>'; }
                $current_day = $day;
                echo '<section class="tnmp-vote-day" aria-labelledby="tnmp-day-' . esc_attr($day) . '"><h3 id="tnmp-day-' . esc_attr($day) . '">' . esc_html($start->format('D j M Y')) . '</h3>';
            }
            echo '<div class="tnmp-vote-slot"><div class="tnmp-vote-time"><h4>' . esc_html($start->format('g:i A')) . '</h4>';
            if ($slot->status === 'closed') { echo '<small class="tnmp-closed">Closed</small>'; }
            echo '</div>';
            echo '<div class="tnmp-vote-options">';
            foreach (array('prefer' => '♡ Prefer', 'yes' => '✓ Yes', 'no' => '× No') as $choice => $label) {
                if ($verified && $poll->status === 'open' && $slot->status === 'open') {
                    echo '<form method="post"><input type="hidden" name="tnmp_action" value="vote"><input type="hidden" name="_tnmp_nonce" value="' . esc_attr(wp_create_nonce('tnmp_vote_' . $attendee->id)) . '"><input type="hidden" name="slot_id" value="' . (int) $slot->id . '"><input type="hidden" name="choice" value="' . esc_attr($choice) . '">';
                    echo '<button type="submit" class="tnmp-choice' . (($mine[$slot->id] ?? '') === $choice ? ' is-selected' : '') . '" aria-pressed="' . (($mine[$slot->id] ?? '') === $choice ? 'true' : 'false') . '">' . esc_html($label) . '</button>';
                    echo '<small>' . (int) ($counts[$slot->id][$choice] ?? 0) . ' vote(s)</small></form>';
                } else {
                    echo '<div><span class="tnmp-choice' . (($mine[$slot->id] ?? '') === $choice ? ' is-selected' : '') . '">' . esc_html($label) . '</span><small>' . (int) ($counts[$slot->id][$choice] ?? 0) . ' vote(s)</small></div>';
                }
            }
            echo '</div></div>';
        }
        if ($current_day !== '') { echo '</section>'; }
    }
    ?></main><?php wp_footer(); ?></body></html><?php
}
