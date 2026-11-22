<?php
// Run with php tests/release-smoke.php from the repository root.
define('ABSPATH', __DIR__ . '/');
define('WP_PLUGIN_DIR', __DIR__ . '/fixtures/plugins');
function add_filter() {}
function add_action() {}
function plugin_basename($file) { return 'tn-meeting-polls/tn-meeting-polls.php'; }
function sanitize_text_field($value) { return $value; }
function esc_url($value) { return $value; }
function current_user_can() { return true; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function wp_nonce_url($url) { return $url; }

function check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$main = file_get_contents($root . '/tn-meeting-polls/tn-meeting-polls.php');
$readme = file_get_contents($root . '/tn-meeting-polls/readme.txt');
$changelog = file_get_contents($root . '/CHANGELOG.md');
check(strpos($main, 'Version: 0.1.11') !== false && strpos($main, "define('TNMP_VERSION', '0.1.11')") !== false, 'header and constant versions');
check(strpos($readme, 'Stable tag: 0.1.11') !== false && strpos($changelog, '## 0.1.11 - ') !== false, 'readme and changelog versions');
check(strpos($main, 'Update URI: https://github.com/cchatterton/tn-meeting-polls') !== false, 'trusted update URI');
check(strpos($main, 'Plugin URI:') === false, 'no Visit plugin site link');
check(strpos($main, 'Author: Techn') !== false && strpos($main, 'License URI: https://www.gnu.org/licenses/gpl-2.0.html') !== false, 'author and licence headers');
check(is_file($root . '/tn-meeting-polls/LICENSE'), 'package licence');

require_once $root . '/tn-meeting-polls/includes/controller-client.php';
tnuc_client_register('/plugin/tn-meeting-polls/tn-meeting-polls.php', 'tn-meeting-polls');
$links = tnuc_client_links(array(), 'tn-meeting-polls/tn-meeting-polls.php');
check(count($links) === 2, 'controller bootstrap row actions');
check(strpos(implode(' ', $links), 'GitHub') !== false && strpos(implode(' ', $links), 'Install Techn Update Controller') !== false, 'GitHub and controller links');
echo "Release metadata and controller checks passed.\n";
