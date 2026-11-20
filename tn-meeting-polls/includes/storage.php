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
    dbDelta("CREATE TABLE $polls (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        organiser_id bigint(20) unsigned NOT NULL,
        title varchar(190) NOT NULL,
        description text NOT NULL,
        timezone varchar(100) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'open',
        final_slot_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY organiser_id (organiser_id)
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
    update_option('tnmp_db_version', TNMP_VERSION, false);
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
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . tnmp_table('slots') . ' WHERE poll_id = %d ORDER BY starts_at, id', $poll_id));
}

function tnmp_get_attendees($poll_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . tnmp_table('attendees') . ' WHERE poll_id = %d ORDER BY id', $poll_id));
}

function tnmp_get_votes($poll_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT v.* FROM ' . tnmp_table('votes') . ' v INNER JOIN ' . tnmp_table('slots') . ' s ON s.id = v.slot_id WHERE s.poll_id = %d', $poll_id));
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
