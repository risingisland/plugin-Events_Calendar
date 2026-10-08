<?php
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/*
 * Language files live in plugins/events_calendar/lang/<code>.php and return an array.
 * To add a language: copy en.php to e.g. fr.php, translate the values, set '_name' and '_locale'.
 * It then appears in Events > Settings > Language. Missing keys fall back to English.
 */
define('EC_LANG_DIR', EC_DIR . 'lang/');

function ec_lang_valid_code($c) {
    return is_string($c) && preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/', $c) === 1;
}

function ec_lang_load($code) {
    static $cache = array();
    if (isset($cache[$code])) { return $cache[$code]; }
    $data = array();
    if (ec_lang_valid_code($code) && is_file(EC_LANG_DIR . $code . '.php')) {
        $d = include EC_LANG_DIR . $code . '.php';
        if (is_array($d)) { $data = $d; }
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

/** Language code from Settings, or 'en' if that file is missing. */
function ec_current_lang() {
    $s = ec_get_settings();
    $c = isset($s['lang']) ? $s['lang'] : 'en';
    return (ec_lang_valid_code($c) && is_file(EC_LANG_DIR . $c . '.php')) ? $c : 'en';
}

/** Translated string; falls back to English, then to the key itself. */
function ec_t($key, $lang = null) {
    if ($lang === null) { $lang = ec_current_lang(); }
    $d = ec_lang_load($lang);
    if (isset($d[$key]) && is_string($d[$key])) { return $d[$key]; }
    $en = ec_lang_load('en');
    return (isset($en[$key]) && is_string($en[$key])) ? $en[$key] : $key;
}

/** Translated list (month names etc.). */
function ec_t_list($key, $lang = null) {
    if ($lang === null) { $lang = ec_current_lang(); }
    $d = ec_lang_load($lang);
    if (isset($d[$key]) && is_array($d[$key])) { return $d[$key]; }
    $en = ec_lang_load('en');
    return (isset($en[$key]) && is_array($en[$key])) ? $en[$key] : array();
}

/** Translated string with %s / %d replaced by $arg. */
function ec_tf($key, $arg, $lang = null) {
    return str_replace(array('%s', '%d'), (string)$arg, ec_t($key, $lang));
}
