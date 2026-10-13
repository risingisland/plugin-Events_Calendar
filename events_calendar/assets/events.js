/*
 * Events Calendar - front end
 *  - shared event modal (used by every view)
 *  - month calendar (EventCalendar library, vendored in assets/vendor/)
 *  - compact dots calendar (no library, for sidebars)
 *  - clickable list/grid cards
 * All event text is inserted with textContent, never as HTML.
 */
(function () {
  'use strict';

  var dataEl = document.getElementById('evc-data');
  if (!dataEl) { return; }

  var data;
  try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }

  // Repeating events arrive as one full record (series) plus a small entry per date.
  var SERIES = data.series || {};
  var EVENTS = (data.events || []).map(function (e) {
    if (!e.series || !SERIES[e.series]) { return e; }
    var full = {}, k;
    for (k in SERIES[e.series]) { if (Object.prototype.hasOwnProperty.call(SERIES[e.series], k)) { full[k] = SERIES[e.series][k]; } }
    for (k in e) { if (Object.prototype.hasOwnProperty.call(e, k)) { full[k] = e[k]; } }
    return full;
  });
  var S = data.settings || {};
  var L = S.labels || {};
  var LOCALE = S.locale || 'en';
  var FIRST_DAY = (S.firstDay === 0) ? 0 : 1;
  var CATEGORIES = S.categories || [];

  EVENTS.sort(function (a, b) {
    return a.start < b.start ? -1 : a.start > b.start ? 1 : a.title.localeCompare(b.title);
  });

  var BY_ID = {};
  EVENTS.forEach(function (e) { BY_ID[e.id] = e; });

  /* ------------------------------------------------------------------
   * Helpers
   * ---------------------------------------------------------------- */
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12, 0, 0); }  // noon: safe across DST changes
  function addDays(d, n) { var r = new Date(d.getTime()); r.setDate(r.getDate() + n); return r; }
  function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmt(d, opts) {
    try { return new Intl.DateTimeFormat(LOCALE, opts).format(d); } catch (e) { return ''; }
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }
  function each(list, fn) { Array.prototype.forEach.call(list, fn); }

  var ICONS = {
    calendar: '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    pin: '<path d="M12 21s-7-6.2-7-11a7 7 0 1 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    repeat: '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/>'
  };
  function icon(name) {
    var w = document.createElement('span');
    w.innerHTML = '<svg class="evc-ic" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" ' +
      'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
      ICONS[name] + '</svg>';
    return w.firstChild;
  }

  /**
   * Second line of defence for the description: the server has already cleaned it, this keeps
   * only the same short list of tags in the browser. A template element is inert, so nothing
   * in the text runs while it is being cleaned.
   */
  var SAFE_TAGS = { P: 1, DIV: 1, BR: 1, B: 1, STRONG: 1, I: 1, EM: 1, U: 1, UL: 1, OL: 1, LI: 1, H3: 1, H4: 1, BLOCKQUOTE: 1, A: 1 };
  var DROP_TAGS = /^(SCRIPT|STYLE|IFRAME|FRAME|FRAMESET|OBJECT|EMBED|APPLET|FORM|INPUT|BUTTON|TEXTAREA|SELECT|OPTION|SVG|MATH|LINK|META|BASE|TITLE|NOSCRIPT|TEMPLATE|IMG|VIDEO|AUDIO|SOURCE|CANVAS|MAP|AREA)$/;
  function safeHtml(html) {
    var t = document.createElement('template');
    t.innerHTML = html;
    (function clean(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (c) {
        if (c.nodeType === 3) { return; }
        if (c.nodeType !== 1) { node.removeChild(c); return; }
        if (DROP_TAGS.test(c.tagName)) { node.removeChild(c); return; }
        clean(c);
        var okLink = c.tagName === 'A' && /^(https?:|mailto:|tel:)/i.test((c.getAttribute('href') || '').replace(/[\s\u0000-\u001f]+/g, ''));
        if (!SAFE_TAGS[c.tagName] || (c.tagName === 'A' && !okLink)) {
          while (c.firstChild) { node.insertBefore(c.firstChild, c); }
          node.removeChild(c);
          return;
        }
        var style = c.getAttribute('style') || '';
        var href = c.getAttribute('href');
        Array.prototype.slice.call(c.attributes).forEach(function (a) { c.removeAttribute(a.name); });
        var m = /text-align\s*:\s*(left|right|center|justify)/i.exec(style);
        if (m && c.tagName !== 'A') { c.style.textAlign = m[1].toLowerCase(); }
        if (c.tagName === 'A') {
          c.setAttribute('href', href);
          c.setAttribute('rel', 'noopener noreferrer');
          c.setAttribute('target', '_blank');
        }
      });
    })(t.content);
    return t.innerHTML;
  }

  /** Readable text colour (dark or white) for a given #rrggbb background. */
  function textOn(hex) {
    var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '');
    if (!m) { return '#fff'; }
    var lum = (0.299 * parseInt(m[1], 16) + 0.587 * parseInt(m[2], 16) + 0.114 * parseInt(m[3], 16)) / 255;
    return lum > 0.62 ? '#2b2840' : '#fff';
  }

  /** Map of YYYY-MM-DD -> events (from `list`) that cover that day. */
  function dayMap(list) {
    var map = {};
    list.forEach(function (e) {
      var d = parse(e.start), end = parse(e.end), guard = 0;
      while (d <= end && guard++ < 400) {
        var k = ymd(d);
        (map[k] = map[k] || []).push(e);
        d = addDays(d, 1);
      }
    });
    return map;
  }

  /** Events a container may show: its data-evc-cat categories, plus an optional chosen category. */
  function eventsFor(root, chosen) {
    var attr = root.getAttribute('data-evc-cat');
    var allowed = null;
    if (attr) {
      allowed = {};
      attr.split(',').forEach(function (c) { c = c.replace(/^\s+|\s+$/g, ''); if (c) { allowed[c] = true; } });
    }
    return EVENTS.filter(function (e) {
      if (allowed && !allowed[e.category]) { return false; }
      if (chosen && e.category !== chosen) { return false; }
      return true;
    });
  }

  function activate(node, fn) {
    node.setAttribute('role', 'button');
    node.tabIndex = 0;
    node.setAttribute('aria-haspopup', 'dialog');
    node.addEventListener('click', fn);
    node.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ' || e.keyCode === 13 || e.keyCode === 32) {
        e.preventDefault();
        fn(e);
      }
    });
  }

  /* ------------------------------------------------------------------
   * Modal (one instance, built on first use, appended to <body>)
   * ---------------------------------------------------------------- */
  var Modal = (function () {
    var overlay, dialog, body, closeBtn, opener = null, prevOverflow = '';

    function build() {
      overlay = el('div', 'evc-overlay');
      overlay.hidden = true;
      dialog = el('div', 'evc-modal');
      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.tabIndex = -1;
      closeBtn = el('button', 'evc-close');
      closeBtn.type = 'button';
      closeBtn.setAttribute('aria-label', L.close || 'Close');
      closeBtn.innerHTML = '&times;';
      body = el('div', 'evc-modal-content');
      dialog.appendChild(closeBtn);
      dialog.appendChild(body);
      overlay.appendChild(dialog);
      document.body.appendChild(overlay);

      closeBtn.addEventListener('click', close);
      overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { close(); } });
      overlay.addEventListener('keydown', onKey);
    }

    function onKey(e) {
      if (e.key === 'Escape' || e.keyCode === 27) { e.stopPropagation(); close(); return; }
      if (e.key !== 'Tab' && e.keyCode !== 9) { return; }
      // keep focus inside the dialog
      var f = dialog.querySelectorAll('button, a[href], [tabindex="0"]');
      if (!f.length) { e.preventDefault(); dialog.focus(); return; }
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
        e.preventDefault(); last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault(); first.focus();
      }
    }

    function show(node, trigger) {
      if (!overlay) { build(); }
      body.textContent = '';
      body.appendChild(node);
      if (overlay.hidden) {
        opener = trigger || null;
        overlay.hidden = false;
        prevOverflow = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';
      }
      dialog.scrollTop = 0;
      dialog.focus();
    }

    function close() {
      if (!overlay || overlay.hidden) { return; }
      overlay.hidden = true;
      document.documentElement.style.overflow = prevOverflow;
      if (opener && opener.focus) { try { opener.focus(); } catch (e) {} }
      opener = null;
    }

    function meta(parent, name, text) {
      if (!text) { return; }
      var row = el('div', 'evc-m-meta');
      row.appendChild(icon(name));
      row.appendChild(el('span', null, text));
      parent.appendChild(row);
    }

    function eventNode(ev, backFn) {
      var wrap = el('div', 'evc-m-event');
      wrap.style.setProperty('--evc-color', ev.color);

      var media;
      if (ev.image) {
        media = el('div', 'evc-m-img');
        var img = document.createElement('img');
        img.src = ev.image;
        img.alt = '';
        media.appendChild(img);
      } else {
        media = el('div', 'evc-m-img evc-noimg');
        media.setAttribute('aria-hidden', 'true');
        media.appendChild(icon('calendar'));
      }
      wrap.appendChild(media);

      var b = el('div', 'evc-m-body');
      if (backFn) {
        var back = el('button', 'evc-m-back', '\u2190 ' + (L.back || 'Back'));
        back.type = 'button';
        back.addEventListener('click', backFn);
        b.appendChild(back);
      }
      if (ev.categoryName) {
        var tag = el('span', 'evc-tag', ev.categoryName);
        if (ev.categoryColor) { tag.style.setProperty('--evc-tag', ev.categoryColor); }
        b.appendChild(tag);
      }
      var h = el('h3', 'evc-m-title', ev.title);
      h.id = 'evc-m-title';
      dialog.setAttribute('aria-labelledby', 'evc-m-title');
      b.appendChild(h);

      meta(b, 'calendar', ev.dateText);
      meta(b, 'clock', ev.timeText);
      meta(b, 'repeat', ev.repeatText);
      meta(b, 'pin', ev.location);

      if (ev.description) {
        var desc = el('div', 'evc-m-desc');
        desc.innerHTML = safeHtml(ev.description);
        b.appendChild(desc);
      }

      if (ev.url && /^https?:\/\//i.test(ev.url)) {
        var a = el('a', 'evc-m-link', L.moreInfo || 'More information');
        a.href = ev.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        b.appendChild(a);
      }
      wrap.appendChild(b);
      return wrap;
    }

    function dayNode(dateStr, evs) {
      var wrap = el('div', 'evc-m-day');
      var b = el('div', 'evc-m-body');
      var title = fmt(parse(dateStr), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
      var h = el('h3', 'evc-m-title evc-m-daytitle', cap(title));
      h.id = 'evc-m-title';
      dialog.setAttribute('aria-labelledby', 'evc-m-title');
      b.appendChild(h);

      var list = el('ul', 'evc-m-list');
      evs.forEach(function (ev) {
        var li = document.createElement('li');
        var btn = el('button', 'evc-m-item');
        btn.type = 'button';
        var dot = el('span', 'evc-m-dot');
        dot.style.background = ev.color;
        btn.appendChild(dot);
        var txt = el('span', 'evc-m-itemtext');
        txt.appendChild(el('strong', null, ev.title));
        if (ev.timeText) { txt.appendChild(el('small', null, ev.timeText)); }
        btn.appendChild(txt);
        btn.addEventListener('click', function () {
          show(eventNode(ev, function () { show(dayNode(dateStr, evs)); }));
        });
        li.appendChild(btn);
        list.appendChild(li);
      });
      b.appendChild(list);
      wrap.appendChild(b);
      return wrap;
    }

    return {
      event: function (ev, trigger) { if (!overlay) { build(); } show(eventNode(ev, null), trigger); },
      day: function (dateStr, evs, trigger) { if (!overlay) { build(); } show(dayNode(dateStr, evs), trigger); },
      close: close
    };
  })();

  /* ------------------------------------------------------------------
   * List / grid cards
   * ---------------------------------------------------------------- */
  function initCards() {
    each(document.querySelectorAll('.evc-card[data-evc-id], .evc-mini-item[data-evc-id]'), function (card) {
      if (card.__evcInit) { return; }
      var ev = BY_ID[card.getAttribute('data-evc-id')];
      if (!ev) { return; }
      card.__evcInit = true;
      activate(card, function () { Modal.event(ev, card); });
    });
  }

  /* ------------------------------------------------------------------
   * Compact dots calendar (vanilla, built for narrow containers)
   * ---------------------------------------------------------------- */
  function createCompact(mount, list) {
    var map = dayMap(list);
    var now = new Date();
    var cur = new Date(now.getFullYear(), now.getMonth(), 1, 12);

    var head = el('div', 'evc-c-head');
    var titleEl = el('div', 'evc-c-title');
    titleEl.setAttribute('aria-live', 'polite');

    function navBtn(txt, label, months) {
      var b = el('button', 'evc-c-btn', txt);
      b.type = 'button';
      b.setAttribute('aria-label', label || '');
      b.title = label || '';
      b.addEventListener('click', function () {
        cur = new Date(cur.getFullYear(), cur.getMonth() + months, 1, 12);
        render();
      });
      return b;
    }
    head.appendChild(navBtn('\u00AB', L.prevYear, -12));
    head.appendChild(navBtn('\u2039', L.prev, -1));
    head.appendChild(titleEl);
    head.appendChild(navBtn('\u203A', L.next, 1));
    head.appendChild(navBtn('\u00BB', L.nextYear, 12));

    var table = el('table', 'evc-c-table');
    mount.appendChild(head);
    mount.appendChild(table);

    function render() {
      titleEl.textContent = '';
      titleEl.appendChild(document.createTextNode(cap(fmt(cur, { month: 'long' })) + ' '));
      titleEl.appendChild(el('span', 'evc-year', String(cur.getFullYear())));

      table.textContent = '';
      var thead = document.createElement('thead');
      var hr = document.createElement('tr');
      for (var i = 0; i < 7; i++) {
        var idx = (FIRST_DAY + i) % 7;
        var ref = new Date(2024, 0, 7 + idx, 12);   // 7 Jan 2024 was a Sunday
        var th = el('th', null, cap(fmt(ref, { weekday: 'short' })));
        th.scope = 'col';
        th.title = cap(fmt(ref, { weekday: 'long' }));
        hr.appendChild(th);
      }
      thead.appendChild(hr);
      table.appendChild(thead);

      var y = cur.getFullYear(), m = cur.getMonth();
      var offset = (new Date(y, m, 1, 12).getDay() - FIRST_DAY + 7) % 7;
      var dim = new Date(y, m + 1, 0).getDate();
      var rows = Math.ceil((offset + dim) / 7);
      var todayKey = ymd(new Date());
      var tbody = document.createElement('tbody');

      for (var r = 0; r < rows; r++) {
        var tr = document.createElement('tr');
        for (var c = 0; c < 7; c++) {
          var day = r * 7 + c - offset + 1;
          var td = document.createElement('td');
          if (day < 1 || day > dim) {
            td.className = 'evc-c-empty';
          } else {
            td.appendChild(dayCell(new Date(y, m, day, 12), todayKey));
          }
          tr.appendChild(td);
        }
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
    }

    function dayCell(date, todayKey) {
      var key = ymd(date);
      var evs = map[key] || [];
      var wd = date.getDay();
      var node;
      var label = fmt(date, { day: 'numeric', month: 'long', year: 'numeric' });

      if (evs.length) {
        var titles = evs.map(function (e) { return e.title; });
        node = el('button', 'evc-c-day evc-has');
        node.type = 'button';
        node.title = titles.join('\n');
        node.setAttribute('aria-label', label + ': ' + titles.join(', '));
        node.addEventListener('click', function () {
          if (evs.length === 1) { Modal.event(evs[0], node); } else { Modal.day(key, evs, node); }
        });
      } else {
        node = el('span', 'evc-c-day');
      }
      if (key === todayKey) { node.className += ' evc-today'; }
      if (wd === 0 || wd === 6) { node.className += ' evc-we'; }

      node.appendChild(el('span', 'evc-c-num', String(date.getDate())));
      var dots = el('span', 'evc-dots');
      evs.slice(0, 3).forEach(function (e) {
        var d = el('span', 'evc-dot');
        d.style.background = e.color;
        dots.appendChild(d);
      });
      node.appendChild(dots);
      return node;
    }

    render();
    return { setList: function (l) { map = dayMap(l); render(); } };
  }

  function initCompact(root) {
    if (root.__evcInit) { return; }
    root.__evcInit = true;
    var mount = root.querySelector('.evc-mount');
    if (mount) { createCompact(mount, eventsFor(root, null)); }
  }

  /* ------------------------------------------------------------------
   * Month calendar (EventCalendar library)
   * ---------------------------------------------------------------- */
  function initCalendar(root) {
    if (root.__evcInit) { return; }
    root.__evcInit = true;
    var mount = root.querySelector('.evc-mount');
    if (!mount) { return; }

    var lib = window.EventCalendar;
    if (!lib || typeof lib.create !== 'function') {
      // Library missing: show the same plain list that no-JS visitors get
      var ns = root.querySelector('noscript');
      if (ns) { mount.innerHTML = ns.textContent; initCards(); }
      return;
    }

    // In a narrow area (below the breakpoint) the calendar becomes a month list or the compact dots calendar
    var mobileAttr = root.getAttribute('data-evc-mobile');
    var mobileMode = (mobileAttr === 'grid' || mobileAttr === 'compact') ? mobileAttr : 'list';
    var breakpoint = parseInt(root.getAttribute('data-evc-breakpoint'), 10) || 560;
    function wantedMode() {
      var w = root.clientWidth || window.innerWidth;
      if (w >= breakpoint || mobileMode === 'grid') { return 'grid'; }
      return mobileMode === 'compact' ? 'compact' : 'list';
    }
    function viewFor(mode) { return mode === 'list' ? 'listMonth' : 'dayGridMonth'; }

    // The calendar shows every event as an all-day bar. EventCalendar treats `end` as exclusive.
    function libEventsFor(chosen) {
      return eventsFor(root, chosen).map(function (e) {
        return {
          id: e.id,
          start: e.start,
          end: ymd(addDays(parse(e.end), 1)),
          allDay: true,
          title: e.title,
          backgroundColor: e.color,
          textColor: textOn(e.color)
        };
      });
    }

    var ec;
    function shiftYear(n) {
      var d = new Date(ec.getOption('date'));
      d.setDate(1);
      d.setFullYear(d.getFullYear() + n);
      ec.setOption('date', d);
    }

    try {
      ec = lib.create(mount, {
        view: viewFor(wantedMode()),
        firstDay: FIRST_DAY,
        locale: LOCALE,
        height: 'auto',
        events: libEventsFor(null),
        headerToolbar: { start: 'title', center: '', end: 'prevYear prev today next nextYear' },
        customButtons: {
          prevYear: { text: '\u00AB', click: function () { shiftYear(-1); } },
          nextYear: { text: '\u00BB', click: function () { shiftYear(1); } }
        },
        buttonText: function (t) {
          return Object.assign({}, t, { today: L.today || 'Today', prev: L.prev || 'Previous', next: L.next || 'Next' });
        },
        // Month name + red year; the midpoint of the range always falls inside the shown month
        titleFormat: function (start, end) {
          var mid = new Date((start.getTime() + end.getTime()) / 2);
          return { html: esc(cap(fmt(mid, { month: 'long' }))) + ' <span class="evc-year">' + mid.getFullYear() + '</span>' };
        },
        allDayContent: { html: '&nbsp;' },
        noEventsContent: L.noEvents || 'No events',
        eventContent: function (info) {
          // List layout (phones): put the time next to the title
          if (info.view && info.view.type === 'listMonth') {
            var ev = BY_ID[info.event.id];
            if (ev) { return ev.title + (ev.timeText ? ' \u00B7 ' + ev.timeText : ''); }
          }
        },
        eventDidMount: function (info) {
          var ev = BY_ID[info.event.id];
          if (!ev) { return; }
          info.el.setAttribute('title', ev.title);          // hover shows the title
          info.el.setAttribute('role', 'button');
          info.el.setAttribute('aria-haspopup', 'dialog');
          info.el.tabIndex = 0;
          info.el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); Modal.event(ev, info.el); }
          });
        },
        eventClick: function (info) {
          if (info.jsEvent && info.jsEvent.preventDefault) { info.jsEvent.preventDefault(); }
          var ev = BY_ID[info.event.id];
          if (ev) { Modal.event(ev, info.el); }
        }
      });
    } catch (err) {
      if (window.console) { console.error('Events Calendar:', err); }
      var ns2 = root.querySelector('noscript');
      if (ns2) { mount.innerHTML = ns2.textContent; initCards(); }
      return;
    }

    // Compact calendar that replaces the month grid in a narrow area
    var compact = null, compactMount = null, currentMode = null;
    if (mobileMode === 'compact') {
      compactMount = el('div', 'evc-compact-embed');
      compactMount.hidden = true;
      root.insertBefore(compactMount, mount.nextSibling);
      compact = createCompact(compactMount, eventsFor(root, null));
    }
    function applyMode() {
      var m = wantedMode();
      if (m === currentMode) { return; }
      currentMode = m;
      if (m === 'compact' && compactMount) {
        mount.hidden = true;
        compactMount.hidden = false;
        return;
      }
      mount.hidden = false;
      if (compactMount) { compactMount.hidden = true; }
      var v = viewFor(m);
      if (ec.getOption('view') !== v) { ec.setOption('view', v); }
    }
    applyMode();

    // Label the year buttons for screen readers
    try {
      each(mount.querySelectorAll('.ec-prevYear'), function (b) {
        b.setAttribute('aria-label', L.prevYear || 'Previous year'); b.title = L.prevYear || '';
      });
      each(mount.querySelectorAll('.ec-nextYear'), function (b) {
        b.setAttribute('aria-label', L.nextYear || 'Next year'); b.title = L.nextYear || '';
      });
    } catch (e) {}

    // Category buttons above the calendar: [events view="calendar" filter="1"]
    if (root.getAttribute('data-evc-filter') === '1') {
      var present = {};
      eventsFor(root, null).forEach(function (e) { if (e.category) { present[e.category] = true; } });
      var cats = CATEGORIES.filter(function (c) { return present[c.id]; });
      if (cats.length > 1) {
        var bar = el('div', 'evc-filter');
        var chosen = null;
        var chips = [];
        var pick = function (id) {
          chosen = id;
          chips.forEach(function (c) {
            var on = c.id === id;
            c.node.classList.toggle('is-active', on);
            c.node.setAttribute('aria-pressed', on ? 'true' : 'false');
          });
          ec.setOption('events', libEventsFor(chosen));
          if (compact) { compact.setList(eventsFor(root, chosen)); }
        };
        var addChip = function (id, label, color) {
          var b = el('button', 'evc-chip');
          b.type = 'button';
          if (color) {
            var d = el('span', 'evc-chip-dot');
            d.style.background = color;
            b.appendChild(d);
          }
          b.appendChild(document.createTextNode(label));
          b.addEventListener('click', function () { pick(id); });
          chips.push({ id: id, node: b });
          bar.appendChild(b);
        };
        addChip(null, L.allCategories || 'All', null);
        cats.forEach(function (c) { addChip(c.id, c.name, c.color); });
        root.insertBefore(bar, mount);
        pick(null);
      }
    }

    // Re-check when the width of the calendar's own container changes
    var timer;
    function onSize() { clearTimeout(timer); timer = setTimeout(applyMode, 120); }
    if (window.ResizeObserver) { new ResizeObserver(onSize).observe(root); }
    else { window.addEventListener('resize', onSize); }
  }

  /* ------------------------------------------------------------------
   * Start
   * ---------------------------------------------------------------- */
  function init() {
    initCards();
    each(document.querySelectorAll('.evc-compact'), initCompact);
    each(document.querySelectorAll('.evc-calendar'), initCalendar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
