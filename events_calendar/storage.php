<?php
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/* ------------------------------------------------------------------
 * Paths
 * ---------------------------------------------------------------- */
define('EC_DATA_DIR',	  GSDATAOTHERPATH . 'events/');
define('EC_EVENTS_FILE',   EC_DATA_DIR . 'events.json');
define('EC_SETTINGS_FILE', EC_DATA_DIR . 'settings.json');
define('EC_CATEGORIES_FILE', EC_DATA_DIR . 'categories.json');
define('EC_LOCK_FILE',	 EC_DATA_DIR . '.lock');

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
		'first_day'	 => 1,		  // 1 = Monday, 0 = Sunday
		'lang'		  => '',		 // website language (locale code such as en_US); empty = the site's language
		'time_format'   => '12',	   // 12 | 24
		'default_color' => '#6c6ce5',
		'mobile_mode'   => 'list',	 // what the month calendar becomes in a narrow area: list | compact | grid
		'breakpoint'	=> 560,		// px, measured on the calendar's own container
	);
}

function ec_get_settings($reload = false) {
	static $cache = null;
	if ($cache === null || $reload) {
		$saved = ec_read_raw(EC_SETTINGS_FILE);
		$cache = array_merge(ec_default_settings(), is_array($saved) ? $saved : array());
	}
	return $cache;
}

