<?php
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/*
 * Admin pages. GetSimple calls ec_admin() for load.php?id=events_calendar&action=...
 * Actions: list (default), edit, settings, categories, help     (GET)
 *          save, delete, save_settings, save_category, delete_category   (POST, nonce protected)
 */

function ec_admin_url($action, $extra = '') {
    return 'load.php?id=' . EC_PLUGIN_ID . '&amp;action=' . $action . $extra;
}

function ec_admin() {
    global $SITEURL;

    $action     = isset($_GET['action']) ? (string)$_GET['action'] : 'list';
    $notice     = null;      // array(type, message)  type: updated | error
    $form_event = null;
    $form_cat   = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nonce   = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
        $allowed = array('save', 'delete', 'save_settings', 'save_category', 'delete_category');

        if (!in_array($action, $allowed, true) || !check_nonce($nonce, 'ec_' . $action, 'load.php')) {
            $notice = array('error', ec_t('n_security'));
            $action = 'list';

        } elseif ($action === 'save') {
            $id       = isset($_POST['id']) ? (string)$_POST['id'] : '';
            $existing = ($id !== '') ? ec_get_event($id) : null;
            list($ev, $errors) = ec_sanitize_event($_POST, $existing ? $existing['id'] : '');
            if ($errors) {
                $notice     = array('error', implode(' ', $errors));
                $form_event = $ev;
                $action     = 'edit';
            } elseif (ec_save_event($ev)) {
                $notice = array('updated', ec_t('n_saved'));
                $action = 'list';
            } else {
                $notice     = array('error', ec_t('n_save_failed'));
                $form_event = $ev;
                $action     = 'edit';
            }

        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
            $notice = ($id !== '' && ec_delete_event($id))
                ? array('updated', ec_t('n_deleted'))
                : array('error', ec_t('n_delete_failed'));
            $action = 'list';

        } elseif ($action === 'save_settings') {
            $notice = ec_save_settings($_POST)
                ? array('updated', ec_t('n_settings_saved'))
                : array('error', ec_t('n_settings_failed'));
            $action = 'settings';

        } elseif ($action === 'save_category') {
            $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
            if ($id !== '' && ec_get_category($id) === null) { $id = ''; }
            list($cat, $errors) = ec_save_category($_POST, $id);
            if ($errors) {
                $notice   = array('error', implode(' ', $errors));
                $form_cat = $cat;
            } else {
                $notice = array('updated', ec_t('n_cat_saved'));
            }
            $action = 'categories';

        } elseif ($action === 'delete_category') {
            $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
            $notice = ($id !== '' && ec_delete_category($id))
                ? array('updated', ec_t('n_cat_deleted'))
                : array('error', ec_t('n_cat_failed'));
            $action = 'categories';
        }
    }

    echo '<link rel="stylesheet" href="' . ec_h($SITEURL . 'plugins/' . EC_PLUGIN_ID . '/assets/admin.css?v=' . ec_asset_ver('admin.css')) . '">';
    echo '<div class="ec-admin">';
    if ($notice) {
        echo '<div class="' . $notice[0] . '" style="display:block;margin:0 0 15px"><p>' . ec_h($notice[1]) . '</p></div>';
    }

    switch ($action) {
        case 'edit':       ec_admin_form($form_event);        break;
        case 'settings':   ec_admin_settings();               break;
        case 'categories': ec_admin_categories($form_cat);    break;
        case 'help':       ec_admin_help();                   break;
        default:           ec_admin_list();                   break;
    }
    echo '</div>';
}

/* ------------------------------------------------------------------ */

