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

/* ------------------------------------------------------------------
 * Language
 *  - Admin screens use the admin's own GetSimple language ($LANG).
 *  - The website uses the "Website language" setting (empty = the site's $LANG).
 *  - Files: lang/<locale>.php, GetSimple format ($i18n = [...]), e.g. en_US.php, es_ES.php.
 *    Missing keys fall back to en_US.
 * ---------------------------------------------------------------- */
$ec_script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
define('EC_IS_ADMIN', defined('GSADMIN')
    ? (strpos($ec_script, '/' . GSADMIN . '/') !== false)
    : (basename($ec_script) !== 'index.php'));
define('EC_LANG_DIR', EC_DIR . 'lang/');

function ec_lang_valid_code($c) {
    return is_string($c) && preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/', $c) === 1;
}

/** Installed language code for $code ("es_ES"), also accepts a short code ("es"). '' if none. */
function ec_find_lang($code) {
    if (!ec_lang_valid_code($code)) { return ''; }
    if (is_file(EC_LANG_DIR . $code . '.php')) { return $code; }
    $m = glob(EC_LANG_DIR . $code . '_*.php');
    return ($m && is_array($m)) ? basename($m[0], '.php') : '';
}

/** Reads a lang file in GetSimple format into an array. */
function ec_lang_load($code) {
    static $cache = array();
    if (isset($cache[$code])) { return $cache[$code]; }
    $data = array();
    if (ec_lang_valid_code($code) && is_file(EC_LANG_DIR . $code . '.php')) {
        $i18n = array();
        include EC_LANG_DIR . $code . '.php';       // the file assigns $i18n
        if (is_array($i18n)) { $data = $i18n; }
    }
    $cache[$code] = $data;
    return $data;
}

/** array(code => display name) of every installed language file. */
function ec_languages() {
    static $list = null;
    if ($list !== null) { return $list; }
    $list = array();
    $files = glob(EC_LANG_DIR . '*.php');
    if (is_array($files)) {
        foreach ($files as $f) {
            $code = basename($f, '.php');
            if (!ec_lang_valid_code($code)) { continue; }
            $d = ec_lang_load($code);
            $list[$code] = (isset($d['_name']) && is_string($d['_name'])) ? $d['_name'] : $code;
        }
    }
    ksort($list);
    return $list;
}

function ec_admin_lang() {
    global $LANG;
    $c = ec_find_lang((string)$LANG);
    return ($c !== '') ? $c : 'en_US';
}

function ec_front_lang() {
    static $c = null;
    if ($c !== null) { return $c; }
    $s = ec_get_settings();
    $c = ($s['lang'] !== '') ? ec_find_lang($s['lang']) : '';
    if ($c === '') {
        global $LANG;
        $c = ec_find_lang((string)$LANG);
    }
    if ($c === '') { $c = 'en_US'; }
    return $c;
}

/** Language of the current request: the admin's in the admin, the website language elsewhere. */
function ec_current_lang() {
    return EC_IS_ADMIN ? ec_admin_lang() : ec_front_lang();
}

/** Translated string; falls back to English, then to the key itself. */
function ec_t($key, $lang = null) {
    if ($lang === null) { $lang = ec_current_lang(); }
    $d = ec_lang_load($lang);
    if (isset($d[$key]) && is_string($d[$key])) { return $d[$key]; }
    $en = ec_lang_load('en_US');
    return (isset($en[$key]) && is_string($en[$key])) ? $en[$key] : $key;
}

/** Translated list (month names etc.). */
function ec_t_list($key, $lang = null) {
    if ($lang === null) { $lang = ec_current_lang(); }
    $d = ec_lang_load($lang);
    if (isset($d[$key]) && is_array($d[$key])) { return $d[$key]; }
    $en = ec_lang_load('en_US');
    return (isset($en[$key]) && is_array($en[$key])) ? $en[$key] : array();
}

/** Translated string with %s / %d replaced by $arg. */
function ec_tf($key, $arg, $lang = null) {
    return str_replace(array('%s', '%d'), (string)$arg, ec_t($key, $lang));
}

require_once EC_DIR . 'render.php';

// Admin-only code is loaded only when load.php is the entry point
if (basename($_SERVER['SCRIPT_NAME']) === 'load.php') {
    require_once EC_DIR . 'admin.php';
}

// Admin navigation: own "Events" tab with a side menu (labels come from the language files).
// Only registered on admin requests so the public site never loads settings for this.
if (EC_IS_ADMIN) {
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