function ec_save_settings($in) {
	$s = ec_default_settings();
	$s['first_day']	 = (isset($in['first_day']) && (string)$in['first_day'] === '0') ? 0 : 1;
	$s['lang']		  = (isset($in['lang']) && array_key_exists($in['lang'], ec_languages())) ? $in['lang'] : '';
	$s['time_format']   = (isset($in['time_format']) && $in['time_format'] === '24') ? '24' : '12';
	$s['default_color'] = (isset($in['default_color']) && ec_valid_color($in['default_color']))
						  ? strtolower($in['default_color']) : $s['default_color'];
	$s['mobile_mode']   = (isset($in['mobile_mode']) && in_array($in['mobile_mode'], array('list', 'compact', 'grid'), true))
						  ? $in['mobile_mode'] : 'list';
	$s['breakpoint']	= isset($in['breakpoint']) ? max(280, min(1400, (int)$in['breakpoint'])) : 560;
	$h = ec_lock();
	$ok = ec_write_raw(EC_SETTINGS_FILE, $s);
	ec_unlock($h);
	ec_get_settings(true);
	ec_front_lang(true);
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
 * Rich text (event description)
 * ---------------------------------------------------------------- */
function ec_desc_is_html($s) {
	return preg_match('/<\/?(p|div|br|b|strong|i|em|u|ul|ol|li|h[1-6]|blockquote|a|span|font)\b/i', (string)$s) === 1;
}

/** Plain text (blank line = new paragraph) to HTML. */
function ec_plain_to_html($s) {
	$s = trim(str_replace(array("\r\n", "\r"), "\n", (string)$s));
	if ($s === '') { return ''; }
	$out = '';
	foreach (preg_split('/\n{2,}/', $s) as $para) {
		$out .= '<p>' . str_replace("\n", '<br>', ec_h($para)) . '</p>';
	}
	return $out;
}

/** HTML ready to show, for both new (HTML) and old (plain text) descriptions. */
function ec_desc_html($s) {
	$s = (string)$s;
	if ($s === '') { return ''; }
	return ec_desc_is_html($s) ? $s : ec_plain_to_html($s);
}

/** One line of plain text, for card excerpts. */
function ec_desc_text($s) {
	$h = ec_desc_html($s);
	$h = preg_replace('#</(p|div|li|h[1-6]|blockquote)>|<br\s*/?>#i', ' ', $h);
	$t = html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8');
	$t = str_replace("\xC2\xA0", ' ', $t);
	$c = preg_replace('/\s+/u', ' ', $t);
	return trim($c === null ? $t : $c);
}

/** Clean editor HTML. Returns '' when nothing visible is left. */
function ec_sanitize_html($html) {
	$html = trim((string)$html);
	if ($html === '') { return ''; }
	$html = ec_substr($html, 0, 30000);
	$out  = class_exists('DOMDocument') ? ec_sanitize_html_dom($html) : ec_sanitize_html_basic($html);
	$out  = trim(preg_replace('/<\?xml[^>]*\?>/i', '', $out));	  // libxml can leave an encoding declaration behind
	if (ec_desc_text($out) === '' && stripos($out, '<li') === false) { return ''; }
	return $out;
}

/** Without the DOM extension: keep a few tags and drop every attribute. */
function ec_sanitize_html_basic($html) {
	$h = strip_tags($html, '<p><div><br><b><strong><i><em><u><ul><ol><li><h3><h4><blockquote><span><font>');
	return preg_replace('/<(\/?)([a-z0-9]+)\b[^>]*>/i', '<$1$2>', $h);
}

function ec_sanitize_html_dom($html) {
	$prev = libxml_use_internal_errors(true);
	$dom  = new DOMDocument('1.0', 'UTF-8');
	$dom->loadHTML('<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);

	$bodies = $dom->getElementsByTagName('body');
	if (!$bodies->length) { return ''; }
	$body = $bodies->item(0);
	ec_clean_dom_node($body);

	$out = '';
	foreach ($body->childNodes as $n) { $out .= $dom->saveHTML($n); }
	return $out;
}

function ec_safe_href($h) {
	$h = preg_replace('/[\x00-\x20]+/', '', (string)$h);
	return preg_match('#^(https?://|mailto:|tel:)#i', $h) === 1;
}

function ec_clean_dom_node($node) {
	static $allowed = array('p' => 1, 'div' => 1, 'br' => 1, 'b' => 1, 'strong' => 1, 'i' => 1, 'em' => 1, 'u' => 1,
							'ul' => 1, 'ol' => 1, 'li' => 1, 'h3' => 1, 'h4' => 1, 'blockquote' => 1, 'a' => 1, 'span' => 1, 'font' => 1);
	// removed together with everything inside them
	static $drop = array('script' => 1, 'style' => 1, 'iframe' => 1, 'frame' => 1, 'frameset' => 1, 'object' => 1, 'embed' => 1,
						 'applet' => 1, 'form' => 1, 'input' => 1, 'button' => 1, 'textarea' => 1, 'select' => 1, 'option' => 1,
						 'svg' => 1, 'math' => 1, 'link' => 1, 'meta' => 1, 'base' => 1, 'title' => 1, 'head' => 1,
						 'noscript' => 1, 'template' => 1, 'img' => 1, 'video' => 1, 'audio' => 1, 'source' => 1,
						 'canvas' => 1, 'map' => 1, 'area' => 1);
	// the editor offers six title sizes; the site uses two
	static $rename = array('h1' => 'h3', 'h2' => 'h3', 'h5' => 'h4', 'h6' => 'h4', 'pre' => 'p', 'font' => 'span');

	$kids = array();
	foreach ($node->childNodes as $c) { $kids[] = $c; }

	foreach ($kids as $c) {
		if ($c->nodeType === XML_TEXT_NODE) { continue; }
		if ($c->nodeType !== XML_ELEMENT_NODE) { $node->removeChild($c); continue; }   // comments, processing instructions

		$tag = strtolower($c->nodeName);
		if (isset($drop[$tag])) { $node->removeChild($c); continue; }

		if (isset($rename[$tag])) {
			$new = $c->ownerDocument->createElement($rename[$tag]);
			foreach ($c->attributes as $a) { $new->setAttribute($a->nodeName, $a->nodeValue); }
			while ($c->firstChild) { $new->appendChild($c->firstChild); }
			$node->replaceChild($new, $c);
			$c   = $new;
			$tag = $rename[$tag];
		}

		ec_clean_dom_node($c);	// children first

		if (!isset($allowed[$tag]) || ($tag === 'a' && !ec_safe_href($c->getAttribute('href')))) {
			while ($c->firstChild) { $node->insertBefore($c->firstChild, $c); }	 // keep the text, lose the tag
			$node->removeChild($c);
			continue;
		}
		ec_clean_dom_attrs($c, $tag);
	}
}

function ec_clean_dom_attrs($el, $tag) {
	$style     = (string)$el->getAttribute('style');
	$align     = trim((string)$el->getAttribute('align'));
	$href      = trim((string)$el->getAttribute('href'));
	$fontColor = trim((string)$el->getAttribute('color'));

	$names = array();
	foreach ($el->attributes as $a) { $names[] = $a->nodeName; }
	foreach ($names as $n) { $el->removeAttribute($n); }

	$css_rules = array();

	$ta = '';
	if (preg_match('/text-align\s*:\s*(left|right|center|justify)/i', $style, $m)) {
		$ta = strtolower($m[1]);
	} elseif (preg_match('/^(left|right|center|justify)$/i', $align, $m)) {
		$ta = strtolower($m[1]);
	}
	if ($ta !== '' && in_array($tag, array('p', 'div', 'h3', 'h4', 'li', 'blockquote'), true)) {
		$css_rules[] = 'text-align: ' . $ta;
	}


	if (preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $cm)) {
		$val = trim($cm[1]);
		if (preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+(?:\s*,\s*[\d.]+\s*)?\)|hsla?\([^)]+\)|[a-zA-Z]+)$/i', $val)) {
			$css_rules[] = 'color: ' . $val;
		}
	}

	elseif ($fontColor !== '' && preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]+)$/i', $fontColor)) {
		$css_rules[] = 'color: ' . $fontColor;
	}

	if (!empty($css_rules)) {
		$el->setAttribute('style', implode('; ', $css_rules));
	}

	if ($tag === 'a') {
		$el->setAttribute('href', $href);
		$el->setAttribute('rel', 'noopener noreferrer');
		$el->setAttribute('target', '_blank');
	}
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
		'id'		  => ($id !== '' ? $id : ec_new_id()),
		'title'	   => ec_clean_text(isset($in['title']) ? $in['title'] : '', 200),
		'date_from'   => isset($in['date_from']) ? trim($in['date_from']) : '',
		'date_to'	 => isset($in['date_to']) ? trim($in['date_to']) : '',
		'time_from'   => isset($in['time_from']) ? trim($in['time_from']) : '',
		'time_to'	 => isset($in['time_to']) ? trim($in['time_to']) : '',
		'all_day'	 => !empty($in['all_day']),
		'location'	=> ec_clean_text(isset($in['location']) ? $in['location'] : '', 200),
		'description' => '',
		'image'	   => '',
		'color'	   => $settings['default_color'],
		'url'		 => '',
		'category'	=> '',
		'status'	  => (isset($in['status']) && $in['status'] === 'draft') ? 'draft' : 'published',
	);

	// Description: limited, cleaned HTML from the editor
	if (isset($in['description'])) {
		$ev['description'] = ec_sanitize_html($in['description']);
	}

	if ($ev['title'] === '') { $errors[] = ec_t('e_title_required'); }

	if (!ec_valid_date($ev['date_from'])) {
		$errors[] = ec_t('e_start_required');
		$ev['date_from'] = '';
	}
	if ($ev['date_to'] === '' && $ev['date_from'] !== '') { $ev['date_to'] = $ev['date_from']; }
	if ($ev['date_to'] !== '' && !ec_valid_date($ev['date_to'])) {
		$errors[] = ec_t('e_end_invalid');
		$ev['date_to'] = $ev['date_from'];
	}
	if ($ev['date_from'] !== '' && $ev['date_to'] !== '' && $ev['date_to'] < $ev['date_from']) {
		$errors[] = ec_t('e_end_before_start');
	}

	if ($ev['all_day']) {
		$ev['time_from'] = '';
		$ev['time_to']   = '';
	} else {
		if ($ev['time_from'] !== '' && !ec_valid_time($ev['time_from'])) {
			$errors[] = ec_t('e_start_time_invalid');
			$ev['time_from'] = '';
		}
		if ($ev['time_to'] !== '' && !ec_valid_time($ev['time_to'])) {
			$errors[] = ec_t('e_end_time_invalid');
			$ev['time_to'] = '';
		}
		if ($ev['time_to'] !== '' && $ev['time_from'] === '') {
			$errors[] = ec_t('e_end_needs_start');
		}
		if ($ev['date_from'] !== '' && $ev['date_from'] === $ev['date_to']
			&& $ev['time_from'] !== '' && $ev['time_to'] !== '' && $ev['time_to'] < $ev['time_from']) {
			$errors[] = ec_t('e_end_time_before');
		}
	}

	if (isset($in['color']) && ec_valid_color($in['color'])) { $ev['color'] = strtolower($in['color']); }

	if (!empty($in['image'])) {
		$img = ec_clean_image_path($in['image']);
		if ($img === '') { $errors[] = ec_t('e_image_missing'); }
		$ev['image'] = $img;
	}

	if (!empty($in['category'])) {
		$cid = strtolower(trim((string)$in['category']));
		if (ec_get_category($cid) !== null) { $ev['category'] = $cid; }   // unknown ids are ignored
	}

	$repeat = ec_sanitize_repeat($in, $ev, $errors);
	if ($repeat) { $ev['repeat'] = $repeat; }

	if (!empty($in['url'])) {
		$u = trim($in['url']);
		if (filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u)) {
			$ev['url'] = $u;
		} else {
			$errors[] = ec_t('e_link_invalid');
		}
	}

	return array($ev, $errors);
}


