<?php
// Run with php tests/cron-smoke.php from the repository root.
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['cron_events'] = array();
$GLOBALS['cleared_hooks'] = array();
function add_action($hook, $callback) {}
function add_filter($hook, $callback) {}
function add_shortcode($name, $callback) {}
function wp_next_scheduled($hook) { return $GLOBALS['cron_events'][$hook]['time'] ?? false; }
function wp_schedule_event($time, $frequency, $hook) { $GLOBALS['cron_events'][$hook] = array('time' => $time, 'frequency' => $frequency); return true; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['cleared_hooks'][] = $hook; unset($GLOBALS['cron_events'][$hook]); }
function flush_rewrite_rules() {}
function check_cron($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
require_once dirname(__DIR__) . '/tn-meeting-polls/includes/setup.php';
tnmp_ensure_cron();
check_cron(($GLOBALS['cron_events']['tnmp_cleanup_history']['frequency'] ?? '') === 'daily', 'history cleanup is scheduled daily');
check_cron(($GLOBALS['cron_events']['tnmp_run_queue']['frequency'] ?? '') === 'tnmp_minute', 'mail queue schedule remains active');
tnmp_ensure_cron();
check_cron(count($GLOBALS['cron_events']) === 2, 'existing jobs are not duplicated');
tnmp_deactivate();
check_cron(in_array('tnmp_cleanup_history', $GLOBALS['cleared_hooks'], true) && !$GLOBALS['cron_events'], 'deactivation clears scheduled jobs');
echo "History cleanup cron scheduling passed.\n";
