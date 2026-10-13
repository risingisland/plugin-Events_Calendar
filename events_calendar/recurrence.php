<?php
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/*
 * Repeating events.
 *
 * An event keeps ONE record. If it has a 'repeat' rule, its occurrences are worked out
 * when needed, inside a window of dates (see ec_window()). Rule format:
 *
 *   'repeat' => array(
 *	   'type'	 => 'weekly' | 'monthly',
 *	   'interval' => 1..12			every N weeks / every N months
 *	   'weekdays' => array(0..6)	  weekly: 0 = Sunday
 *	   'nth'	  => 1|2|3|4|-1	   monthly: the Nth (or last) ...
 *	   'weekday'  => 0..6			 monthly: ... weekday of the month
 *	   'end'	  => 'never' | 'until' | 'count'
 *	   'until'	=> 'YYYY-MM-DD'	 when end = until
 *	   'count'	=> 1..500		   when end = count
 *	   'skip'	 => array('YYYY-MM-DD', ...)   cancelled dates
 *   )
 *
 * An occurrence is a copy of the event with its own dates and the id "<event id>@<YYYY-MM-DD>".
 * Dates are handled as day numbers (days since 1970-01-01, GMT) so daylight saving never matters.
 */

define('EC_MAX_OCCURRENCES', 500);	 // per event, inside one window

function ec_day_num($ymd) {
	return (int)floor(gmmktime(0, 0, 0, (int)substr($ymd, 5, 2), (int)substr($ymd, 8, 2), (int)substr($ymd, 0, 4)) / 86400);
}
function ec_day_ymd($n) { return gmdate('Y-m-d', $n * 86400); }
function ec_day_wd($n)  { return (int)gmdate('w', $n * 86400); }

function ec_is_repeating($ev) {
	return !empty($ev['repeat']) && is_array($ev['repeat']) && !empty($ev['repeat']['type']);
}

/** Window of day numbers in which repeating events are expanded: about 6 months back, 18 ahead. */
function ec_window($from = '', $to = '') {
	$today = ec_day_num(date('Y-m-d'));
	$start = ec_valid_date($from) ? ec_day_num($from) : $today - 183;
	$end   = ec_valid_date($to)   ? ec_day_num($to)   : $today + 550;
	if ($end < $start) { $end = $start; }
	if ($end - $start > 1100) { $end = $start + 1100; }
	return array($start, $end);
}

/**
 * The occurrences of an event that touch the window $ws..$we (day numbers).
 * A normal event is returned as it is, as a one-item list; the caller filters it.
 */
