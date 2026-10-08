<?php
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/* ------------------------------------------------------------------
 * Paths
 * ---------------------------------------------------------------- */
define('EC_DATA_DIR',      GSDATAOTHERPATH . 'events/');
define('EC_EVENTS_FILE',   EC_DATA_DIR . 'events.json');
define('EC_SETTINGS_FILE', EC_DATA_DIR . 'settings.json');
define('EC_LOCK_FILE',     EC_DATA_DIR . '.lock');

/* ------------------------------------------------------------------
 * Small helpers
 * ---------------------------------------------------------------- */
function ec_h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ec_substr($s, $start, $len) {
    return function_exists('mb_substr') ? mb_substr($s, $start, $len, 'UTF-8') : substr($s, $start, $len);
}

function ec_strlen($s) {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function ec_clean_text($s, $max) {
    $s = trim(strip_tags((string)$s));
    $s = preg_replace('/\s+/u', ' ', $s);
    return ec_substr($s, 0, $max);
}

/* ------------------------------------------------------------------
 * Data folder + locked JSON I/O
 * ---------------------------------------------------------------- */
function ec_ensure_dir() {
    if (!is_dir(EC_DATA_DIR)) {
        @mkdir(EC_DATA_DIR, 0755, true);
    }
    if (is_dir(EC_DATA_DIR)) {
        $ht = EC_DATA_DIR . '.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
        }
        if (!file_exists(EC_DATA_DIR . 'index.html')) {
            @file_put_contents(EC_DATA_DIR . 'index.html', '');
        }
    }
}

function ec_lock() {
    ec_ensure_dir();
    $h = @fopen(EC_LOCK_FILE, 'c');
    if ($h) { flock($h, LOCK_EX); }
    return $h;
}

function ec_unlock($h) {
    if ($h) { flock($h, LOCK_UN); fclose($h); }
}

function ec_read_raw($file) {
    if (!is_file($file)) { return null; }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') { return null; }
    $data = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
}

/** Write without taking the lock (caller holds it). Keeps one .bak copy. */
function ec_write_raw($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    if (is_file($file)) { @copy($file, $file . '.bak'); }
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $json) === false) { return false; }
    if (!@rename($tmp, $file)) {
        // Fallback for hosts where rename() over an existing file fails
        $ok = (file_put_contents($file, $json) !== false);
        @unlink($tmp);
        return $ok;
    }
    return true;
}

/* ------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------- */
function ec_default_settings() {
    return array(
        'first_day'     => 1,          // 1 = Monday, 0 = Sunday
        'lang'          => 'en',       // en | es
        'time_format'   => '12',       // 12 | 24
        'default_color' => '#6c6ce5',
    );
}

function ec_get_settings() {
    $saved = ec_read_raw(EC_SETTINGS_FILE);
    return array_merge(ec_default_settings(), is_array($saved) ? $saved : array());
}

function ec_save_settings($in) {
    $s = ec_default_settings();
    $s['first_day']     = (isset($in['first_day']) && (string)$in['first_day'] === '0') ? 0 : 1;
    $s['lang']          = (isset($in['lang']) && $in['lang'] === 'es') ? 'es' : 'en';
    $s['time_format']   = (isset($in['time_format']) && $in['time_format'] === '24') ? '24' : '12';
    $s['default_color'] = (isset($in['default_color']) && ec_valid_color($in['default_color']))
                          ? strtolower($in['default_color']) : $s['default_color'];
    $h = ec_lock();
    $ok = ec_write_raw(EC_SETTINGS_FILE, $s);
    ec_unlock($h);
    return $ok;
}

/* ------------------------------------------------------------------
 * Validation helpers
 * ---------------------------------------------------------------- */
function ec_valid_date($s) {
    if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) { return false; }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function ec_valid_time($s) {
    return is_string($s) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s) === 1;
}

function ec_valid_color($s) {
    return is_string($s) && preg_match('/^#[0-9a-fA-F]{6}$/', $s) === 1;
}

/* ------------------------------------------------------------------
 * Uploads folder helpers (shared by admin form validation + image browser)
 * ---------------------------------------------------------------- */
function ec_image_exts() {
    return array('jpg', 'jpeg', 'png', 'gif', 'webp');
}

/** Normalise a relative path. Returns false if it tries to escape. */
function ec_safe_rel($rel) {
    $rel = str_replace('\\', '/', (string)$rel);
    if (strpos($rel, "\0") !== false) { return false; }
    $parts = array();
    foreach (explode('/', $rel) as $p) {
        if ($p === '' || $p === '.') { continue; }
        if ($p === '..') { return false; }
        $parts[] = $p;
    }
    return implode('/', $parts);
}

/** Resolve a relative path inside data/uploads to an absolute path, or false. */
function ec_resolve_upload($rel) {
    $rel = ec_safe_rel($rel);
    if ($rel === false) { return false; }
    $root = realpath(GSDATAUPLOADPATH);
    if ($root === false) { return false; }
    if ($rel === '') { return $root; }
    $abs = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    if ($abs === false) { return false; }
    if ($abs !== $root && strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0) { return false; }
    return $abs;
}