function ec_admin_list() {
    $settings = ec_get_settings();
    $events   = ec_get_events();
    usort($events, function ($a, $b) { return strcmp($b['date_from'], $a['date_from']); });
    $today = date('Y-m-d');

    echo '<h3>' . ec_h(ec_t('h_events')) . '</h3>';
    echo '<p><a class="ec-btn ec-btn-primary" href="' . ec_admin_url('edit') . '">' . ec_h(ec_t('btn_add')) . '</a></p>';

    if (!$events) {
        echo '<p>' . ec_h(ec_t('no_events_yet')) . '</p>';
        return;
    }

    echo '<table class="ec-table"><thead><tr>'
       . '<th>' . ec_h(ec_t('th_title')) . '</th><th>' . ec_h(ec_t('th_when')) . '</th>'
       . '<th>' . ec_h(ec_t('th_location')) . '</th><th>' . ec_h(ec_t('th_category')) . '</th>'
       . '<th>' . ec_h(ec_t('th_status')) . '</th><th></th></tr></thead><tbody>';

    foreach ($events as $ev) {
        $when = ec_event_when($ev, $settings);
        $end  = ($ev['date_to'] !== '') ? $ev['date_to'] : $ev['date_from'];
        $past = ($end < $today);
        $cat  = ec_event_category($ev);

        echo '<tr' . ($past ? ' class="ec-past"' : '') . '>';
        echo '<td><span class="ec-dot" style="background:' . ec_h($ev['color']) . '"></span>'
           . '<a href="' . ec_admin_url('edit', '&amp;eid=' . rawurlencode($ev['id'])) . '">' . ec_h($ev['title']) . '</a></td>';
        echo '<td>' . ec_h($when['date']) . ($when['time'] !== '' ? '<br><small>' . ec_h($when['time']) . '</small>' : '') . '</td>';
        echo '<td>' . ec_h($ev['location']) . '</td>';
        echo '<td>' . ($cat['id'] !== '' ? ec_h($cat['name']) : '') . '</td>';
        echo '<td>' . ec_h($ev['status'] === 'draft' ? ec_t('status_draft') : ec_t('status_published'))
           . ($past ? ' <small>(' . ec_h(ec_t('past')) . ')</small>' : '') . '</td>';
        echo '<td class="ec-actions">';
        echo '<a href="' . ec_admin_url('edit', '&amp;eid=' . rawurlencode($ev['id'])) . '">' . ec_h(ec_t('edit')) . '</a> ';
        echo '<form method="post" action="' . ec_admin_url('delete') . '" class="ec-inline" '
           . 'onsubmit="return confirm(' . ec_h(json_encode(ec_t('confirm_delete'))) . ');">';
        echo '<input type="hidden" name="nonce" value="' . ec_h(get_nonce('ec_delete', 'load.php')) . '">';
        echo '<input type="hidden" name="id" value="' . ec_h($ev['id']) . '">';
        echo '<button type="submit" class="ec-link-danger">' . ec_h(ec_t('delete')) . '</button></form>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<p class="ec-help"><a href="' . ec_admin_url('help') . '">' . ec_h(ec_t('h_help')) . '</a></p>';
}

/* ------------------------------------------------------------------ */

/** Labels the image browser JavaScript needs. */
function ec_admin_js_labels() {
    $keys = array('img_library', 'img_root', 'img_new_folder', 'img_upload', 'img_drop', 'img_empty', 'img_use',
                  'img_loading', 'img_uploading', 'img_uploaded', 'img_pick_files', 'img_prompt', 'img_up',
                  'img_truncated', 'img_network', 'img_bad_response', 'cancel', 'close', 'no_image');
    $out = array();
    foreach ($keys as $k) { $out[$k] = ec_t($k); }
    return $out;
}

function ec_admin_form($ev = null) {
    global $SITEURL;
    $settings = ec_get_settings();

    if ($ev === null) {
        $eid = isset($_GET['eid']) ? (string)$_GET['eid'] : '';
        $ev  = ($eid !== '') ? ec_get_event($eid) : null;
    }
    $is_new = ($ev === null || empty($ev['id']) || ec_get_event($ev['id']) === null);

    if ($ev === null) {
        $ev = array(
            'id' => '', 'title' => '', 'date_from' => date('Y-m-d'), 'date_to' => '',
            'time_from' => '', 'time_to' => '', 'all_day' => false, 'location' => '',
            'description' => '', 'image' => '', 'color' => $settings['default_color'],
            'url' => '', 'category' => '', 'status' => 'published',
        );
    }
    if (!isset($ev['category'])) { $ev['category'] = ''; }

    $presets    = array('#f51b50', '#6c6ce5', '#ffd15c', '#1da1f2', '#bd2f9e', '#22c55e', '#f97316', '#64748b');
    $categories = ec_get_categories();
    $cat_colors = array();
    foreach ($categories as $c) { $cat_colors[$c['id']] = $c['color']; }

    echo '<h3>' . ec_h($is_new ? ec_t('h_add_event') : ec_t('h_edit_event')) . '</h3>';

    echo '<form method="post" action="' . ec_admin_url('save') . '" class="ec-form" id="ec-form" '
       . 'data-ajax="' . ec_h('load.php?id=' . EC_PLUGIN_ID . '&action=ajax') . '" '
       . 'data-nonce="' . ec_h(get_nonce('ec_ajax', 'load.php')) . '" '
       . 'data-uploads="' . ec_h($SITEURL . 'data/uploads/') . '" '
       . 'data-new="' . ($is_new ? '1' : '0') . '" '
       . 'data-catcolors="' . ec_h(json_encode((object)$cat_colors)) . '" '
       . 'data-labels="' . ec_h(json_encode(ec_admin_js_labels())) . '">';
    echo '<input type="hidden" name="nonce" value="' . ec_h(get_nonce('ec_save', 'load.php')) . '">';
    echo '<input type="hidden" name="id" value="' . ec_h($is_new ? '' : $ev['id']) . '">';

    $opt = ' <small>(' . ec_h(ec_t('optional')) . ')</small>';

    // Title
    echo '<p><label for="ec_title">' . ec_h(ec_t('f_title')) . '</label>'
       . '<input type="text" id="ec_title" name="title" maxlength="200" required value="' . ec_h($ev['title']) . '"></p>';

    // Dates
    echo '<div class="ec-row">';
    echo '<p><label for="ec_date_from">' . ec_h(ec_t('f_start_date')) . '</label>'
       . '<input type="date" id="ec_date_from" name="date_from" required value="' . ec_h($ev['date_from']) . '"></p>';
    echo '<p><label for="ec_date_to">' . ec_h(ec_t('f_end_date')) . $opt . '</label>'
       . '<input type="date" id="ec_date_to" name="date_to" value="' . ec_h($ev['date_to'] !== $ev['date_from'] ? $ev['date_to'] : '') . '"></p>';
    echo '</div>';

    // Times
    echo '<p class="ec-check"><label><input type="checkbox" id="ec_all_day" name="all_day" value="1"'
       . (!empty($ev['all_day']) ? ' checked' : '') . '> ' . ec_h(ec_t('f_all_day')) . '</label></p>';
    echo '<div class="ec-row ec-times">';
    echo '<p><label for="ec_time_from">' . ec_h(ec_t('f_start_time')) . '</label>'
       . '<input type="time" id="ec_time_from" name="time_from" value="' . ec_h($ev['time_from']) . '"></p>';
    echo '<p><label for="ec_time_to">' . ec_h(ec_t('f_end_time')) . $opt . '</label>'
       . '<input type="time" id="ec_time_to" name="time_to" value="' . ec_h($ev['time_to']) . '"></p>';
    echo '</div>';

    // Location
    echo '<p><label for="ec_location">' . ec_h(ec_t('f_location')) . '</label>'
       . '<input type="text" id="ec_location" name="location" maxlength="200" value="' . ec_h($ev['location']) . '"></p>';

    // Description
    echo '<p><label for="ec_description">' . ec_h(ec_t('f_description')) . '</label>'
       . '<textarea id="ec_description" name="description" rows="7" maxlength="5000">' . ec_h($ev['description']) . '</textarea>'
       . '<small>' . ec_h(ec_t('hint_plain')) . '</small></p>';

    // Category
    echo '<p><label for="ec_category">' . ec_h(ec_t('f_category')) . '</label>'
       . '<select id="ec_category" name="category"><option value="">' . ec_h(ec_t('opt_no_category')) . '</option>';
    foreach ($categories as $c) {
        echo '<option value="' . ec_h($c['id']) . '"' . ($ev['category'] === $c['id'] ? ' selected' : '') . '>' . ec_h($c['name']) . '</option>';
    }
    echo '</select></p>';

    // Colour
    echo '<div class="ec-field"><label for="ec_color">' . ec_h(ec_t('f_color')) . '</label><div class="ec-colors">';
    echo '<input type="color" id="ec_color" name="color" value="' . ec_h($ev['color']) . '">';
    foreach ($presets as $c) {
        echo '<button type="button" class="ec-swatch" data-color="' . $c . '" style="background:' . $c . '" '
           . 'aria-label="' . ec_h(ec_t('use_color') . ' ' . $c) . '"></button>';
    }
    echo '</div></div>';

    // Image
    echo '<div class="ec-field"><label>' . ec_h(ec_t('f_image')) . '</label>';
    echo '<input type="hidden" id="ec_image" name="image" value="' . ec_h($ev['image']) . '">';
    echo '<div class="ec-img-pick">';
    echo '<div class="ec-img-preview" id="ec_img_preview">'
       . ($ev['image'] !== '' ? '<img src="' . ec_h(ec_image_url($ev['image'])) . '" alt="">' : '<span>' . ec_h(ec_t('no_image')) . '</span>') . '</div>';
    echo '<div><button type="button" class="ec-btn" id="ec_img_choose">' . ec_h(ec_t('btn_choose_image')) . '</button> '
       . '<button type="button" class="ec-btn" id="ec_img_clear"' . ($ev['image'] === '' ? ' hidden' : '') . '>' . ec_h(ec_t('btn_remove')) . '</button>'
       . '<p class="ec-help" id="ec_img_name">' . ec_h($ev['image']) . '</p></div>';
    echo '</div></div>';

    // Optional link + status
    echo '<p><label for="ec_url">' . ec_h(ec_t('f_link')) . $opt . '</label>'
       . '<input type="url" id="ec_url" name="url" placeholder="https://" value="' . ec_h($ev['url']) . '"></p>';
    echo '<p><label for="ec_status">' . ec_h(ec_t('f_status')) . '</label><select id="ec_status" name="status">'
       . '<option value="published"' . ($ev['status'] !== 'draft' ? ' selected' : '') . '>' . ec_h(ec_t('status_published')) . '</option>'
       . '<option value="draft"' . ($ev['status'] === 'draft' ? ' selected' : '') . '>' . ec_h(ec_t('status_draft_opt')) . '</option>'
       . '</select></p>';

    echo '<p class="ec-submit"><button type="submit" class="ec-btn ec-btn-primary">' . ec_h(ec_t('btn_save_event')) . '</button> '
       . '<a href="' . ec_admin_url('list') . '">' . ec_h(ec_t('cancel')) . '</a></p>';
    echo '</form>';

    echo '<script src="' . ec_h($SITEURL . 'plugins/' . EC_PLUGIN_ID . '/assets/admin.js?v=' . ec_asset_ver('admin.js')) . '"></script>';
}

/* ------------------------------------------------------------------ */

function ec_admin_categories($form_cat = null) {
    $cats   = ec_get_categories();
    $counts = ec_category_counts();
    $edit   = $form_cat;
    if ($edit === null && isset($_GET['cid'])) { $edit = ec_get_category((string)$_GET['cid']); }
    $settings = ec_get_settings();
    if ($edit === null) { $edit = array('id' => '', 'name' => '', 'color' => $settings['default_color']); }
    $editing = ($edit['id'] !== '' && ec_get_category($edit['id']) !== null);

    echo '<h3>' . ec_h(ec_t('h_categories')) . '</h3>';

    if (!$cats) {
        echo '<p>' . ec_h(ec_t('c_none')) . '</p>';
    } else {
        echo '<table class="ec-table"><thead><tr><th>' . ec_h(ec_t('c_name')) . '</th><th>Slug</th><th>'
           . ec_h(ec_t('c_events')) . '</th><th></th></tr></thead><tbody>';
        foreach ($cats as $c) {
            $n = isset($counts[$c['id']]) ? $counts[$c['id']] : 0;
            echo '<tr><td><span class="ec-dot" style="background:' . ec_h($c['color']) . '"></span>'
               . '<a href="' . ec_admin_url('categories', '&amp;cid=' . rawurlencode($c['id'])) . '">' . ec_h($c['name']) . '</a></td>';
            echo '<td><code>' . ec_h($c['id']) . '</code></td><td>' . (int)$n . '</td><td class="ec-actions">';
            echo '<a href="' . ec_admin_url('categories', '&amp;cid=' . rawurlencode($c['id'])) . '">' . ec_h(ec_t('edit')) . '</a> ';
            echo '<form method="post" action="' . ec_admin_url('delete_category') . '" class="ec-inline" '
               . 'onsubmit="return confirm(' . ec_h(json_encode(ec_t('confirm_delete_cat'))) . ');">';
            echo '<input type="hidden" name="nonce" value="' . ec_h(get_nonce('ec_delete_category', 'load.php')) . '">';
            echo '<input type="hidden" name="id" value="' . ec_h($c['id']) . '">';
            echo '<button type="submit" class="ec-link-danger">' . ec_h(ec_t('delete')) . '</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="ec-help">' . ec_h(ec_t('c_help')) . '.</p>';
    }

    echo '<h3 style="margin-top:28px">' . ec_h($editing ? ec_t('h_edit_category') : ec_t('h_add_category')) . '</h3>';
    echo '<form method="post" action="' . ec_admin_url('save_category') . '" class="ec-form ec-form-narrow">';
    echo '<input type="hidden" name="nonce" value="' . ec_h(get_nonce('ec_save_category', 'load.php')) . '">';
    echo '<input type="hidden" name="id" value="' . ec_h($editing ? $edit['id'] : '') . '">';
    echo '<p><label for="ec_cat_name">' . ec_h(ec_t('c_name')) . '</label>'
       . '<input type="text" id="ec_cat_name" name="name" maxlength="60" required value="' . ec_h($edit['name']) . '"></p>';
    echo '<p><label for="ec_cat_color">' . ec_h(ec_t('c_color')) . '</label>'
       . '<input type="color" id="ec_cat_color" name="color" value="' . ec_h($edit['color']) . '"></p>';
    echo '<p class="ec-submit"><button type="submit" class="ec-btn ec-btn-primary">' . ec_h(ec_t('btn_save_category')) . '</button>';
    if ($editing) { echo ' <a href="' . ec_admin_url('categories') . '">' . ec_h(ec_t('cancel')) . '</a>'; }
    echo '</p></form>';
}

/* ------------------------------------------------------------------ */

function ec_admin_settings() {
    $s = ec_get_settings();

    echo '<h3>' . ec_h(ec_t('h_settings')) . '</h3>';
    echo '<form method="post" action="' . ec_admin_url('save_settings') . '" class="ec-form ec-form-narrow">';
    echo '<input type="hidden" name="nonce" value="' . ec_h(get_nonce('ec_save_settings', 'load.php')) . '">';

    echo '<p><label for="ec_lang">' . ec_h(ec_t('s_language')) . '</label><select id="ec_lang" name="lang">';
    $cur = ($s['lang'] !== '') ? ec_find_lang($s['lang']) : '';
    echo '<option value=""' . ($cur === '' ? ' selected' : '') . '>' . ec_h(ec_t('s_language_auto')) . '</option>';
    foreach (ec_languages() as $code => $name) {
        echo '<option value="' . ec_h($code) . '"' . ($cur === $code ? ' selected' : '') . '>' . ec_h($name) . '</option>';
    }
    echo '</select><small>' . ec_h(ec_t('s_language_hint')) . '</small></p>';

    echo '<p><label for="ec_first_day">' . ec_h(ec_t('s_week_start')) . '</label><select id="ec_first_day" name="first_day">'
       . '<option value="1"' . ((int)$s['first_day'] === 1 ? ' selected' : '') . '>' . ec_h(ec_t('monday')) . '</option>'
       . '<option value="0"' . ((int)$s['first_day'] === 0 ? ' selected' : '') . '>' . ec_h(ec_t('sunday')) . '</option></select></p>';

    echo '<p><label for="ec_time_format">' . ec_h(ec_t('s_time_format')) . '</label><select id="ec_time_format" name="time_format">'
       . '<option value="12"' . ($s['time_format'] === '12' ? ' selected' : '') . '>' . ec_h(ec_t('t12')) . '</option>'
       . '<option value="24"' . ($s['time_format'] === '24' ? ' selected' : '') . '>' . ec_h(ec_t('t24')) . '</option></select></p>';

    echo '<p><label for="ec_mobile_mode">' . ec_h(ec_t('s_mobile')) . '</label><select id="ec_mobile_mode" name="mobile_mode">'
       . '<option value="list"' . ($s['mobile_mode'] === 'list' ? ' selected' : '') . '>' . ec_h(ec_t('m_list')) . '</option>'
       . '<option value="compact"' . ($s['mobile_mode'] === 'compact' ? ' selected' : '') . '>' . ec_h(ec_t('m_compact')) . '</option>'
       . '<option value="grid"' . ($s['mobile_mode'] === 'grid' ? ' selected' : '') . '>' . ec_h(ec_t('m_grid')) . '</option></select></p>';

    echo '<p><label for="ec_breakpoint">' . ec_h(ec_t('s_breakpoint')) . '</label>'
       . '<input type="number" id="ec_breakpoint" name="breakpoint" min="280" max="1400" step="10" value="' . (int)$s['breakpoint'] . '">'
       . '<small>' . ec_h(ec_t('h_breakpoint')) . '</small></p>';

    echo '<p><label for="ec_default_color">' . ec_h(ec_t('s_default_color')) . '</label>'
       . '<input type="color" id="ec_default_color" name="default_color" value="' . ec_h($s['default_color']) . '"></p>';

    echo '<p class="ec-submit"><button type="submit" class="ec-btn ec-btn-primary">' . ec_h(ec_t('btn_save_settings')) . '</button></p>';
    echo '</form>';
}


/* ------------------------------------------------------------------ */

function ec_admin_help() {
    echo '<h3>' . ec_h(ec_t('h_help')) . '</h3>';
    echo '<p>' . ec_h(ec_t('help_intro')) . '</p>';

    /* Views */
    $views = array(
        'calendar' => array('[events view="calendar"]',                  'v_calendar'),
        'compact'  => array('[events view="compact"]',                   'v_compact'),
        'mini'     => array('[events view="mini" limit="5"]',            'v_mini'),
        'list'     => array('[events view="list" limit="6"]',            'v_list'),
        'grid'     => array('[events view="grid" columns="3"]',          'v_grid'),
    );
    echo '<h4>' . ec_h(ec_t('help_h_views')) . '</h4>';
    echo '<table class="ec-table ec-help-table"><thead><tr><th>' . ec_h(ec_t('help_th_view')) . '</th><th>'
       . ec_h(ec_t('help_th_desc')) . '</th><th>' . ec_h(ec_t('help_h_examples')) . '</th></tr></thead><tbody>';
    foreach ($views as $name => $v) {
        echo '<tr><td><code>' . ec_h($name) . '</code></td><td>' . ec_h(ec_t($v[1])) . '</td><td><code>' . ec_h($v[0]) . '</code></td></tr>';
    }
    echo '</tbody></table>';

    /* Options */
    $all = ec_t('help_all');
    $set = ec_t('help_default_setting');
    $s   = ec_get_settings();
    $attrs = array(
        array('view',       'a_view',       'list'),
        array('limit',      'a_limit',      '0 (' . $all . ')'),
        array('columns',    'a_columns',    '3'),
        array('past',       'a_past',       '0'),
        array('from, to',   'a_range',      ''),
        array('order',      'a_order',      'asc'),
        array('excerpt',    'a_excerpt',    '110'),
        array('category',   'a_category',   ''),
        array('filter',     'a_filter',     '0'),
        array('mobile',     'a_mobile',     $s['mobile_mode'] . ' (' . $set . ')'),
        array('breakpoint', 'a_breakpoint', (int)$s['breakpoint'] . ' (' . $set . ')'),
    );
    echo '<h4>' . ec_h(ec_t('help_h_attrs')) . '</h4>';
    echo '<table class="ec-table ec-help-table"><thead><tr><th>' . ec_h(ec_t('help_th_attr')) . '</th><th>'
       . ec_h(ec_t('help_th_desc')) . '</th><th>' . ec_h(ec_t('help_th_default')) . '</th></tr></thead><tbody>';
    foreach ($attrs as $a) {
        echo '<tr><td><code>' . ec_h($a[0]) . '</code></td><td>' . ec_h(ec_t($a[1])) . '</td><td>' . ec_h($a[2]) . '</td></tr>';
    }
    echo '</tbody></table>';

    /* Examples */
    $examples = implode("\n", array(
        '[events view="calendar"]',
        '[events view="calendar" filter="1" mobile="compact" breakpoint="600"]',
        '[events view="calendar" category="music"]',
        '[events view="compact"]',
        '[events view="mini" limit="4" category="music,expo"]',
        '[events view="list" limit="6" excerpt="150"]',
        '[events view="grid" columns="3" order="desc" past="1"]',
        '[events view="grid" from="2026-11-01" to="2026-11-30"]',
    ));
    echo '<h4>' . ec_h(ec_t('help_h_examples')) . '</h4>';
    echo '<pre class="ec-code">' . ec_h($examples) . '</pre>';

    /* Template code */
    $tpl = implode("\n", array(
        "<?php get_events(array('view' => 'calendar')); ?>",
        "<?php get_events(array('view' => 'mini', 'limit' => 4)); ?>",
        "<?php get_events(array('view' => 'compact', 'category' => 'music')); ?>",
        "<?php get_events(array('view' => 'grid', 'columns' => 3, 'limit' => 6)); ?>",
        "<?php echo get_events(array('view' => 'list'), false); ?>",
    ));
    echo '<h4>' . ec_h(ec_t('help_h_template')) . '</h4>';
    echo '<pre class="ec-code">' . ec_h($tpl) . '</pre>';
    echo '<p class="ec-help">' . ec_h(ec_t('help_note_tpl')) . '</p>';
    echo '<p class="ec-help">' . ec_h(ec_t('help_note_multi')) . '</p>';
    echo '<p class="ec-help">' . ec_h(ec_t('help_note_nojs')) . '</p>';
}