function ec_expand_event($ev, $ws, $we) {
	if (!ec_is_repeating($ev)) { return array($ev); }

	$r		= $ev['repeat'];
	$start	= ec_day_num($ev['date_from']);
	$endDay   = ($ev['date_to'] !== '') ? ec_day_num($ev['date_to']) : $start;
	$dur	  = max(0, $endDay - $start);
	$interval = isset($r['interval']) ? max(1, min(12, (int)$r['interval'])) : 1;
	$end	  = isset($r['end']) ? $r['end'] : 'never';

	$limit	= $we;
	$countMax = PHP_INT_MAX;
	if ($end === 'until' && !empty($r['until']) && ec_valid_date($r['until'])) {
		$limit = min($limit, ec_day_num($r['until']));
	} elseif ($end === 'count' && !empty($r['count'])) {
		$countMax = max(1, min(EC_MAX_OCCURRENCES, (int)$r['count']));
	}

	$skip = array();
	if (!empty($r['skip']) && is_array($r['skip'])) {
		foreach ($r['skip'] as $d) { $skip[$d] = true; }
	}

	$settings = ec_get_settings();
	$firstDay = ((int)$settings['first_day'] === 0) ? 0 : 1;

	$n = 0; $emitted = 0; $out = array();
	// Called for every date the rule produces, in order. Returns false to stop.
	$accept = function ($d) use (&$n, &$emitted, &$out, $ev, $dur, $ws, $we, $skip, $countMax) {
		$n++;
		$ymd = ec_day_ymd($d);
		if (!isset($skip[$ymd]) && ($d + $dur) >= $ws && $d <= $we) {
			$occ			  = $ev;
			$occ['date_from'] = $ymd;
			$occ['date_to']   = ec_day_ymd($d + $dur);
			$occ['series']	= $ev['id'];
			$occ['id']		= $ev['id'] . '@' . $ymd;
			$out[] = $occ;
			$emitted++;
		}
		return !($n >= $countMax || $emitted >= EC_MAX_OCCURRENCES);
	};

	if ($r['type'] === 'weekly') {
		$wds = array();
		if (!empty($r['weekdays']) && is_array($r['weekdays'])) {
			foreach ($r['weekdays'] as $w) { $w = (int)$w; if ($w >= 0 && $w <= 6) { $wds[$w] = $w; } }
		}
		if (!$wds) { $wds[ec_day_wd($start)] = ec_day_wd($start); }
		// order the weekdays as they fall inside a week that starts on $firstDay
		$order = array();
		foreach ($wds as $w) { $order[(($w - $firstDay) + 7) % 7] = $w; }
		ksort($order);

		$week0 = $start - ((ec_day_wd($start) - $firstDay + 7) % 7);   // first day of the week that holds the start date
		for ($w = 0; $w < 6000; $w++) {
			$weekStart = $week0 + 7 * $interval * $w;
			if ($weekStart > $limit) { break; }
			foreach ($order as $offset => $wd) {
				$d = $weekStart + $offset;
				if ($d < $start) { continue; }
				if ($d > $limit) { break; }
				if (!$accept($d)) { break 2; }
			}
		}

	} elseif ($r['type'] === 'monthly') {
		$nth = isset($r['nth']) ? (int)$r['nth'] : 1;
		if (!in_array($nth, array(1, 2, 3, 4, -1), true)) { $nth = 1; }
		$wd  = isset($r['weekday']) ? max(0, min(6, (int)$r['weekday'])) : ec_day_wd($start);

		$baseIdx = (int)substr($ev['date_from'], 0, 4) * 12 + ((int)substr($ev['date_from'], 5, 2) - 1);
		for ($m = 0; $m < 2400; $m++) {
			$idx   = $baseIdx + $interval * $m;
			$y	 = intdiv($idx, 12);
			$mo	= ($idx % 12) + 1;
			$first = ec_day_num(sprintf('%04d-%02d-01', $y, $mo));
			if ($first > $limit) { break; }
			$dim = (int)gmdate('t', $first * 86400);
			if ($nth > 0) {
				$day = 1 + (($wd - ec_day_wd($first) + 7) % 7) + 7 * ($nth - 1);
			} else {
				$lastWd = ec_day_wd($first + $dim - 1);
				$day	= $dim - (($lastWd - $wd + 7) % 7);
			}
			if ($day < 1 || $day > $dim) { continue; }
			$d = $first + $day - 1;
			if ($d < $start) { continue; }
			if ($d > $limit) { break; }
			if (!$accept($d)) { break; }
		}
	}

	return $out;
}

function ec_ucfirst($s) {
	if ($s === '') { return $s; }
	return function_exists('mb_strtoupper')
		? mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8')
		: ucfirst($s);
}

