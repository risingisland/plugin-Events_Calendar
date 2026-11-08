<?php
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

function ec_admin_url($action, $extra = '') {
	return 'load.php?id=' . EC_PLUGIN_ID . '&amp;action=' . $action . $extra;
}

function ec_admin() {
	global $SITEURL;

	$action	 = isset($_GET['action']) ? (string)$_GET['action'] : 'list';
	$notice	 = null;	  // array(type, message)  type: updated | error
	$form_event = null;
	$form_cat   = null;

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$nonce   = isset($_POST['nonce']) ? (string)$_POST['nonce'] : '';
		$allowed = array('save', 'delete', 'save_settings', 'save_category', 'delete_category');

		if (!in_array($action, $allowed, true) || !check_nonce($nonce, 'ec_' . $action, 'load.php')) {
			$notice = array('error', ec_t('n_security'));
			$action = 'list';

		} elseif ($action === 'save') {
			$id	   = isset($_POST['id']) ? (string)$_POST['id'] : '';
			$existing = ($id !== '') ? ec_get_event($id) : null;
			list($ev, $errors) = ec_sanitize_event($_POST, $existing ? $existing['id'] : '');
			if ($errors) {
				$notice	 = array('error', implode(' ', $errors));
				$form_event = $ev;
				$action	 = 'edit';
			} elseif (ec_save_event($ev)) {
				$notice = array('updated', ec_t('n_saved'));
				$action = 'list';
			} else {
				$notice	 = array('error', ec_t('n_save_failed'));
				$form_event = $ev;
				$action	 = 'edit';
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
		case 'edit':	   ec_admin_form($form_event);		break;
		case 'settings':   ec_admin_settings();			   break;
		case 'categories': ec_admin_categories($form_cat);	break;
		case 'help':	   ec_admin_help();				   break;
		default:		   ec_admin_list();				   break;
	}
	echo '</div>';
}

/* ----- Tab: List ------------------------------------------------------------- */

function ec_admin_list() {
	$settings = ec_get_settings();
	$events   = ec_get_events();
	usort($events, function ($a, $b) { return strcmp($b['date_from'], $a['date_from']); });
	$today = date('Y-m-d');

	echo '<div class="ec-head"><div><h3><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.25em" height="1.25em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="currentColor" d="M19 4h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2m-1 15h-6v-6h6zm1-10H5V7h14z"/></svg> ' . ec_h(ec_t('h_events')) . ' </h3>'
	   . '<p class="ec-sub">' . ec_h(ec_t('plugin_desc')) . '</p></div>'
	   . '<a class="ec-btn ec-btn-primary" href="' . ec_admin_url('edit') . '"><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.25em" height="1.25em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="currentColor" d="M5 22h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2m11-6h-3v3h-2v-3H8v-2h3v-3h2v3h3zM5 7h14v2H5z"/></svg> ' . ec_h(ec_t('btn_add')) . '</a></div>';
	
	echo '<hr>';
	 
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
		$rt = ec_repeat_text($ev);
		echo '<td>' . ec_h($when['date']) . ($when['time'] !== '' ? '<br><small>' . ec_h($when['time']) . '</small>' : '')
		   . ($rt !== '' ? '<br><small class="ec-rep-note">&#8635; ' . ec_h($rt) . '</small>' : '') . '</td>';
		echo '<td>' . ec_h($ev['location']) . '</td>';
		echo '<td>' . ($cat['id'] !== '' ? ec_h($cat['name']) : '') . '</td>';
		echo '<td>' . ec_h($ev['status'] === 'draft' ? ec_t('status_draft') : ec_t('status_published'))
		   . ($past ? ' <small>(' . ec_h(ec_t('past')) . ')</small>' : '') . '</td>';
		echo '<td class="ec-actions">';
		echo '<a class="ec-btn ec-btn-edit ec-btn-sm" href="' . ec_admin_url('edit', '&amp;eid=' . rawurlencode($ev['id'])) . '"><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.25em" height="1.25em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="currentColor" d="M5 22h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2m4.799-2.013H8v-1.799l4.977-4.97l1.799 1.799zm5.824-5.817l-1.799-1.799L15.196 11l1.799 1.799zM5 7h14v2H5z"/></svg> ' . ec_h(ec_t('edit')) . '</a> ';
		echo '<form method="post" action="' . ec_admin_url('delete') . '" class="ec-inline" '
		   . 'onsubmit="return confirm(' . ec_h(json_encode(ec_t('confirm_delete'))) . ');">';
		echo '<input type="hidden" name="nonce" value="' . ec_h(get_nonce('ec_delete', 'load.php')) . '">';
		echo '<input type="hidden" name="id" value="' . ec_h($ev['id']) . '">';
		echo '<button type="submit" class="ec-btn ec-btn-danger ec-btn-sm"><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.25em" height="1.25em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="currentColor" d="M5 22h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2m10.707-4.707l-1.414 1.414L12 16.414l-2.293 2.293l-1.414-1.414L10.586 15l-2.293-2.293l1.414-1.414L12 13.586l2.293-2.293l1.414 1.414L13.414 15zM5 7h14v2H5z"/></svg> ' . ec_h(ec_t('delete')) . '</button></form>';
		echo '</td></tr>';
	}
	echo '</tbody></table>';

	echo '<p class="ec-help"><a href="' . ec_admin_url('help') . '"><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.25em" height="1.25em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="currentColor" d="M19 4h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2m-6 16h-2v-2h2zm0-4h-2v-5h2zm6-7H5V7h14z"/></svg> ' . ec_h(ec_t('h_help')) . '</a></p>';
	
	echo '
	<footer class="donate">
		<p>Support my plugin development: <a href="https://ko-fi.com/ericmontgomery" target="_blank"><svg xmlns="http://www.w3.org/2000/svg" width="1.5em" height="1.5em" viewBox="0 0 36 36"><path d="M0 0h36v36H0z" fill="none"/><ellipse cx="18" cy="30.54" fill="#3b88c3" rx="18" ry="5.36"/><path fill="#88c9f9" d="M33.812 28.538c0 1.616-2.5 2.587-14.482 2.587c-10.925 0-13.612-.971-13.612-2.587s5.683-2.926 13.612-2.926s14.482 1.31 14.482 2.926"/><path fill="#f4900c" d="M7 28.25c0-1 1-5 11-5c12 0 15 4 15 5s0 2-14 2c-12 0-12-1-12-2"/><circle cx="7" cy="6.25" r="5" fill="#ffcc4d"/><circle cx="7" cy="6.25" r="4" fill="#ffac33"/><path fill="#c1694f" d="M26.949 14.467a21 21 0 0 0-.524-3.459a22 22 0 0 0-1.001-3.158c-.377-.929-.674-1.46-.674-1.46l-2.583.333s.298.622.621 1.841c.131.495.265 1.09.386 1.784a26 26 0 0 1 .343 2.948q.036.555.053 1.158c.004.146.011.287.013.437c.007.469-.026.946-.084 1.422q-.081.644-.223 1.279a19 19 0 0 1-.479 1.77c-.149.46-.308.901-.468 1.316a35 35 0 0 1-.707 1.687c-.358.798-.622 1.33-.622 1.441c0 .351.25 1.007 2 1.444c2.736.684 3-.444 3-.444s.174-.528.376-1.567c.1-.513.206-1.147.303-1.915q.088-.689.158-1.513a44 44 0 0 0 .157-3.478c.002-.176.006-.346.006-.527c0-.459-.021-.903-.051-1.339"/><path fill="#d99e82" d="M24.324 21.362c-1.003-.175-1.643-.467-1.994-.686a35 35 0 0 1-.707 1.687c.655.356 1.487.64 2.389.796a9 9 0 0 0 1.523.136c.296 0 .574-.023.841-.057c.1-.513.206-1.147.303-1.915c-.318.113-1.107.257-2.355.039m.758-3.342c-.782-.077-1.383-.249-1.805-.43a19 19 0 0 1-.479 1.77a8.4 8.4 0 0 0 2.106.476q.462.045.908.045c.359 0 .7-.028 1.026-.071q.074-.857.118-1.863c-.45.098-1.08.152-1.874.073m.221-1.524c.588 0 1.165-.059 1.692-.163c.001-.176.005-.346.005-.527c0-.458-.021-.902-.051-1.339c-.378.099-.919.194-1.682.205c-.775-.003-1.32-.111-1.698-.219c.004.146.011.287.013.437c.007.469-.026.946-.084 1.422a9 9 0 0 0 1.76.184zm-.178-3.273a8.7 8.7 0 0 0 1.648-.418a22 22 0 0 0-.347-1.797a6.4 6.4 0 0 1-1.56.409a6.5 6.5 0 0 1-1.522.055q.109.838.173 1.823c.109.004.207.021.319.021c.42 0 .857-.031 1.289-.093m.909-3.658a22 22 0 0 0-.61-1.715a6.7 6.7 0 0 1-1.242.471a6.6 6.6 0 0 1-1.395.243c.131.495.265 1.09.386 1.784a9 9 0 0 0 1.497-.269a9 9 0 0 0 1.364-.514"/><path fill="#3e721d" d="M34.549 2.943c-.032-.042-3.202-4.283-7.313-2.423c-1.847.835-3.038 2.2-3.469 3.963c-.804-1.125-2.026-1.839-3.644-2.127c-4.421-.788-7.092 4.123-7.118 4.172a.174.174 0 0 0 .256.224c.02-.015 2.037-1.427 5.396-.828a97 97 0 0 1 5.864 1.217a.18.18 0 0 0 .172-.051c.022-.024 2.303-2.475 4.4-3.423c3.104-1.404 5.221-.472 5.24-.462a.176.176 0 0 0 .216-.262"/><path fill="#5c913b" d="M31.129 5.401c-3.134-1.655-5.57-.831-7.083.174c-1.813-.351-5.201-.357-8.072 3.324c-3.525 4.518-.433 10.152-.402 10.209c.031.056.09.089.153.089l.021-.001a.18.18 0 0 0 .147-.128c.011-.039 1.097-3.967 3.843-7.27c1.501-1.805 3.293-3.474 4.554-4.573c1.27.211 3.136.678 4.969 1.702c3.573 1.998 5.212 5.379 5.228 5.413c.032.066.101.112.177.099a.17.17 0 0 0 .151-.134c.014-.063 1.392-6.222-3.686-8.904"/></svg> RisingIsland</a></p>
	</footer>';
}

