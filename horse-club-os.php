<?php
/**
 * Plugin Name: Horse Club OS
 * Description: Базовая система управления клиентами, лошадьми, тренерами, услугами и занятиями конного клуба.
 * Version: 0.16.1
 * Author: Horse Club OS
 * Text Domain: horse-club-os
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'HCOS_VERSION', '0.16.1' );
define( 'HCOS_PLUGIN_FILE', __FILE__ );
define( 'HCOS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-security.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-audit.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-post-types.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-acf.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-admin.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-lesson-validation.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-calendar.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-memberships.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-bookings.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-pricing-plans.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-payments.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-attendance.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-reports.php';
require_once HCOS_PLUGIN_DIR . 'includes/class-hcos-plugin.php';

register_activation_hook( __FILE__, array( 'HCOS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HCOS_Plugin', 'deactivate' ) );

HCOS_Plugin::instance();
