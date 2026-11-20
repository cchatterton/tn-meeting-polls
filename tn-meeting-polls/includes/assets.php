<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_enqueue_scripts', 'tnmp_admin_assets');
add_action('wp_enqueue_scripts', 'tnmp_front_assets');

function tnmp_admin_assets($hook) {
    if ($hook === 'toplevel_page_tn-meeting-polls') {
        tnmp_enqueue_assets();
    }
}

function tnmp_front_assets() {
    if (is_singular() && has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'tn_meeting_polls')) {
        tnmp_enqueue_assets();
    }
}

function tnmp_enqueue_assets() {
    wp_enqueue_style('tnmp', TNMP_URL . 'assets/meeting-polls.css', array(), TNMP_VERSION);
    wp_enqueue_script('tnmp', TNMP_URL . 'assets/meeting-polls.js', array(), TNMP_VERSION, true);
    wp_localize_script('tnmp', 'tnmpData', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('tnmp_results'),
    ));
}