/* ----- Tab: Add / Edit ------------------------------------------------------------- */

function ec_admin_js_labels() {
	$keys = array(
		'img_library', 'img_root', 'img_new_folder', 'img_upload', 'img_drop', 'img_empty', 'img_use',
		'img_loading', 'img_uploading', 'img_uploaded', 'img_pick_files', 'img_prompt', 'img_up',
		'img_truncated', 'img_network', 'img_bad_response', 'cancel', 'close', 'no_image',
		'tb_styles', 'tb_title1', 'tb_title2', 'tb_title3', 'tb_title4', 'tb_title5', 'tb_title6',
		'tb_paragraph', 'tb_pre', 'tb_bold', 'tb_italic', 'tb_underline', 'tb_color',
		'tb_align_left', 'tb_align_center', 'tb_align_right', 'tb_list_ol', 'tb_list_ul',
		'tb_outdent', 'tb_indent', 'tb_clear'
	);
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

	$presets	= array('#f51b50', '#6c6ce5', '#ffd15c', '#1da1f2', '#bd2f9e', '#22c55e', '#f97316', '#64748b');
	$categories = ec_get_categories();
	$cat_colors = array();
	foreach ($categories as $c) { $cat_colors[$c['id']] = $c['color']; }

	echo '<div class="ec-head"><h3><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.25em" height="1.25em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="currentColor" d="M5 22h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2m4.799-2.013H8v-1.799l4.977-4.97l1.799 1.799zm5.824-5.817l-1.799-1.799L15.196 11l1.799 1.799zM5 7h14v2H5z"/></svg> ' . ec_h($is_new ? ec_t('h_add_event') : ec_t('h_edit_event')) . ' </h3></div>';
	
	echo '<hr>';

	echo '<form method="post" action="' . ec_admin_url('save') . '" class="ec-form" id="ec-form" '
	   . 'data-ajax="' . ec_h('load.php?id=' . EC_PLUGIN_ID . '&action=ajax') . '" '
	   . 'data-nonce="' . ec_h(get_nonce('ec_ajax', 'load.php')) . '" '
	   . 'data-uploads="' . ec_h($SITEURL . 'data/uploads/') . '" '
	   . 'data-new="' . ($is_new ? '1' : '0') . '" '
	   . 'data-hasrepeat="' . (ec_is_repeating($ev) ? '1' : '0') . '" '
	   . 'data-catcolors="' . ec_h(json_encode((object)$cat_colors)) . '" '
	   . 'data-labels="' . ec_h(json_encode(ec_admin_js_labels())) . '">';
	echo '<input type="hidden" name="nonce" value="' . ec_h(get_nonce('ec_save', 'load.php')) . '">';
	echo '<input type="hidden" name="id" value="' . ec_h($is_new ? '' : $ev['id']) . '">';

	$opt = ' <small>(' . ec_h(ec_t('optional')) . ')</small>';

	// Row: Title | Category
	echo '<div class="ec-row ec-cols">';
	echo '<p><label for="ec_title">' . ec_h(ec_t('f_title')) . '</label>'
	   . '<input type="text" id="ec_title" name="title" maxlength="200" required value="' . ec_h($ev['title']) . '"></p>';

	echo '<p><label for="ec_category">' . ec_h(ec_t('f_category')) . '</label>'
	   . '<select id="ec_category" name="category"><option value="">' . ec_h(ec_t('opt_no_category')) . '</option>';
	foreach ($categories as $c) {
		echo '<option value="' . ec_h($c['id']) . '"' . ($ev['category'] === $c['id'] ? ' selected' : '') . '>' . ec_h($c['name']) . '</option>';
	}
	echo '</select></p>';

	echo '</div>';

	// Status
	echo '<p><label for="ec_status">' . ec_h(ec_t('f_status')) . '</label><select id="ec_status" name="status">'
	   . '<option value="published"' . ($ev['status'] !== 'draft' ? ' selected' : '') . '>' . ec_h(ec_t('status_published')) . '</option>'
	   . '<option value="draft"' . ($ev['status'] === 'draft' ? ' selected' : '') . '>' . ec_h(ec_t('status_draft_opt')) . '</option>'
	   . '</select></p>';


	// Row: Colour | Image
	echo '<div class="ec-row ec-cols">';
	echo '<div class="ec-field"><label for="ec_color">' . ec_h(ec_t('f_color')) . '</label><div class="ec-colors">';
	echo '<input type="color" id="ec_color" name="color" value="' . ec_h($ev['color']) . '">';
	foreach ($presets as $c) {
		echo '<button type="button" class="ec-swatch" data-color="' . $c . '" style="background:' . $c . '" '
		   . 'aria-label="' . ec_h(ec_t('use_color') . ' ' . $c) . '"></button>';
	}
	echo '</div></div>';

	echo '<div class="ec-field"><label>' . ec_h(ec_t('f_image')) . '</label>';
	echo '<input type="hidden" id="ec_image" name="image" value="' . ec_h($ev['image']) . '">';
	echo '<div class="ec-img-pick">';
	echo '<div class="ec-img-preview" id="ec_img_preview">'
	   . ($ev['image'] !== '' ? '<img src="' . ec_h(ec_image_url($ev['image'])) . '" alt="">' : '<span>' . ec_h(ec_t('no_image')) . '</span>') . '</div>';
	echo '<div><button type="button" class="ec-btn" id="ec_img_choose">' . ec_h(ec_t('btn_choose_image')) . '</button> '
	   . '<button type="button" class="ec-btn" id="ec_img_clear"' . ($ev['image'] === '' ? ' hidden' : '') . '>' . ec_h(ec_t('btn_remove')) . '</button>'
	   . '<p class="ec-help" id="ec_img_name">' . ec_h($ev['image']) . '</p></div>';
	echo '</div></div>';

	echo '</div>';

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

	ec_admin_repeat_fields($ev);

	// Location
	echo '<p><label for="ec_location">' . ec_h(ec_t('f_location')) . '</label>'
	   . '<input type="text" id="ec_location" name="location" maxlength="200" value="' . ec_h($ev['location']) . '"></p>';

	// Description: rich text editor.
	$desc_html = ec_desc_html($ev['description']);
	echo '<div class="ec-field ec-desc"><label id="ec_desc_label">' . ec_h(ec_t('f_description')) . '</label>'
	   . '<input type="hidden" id="ec_description" name="description" value="' . ec_h($desc_html) . '">'
	   . '<div class="ec-editor-wrap"><div id="ec_desc_editor" data-tiny-editor data-autofocus="no" data-fontname="no" '
	   . 'role="textbox" aria-multiline="true" aria-labelledby="ec_desc_label">' . $desc_html . '</div></div>'
	   . '<small>' . ec_h(ec_t('hint_editor')) . '</small></div>';

	// Link (optional)
	echo '<p><label for="ec_url">' . ec_h(ec_t('f_link')) . $opt . '</label>'
	   . '<input type="url" id="ec_url" name="url" placeholder="https://" value="' . ec_h($ev['url']) . '"></p>';

	echo '<p class="ec-submit"><button type="submit" class="ec-btn ec-btn-save">' . ec_h(ec_t('btn_save_event')) . '</button> '
	   . '<a class="ec-btn" href="' . ec_admin_url('list') . '">' . ec_h(ec_t('cancel')) . '</a></p>';
	echo '</form>';

	echo '<script src="' . ec_h(ec_asset_url('vendor/tiny-editor.bundle.js')) . '"></script>';
	echo '<script src="' . ec_h($SITEURL . 'plugins/' . EC_PLUGIN_ID . '/assets/admin.js?v=' . ec_asset_ver('admin.js')) . '"></script>';
}

