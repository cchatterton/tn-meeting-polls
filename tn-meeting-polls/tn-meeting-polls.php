<?php
/**
 * Plugin Name: TN Meeting Polls
 * Description: Verified meeting-time polls with optional calendar placeholders.
 * Version: 0.1.20
 * Author: Techn
 * Author URI: https://techn.com.au
 * Update URI: https://github.com/cchatterton/tn-meeting-polls
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tn-meeting-polls
 * Techn Controller API: 1
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TNMP_VERSION', '0.1.20');
define('TNMP_FILE', __FILE__);
define('TNMP_DIR', plugin_dir_path(__FILE__));
define('TNMP_URL', plugin_dir_url(__FILE__));

require_once TNMP_DIR . 'includes/storage.php';
require_once TNMP_DIR . 'includes/calendar.php';
require_once TNMP_DIR . 'includes/actions.php';
require_once TNMP_DIR . 'includes/views.php';
require_once TNMP_DIR . 'includes/assets.php';
require_once TNMP_DIR . 'includes/setup.php';
require_once TNMP_DIR . 'includes/controller-client.php';

tnuc_client_register(__FILE__, 'tn-meeting-polls');

register_activation_hook(__FILE__, 'tnmp_activate');
register_deactivation_hook(__FILE__, 'tnmp_deactivate');
