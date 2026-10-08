<?php
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/*
 * Admin pages. GetSimple calls ec_admin() for load.php?id=events_calendar&action=...
 * Actions: list (default), edit, settings   (GET)
 *          save, delete, save_settings      (POST, nonce protected)
 */

function ec_admin_url($action, $extra = '') {
    return 'load.php?id=' . EC_PLUGIN_ID . '&amp;action=' . $action . $extra;
}

function ec_admin() {
    $action     = isset($_GET['action']) ? (string)$_GET['action'] : 'list';
    $notice     = null;      // array(type, message)  type: updated | error
    $form_event = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nonce = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';

        if (!in_array($action, array('save', 'delete', 'save_settings'), true)
            || !check_nonce($nonce, 'ec_' . $action, 'load.php')) {
            $notice = array('error', 'Security check failed. Please reload the page and try again.');
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
                $notice = array('updated', 'Event saved.');
                $action = 'list';
            } else {
                $notice     = array('error', 'The event could not be saved. Check that data/other/ is writable.');
                $form_event = $ev;
                $action     = 'edit';
            }

        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
            $notice = ($id !== '' && ec_delete_event($id))
                ? array('updated', 'Event deleted.')
                : array('error', 'The event could not be deleted.');
            $action = 'list';

        } elseif ($action === 'save_settings') {
            $notice = ec_save_settings($_POST)
                ? array('updated', 'Settings saved.')
                : array('error', 'Settings could not be saved. Check that data/other/ is writable.');
            $action = 'settings';
        }
    }

    global $SITEURL;
    echo '<link rel="stylesheet" href="' . ec_h($SITEURL . 'plugins/' . EC_PLUGIN_ID . '/assets/admin.css?v=' . ec_asset_ver('admin.css')) . '">';
    echo '<div class="ec-admin">';
    if ($notice) {
        echo '<div class="' . $notice[0] . '" style="display:block;margin:0 0 15px"><p>' . ec_h($notice[1]) . '</p></div>';
    }

    switch ($action) {
        case 'edit':     ec_admin_form($form_event); break;
        case 'settings': ec_admin_settings();        break;
        default:         ec_admin_list();            break;
    }
    echo '</div>';
}

/* ------------------------------------------------------------------ */

function ec_admin_list() {
    $settings = ec_get_settings();
    $events   = ec_get_events();
    usort($events, function ($a, $b) { return strcmp($b['date_from'], $a['date_from']); });
    $today = date('Y-m-d');

    echo '<h3>Events</h3>';
    echo '<p><a class="ec-btn ec-btn-primary" href="' . ec_admin_url('edit') . '">Add event</a></p>';

    if (!$events) {
        echo '<p>No events yet.</p>';
        return;
    }

    echo '<table class="ec-table"><thead><tr><th>Title</th><th>When</th><th>Location</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($events as $ev) {
        $when = ec_event_when($ev, $settings);
        $end  = ($ev['date_to'] !== '') ? $ev['date_to'] : $ev['date_from'];
        $past = ($end < $today);

        echo '<tr' . ($past ? ' class="ec-past"' : '') . '>';
        echo '<td><span class="ec-dot" style="background:' . ec_h($ev['color']) . '"></span>'
           . '<a href="' . ec_admin_url('edit', '&amp;eid=' . rawurlencode($ev['id'])) . '">' . ec_h($ev['title']) . '</a></td>';
        echo '<td>' . ec_h($when['date']) . ($when['time'] !== '' ? '<br><small>' . ec_h($when['time']) . '</small>' : '') . '</td>';
        echo '<td>' . ec_h($ev['location']) . '</td>';
        echo '<td>' . ($ev['status'] === 'draft' ? 'Draft' : 'Published') . ($past ? ' <small>(past)</small>' : '') . '</td>';
        echo '<td class="ec-actions">';
        echo '<a href="' . ec_admin_url('edit', '&amp;eid=' . rawurlencode($ev['id'])) . '">Edit</a> ';
        echo '<form method="post" action="' . ec_admin_url('delete') . '" class="ec-inline" '
           . 'onsubmit="return confirm(\'Delete this event?\');">';
        echo '<input type="hidden" name="nonce" value="' . ec_h(get_nonce('ec_delete', 'load.php')) . '">';
        echo '<input type="hidden" name="id" value="' . ec_h($ev['id']) . '">';
        echo '<button type="submit" class="ec-link-danger">Delete</button></form>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<p class="ec-help">Show these on a page with <code>[events view="list"]</code> or '
       . '<code>[events view="grid" columns="3" limit="6"]</code>. In a theme use '
       . '<code>&lt;?php get_events(array(\'view\' =&gt; \'grid\')); ?&gt;</code>.</p>';
}