/* ----- Tab: Categories ------------------------------------------------------------- */

function ec_admin_categories($form_cat = null) {
	$cats   = ec_get_categories();
	$counts = ec_category_counts();
	$edit   = $form_cat;
	if ($edit === null && isset($_GET['cid'])) { $edit = ec_get_category((string)$_GET['cid']); }
	$settings = ec_get_settings();
	if ($edit === null) { $edit = array('id' => '', 'name' => '', 'color' => $settings['default_color']); }
	$editing = ($edit['id'] !== '' && ec_get_category($edit['id']) !== null);

	echo '<div class="ec-head"><h3><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.25em" height="1.25em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="currentColor" d="M19 4h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2m-4.588 15l-2.449-1.288L9.514 19l.468-2.728L8 14.342l2.738-.398l1.225-2.48l1.225 2.48l2.738.398l-1.981 1.931zM19 9H5V7h14z"/></svg> ' . ec_h(ec_t('h_categories')) . ' </h3></div>';

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
	echo '<p class="ec-submit"><button type="submit" class="ec-btn ec-btn-save">' . ec_h(ec_t('btn_save_category')) . '</button>';
	if ($editing) { echo ' <a href="' . ec_admin_url('categories') . '">' . ec_h(ec_t('cancel')) . '</a>'; }
	echo '</p></form>';
}

