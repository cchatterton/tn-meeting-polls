<?php
if (!defined('ABSPATH')) {
    exit;
}

function tnmp_notice_text($code) {
    $notices = array(
        'created' => 'Poll created. Invitation and calendar emails are being sent.',
        'invalid' => 'Please complete the meeting title, at least one valid time, and at least one attendee.',
        'invalid_time' => 'Check the proposed dates, start times and durations. Each time must be in the future.',
        'invalid_attendee' => 'Check attendee email addresses and remove duplicates.',
        'database' => 'The poll could not be saved. Please try again.',
        'slot_closed' => 'That proposed time is closed. Placeholder cancellations have been queued.',
        'finalized' => 'The final time has been selected. Calendar updates have been queued.',
        'cancelled' => 'The poll is cancelled. Placeholder cancellations have been queued.',
        'already_closed' => 'This poll is no longer open.',
        'resent' => 'A new voting link was queued. The earlier link has been replaced.',
        'retry_queued' => 'Failed email messages have been queued for another attempt.',
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
    if (!in_array($view, array('create', 'open', 'history'), true)) { $view = 'open'; }
    ob_start();
    if ($notice) {
        echo '<div class="notice notice-info tnmp-notice" role="status"><p>' . esc_html($notice) . '</p></div>';
    }
    echo '<header class="tnmp-hero"><div><p class="tnmp-hero-eyebrow">PLAN. VOTE. MEET.</p><h2>Meeting Polls</h2><p>Find a time that works, with verified votes and optional calendar holds.</p></div><span class="tnmp-version" aria-label="' . esc_attr('Version ' . TNMP_VERSION) . '">v' . esc_html(TNMP_VERSION) . '</span></header>';
    echo '<nav class="tnmp-view-nav" aria-label="Meeting poll views">';
    foreach (array('create' => 'Create a poll', 'open' => 'Open polls', 'history' => 'History') as $key => $label) {
        echo '<a href="' . esc_url(tnmp_view_url($key)) . '"' . ($view === $key ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
    }
    echo '</nav>';
    if ($view === 'create') {
        echo '<h2>Create a meeting poll</h2>';
        tnmp_render_create_form();
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

function tnmp_render_create_form() {
    $zone = wp_timezone_string() ?: 'UTC';
    if (!tnmp_valid_timezone($zone)) { $zone = 'UTC'; }
    $timezone_options = timezone_identifiers_list();
    echo '<form class="tnmp-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="tnmp_create">';
    wp_nonce_field('tnmp_create');
    tnmp_return_field();
    echo '<label>Meeting title <input type="text" name="title" maxlength="190" required></label>';
    echo '<label>Details <textarea name="description" rows="3" placeholder="Purpose, location, or meeting link"></textarea></label>';
    echo '<label>Time zone (defaults to the WordPress site setting) <select name="timezone" required>';
    if (!in_array($zone, $timezone_options, true)) {
        echo '<option value="' . esc_attr($zone) . '" selected>' . esc_html($zone) . ' (site setting)</option>';
    }
    foreach ($timezone_options as $timezone) {
        echo '<option value="' . esc_attr($timezone) . '"' . selected($timezone, $zone, false) . '>' . esc_html($timezone) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Meeting duration (minutes) <input type="number" name="duration" min="5" max="1440" step="5" value="60" required></label>';
    echo '<h3>Proposed dates and times</h3><p>Pick a date, then add one or more start times for that date. The duration applies to every proposed time. Up to 10 options total.</p>';
    echo '<div id="tnmp-dates">';
    tnmp_date_group(0);
    echo '</div><button type="button" class="button" id="tnmp-add-date">Add date</button>';
    echo '<h3>Attendees</h3><p>Select “Send placeholders” only for people who should receive one tentative calendar invitation per proposed time.</p>';
    echo '<div id="tnmp-attendees">';
    tnmp_attendee_row(0, true);
    echo '</div><button type="button" class="button tnmp-add" data-target="tnmp-attendees" data-limit="50">Add attendee</button>';
    echo '<p class="tnmp-submit"><button type="submit" class="button button-primary">Create poll and send invitations</button></p>';
    echo '</form>';
}

function tnmp_date_group($index) {
    echo '<fieldset class="tnmp-date-group" data-date-index="' . (int) $index . '"><legend>Proposed date</legend>';
    echo '<label>Date <input type="date" name="dates[' . (int) $index . ']" required></label>';
    echo '<div class="tnmp-times">';
    tnmp_time_row($index);
    echo '</div><button type="button" class="button tnmp-add-time">Add time on this date</button></fieldset>';
}

function tnmp_time_row($index) {
    echo '<div class="tnmp-row tnmp-time-row"><label>Start <input type="time" name="times[' . (int) $index . '][]" required></label></div>';
}

function tnmp_attendee_row($index, $required = false) {
    echo '<div class="tnmp-row tnmp-attendee-row">';
    echo '<label>Email <input type="email" name="emails[]"' . ($required ? ' required' : '') . '></label>';
    echo '<label>Name <input type="text" name="names[]"></label>';
    echo '<label class="tnmp-check"><input type="checkbox" name="holds[' . (int) $index . ']" value="1"> Send placeholders</label>';
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
    echo '<section class="tnmp-card tnmp-poll" data-poll-id="' . (int) $poll->id . '">';
    echo '<div class="tnmp-poll-head"><h3>' . esc_html($poll->title) . '</h3><span class="tnmp-status">' . esc_html(ucfirst($poll->status)) . '</span></div>';
    if ($poll->description !== '') {
        echo '<p>' . nl2br(esc_html($poll->description)) . '</p>';
    }
    echo '<p class="tnmp-muted">Time zone: ' . esc_html($poll->timezone) . ' · ' . count($attendees) . ' attendee(s) · ' . tnmp_pending_jobs($poll->id) . ' queued email message(s)';
    if ($failed) {
        echo ' · <strong>' . $failed . ' failed email message(s)</strong>';
    }
    echo '</p>';
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
        echo '<header class="tnmp-vote-header"><p class="tnmp-eyebrow">Scheduling poll</p><span class="tnmp-version" aria-label="' . esc_attr('Version ' . TNMP_VERSION) . '">v' . esc_html(TNMP_VERSION) . '</span><h1>' . esc_html($poll->title) . '</h1></header>';
        if ($poll->description !== '') { echo '<p>' . nl2br(esc_html($poll->description)) . '</p>'; }
        echo '<p class="tnmp-muted">Voting as ' . esc_html($attendee->email) . ' · Times shown in ' . esc_html($poll->timezone) . '</p>';
        if ($message !== '') { echo '<div class="tnmp-notice" role="status">' . esc_html($message) . '</div>'; }
        if (!$verified && $poll->status === 'open') {
            echo '<section class="tnmp-card"><h2>Verify your email</h2><p>Request a six digit code, then enter it below to vote.</p>';
            echo '<form method="post">';
            echo '<input type="hidden" name="tnmp_action" value="send_code">';
            echo '<input type="hidden" name="_tnmp_nonce" value="' . esc_attr(wp_create_nonce('tnmp_vote_' . $attendee->id)) . '">';
            echo '<button type="submit">Send verification code</button></form>';
            echo '<form method="post" class="tnmp-code-form"><input type="hidden" name="tnmp_action" value="verify"><input type="hidden" name="_tnmp_nonce" value="' . esc_attr(wp_create_nonce('tnmp_vote_' . $attendee->id)) . '">';
            echo '<label>Six digit code <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><button type="submit">Verify and continue</button></form></section>';
        }
        if ($poll->status !== 'open') { echo '<div class="tnmp-notice">This poll is ' . esc_html($poll->status === 'final' ? 'finalised' : 'cancelled') . '. Voting is closed.</div>'; }
        echo '<h2>Choose times you prefer or are available</h2>';
        $votes = tnmp_get_votes($poll->id);
        $counts = array();
        $mine = array();
        foreach ($votes as $vote) {
            $counts[$vote->slot_id][$vote->choice] = ($counts[$vote->slot_id][$vote->choice] ?? 0) + 1;
            if ((int) $vote->attendee_id === (int) $attendee->id) { $mine[$vote->slot_id] = $vote->choice; }
        }
        foreach (tnmp_get_slots($poll->id) as $slot) {
            echo '<section class="tnmp-vote-slot"><h3>' . esc_html(tnmp_format_time($slot->starts_at, $poll->timezone)) . '</h3><p class="tnmp-muted">Until ' . esc_html(tnmp_format_time($slot->ends_at, $poll->timezone)) . '</p>';
            if ($slot->status === 'closed') { echo '<p class="tnmp-closed">This time is closed.</p>'; }
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
            echo '</div></section>';
        }
        if ($attendee->holds) { echo '<p class="tnmp-muted">Voting No cancels your calendar placeholder for that time. You can change your vote while the poll is open.</p>'; }
    }
    ?></main><?php wp_footer(); ?></body></html><?php
}