/* ------------------------------------------------------------------ */

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
            'url' => '', 'status' => 'published',
        );
    }

    $presets = array('#f51b50', '#6c6ce5', '#ffd15c', '#1da1f2', '#bd2f9e', '#22c55e', '#f97316', '#64748b');

    echo '<h3>' . ($is_new ? 'Add event' : 'Edit event') . '</h3>';

    echo '<form method="post" action="' . ec_admin_url('save') . '" class="ec-form" id="ec-form" '
       . 'data-ajax="' . ec_h('load.php?id=' . EC_PLUGIN_ID . '&action=ajax') . '" '
       . 'data-nonce="' . ec_h(get_nonce('ec_ajax', 'load.php')) . '" '
       . 'data-uploads="' . ec_h($SITEURL . 'data/uploads/') . '">';
    echo '<input type="hidden" name="nonce" value="' . ec_h(get_nonce('ec_save', 'load.php')) . '">';
    echo '<input type="hidden" name="id" value="' . ec_h($is_new ? '' : $ev['id']) . '">';

    // Title
    echo '<p><label for="ec_title">Title</label>'
       . '<input type="text" id="ec_title" name="title" maxlength="200" required value="' . ec_h($ev['title']) . '"></p>';

    // Dates
    echo '<div class="ec-row">';
    echo '<p><label for="ec_date_from">Start date</label>'
       . '<input type="date" id="ec_date_from" name="date_from" required value="' . ec_h($ev['date_from']) . '"></p>';
    echo '<p><label for="ec_date_to">End date <small>(optional)</small></label>'
       . '<input type="date" id="ec_date_to" name="date_to" value="' . ec_h($ev['date_to'] !== $ev['date_from'] ? $ev['date_to'] : '') . '"></p>';
    echo '</div>';

    // Times
    echo '<p class="ec-check"><label><input type="checkbox" id="ec_all_day" name="all_day" value="1"'
       . (!empty($ev['all_day']) ? ' checked' : '') . '> All-day event</label></p>';
    echo '<div class="ec-row ec-times">';
    echo '<p><label for="ec_time_from">Start time</label>'
       . '<input type="time" id="ec_time_from" name="time_from" value="' . ec_h($ev['time_from']) . '"></p>';
    echo '<p><label for="ec_time_to">End time <small>(optional)</small></label>'
       . '<input type="time" id="ec_time_to" name="time_to" value="' . ec_h($ev['time_to']) . '"></p>';
    echo '</div>';

    // Location
    echo '<p><label for="ec_location">Location</label>'
       . '<input type="text" id="ec_location" name="location" maxlength="200" value="' . ec_h($ev['location']) . '"></p>';

    // Description
    echo '<p><label for="ec_description">Description</label>'
       . '<textarea id="ec_description" name="description" rows="7" maxlength="5000">' . ec_h($ev['description']) . '</textarea>'
       . '<small>Plain text. Line breaks are kept.</small></p>';

    // Colour
    echo '<div class="ec-field"><label for="ec_color">Colour</label><div class="ec-colors">';
    echo '<input type="color" id="ec_color" name="color" value="' . ec_h($ev['color']) . '">';
    foreach ($presets as $c) {
        echo '<button type="button" class="ec-swatch" data-color="' . $c . '" style="background:' . $c . '" aria-label="Use colour ' . $c . '"></button>';
    }
    echo '</div></div>';

    // Image
    echo '<div class="ec-field"><label>Image</label>';
    echo '<input type="hidden" id="ec_image" name="image" value="' . ec_h($ev['image']) . '">';
    echo '<div class="ec-img-pick">';
    echo '<div class="ec-img-preview" id="ec_img_preview">'
       . ($ev['image'] !== '' ? '<img src="' . ec_h(ec_image_url($ev['image'])) . '" alt="">' : '<span>No image</span>') . '</div>';
    echo '<div><button type="button" class="ec-btn" id="ec_img_choose">Choose image</button> '
       . '<button type="button" class="ec-btn" id="ec_img_clear"' . ($ev['image'] === '' ? ' hidden' : '') . '>Remove</button>'
       . '<p class="ec-help" id="ec_img_name">' . ec_h($ev['image']) . '</p></div>';
    echo '</div></div>';

    // Optional link + status
    echo '<p><label for="ec_url">Link <small>(optional)</small></label>'
       . '<input type="url" id="ec_url" name="url" placeholder="https://" value="' . ec_h($ev['url']) . '"></p>';
    echo '<p><label for="ec_status">Status</label><select id="ec_status" name="status">'
       . '<option value="published"' . ($ev['status'] !== 'draft' ? ' selected' : '') . '>Published</option>'
       . '<option value="draft"' . ($ev['status'] === 'draft' ? ' selected' : '') . '>Draft (hidden on the site)</option>'
       . '</select></p>';

    echo '<p class="ec-submit"><button type="submit" class="ec-btn ec-btn-primary">Save event</button> '
       . '<a href="' . ec_admin_url('list') . '">Cancel</a></p>';
    echo '</form>';

    echo '<script src="' . ec_h($SITEURL . 'plugins/' . EC_PLUGIN_ID . '/assets/admin.js?v=' . ec_asset_ver('admin.js')) . '"></script>';
}

