<?php
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/* ------------------------------------------------------------------
 * Strings and date formatting (en / es)
 * ---------------------------------------------------------------- */
function ec_format_date($ymd, $lang) {
    if (!ec_valid_date($ymd)) { return ''; }
    $days   = ec_t_list('days', $lang);
    $months = ec_t_list('months', $lang);
    $ts = mktime(12, 0, 0, (int)substr($ymd, 5, 2), (int)substr($ymd, 8, 2), (int)substr($ymd, 0, 4));
    $weekday = isset($days[(int)date('w', $ts)]) ? $days[(int)date('w', $ts)] : '';
    $month   = isset($months[(int)date('n', $ts) - 1]) ? $months[(int)date('n', $ts) - 1] : '';
    return strtr(ec_t('date_format', $lang), array(
        '{weekday}' => $weekday,
        '{d}'       => (string)(int)date('j', $ts),
        '{month}'   => $month,
        '{y}'       => date('Y', $ts),
    ));
}

/** Short date for the mini-list: "15 Oct", "15-17 Oct" or "28 Oct - 2 Nov". */
function ec_format_short_range($from, $to, $lang) {
    if (!ec_valid_date($from)) { return ''; }
    $mons = ec_t_list('months_short', $lang);
    $one = function ($ymd) use ($mons, $lang) {
        $m = (int)substr($ymd, 5, 2);
        return strtr(ec_t('short_date', $lang), array(
            '{d}'   => (string)(int)substr($ymd, 8, 2),
            '{mon}' => isset($mons[$m - 1]) ? $mons[$m - 1] : '',
        ));
    };
    if ($to === '' || $to === $from || !ec_valid_date($to)) { return $one($from); }
    if (substr($from, 0, 7) === substr($to, 0, 7)) {
        // same month: "15-17 Oct" (day numbers collapse, month shown once)
        $m = (int)substr($from, 5, 2);
        $short = $one($to);
        $dayFrom = (string)(int)substr($from, 8, 2);
        return preg_replace('/\d+/', $dayFrom . '-' . (int)substr($to, 8, 2), $short, 1);
    }
    return $one($from) . ' - ' . $one($to);
}

function ec_format_time($hm, $fmt) {
    if (!ec_valid_time($hm)) { return ''; }
    if ($fmt === '24') { return $hm; }
    return date('h:i a', strtotime('2000-01-01 ' . $hm));
}

/** Human readable date range and time range for one event. */
function ec_event_when($ev, $settings) {
    $lang = $settings['lang'];
    $date = ec_format_date($ev['date_from'], $lang);
    if ($ev['date_to'] !== '' && $ev['date_to'] !== $ev['date_from']) {
        $date .= ' - ' . ec_format_date($ev['date_to'], $lang);
    }
    if (!empty($ev['all_day'])) {
        $time = ec_t('all_day', $lang);
    } else {
        $time = ec_format_time($ev['time_from'], $settings['time_format']);
        if ($time !== '' && $ev['time_to'] !== '') {
            $time .= ' - ' . ec_format_time($ev['time_to'], $settings['time_format']);
        }
    }
    return array('date' => $date, 'time' => $time);
}

function ec_image_url($rel) {
    global $SITEURL;
    $parts = array_map('rawurlencode', explode('/', $rel));
    return $SITEURL . 'data/uploads/' . implode('/', $parts);
}

function ec_icon($name) {
    $paths = array(
        'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pin'      => '<path d="M12 21s-7-6.2-7-11a7 7 0 1 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    );
    return '<svg class="evc-ic" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" '
         . 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . $paths[$name] . '</svg>';
}

function ec_excerpt($text, $max) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    if ($max <= 0 || ec_strlen($text) <= $max) { return $text; }
    $cut = ec_substr($text, 0, $max);
    $sp  = strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) { $cut = substr($cut, 0, $sp); }
    return rtrim($cut, " ,.;:-") . ' ...';
}

/* ------------------------------------------------------------------
 * Assets. Everything is printed once per page, with the first view that needs it.
 * ---------------------------------------------------------------- */

/** Version string for asset URLs: plugin version + file modification time, so edits bypass browser caches. */
function ec_asset_ver($file) {
    $path = EC_DIR . 'assets/' . $file;
    $t = is_file($path) ? filemtime($path) : 0;
    return EC_VERSION . '.' . $t;
}

function ec_asset_url($file) {
    global $SITEURL;
    return $SITEURL . 'plugins/' . EC_PLUGIN_ID . '/assets/' . $file . '?v=' . ec_asset_ver($file);
}

