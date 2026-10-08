<?php
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/*
 * Image browser backend. Served through load.php?id=events_calendar&action=ajax
 * Every request: logged-in admin + valid nonce. Every path is resolved and
 * checked to stay inside data/uploads.
 */

define('EC_MAX_UPLOAD_BYTES', 10 * 1024 * 1024);
define('EC_MAX_LIST', 500);

function ec_json($data, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data);
    exit;
}

function ec_media_require_login() {
    if (function_exists('is_logged_in')) {
        if (is_logged_in()) { return; }
        ec_json(array('error' => ec_t('m_not_logged_in')), 403);
    }
    if (function_exists('login_cookie_check')) {   // older GetSimple: redirects when not logged in
        login_cookie_check();
        return;
    }
    ec_json(array('error' => ec_t('m_not_logged_in')), 403);
}

function ec_media_ajax() {
    ec_media_require_login();

    $nonce = isset($_GET['nonce']) ? (string)$_GET['nonce'] : '';
    if (!check_nonce($nonce, 'ec_ajax', 'load.php')) {
        ec_json(array('error' => ec_t('m_security')), 403);
    }

    $op  = isset($_GET['op']) ? (string)$_GET['op'] : '';
    $dir = isset($_GET['dir']) ? (string)$_GET['dir'] : '';

    switch ($op) {
        case 'list':
            ec_json(ec_media_list($dir));
        case 'upload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ec_json(array('error' => ec_t('m_post_required')), 405); }
            ec_json(ec_media_upload($dir));
        case 'mkdir':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ec_json(array('error' => ec_t('m_post_required')), 405); }
            $name = isset($_GET['name']) ? (string)$_GET['name'] : '';
            ec_json(ec_media_mkdir($dir, $name));
        default:
            ec_json(array('error' => ec_t('m_unknown_op')), 400);
    }
}

/* ------------------------------------------------------------------ */

function ec_media_list($dirRel) {
    $rel = ec_safe_rel($dirRel);
    $abs = ($rel === false) ? false : ec_resolve_upload($rel);
    if ($abs === false || !is_dir($abs)) { return array('error' => ec_t('m_no_folder')); }

    $folders = array();
    $images  = array();
    $exts    = ec_image_exts();
    $prefix  = ($rel === '') ? '' : $rel . '/';

    $items = @scandir($abs);
    if ($items === false) { return array('error' => ec_t('m_cannot_read')); }

    foreach ($items as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.') { continue; }
        $full = $abs . DIRECTORY_SEPARATOR . $name;
        if (is_dir($full)) {
            $folders[] = array('name' => $name, 'path' => $prefix . $name);
        } elseif (is_file($full) && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $exts, true)) {
            $images[] = array('name' => $name, 'path' => $prefix . $name, 'mtime' => filemtime($full));
        }
    }

    usort($folders, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    usort($images, function ($a, $b) { return $b['mtime'] - $a['mtime']; });

    $parent = null;
    if ($rel !== '') {
        $pos    = strrpos($rel, '/');
        $parent = ($pos === false) ? '' : substr($rel, 0, $pos);
    }

    return array(
        'dir'       => $rel,
        'parent'    => $parent,
        'folders'   => array_slice($folders, 0, EC_MAX_LIST),
        'images'    => array_slice($images, 0, EC_MAX_LIST),
        'truncated' => (count($folders) > EC_MAX_LIST || count($images) > EC_MAX_LIST),
    );
}

function ec_media_slug($name, $allowDots) {
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($t !== false && $t !== '') { $name = $t; }
    }
    $pattern = $allowDots ? '/[^A-Za-z0-9._-]+/' : '/[^A-Za-z0-9_-]+/';
    $name = preg_replace($pattern, '-', $name);
    $name = trim($name, '-._');
    return substr($name, 0, 80);
}