/* ------------------------------------------------------------------ */

function ec_admin_settings() {
    global $SITEURL;
    $s = ec_get_settings();

    echo '<h3>Settings</h3>';
    echo '<form method="post" action="' . ec_admin_url('save_settings') . '" class="ec-form">';
    echo '<input type="hidden" name="nonce" value="' . ec_h(get_nonce('ec_save_settings', 'load.php')) . '">';

    echo '<p><label for="ec_first_day">Week starts on</label><select id="ec_first_day" name="first_day">'
       . '<option value="1"' . ((int)$s['first_day'] === 1 ? ' selected' : '') . '>Monday</option>'
       . '<option value="0"' . ((int)$s['first_day'] === 0 ? ' selected' : '') . '>Sunday</option></select></p>';

    echo '<p><label for="ec_lang">Language for dates</label><select id="ec_lang" name="lang">'
       . '<option value="en"' . ($s['lang'] === 'en' ? ' selected' : '') . '>English</option>'
       . '<option value="es"' . ($s['lang'] === 'es' ? ' selected' : '') . '>Español</option></select></p>';

    echo '<p><label for="ec_time_format">Time format</label><select id="ec_time_format" name="time_format">'
       . '<option value="12"' . ($s['time_format'] === '12' ? ' selected' : '') . '>12-hour (09:00 am)</option>'
       . '<option value="24"' . ($s['time_format'] === '24' ? ' selected' : '') . '>24-hour (09:00)</option></select></p>';

    echo '<p><label for="ec_default_color">Default event colour</label>'
       . '<input type="color" id="ec_default_color" name="default_color" value="' . ec_h($s['default_color']) . '"></p>';

    echo '<p class="ec-submit"><button type="submit" class="ec-btn ec-btn-primary">Save settings</button></p>';
    echo '</form>';
}