/** Printed before a view: stylesheets and the shared event data. */
function ec_assets_before($needs_lib) {
    static $css = false, $lib_css = false, $data = false;
    $h = '';
    if ($needs_lib && !$lib_css) {
        $lib_css = true;
        $h .= '<link rel="stylesheet" href="' . ec_h(ec_asset_url('vendor/event-calendar.min.css')) . '">' . "\n";
    }
    if (!$css) {
        $css = true;
        $h .= '<link rel="stylesheet" href="' . ec_h(ec_asset_url('events.css')) . '">' . "\n";
    }
    if (!$data) {
        $data = true;
        $h .= ec_data_script();
    }
    return $h;
}

/** Printed after a view: scripts. Order does not matter, events.js starts on DOMContentLoaded. */
function ec_assets_after($needs_lib) {
    static $js = false, $lib_js = false;
    $h = '';
    if ($needs_lib && !$lib_js) {
        $lib_js = true;
        $h .= '<script src="' . ec_h(ec_asset_url('vendor/event-calendar.min.js')) . '"></script>' . "\n";
    }
    if (!$js) {
        $js = true;
        $h .= '<script src="' . ec_h(ec_asset_url('events.js')) . '"></script>' . "\n";
    }
    return $h;
}

/** Category array (id, name, color) of an event, or an empty one. */
function ec_event_category($ev) {
    static $map = null;
    if ($map === null) {
        $map = array();
        foreach (ec_get_categories() as $c) { $map[$c['id']] = $c; }
    }
    $id = isset($ev['category']) ? $ev['category'] : '';
    return ($id !== '' && isset($map[$id])) ? $map[$id] : array('id' => '', 'name' => '', 'color' => '');
}

/** Public data for one event, as used by the modal and the calendars. */
function ec_event_public($ev, $settings) {
    $when = ec_event_when($ev, $settings);
    $cat  = ec_event_category($ev);
    return array(
        'id'          => $ev['id'],
        'title'       => $ev['title'],
        'start'       => $ev['date_from'],
        'end'         => ($ev['date_to'] !== '' ? $ev['date_to'] : $ev['date_from']),
        'color'       => $ev['color'],
        'location'    => $ev['location'],
        'description' => $ev['description'],
        'image'       => ($ev['image'] !== '' ? ec_image_url($ev['image']) : ''),
        'url'         => $ev['url'],
        'category'    => $cat['id'],
        'categoryName'  => $cat['name'],
        'categoryColor' => $cat['color'],
        'dateText'    => $when['date'],
        'timeText'    => $when['time'],
    );
}

/** Categories that are used by at least one published event. */
function ec_categories_public() {
    $used = array();
    foreach (ec_get_events() as $e) {
        if (isset($e['status']) && $e['status'] === 'published' && !empty($e['category'])) { $used[$e['category']] = true; }
    }
    $out = array();
    foreach (ec_get_categories() as $c) {
        if (isset($used[$c['id']])) { $out[] = array('id' => $c['id'], 'name' => $c['name'], 'color' => $c['color']); }
    }
    return $out;
}

/** One JSON block for every view on the page: published events + labels. */
function ec_data_script() {
    $settings = ec_get_settings();
    $lang     = $settings['lang'];
    $events   = array();
    foreach (ec_get_events() as $ev) {
        if (isset($ev['status']) && $ev['status'] === 'published') {
            $events[] = ec_event_public($ev, $settings);
        }
    }
    $payload = array(
        'events'   => $events,
        'settings' => array(
            'firstDay' => (int)$settings['first_day'],
            'locale'   => ec_t('_locale', $lang),
            'categories' => ec_categories_public(),
            'labels'   => array(
                'today'     => ec_t('today', $lang),
                'prev'      => ec_t('prev', $lang),
                'next'      => ec_t('next', $lang),
                'prevYear'  => ec_t('prev_year', $lang),
                'nextYear'  => ec_t('next_year', $lang),
                'close'     => ec_t('close', $lang),
                'back'      => ec_t('back', $lang),
                'moreInfo'  => ec_t('more_info', $lang),
                'noEvents'  => ec_t('js_no_events', $lang),
                'allCategories' => ec_t('all_categories', $lang),
            ),
        ),
    );
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($json === false) { $json = '{"events":[],"settings":{}}'; }
    return '<script type="application/json" id="evc-data">' . $json . '</script>' . "\n";
}

