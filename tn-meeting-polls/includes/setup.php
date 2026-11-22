<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'tnmp_register_routes');
add_action('init', 'tnmp_maybe_upgrade');
add_action('init', 'tnmp_ensure_cron');
add_filter('query_vars', 'tnmp_query_vars');
add_action('template_redirect', 'tnmp_vote_page');
add_action('admin_menu', 'tnmp_admin_menu');
add_shortcode('tn_meeting_polls', 'tnmp_shortcode');
add_action('admin_post_tnmp_create', 'tnmp_create');
add_action('admin_post_tnmp_edit', 'tnmp_edit');
add_action('admin_post_tnmp_manage', 'tnmp_manage');
add_action('admin_post_tnmp_save_settings', 'tnmp_save_settings');
add_action('admin_post_tnmp_contact', 'tnmp_contact_action');
add_action('admin_post_tnmp_template', 'tnmp_template_action');
add_action('wp_ajax_tnmp_results', 'tnmp_ajax_results');
add_action('tnmp_run_queue', 'tnmp_process_queue');
add_action('tnmp_cleanup_history', 'tnmp_cleanup_history');
add_filter('cron_schedules', 'tnmp_cron_schedules');

function tnmp_register_routes() {
    add_rewrite_rule('^meeting-vote/?$', 'index.php?tnmp_vote=1', 'top');
}

function tnmp_query_vars($vars) {
    $vars[] = 'tnmp_vote';
    return $vars;
}

function tnmp_activate() {
    tnmp_install_tables();
    tnmp_ensure_cron();
    tnmp_register_routes();
    flush_rewrite_rules();
}

function tnmp_deactivate() {
    wp_clear_scheduled_hook('tnmp_run_queue');
    wp_clear_scheduled_hook('tnmp_cleanup_history');
    flush_rewrite_rules();
}

function tnmp_cron_schedules($schedules) {
    $schedules['tnmp_minute'] = array('interval' => 60, 'display' => 'Every minute for meeting mail');
    return $schedules;
}

function tnmp_ensure_cron() {
    if (!wp_next_scheduled('tnmp_run_queue')) {
        wp_schedule_event(time() + 60, 'tnmp_minute', 'tnmp_run_queue');
    }
    if (!wp_next_scheduled('tnmp_cleanup_history')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tnmp_cleanup_history');
    }
}

function tnmp_admin_menu() {
    add_menu_page('Meeting Polls', 'Meeting Polls', 'read', 'tn-meeting-polls', 'tnmp_admin_page', 'dashicons-calendar-alt', 30);
}