function ec_media_ext_for_type($type) {
    $map = array(
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
    );
    if (defined('IMAGETYPE_WEBP')) { $map[IMAGETYPE_WEBP] = 'webp'; }
    return isset($map[$type]) ? $map[$type] : '';
}

function ec_media_upload_error($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return ec_t('m_too_large_srv');
        case UPLOAD_ERR_PARTIAL:    return ec_t('m_interrupted');
        case UPLOAD_ERR_NO_FILE:    return ec_t('m_no_file');
        case UPLOAD_ERR_NO_TMP_DIR: return ec_t('m_no_tmp');
        case UPLOAD_ERR_CANT_WRITE: return ec_t('m_cant_write');
        default:                    return ec_t('m_failed');
    }
}

function ec_media_upload($dirRel) {
    $rel = ec_safe_rel($dirRel);
    $abs = ($rel === false) ? false : ec_resolve_upload($rel);
    if ($abs === false || !is_dir($abs)) { return array('error' => ec_t('m_no_folder')); }
    if (!is_writable($abs)) { return array('error' => ec_t('m_not_writable')); }

    if (empty($_FILES['files'])) {
        return array('error' => ec_t('m_no_files'));
    }

    $f      = $_FILES['files'];
    $names  = (array)$f['name'];
    $prefix = ($rel === '') ? '' : $rel . '/';
    $out    = array();

    foreach ($names as $i => $orig) {
        $res = array('name' => (string)$orig, 'ok' => false);

        if ($f['error'][$i] !== UPLOAD_ERR_OK) {
            $res['error'] = ec_media_upload_error($f['error'][$i]);
            $out[] = $res; continue;
        }
        if ($f['size'][$i] > EC_MAX_UPLOAD_BYTES) {
            $res['error'] = ec_tf('m_too_big', EC_MAX_UPLOAD_BYTES / 1048576);
            $out[] = $res; continue;
        }
        $tmp = $f['tmp_name'][$i];
        if (!is_uploaded_file($tmp)) {
            $res['error'] = ec_t('m_invalid');
            $out[] = $res; continue;
        }

        // Trust the file contents, not the name or the browser's MIME type
        $info = @getimagesize($tmp);
        $ext  = $info ? ec_media_ext_for_type($info[2]) : '';
        if ($ext === '') {
            $res['error'] = ec_t('m_unsupported');
            $out[] = $res; continue;
        }

        $base = ec_media_slug(pathinfo((string)$orig, PATHINFO_FILENAME), true);
        if ($base === '') { $base = 'image'; }
        $name = $base . '.' . $ext;
        $n    = 1;
        while (file_exists($abs . DIRECTORY_SEPARATOR . $name)) {
            $name = $base . '-' . $n . '.' . $ext;
            $n++;
        }

        if (!move_uploaded_file($tmp, $abs . DIRECTORY_SEPARATOR . $name)) {
            $res['error'] = ec_t('m_save_failed');
            $out[] = $res; continue;
        }
        @chmod($abs . DIRECTORY_SEPARATOR . $name, 0644);

        $res['ok']   = true;
        $res['name'] = $name;
        $res['path'] = $prefix . $name;
        $out[] = $res;
    }

    return array('results' => $out);
}

function ec_media_mkdir($parentRel, $name) {
    $rel = ec_safe_rel($parentRel);
    $abs = ($rel === false) ? false : ec_resolve_upload($rel);
    if ($abs === false || !is_dir($abs)) { return array('error' => ec_t('m_no_folder')); }

    $slug = ec_media_slug($name, false);
    if ($slug === '') { return array('error' => ec_t('m_folder_name')); }

    $target = $abs . DIRECTORY_SEPARATOR . $slug;
    if (file_exists($target)) { return array('error' => ec_t('m_folder_exists')); }
    if (!@mkdir($target, 0755)) { return array('error' => ec_t('m_mkdir_failed')); }

    return array('ok' => true, 'path' => ($rel === '' ? '' : $rel . '/') . $slug);
}
