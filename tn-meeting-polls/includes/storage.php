<?php
if (!defined('ABSPATH')) {
    exit;
}

function tnmp_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'tnmp_' . $name;
}

function tnmp_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $polls = tnmp_table('polls');
    $slots = tnmp_table('slots');
    $attendees = tnmp_table('attendees');
    $votes = tnmp_table('votes');
    $events = tnmp_table('events');
    $jobs = tnmp_table('jobs');
    $contacts = tnmp_table('contacts');
    $templates = tnmp_table('templates');
    dbDelta("CREATE TABLE $polls (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        organiser_id bigint(20) unsigned NOT NULL,
        title varchar(190) NOT NULL,
        description text NOT NULL,
        timezone varchar(100) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'open',
        final_slot_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY organiser_id (organiser_id),
        KEY history_cleanup (status,updated_at)
    ) $charset;");
    dbDelta("CREATE TABLE $slots (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        poll_id bigint(20) unsigned NOT NULL,
        starts_at datetime NOT NULL,
        ends_at datetime NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'open',
        PRIMARY KEY  (id),
        KEY poll_id (poll_id)
    ) $charset;");
    dbDelta("CREATE TABLE $attendees (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        poll_id bigint(20) unsigned NOT NULL,
        name varchar(190) NOT NULL,
        email varchar(190) NOT NULL,
        holds tinyint(1) NOT NULL DEFAULT 0,
        invite_state varchar(15) NOT NULL DEFAULT 'pending',
        token_nonce char(64) NOT NULL,
        token_hash char(64) NOT NULL,
        otp_hash varchar(255) NOT NULL DEFAULT '',
        otp_expires datetime DEFAULT NULL,
        otp_last_sent datetime DEFAULT NULL,
        otp_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
        session_hash char(64) NOT NULL DEFAULT '',
        session_expires datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY token_hash (token_hash),
        KEY poll_id (poll_id)
    ) $charset;");
    dbDelta("CREATE TABLE $votes (
        attendee_id bigint(20) unsigned NOT NULL,
        slot_id bigint(20) unsigned NOT NULL,
        choice varchar(10) NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (attendee_id,slot_id),
        KEY slot_id (slot_id)
    ) $charset;");
    dbDelta("CREATE TABLE $events (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        attendee_id bigint(20) unsigned NOT NULL,
        slot_id bigint(20) unsigned NOT NULL,
        uid varchar(190) NOT NULL,
        sequence_no int(10) unsigned NOT NULL DEFAULT 0,
        sent_once tinyint(1) NOT NULL DEFAULT 0,
        state varchar(25) NOT NULL DEFAULT 'none',
        PRIMARY KEY  (id),
        UNIQUE KEY attendee_slot (attendee_id,slot_id)
    ) $charset;");
    dbDelta("CREATE TABLE $jobs (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        poll_id bigint(20) unsigned NOT NULL,
        attendee_id bigint(20) unsigned NOT NULL,
        slot_id bigint(20) unsigned NOT NULL DEFAULT 0,
        job_type varchar(20) NOT NULL,
        state varchar(15) NOT NULL DEFAULT 'pending',
        attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
        available_at datetime NOT NULL,
        last_error varchar(255) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY ready (state,available_at),
        KEY poll_id (poll_id)
    ) $charset;");
    dbDelta("CREATE TABLE $contacts (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        organiser_id bigint(20) unsigned NOT NULL,
        name varchar(190) NOT NULL,
        email varchar(190) NOT NULL,
        hold_default tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY organiser_email (organiser_id,email),
        KEY organiser_name (organiser_id,name)
    ) $charset;");
    dbDelta("CREATE TABLE $templates (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        organiser_id bigint(20) unsigned NOT NULL,
        title varchar(190) NOT NULL,
        description text NOT NULL,
        duration int(10) unsigned NOT NULL,
        PRIMARY KEY  (id),
        KEY organiser_id (organiser_id)
    ) $charset;");
    $wpdb->query($wpdb->prepare("UPDATE $polls SET updated_at = %s WHERE updated_at IS NULL", tnmp_now()));
    update_option('tnmp_db_version', TNMP_VERSION, false);
}

function tnmp_maybe_upgrade() {
    if (get_option('tnmp_db_version') !== TNMP_VERSION) {
        tnmp_install_tables();
    }
}

function tnmp_user_settings($user_id) {
    $saved = get_user_meta($user_id, 'tnmp_settings', true);
    $saved = is_array($saved) ? $saved : array();
    $duration = (int) ($saved['duration'] ?? 60);
    $earliest = (int) ($saved['earliest'] ?? 0);
    $latest = (int) ($saved['latest'] ?? 24);
    $retention_days = (int) ($saved['retention_days'] ?? 90);
    if ($earliest < 0 || $earliest > 22 || $latest < 1 || $latest > 24 || $latest <= $earliest) {
        $earliest = 0;
        $latest = 24;
    }
    return array('duration' => $duration >= 5 && $duration <= 1440 && $duration % 5 === 0 ? $duration : 60, 'earliest' => $earliest, 'latest' => $latest, 'retention_days' => $retention_days >= 1 && $retention_days <= 3650 ? $retention_days : 90);
}

function tnmp_get_contacts($user_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT id, name, email, hold_default FROM ' . tnmp_table('contacts') . ' WHERE organiser_id = %d ORDER BY name ASC, email ASC', $user_id));
}

function tnmp_get_contact($id, $user_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT id, name, email, hold_default FROM ' . tnmp_table('contacts') . ' WHERE id = %d AND organiser_id = %d', $id, $user_id));
}