/** Returns a clean relative image path if it is a real image in uploads, else ''. */
function ec_clean_image_path($rel) {
    $rel = ec_safe_rel($rel);
    if ($rel === false || $rel === '') { return ''; }
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (!in_array($ext, ec_image_exts(), true)) { return ''; }
    $abs = ec_resolve_upload($rel);
    return ($abs !== false && is_file($abs)) ? $rel : '';
}

/* ------------------------------------------------------------------
 * Events
 * ---------------------------------------------------------------- */
function ec_new_id() {
    $rand = function_exists('random_bytes') ? bin2hex(random_bytes(2)) : substr(md5(uniqid('', true)), 0, 4);
    return 'e_' . date('Ymd') . '_' . $rand;
}

function ec_get_events() {
    $d = ec_read_raw(EC_EVENTS_FILE);
    return is_array($d) ? array_values($d) : array();
}

function ec_get_event($id) {
    foreach (ec_get_events() as $e) {
        if (isset($e['id']) && $e['id'] === $id) { return $e; }
    }
    return null;
}

function ec_save_event($ev) {
    $h = ec_lock();
    $events = ec_get_events();
    $found = false;
    foreach ($events as $i => $e) {
        if ($e['id'] === $ev['id']) { $events[$i] = $ev; $found = true; break; }
    }
    if (!$found) { $events[] = $ev; }
    $ok = ec_write_raw(EC_EVENTS_FILE, $events);
    ec_unlock($h);
    return $ok;
}

function ec_delete_event($id) {
    $h = ec_lock();
    $events = array();
    foreach (ec_get_events() as $e) {
        if ($e['id'] !== $id) { $events[] = $e; }
    }
    $ok = ec_write_raw(EC_EVENTS_FILE, $events);
    ec_unlock($h);
    return $ok;
}

/**
 * Validate and normalise submitted data.
 * Returns array($event, $errors). $event is always usable to re-fill the form.
 */
function ec_sanitize_event($in, $id = '') {
    $errors   = array();
    $settings = ec_get_settings();

    $ev = array(
        'id'          => ($id !== '' ? $id : ec_new_id()),
        'title'       => ec_clean_text(isset($in['title']) ? $in['title'] : '', 200),
        'date_from'   => isset($in['date_from']) ? trim($in['date_from']) : '',
        'date_to'     => isset($in['date_to']) ? trim($in['date_to']) : '',
        'time_from'   => isset($in['time_from']) ? trim($in['time_from']) : '',
        'time_to'     => isset($in['time_to']) ? trim($in['time_to']) : '',
        'all_day'     => !empty($in['all_day']),
        'location'    => ec_clean_text(isset($in['location']) ? $in['location'] : '', 200),
        'description' => '',
        'image'       => '',
        'color'       => $settings['default_color'],
        'url'         => '',
        'status'      => (isset($in['status']) && $in['status'] === 'draft') ? 'draft' : 'published',
    );

    // Description: plain text, line breaks kept, tags removed
    if (isset($in['description'])) {
        $d = str_replace(array("\r\n", "\r"), "\n", strip_tags((string)$in['description']));
        $ev['description'] = ec_substr(trim($d), 0, 5000);
    }

    if ($ev['title'] === '') { $errors[] = 'Title is required.'; }

    if (!ec_valid_date($ev['date_from'])) {
        $errors[] = 'Start date is required.';
        $ev['date_from'] = '';
    }
    if ($ev['date_to'] === '' && $ev['date_from'] !== '') { $ev['date_to'] = $ev['date_from']; }
    if ($ev['date_to'] !== '' && !ec_valid_date($ev['date_to'])) {
        $errors[] = 'End date is not valid.';
        $ev['date_to'] = $ev['date_from'];
    }
    if ($ev['date_from'] !== '' && $ev['date_to'] !== '' && $ev['date_to'] < $ev['date_from']) {
        $errors[] = 'End date cannot be before the start date.';
    }

    if ($ev['all_day']) {
        $ev['time_from'] = '';
        $ev['time_to']   = '';
    } else {
        if ($ev['time_from'] !== '' && !ec_valid_time($ev['time_from'])) {
            $errors[] = 'Start time is not valid.';
            $ev['time_from'] = '';
        }
        if ($ev['time_to'] !== '' && !ec_valid_time($ev['time_to'])) {
            $errors[] = 'End time is not valid.';
            $ev['time_to'] = '';
        }
        if ($ev['time_to'] !== '' && $ev['time_from'] === '') {
            $errors[] = 'An end time needs a start time.';
        }
        if ($ev['date_from'] !== '' && $ev['date_from'] === $ev['date_to']
            && $ev['time_from'] !== '' && $ev['time_to'] !== '' && $ev['time_to'] < $ev['time_from']) {
            $errors[] = 'End time cannot be before the start time on a single-day event.';
        }
    }

    if (isset($in['color']) && ec_valid_color($in['color'])) { $ev['color'] = strtolower($in['color']); }

    if (!empty($in['image'])) {
        $img = ec_clean_image_path($in['image']);
        if ($img === '') { $errors[] = 'The selected image was not found in the uploads folder.'; }
        $ev['image'] = $img;
    }

    if (!empty($in['url'])) {
        $u = trim($in['url']);
        if (filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u)) {
            $ev['url'] = $u;
        } else {
            $errors[] = 'The link must be a full http:// or https:// address.';
        }
    }

    return array($ev, $errors);
}
