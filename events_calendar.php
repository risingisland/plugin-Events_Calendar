<?php
/*
Plugin Name: Events Calendar
Description: Events with calendar, compact, list and grid views.
Version: 0.1
Author: Eric
*/

if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

$thisfile = basename(__FILE__, '.php');

register_plugin(
    $thisfile,
    'Events Calendar',
    '0.1',
    'Eric',
    '',
    'Events with calendar, compact, list and grid views.',
    'events',        // admin tab this plugin lives under
    'ec_admin'       // admin page function
);

define('EC_PLUGIN_ID', $thisfile);
define('EC_DIR', GSPLUGINPATH . $thisfile . '/');
define('EC_VERSION', '0.1');

// Always needed (front end and admin)
require_once EC_DIR . 'storage.php';
require_once EC_DIR . 'i18n.php';
require_once EC_DIR . 'render.php';

// Admin-only code is loaded only when load.php is the entry point
if (basename($_SERVER['SCRIPT_NAME']) === 'load.php') {
    require_once EC_DIR . 'admin.php';
}

// Admin navigation: own "Events" tab with a side menu (labels come from the language files).
// Only registered on admin requests so the public site never loads settings for this.
$ec_script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
$ec_is_admin = defined('GSADMIN')
    ? (strpos($ec_script, '/' . GSADMIN . '/') !== false)
    : (basename($ec_script) !== 'index.php');
if ($ec_is_admin) {
    add_action('nav-tab', 'createNavTab', array('events', $thisfile, ec_t('tab_events'), 'action=list'));
    add_action('events-sidebar', 'createSideMenu', array($thisfile, ec_t('menu_all'), 'action=list'));
    add_action('events-sidebar', 'createSideMenu', array($thisfile, ec_t('menu_add'), 'action=edit'));
    add_action('events-sidebar', 'createSideMenu', array($thisfile, ec_t('menu_categories'), 'action=categories'));
    add_action('events-sidebar', 'createSideMenu', array($thisfile, ec_t('menu_settings'), 'action=settings'));
    add_action('events-sidebar', 'createSideMenu', array($thisfile, ec_t('menu_help'), 'action=help'));
}

// Shortcode in page content: [events view="list"]
add_filter('content', 'ec_content_filter');

// AJAX endpoint for the image browser. Runs very early so no admin HTML is sent.
add_action('common', 'ec_early_ajax');

function ec_early_ajax() {
    if (basename($_SERVER['SCRIPT_NAME']) !== 'load.php') { return; }
    if (!isset($_GET['id']) || $_GET['id'] !== EC_PLUGIN_ID) { return; }
    if (!isset($_GET['action']) || $_GET['action'] !== 'ajax') { return; }
    require_once EC_DIR . 'media.php';
    ec_media_ajax();   // always exits
}