/* ------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------- */
function ec_card_html($ev, $settings, $excerpt_len) {
    $when = ec_event_when($ev, $settings);
    // A plain div (not <article>) so theme rules for article/section elements cannot reach it
    $h  = '<div class="evc-card" data-evc-id="' . ec_h($ev['id']) . '" style="--evc-color:' . ec_h($ev['color']) . '">';
    if ($ev['image'] !== '') {
        $h .= '<div class="evc-card-img"><img src="' . ec_h(ec_image_url($ev['image'])) . '" alt="" loading="lazy"></div>';
    } else {
        // Keeps cards the same height when an event has no image; coloured with the event colour
        $h .= '<div class="evc-card-img evc-noimg" aria-hidden="true">' . ec_icon('calendar') . '</div>';
    }
    $h .= '<div class="evc-card-body">';
    $cat = ec_event_category($ev);
    if ($cat['id'] !== '') {
        $h .= '<span class="evc-tag" style="--evc-tag:' . ec_h($cat['color']) . '">' . ec_h($cat['name']) . '</span>';
    }
    $h .= '<h3 class="evc-card-title">' . ec_h($ev['title']) . '</h3>';
    if ($when['date'] !== '') {
        $h .= '<div class="evc-meta">' . ec_icon('calendar') . '<span>' . ec_h($when['date']) . '</span></div>';
    }
    if ($when['time'] !== '') {
        $h .= '<div class="evc-meta">' . ec_icon('clock') . '<span>' . ec_h($when['time']) . '</span></div>';
    }
    if ($ev['location'] !== '') {
        $h .= '<div class="evc-meta">' . ec_icon('pin') . '<span>' . ec_h($ev['location']) . '</span></div>';
    }
    $ex = ec_excerpt($ev['description'], $excerpt_len);
    if ($ex !== '') {
        $h .= '<p class="evc-card-text">' . ec_h($ex) . '</p>';
    }
    $h .= '</div></div>';
    return $h;
}

/** "a, b" -> array('a', 'b'), only valid slugs. */
function ec_parse_cats($s) {
    $out = array();
    foreach (explode(',', strtolower((string)$s)) as $c) {
        $c = trim($c);
        if ($c !== '' && preg_match('/^[a-z0-9-]+$/', $c)) { $out[] = $c; }
    }
    return array_values(array_unique($out));
}

/** Published events matching the attributes, sorted. */
function ec_collect_events($a) {
    $today = date('Y-m-d');
    $from  = ec_valid_date($a['from']) ? $a['from'] : '';
    $to    = ec_valid_date($a['to']) ? $a['to'] : '';

    $cats = ec_parse_cats(isset($a['category']) ? $a['category'] : '');

    $events = array();
    foreach (ec_get_events() as $ev) {
        if (!isset($ev['status']) || $ev['status'] !== 'published') { continue; }
        if ($cats && !in_array(isset($ev['category']) ? $ev['category'] : '', $cats, true)) { continue; }
        $end = ($ev['date_to'] !== '') ? $ev['date_to'] : $ev['date_from'];
        if ($from !== '' && $end < $from) { continue; }
        if ($to !== '' && $ev['date_from'] > $to) { continue; }
        if ($from === '' && $a['past'] !== '1' && $end < $today) { continue; }
        $events[] = $ev;
    }

    usort($events, function ($x, $y) {
        $kx = $x['date_from'] . ' ' . ($x['time_from'] !== '' ? $x['time_from'] : '00:00');
        $ky = $y['date_from'] . ' ' . ($y['time_from'] !== '' ? $y['time_from'] : '00:00');
        return strcmp($kx, $ky);
    });
    if (strtolower($a['order']) === 'desc') { $events = array_reverse($events); }

    $limit = (int)$a['limit'];
    if ($limit > 0) { $events = array_slice($events, 0, $limit); }
    return $events;
}

/**
 * Render one events view.
 * Attributes:
 *   view     list | grid | calendar | compact | mini    (default list)
 *   category one or more category slugs, comma separated (all views)
 *   filter   1 = show category buttons above the calendar (calendar)
 *   limit    maximum number of events (list, grid)
 *   columns  1-4 (grid)
 *   past     1 = include finished events (list, grid)
 *   from, to YYYY-MM-DD range (list, grid)
 *   order    asc | desc (list, grid)
 *   excerpt  characters of description on cards (0 = none)
 *   mobile   list | compact | grid  what the calendar shows in a narrow area (calendar), default from Settings
 *   breakpoint  width in px below which the calendar switches (calendar), default from Settings
 */