/* ----- Tab: Settings ------------------------------------------------------------- */

function ec_admin_settings() {
	$s = ec_get_settings();

	echo '<div class="ec-head"><h3><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.25em" height="1.25em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="currentColor" d="M19 4h-2V2h-2v2H9V2H7v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2M5 20V8h14V6v14z"/><path fill="currentColor" d="m15.67 12.36l-.42-.73a.64.64 0 0 0-.88-.23l-.34.2c-.29-.25-.62-.44-.97-.57v-.39c0-.35-.29-.64-.64-.64h-.84c-.35 0-.64.29-.64.64v.39c-.35.13-.68.32-.97.57l-.34-.2a.65.65 0 0 0-.88.23l-.42.73c-.18.31-.07.7.23.88l.33.19c-.04.19-.05.38-.05.57s.02.38.05.57l-.33.19c-.31.18-.41.57-.23.88l.42.73c.18.3.57.41.88.23l.34-.2c.29.25.62.44.97.57v.39c0 .35.29.64.64.64h.84c.35 0 .64-.29.64-.64v-.39c.35-.13.68-.32.97-.57l.34.2c.31.18.7.07.88-.23l.42-.73c.18-.31.07-.7-.23-.88l-.33-.19c.04-.19.05-.38.05-.57s-.02-.38-.05-.57l.33-.19c.31-.18.41-.57.23-.88M12 15.54c-.84 0-1.55-.71-1.55-1.55s.71-1.55 1.55-1.55s1.55.71 1.55 1.55s-.71 1.55-1.55 1.55"/></svg> ' . ec_h(ec_t('h_settings')) . ' </h3></div>';
	echo '<hr>';
	
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

	echo '<p class="ec-submit"><button type="submit" class="ec-btn ec-btn-save">' . ec_h(ec_t('btn_save_settings')) . '</button></p>';
	echo '</form>';
}