/* ------------------------------------------------------------------
 * Categories: array of array('id' => slug, 'name' => ..., 'color' => '#rrggbb')
 * ---------------------------------------------------------------- */

function ec_translit($s) {
	static $map = null;
	if ($map === null) {
		$map = array();
		$groups = array(
			'a' => 'àáâãäåāăą', 'A' => 'ÀÁÂÃÄÅĀĂĄ', 'e' => 'èéêëēĕėęě', 'E' => 'ÈÉÊËĒĔĖĘĚ',
			'i' => 'ìíîïĩīĭįı', 'I' => 'ÌÍÎÏĨĪĬĮİ', 'o' => 'òóôõöøōŏő', 'O' => 'ÒÓÔÕÖØŌŎŐ',
			'u' => 'ùúûüũūŭůűų', 'U' => 'ÙÚÛÜŨŪŬŮŰŲ', 'c' => 'çćĉċč', 'C' => 'ÇĆĈĊČ',
			'n' => 'ñńņňŉ', 'N' => 'ÑŃŅŇ', 's' => 'śŝşš', 'S' => 'ŚŜŞŠ', 'z' => 'źżž', 'Z' => 'ŹŻŽ',
			'y' => 'ýÿŷ', 'Y' => 'ÝŸŶ', 'd' => 'ďđ', 'D' => 'ĎĐ', 'l' => 'ĺļľłŀ', 'L' => 'ĹĻĽŁĿ',
			'g' => 'ĝğġģ', 'G' => 'ĜĞĠĢ', 'r' => 'ŕŗř', 'R' => 'ŔŖŘ', 't' => 'ţťŧ', 'T' => 'ŢŤŦ',
		);
		foreach ($groups as $plain => $chars) {
			preg_match_all('/./u', $chars, $m);
			foreach ($m[0] as $ch) { $map[$ch] = $plain; }
		}
		$map['ß'] = 'ss'; $map['æ'] = 'ae'; $map['Æ'] = 'AE'; $map['œ'] = 'oe'; $map['Œ'] = 'OE';
	}
	return strtr((string)$s, $map);
}