function ec_render($atts = array()) {
    static $n = 0;

    $a = array_merge(array(
        'view' => 'list', 'limit' => 0, 'columns' => 3, 'past' => '0',
        'from' => '', 'to' => '', 'order' => 'asc', 'excerpt' => 110, 'mobile' => '', 'breakpoint' => '',
        'category' => '', 'filter' => '0',
    ), array_change_key_case((array)$atts, CASE_LOWER));

    $view = strtolower(trim($a['view']));
    if (!in_array($view, array('list', 'grid', 'calendar', 'compact', 'mini'), true)) {
        return '<!-- events: unknown view "' . ec_h($view) . '" -->';
    }

    $n++;
    $settings   = ec_get_settings();
    $needs_lib  = ($view === 'calendar');
    $html       = ec_assets_before($needs_lib);
    $excerpt    = max(0, (int)$a['excerpt']);

    if ($view === 'calendar' || $view === 'compact') {
        $attr = '';
        if ($view === 'calendar') {
            $mobile = in_array($a['mobile'], array('list', 'compact', 'grid'), true) ? $a['mobile'] : $settings['mobile_mode'];
            $bp     = ((int)$a['breakpoint'] > 0) ? (int)$a['breakpoint'] : (int)$settings['breakpoint'];
            $bp     = max(280, min(1400, $bp));
            $attr   = ' data-evc-mobile="' . ec_h($mobile) . '" data-evc-breakpoint="' . $bp . '"'
                    . ($a['filter'] === '1' ? ' data-evc-filter="1"' : '');
        }
        $cats = ec_parse_cats($a['category']);
        if ($cats) { $attr .= ' data-evc-cat="' . ec_h(implode(',', $cats)) . '"'; }
        $html .= '<div class="evc evc-' . $view . '" id="evc-' . $n . '" data-evc-view="' . $view . '"' . $attr . '>';
        $html .= '<div class="evc-mount"></div>';
        // Without JavaScript: a plain list of upcoming events
        $fallback = ec_collect_events(array_merge($a, array('limit' => 20, 'past' => '0', 'from' => '', 'to' => '', 'order' => 'asc')));
        $html .= '<noscript><div class="evc evc-list">';
        if (!$fallback) { $html .= '<p class="evc-empty">' . ec_h(ec_t('no_events', $settings['lang'])) . '</p>'; }
        foreach ($fallback as $ev) { $html .= ec_card_html($ev, $settings, $excerpt); }
        $html .= '</div></noscript>';
        $html .= '</div>';
        return $html . ec_assets_after($needs_lib);
    }

    // mini list (sidebar): colour dot, title, short date
    if ($view === 'mini') {
        if ((int)$a['limit'] <= 0) { $a['limit'] = 5; }
        $events = ec_collect_events($a);
        $html .= '<ul class="evc evc-mini" id="evc-' . $n . '" data-evc-view="mini">';
        if (!$events) {
            $html .= '<li class="evc-mini-empty">' . ec_h(ec_t('no_events', $settings['lang'])) . '</li>';
        }
        foreach ($events as $ev) {
            $date = ec_format_short_range($ev['date_from'], $ev['date_to'], $settings['lang']);
            $html .= '<li class="evc-mini-item" data-evc-id="' . ec_h($ev['id']) . '" style="--evc-color:' . ec_h($ev['color']) . '">'
                   . '<span class="evc-mini-dot" aria-hidden="true"></span>'
                   . '<span class="evc-mini-text"><span class="evc-mini-title">' . ec_h($ev['title']) . '</span>'
                   . '<span class="evc-mini-date">' . ec_h($date) . '</span></span></li>';
        }
        $html .= '</ul>';
        return $html . ec_assets_after(false);
    }

    // list and grid
    $events = ec_collect_events($a);
    $cols   = max(1, min(4, (int)$a['columns']));

    $html .= '<div class="evc evc-' . $view . ($view === 'grid' ? ' evc-cols-' . $cols : '')
           . '" id="evc-' . $n . '" data-evc-view="' . $view . '">';
    if (!$events) {
        $html .= '<p class="evc-empty">' . ec_h(ec_t('no_events', $settings['lang'])) . '</p>';
    }
    foreach ($events as $ev) {
        $html .= ec_card_html($ev, $settings, $excerpt);
    }
    $html .= '</div>';
    return $html . ec_assets_after(false);
}

/* ------------------------------------------------------------------
 * Entry points: template function and page shortcode
 * ---------------------------------------------------------------- */

/** Template code:  <?php get_events(array('view' => 'grid', 'limit' => 6)); ?> */
function get_events($atts = array(), $echo = true) {
    $html = ec_render($atts);
    if ($echo) { echo $html; return; }
    return $html;
}

/** Page content filter for  [events view="list" limit="6"] */
function ec_content_filter($content) {
    if (strpos($content, '[events') === false) { return $content; }
    return preg_replace_callback(
        '/(?:<p>\s*)?\[events((?:\s+[a-z_]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|&quot;.*?&quot;))*)\s*\](?:\s*<\/p>)?/i',
        'ec_shortcode_callback',
        $content
    );
}

function ec_shortcode_callback($m) {
    $atts = array();
    $raw  = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    if (preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $raw, $pairs, PREG_SET_ORDER)) {
        foreach ($pairs as $p) {
            $atts[strtolower($p[1])] = isset($p[3]) && $p[3] !== '' ? $p[3] : $p[2];
        }
    }
    return ec_render($atts);
}