/* ----- Tab: Help ------------------------------------------------------------- */

function ec_admin_help() {
	echo '<div class="ec-head"><h3><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.25em" height="1.25em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="currentColor" d="M19 4h-2V2h-2v2H9V2H7v2H5c-1.103 0-2 .897-2 2v14c0 1.103.897 2 2 2h14c1.103 0 2-.897 2-2V6c0-1.103-.897-2-2-2m-6 16h-2v-2h2zm0-4h-2v-5h2zm6-7H5V7h14z"/></svg> ' . ec_h(ec_t('h_help')) . ' </h3></div>';
	echo '<hr>';
	echo '<p>' . ec_h(ec_t('help_intro')) . '</p>';

	/* Views */
	$views = array(
		'calendar' => array('[events view="calendar"]',				  'v_calendar'),
		'compact'  => array('[events view="compact"]',				   'v_compact'),
		'mini'	 => array('[events view="mini" limit="5"]',			'v_mini'),
		'list'	 => array('[events view="list" limit="6"]',			'v_list'),
		'grid'	 => array('[events view="grid" columns="3"]',		  'v_grid'),
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
		array('view',	   'a_view',	   'list'),
		array('limit',	  'a_limit',	  '0 (' . $all . ')'),
		array('columns',	'a_columns',	'3'),
		array('past',	   'a_past',	   '0'),
		array('from, to',   'a_range',	  ''),
		array('order',	  'a_order',	  'asc'),
		array('excerpt',	'a_excerpt',	'110'),
		array('category',   'a_category',   ''),
		array('filter',	 'a_filter',	 '0'),
		array('collapse',   'a_collapse',   '0'),
		array('mobile',	 'a_mobile',	 $s['mobile_mode'] . ' (' . $set . ')'),
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
		'[events view="mini" limit="3" collapse="1"]',
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
	echo '<p class="ec-help">' . ec_h(ec_t('help_note_repeat')) . '</p>';
	echo '<p class="ec-help">' . ec_h(ec_t('help_note_nojs')) . '</p>';
}


/* ------------------------------------------------------------------ */

/** The "Repeat" part of the event form. */
function ec_admin_repeat_fields($ev) {
	$settings = ec_get_settings();
	$firstDay = ((int)$settings['first_day'] === 0) ? 0 : 1;
	$r		= (isset($ev['repeat']) && is_array($ev['repeat'])) ? $ev['repeat'] : array();
	$type	 = isset($r['type']) ? $r['type'] : '';
	$interval = isset($r['interval']) ? (int)$r['interval'] : 1;
	$end	  = isset($r['end']) ? $r['end'] : 'never';
	$days	 = ec_t_list('days');
	$ords	 = ec_t_list('ordinals');
	$wds	  = (isset($r['weekdays']) && is_array($r['weekdays'])) ? $r['weekdays'] : array();
	$nth	  = isset($r['nth']) ? (int)$r['nth'] : 1;
	$mwd	  = isset($r['weekday']) ? (int)$r['weekday'] : 1;
	$skip	 = (isset($r['skip']) && is_array($r['skip'])) ? implode("\n", $r['skip']) : '';
	$hid	  = function ($cond) { return $cond ? '' : ' hidden'; };

	echo '<div class="ec-field ec-repeat">';
	echo '<label for="ec_repeat_type">' . ec_h(ec_t('f_repeat')) . '</label>';
	echo '<select id="ec_repeat_type" name="repeat_type">'
	   . '<option value=""' . ($type === '' ? ' selected' : '') . '>' . ec_h(ec_t('rep_none')) . '</option>'
	   . '<option value="weekly"' . ($type === 'weekly' ? ' selected' : '') . '>' . ec_h(ec_t('rep_weekly_opt')) . '</option>'
	   . '<option value="monthly"' . ($type === 'monthly' ? ' selected' : '') . '>' . ec_h(ec_t('rep_monthly_opt')) . '</option>'
	   . '</select>';

	echo '<div class="ec-rep-all"' . $hid($type !== '') . '>';

	// every N weeks / months
	echo '<p class="ec-rep-line">' . ec_h(ec_t('rep_every')) . ' '
	   . '<input type="number" id="ec_repeat_interval" name="repeat_interval" min="1" max="12" value="' . max(1, $interval) . '"> '
	   . '<span id="ec_rep_unit" data-weeks="' . ec_h(ec_t('rep_weeks')) . '" data-months="' . ec_h(ec_t('rep_months')) . '">'
	   . ec_h($type === 'monthly' ? ec_t('rep_months') : ec_t('rep_weeks')) . '</span></p>';

	// weekly: weekday checkboxes
	echo '<div class="ec-rep-weekly ec-rep-line"' . $hid($type === 'weekly') . '><span class="ec-rep-label">' . ec_h(ec_t('rep_on')) . '</span> ';
	for ($i = 0; $i < 7; $i++) {
		$w = ($firstDay + $i) % 7;
		$label = isset($days[$w]) ? ec_ucfirst(ec_substr($days[$w], 0, 3)) : (string)$w;
		echo '<label class="ec-wd"><input type="checkbox" name="repeat_weekdays[]" value="' . $w . '"'
		   . (in_array($w, $wds, true) ? ' checked' : '') . '> ' . ec_h($label) . '</label> ';
	}
	echo '</div>';

	// monthly: the Nth weekday
	echo '<div class="ec-rep-monthly ec-rep-line"' . $hid($type === 'monthly') . '><span class="ec-rep-label">' . ec_h(ec_t('rep_on_the')) . '</span> ';
	echo '<select id="ec_repeat_nth" name="repeat_nth">';
	foreach (array(1, 2, 3, 4, -1) as $k => $v) {
		echo '<option value="' . $v . '"' . ($nth === $v ? ' selected' : '') . '>' . ec_h(isset($ords[$k]) ? $ords[$k] : $v) . '</option>';
	}
	echo '</select> <select id="ec_repeat_weekday" name="repeat_weekday">';
	for ($i = 0; $i < 7; $i++) {
		$w = ($firstDay + $i) % 7;
		echo '<option value="' . $w . '"' . ($mwd === $w ? ' selected' : '') . '>' . ec_h(isset($days[$w]) ? ec_ucfirst($days[$w]) : $w) . '</option>';
	}
	echo '</select></div>';

	// end
	echo '<p class="ec-rep-line"><label for="ec_repeat_end" class="ec-rep-label">' . ec_h(ec_t('rep_end')) . '</label> '
	   . '<select id="ec_repeat_end" name="repeat_end">'
	   . '<option value="never"' . ($end === 'never' ? ' selected' : '') . '>' . ec_h(ec_t('rep_end_never')) . '</option>'
	   . '<option value="until"' . ($end === 'until' ? ' selected' : '') . '>' . ec_h(ec_t('rep_end_until')) . '</option>'
	   . '<option value="count"' . ($end === 'count' ? ' selected' : '') . '>' . ec_h(ec_t('rep_end_count')) . '</option>'
	   . '</select> '
	   . '<input type="date" id="ec_repeat_until" name="repeat_until" value="' . ec_h(isset($r['until']) ? $r['until'] : '') . '"' . $hid($end === 'until') . '> '
	   . '<span class="ec-rep-countwrap"' . $hid($end === 'count') . '><input type="number" id="ec_repeat_count" name="repeat_count" min="1" max="'
	   . EC_MAX_OCCURRENCES . '" value="' . (isset($r['count']) ? (int)$r['count'] : 10) . '"> ' . ec_h(ec_t('rep_times')) . '</span></p>';

	// skipped dates
	echo '<p><label for="ec_repeat_skip">' . ec_h(ec_t('rep_skip')) . '</label>'
	   . '<textarea id="ec_repeat_skip" name="repeat_skip" rows="3" placeholder="2026-12-25">' . ec_h($skip) . '</textarea>'
	   . '<br><small>' . ec_h(ec_t('rep_skip_hint')) . '</small></p>';
	echo '<p class="ec-help">' . ec_h(ec_t('rep_window_hint')) . '</p>';

	echo '</div></div>';
}