function ec_slugify($s) {
	$s = ec_translit($s);
	$s = strtolower($s);
	$s = preg_replace('/[^a-z0-9]+/', '-', $s);
	return substr(trim($s, '-'), 0, 40);
}

function ec_get_categories() {
	$d = ec_read_raw(EC_CATEGORIES_FILE);
	return is_array($d) ? array_values($d) : array();
}

function ec_get_category($id) {
	foreach (ec_get_categories() as $c) {
		if (isset($c['id']) && $c['id'] === $id) { return $c; }
	}
	return null;
}

/** Validate and save a category. Returns array($category, $errors). */
function ec_save_category($in, $id = '') {
	$settings = ec_get_settings();
	$name  = ec_clean_text(isset($in['name']) ? $in['name'] : '', 60);
	$color = (isset($in['color']) && ec_valid_color($in['color'])) ? strtolower($in['color']) : $settings['default_color'];
	$cat   = array('id' => $id, 'name' => $name, 'color' => $color);
	$errors = array();

	if ($name === '') { $errors[] = ec_t('e_cat_name'); return array($cat, $errors); }

	$h = ec_lock();
	$cats = ec_get_categories();

	if ($id === '' ) {
		$base = ec_slugify($name);
		if ($base === '') { $base = 'category'; }
		$slug = $base;
		$n = 2;
		$taken = array();
		foreach ($cats as $c) { $taken[$c['id']] = true; }
		while (isset($taken[$slug])) { $slug = $base . '-' . $n; $n++; }
		$cat['id'] = $slug;
		$cats[] = $cat;
	} else {
		$found = false;
		foreach ($cats as $i => $c) {
			if ($c['id'] === $id) { $cats[$i] = $cat; $found = true; break; }
		}
		if (!$found) { $cats[] = $cat; }
	}

	if (!ec_write_raw(EC_CATEGORIES_FILE, $cats)) { $errors[] = ec_t('n_cat_failed'); }
	ec_unlock($h);
	return array($cat, $errors);
}

/** Delete a category and clear it from the events that used it, in one locked operation. */
function ec_delete_category($id) {
	$h = ec_lock();
	$cats = array();
	foreach (ec_get_categories() as $c) {
		if ($c['id'] !== $id) { $cats[] = $c; }
	}
	$ok = ec_write_raw(EC_CATEGORIES_FILE, $cats);

	$events  = ec_get_events();
	$changed = false;
	foreach ($events as $i => $e) {
		if (isset($e['category']) && $e['category'] === $id) { $events[$i]['category'] = ''; $changed = true; }
	}
	if ($changed) { $ok = ec_write_raw(EC_EVENTS_FILE, $events) && $ok; }
	ec_unlock($h);
	return $ok;
}

/** Number of events per category id. */
function ec_category_counts() {
	$n = array();
	foreach (ec_get_events() as $e) {
		$c = isset($e['category']) ? $e['category'] : '';
		if ($c !== '') { $n[$c] = isset($n[$c]) ? $n[$c] + 1 : 1; }
	}
	return $n;
}