/** Readable description of the rule, e.g. "Every Tuesday" or "2nd Tuesday of every month". */
function ec_repeat_text($ev, $lang = null) {
	if (!ec_is_repeating($ev)) { return ''; }
	$r		= $ev['repeat'];
	$interval = isset($r['interval']) ? max(1, (int)$r['interval']) : 1;
	$settings = ec_get_settings();
	$firstDay = ((int)$settings['first_day'] === 0) ? 0 : 1;

	if ($r['type'] === 'weekly') {
		$names = ec_t_list('days_repeat', $lang);
		if (!$names) { $names = ec_t_list('days', $lang); }
		$wds = array();
		foreach ((array)$r['weekdays'] as $w) { $wds[(((int)$w - $firstDay) + 7) % 7] = (int)$w; }
		ksort($wds);
		$list = array();
		foreach ($wds as $w) { if (isset($names[$w])) { $list[] = $names[$w]; } }
		$last = array_pop($list);
		$text = $list ? implode(', ', $list) . ' ' . ec_t('list_and', $lang) . ' ' . $last : (string)$last;   // "Mon, Wed and Fri"
		return ec_ucfirst(strtr(ec_t($interval > 1 ? 'rep_weekly_n_txt' : 'rep_weekly_txt', $lang),
					 array('{weekdays}' => $text, '{n}' => (string)$interval)));
	}

	$ord   = ec_t_list('ordinals', $lang);
	$days  = ec_t_list('days', $lang);
	$nth   = (int)$r['nth'];
	$oi	= ($nth > 0) ? $nth - 1 : 4;
	$wd	= (int)$r['weekday'];
	return ec_ucfirst(strtr(ec_t($interval > 1 ? 'rep_monthly_n_txt' : 'rep_monthly_txt', $lang), array(
		'{nth}'	 => isset($ord[$oi]) ? $ord[$oi] : '',
		'{weekday}' => isset($days[$wd]) ? $days[$wd] : '',
		'{n}'	   => (string)$interval,
	)));
}

/**
 * Read the repeat fields of the admin form. Returns the rule array, or array() for "does not repeat".
 * Problems are appended to $errors.
 */
function ec_sanitize_repeat($in, $ev, &$errors) {
	$type = isset($in['repeat_type']) ? (string)$in['repeat_type'] : '';
	if ($type !== 'weekly' && $type !== 'monthly') { return array(); }

	$startWd = ec_valid_date($ev['date_from']) ? ec_day_wd(ec_day_num($ev['date_from'])) : 1;
	$r = array('type' => $type);
	$r['interval'] = isset($in['repeat_interval']) ? max(1, min(12, (int)$in['repeat_interval'])) : 1;

	if ($type === 'weekly') {
		$wds = array();
		$posted = (isset($in['repeat_weekdays']) && is_array($in['repeat_weekdays'])) ? $in['repeat_weekdays'] : array();
		foreach ($posted as $w) { $w = (int)$w; if ($w >= 0 && $w <= 6) { $wds[$w] = $w; } }
		if (!$wds) { $wds[$startWd] = $startWd; }
		ksort($wds);
		$r['weekdays'] = array_values($wds);
	} else {
		$nth = isset($in['repeat_nth']) ? (int)$in['repeat_nth'] : 1;
		if (!in_array($nth, array(1, 2, 3, 4, -1), true)) { $nth = 1; }
		$r['nth']	 = $nth;
		$r['weekday'] = isset($in['repeat_weekday']) ? max(0, min(6, (int)$in['repeat_weekday'])) : $startWd;
	}

	$end = isset($in['repeat_end']) ? (string)$in['repeat_end'] : 'never';
	if ($end === 'until') {
		$u = isset($in['repeat_until']) ? trim((string)$in['repeat_until']) : '';
		if (!ec_valid_date($u) || (ec_valid_date($ev['date_from']) && $u < $ev['date_from'])) {
			$errors[] = ec_t('e_rep_until');
			$end = 'never';
		} else {
			$r['until'] = $u;
		}
	} elseif ($end === 'count') {
		$r['count'] = isset($in['repeat_count']) ? max(1, min(EC_MAX_OCCURRENCES, (int)$in['repeat_count'])) : 10;
	} else {
		$end = 'never';
	}
	$r['end'] = $end;

	$skip = array();
	if (!empty($in['repeat_skip'])) {
		foreach (preg_split('/[\s,;]+/', (string)$in['repeat_skip']) as $d) {
			if (ec_valid_date($d)) { $skip[$d] = $d; }
		}
		ksort($skip);
	}
	$r['skip'] = array_slice(array_values($skip), 0, 200);

	return $r;
}
