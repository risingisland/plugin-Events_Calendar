<?php
/*
Plugin Name: Events Calendar
Description: Events with calendar, compact, list and grid views.
Version: 1.0
Author: risingisland
Author URI: https://ko-fi.com/ericmontgomery
*/

if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

$thisfile = basename(__FILE__, '.php');

register_plugin(
	$thisfile,
	'Events Calendar',
	'1.0',
	'risingisland',
	'https://ko-fi.com/ericmontgomery',
	'Events with calendar, compact, list and grid views.',
	'events',		// admin tab this plugin lives under
	'ec_admin'	   // admin page function
);

define('EC_PLUGIN_ID', $thisfile);
define('EC_DIR', GSPLUGINPATH . $thisfile . '/');
define('EC_VERSION', '0.1');

// Always needed (front end and admin)
require_once EC_DIR . 'storage.php';

/* ------------------------------------------------------------------
 * Language
 * ---------------------------------------------------------------- */
$ec_script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
define('EC_IS_ADMIN', defined('GSADMIN')
	? (strpos($ec_script, '/' . GSADMIN . '/') !== false)
	: (basename($ec_script) !== 'index.php'));
define('EC_LANG_DIR', EC_DIR . 'lang/');

function ec_lang_valid_code($c) {
	return is_string($c) && preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/', $c) === 1;
}

function ec_find_lang($code) {
	if (!ec_lang_valid_code($code)) { return ''; }
	if (is_file(EC_LANG_DIR . $code . '.php')) { return $code; }
	$m = glob(EC_LANG_DIR . $code . '_*.php');
	return ($m && is_array($m)) ? basename($m[0], '.php') : '';
}

function ec_lang_load($code) {
	static $cache = array();
	if (isset($cache[$code])) { return $cache[$code]; }
	$data = array();
	if (ec_lang_valid_code($code) && is_file(EC_LANG_DIR . $code . '.php')) {
		$i18n = array();
		include EC_LANG_DIR . $code . '.php';	   // the file assigns $i18n
		if (is_array($i18n)) { $data = $i18n; }
	}
	$cache[$code] = $data;
	return $data;
}

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

function ec_front_lang($reset = false) {
	static $c = null;
	if ($reset) { $c = null; }
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

function ec_current_lang() {
	return EC_IS_ADMIN ? ec_admin_lang() : ec_front_lang();
}

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

require_once EC_DIR . 'recurrence.php';
require_once EC_DIR . 'render.php';

if (basename($_SERVER['SCRIPT_NAME']) === 'load.php') {
	require_once EC_DIR . 'admin.php';
}

// Admin nav
if (EC_IS_ADMIN) {
	add_action('nav-tab', 'createNavTab', array('events', $thisfile, '<svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-right: 5px;" width="1.35rem" height="1.35rem" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="currentColor" d="M19 4h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2m-1 15h-6v-6h6zm1-10H5V7h14z"/></svg> ' . ec_t('tab_events'), 'action=list'));
	add_action('events-sidebar', 'createSideMenu', array($thisfile, ec_t('menu_all') . ' <svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-left: 5px;" width="18" height="18" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="#6c6ce5" d="M21 20V6c0-1.103-.897-2-2-2h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2h14c1.103 0 2-.897 2-2M9 18H7v-2h2zm0-4H7v-2h2zm4 4h-2v-2h2zm0-4h-2v-2h2zm4 4h-2v-2h2zm0-4h-2v-2h2zm2-5H5V7h14z"/></svg>', 'action=list'));
	add_action('events-sidebar', 'createSideMenu', array($thisfile, ec_t('menu_add') . ' <svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-left: 5px;" width="18" height="18" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="#6c6ce5" d="M5 22h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2m11-6h-3v3h-2v-3H8v-2h3v-3h2v3h3zM5 7h14v2H5z"/></svg>', 'action=edit'));
	add_action('events-sidebar', 'createSideMenu', array($thisfile, ec_t('menu_categories') . '<svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-left: 5px;" width="18" height="18" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="#6c6ce5" d="m9.981 14.811l-.467 2.726l2.449-1.287l2.449 1.287l-.468-2.726l1.982-1.932l-2.738-.398L11.963 10l-1.225 2.481L8 12.879z"/><path fill="#6c6ce5" d="M19 4h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2m.002 16H5V8h14z"/></svg>', 'action=categories'));
	add_action('events-sidebar', 'createSideMenu', array($thisfile, ec_t('menu_settings') . ' <svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-left: 5px;" width="18" height="18" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="#6c6ce5" d="M19 4h-2V2h-2v2H9V2H7v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2M5 20V8h14V6v14z"/><path fill="#6c6ce5" d="m15.67 12.36l-.42-.73a.64.64 0 0 0-.88-.23l-.34.2c-.29-.25-.62-.44-.97-.57v-.39c0-.35-.29-.64-.64-.64h-.84c-.35 0-.64.29-.64.64v.39c-.35.13-.68.32-.97.57l-.34-.2a.65.65 0 0 0-.88.23l-.42.73c-.18.31-.07.7.23.88l.33.19c-.04.19-.05.38-.05.57s.02.38.05.57l-.33.19c-.31.18-.41.57-.23.88l.42.73c.18.3.57.41.88.23l.34-.2c.29.25.62.44.97.57v.39c0 .35.29.64.64.64h.84c.35 0 .64-.29.64-.64v-.39c.35-.13.68-.32.97-.57l.34.2c.31.18.7.07.88-.23l.42-.73c.18-.31.07-.7-.23-.88l-.33-.19c.04-.19.05-.38.05-.57s-.02-.38-.05-.57l.33-.19c.31-.18.41-.57.23-.88M12 15.54c-.84 0-1.55-.71-1.55-1.55s.71-1.55 1.55-1.55s1.55.71 1.55 1.55s-.71 1.55-1.55 1.55"/></svg>', 'action=settings'));
	add_action('events-sidebar', 'createSideMenu', array($thisfile, ec_t('menu_help') . ' <svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-left: 5px;" width="18" height="18" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="#6c6ce5" d="M19 4h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2m-6 16h-2v-2h2zm0-4h-2v-5h2zm6-7H5V7h14z"/></svg>', 'action=help'));
}

// Shortcode in page content: [events view="list"]
add_filter('content', 'ec_content_filter');

// AJAX
add_action('common', 'ec_early_ajax');

function ec_early_ajax() {
	if (basename($_SERVER['SCRIPT_NAME']) !== 'load.php') { return; }
	if (!isset($_GET['id']) || $_GET['id'] !== EC_PLUGIN_ID) { return; }
	if (!isset($_GET['action']) || $_GET['action'] !== 'ajax') { return; }
	require_once EC_DIR . 'media.php';
	ec_media_ajax();   // always exits
}