function tnmp_get_templates($user_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT id, title, description, duration FROM ' . tnmp_table('templates') . ' WHERE organiser_id = %d ORDER BY title ASC, id ASC', $user_id));
}

function tnmp_get_template($id, $user_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT id, title, description, duration FROM ' . tnmp_table('templates') . ' WHERE id = %d AND organiser_id = %d', $id, $user_id));
}

function tnmp_now() {
    return gmdate('Y-m-d H:i:s');
}

function tnmp_get_poll($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . tnmp_table('polls') . ' WHERE id = %d', $id));
}

function tnmp_get_slot($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . tnmp_table('slots') . ' WHERE id = %d', $id));
}

function tnmp_get_attendee($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . tnmp_table('attendees') . ' WHERE id = %d', $id));
}

function tnmp_get_event($attendee_id, $slot_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . tnmp_table('events') . ' WHERE attendee_id = %d AND slot_id = %d', $attendee_id, $slot_id));
}

function tnmp_get_slots($poll_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . tnmp_table('slots') . " WHERE poll_id = %d AND status <> 'removed' ORDER BY starts_at, id", $poll_id));
}

function tnmp_get_attendees($poll_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . tnmp_table('attendees') . " WHERE poll_id = %d AND invite_state <> 'removed' ORDER BY id", $poll_id));
}

function tnmp_get_votes($poll_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare("SELECT v.* FROM " . tnmp_table('votes') . " v INNER JOIN " . tnmp_table('slots') . " s ON s.id = v.slot_id INNER JOIN " . tnmp_table('attendees') . " a ON a.id = v.attendee_id WHERE s.poll_id = %d AND s.status <> 'removed' AND a.invite_state <> 'removed'", $poll_id));
}

function tnmp_queue($type, $poll_id, $attendee_id, $slot_id = 0) {
    global $wpdb;
    $wpdb->insert(tnmp_table('jobs'), array(
        'poll_id' => $poll_id,
        'attendee_id' => $attendee_id,
        'slot_id' => $slot_id,
        'job_type' => $type,
        'state' => 'pending',
        'attempts' => 0,
        'available_at' => tnmp_now(),
        'last_error' => '',
    ));
    tnmp_schedule_queue();
}

function tnmp_schedule_queue() {
    if (!wp_next_scheduled('tnmp_run_queue')) {
        wp_schedule_single_event(time() + 5, 'tnmp_run_queue');
    }
}

function tnmp_pending_jobs($poll_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . tnmp_table('jobs') . " WHERE poll_id = %d AND state IN ('pending','running')", $poll_id));
}

function tnmp_delete_historical_poll($poll_id, $organiser_id, $cutoff = null) {
    global $wpdb;
    $polls = tnmp_table('polls');
    $attendees = tnmp_table('attendees');
    $wpdb->query('START TRANSACTION');
    $poll = $wpdb->get_row($wpdb->prepare("SELECT id, organiser_id, status, updated_at FROM $polls WHERE id = %d FOR UPDATE", $poll_id));
    if (!$poll || (int) $poll->organiser_id !== (int) $organiser_id || !in_array($poll->status, array('final', 'cancelled'), true) || ($cutoff !== null && $poll->updated_at >= $cutoff)) {
        $wpdb->query('ROLLBACK');
        return false;
    }
    if (tnmp_pending_jobs($poll_id)) {
        $wpdb->query('ROLLBACK');
        return false;
    }
    $statements = array(
        $wpdb->prepare('DELETE FROM ' . tnmp_table('votes') . " WHERE attendee_id IN (SELECT id FROM $attendees WHERE poll_id = %d)", $poll_id),
        $wpdb->prepare('DELETE FROM ' . tnmp_table('events') . " WHERE attendee_id IN (SELECT id FROM $attendees WHERE poll_id = %d)", $poll_id),
        $wpdb->prepare('DELETE FROM ' . tnmp_table('jobs') . ' WHERE poll_id = %d', $poll_id),
        $wpdb->prepare("DELETE FROM $attendees WHERE poll_id = %d", $poll_id),
        $wpdb->prepare('DELETE FROM ' . tnmp_table('slots') . ' WHERE poll_id = %d', $poll_id),
        $wpdb->prepare("DELETE FROM $polls WHERE id = %d", $poll_id),
    );
    foreach ($statements as $statement) {
        if ($wpdb->query($statement) === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }
    return $wpdb->query('COMMIT') !== false;
}

function tnmp_cleanup_history() {
    global $wpdb;
    $polls = tnmp_table('polls');
    $organisers = $wpdb->get_col("SELECT DISTINCT organiser_id FROM $polls WHERE status IN ('final','cancelled')");
    if (!is_array($organisers)) { return; }
    $remaining = 100;
    foreach ($organisers as $organiser_id) {
        $days = tnmp_user_settings((int) $organiser_id)['retention_days'];
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $jobs = tnmp_table('jobs');
        $matches = $wpdb->get_col($wpdb->prepare("SELECT p.id FROM $polls p WHERE p.organiser_id = %d AND p.status IN ('final','cancelled') AND p.updated_at < %s AND NOT EXISTS (SELECT 1 FROM $jobs j WHERE j.poll_id = p.id AND j.state IN ('pending','running')) ORDER BY p.updated_at, p.id LIMIT %d", (int) $organiser_id, $cutoff, $remaining));
        if (!is_array($matches)) { continue; }
        foreach ($matches as $poll_id) {
            if (tnmp_delete_historical_poll((int) $poll_id, (int) $organiser_id, $cutoff)) { $remaining--; }
        }
        if ($remaining <= 0) { break; }
    }
}
