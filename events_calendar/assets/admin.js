(function () {
  'use strict';

  var form = document.getElementById('ec-form');
  if (!form) { return; }

  var cfg = {
    ajax: form.getAttribute('data-ajax'),
    nonce: form.getAttribute('data-nonce'),
    uploads: form.getAttribute('data-uploads')
  };
  var LABELS = {};
  try { LABELS = JSON.parse(form.getAttribute('data-labels') || '{}'); } catch (e) {}
  var CAT_COLORS = {};
  try { CAT_COLORS = JSON.parse(form.getAttribute('data-catcolors') || '{}'); } catch (e) {}

  function $(id) { return document.getElementById(id); }
  function t(key, fallback) { return LABELS[key] || fallback || key; }
  function h(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------------- Colour swatches ---------------- */
  var color = $('ec_color');
  var swatches = form.querySelectorAll('.ec-swatch');

  function markSwatch() {
    for (var i = 0; i < swatches.length; i++) {
      var on = swatches[i].getAttribute('data-color').toLowerCase() === color.value.toLowerCase();
      swatches[i].classList.toggle('is-on', on);
    }
  }
  // For existing events the colour counts as already chosen; for new ones it follows the category
  var colorTouched = form.getAttribute('data-new') !== '1';
  for (var i = 0; i < swatches.length; i++) {
    swatches[i].addEventListener('click', function () {
      color.value = this.getAttribute('data-color');
      colorTouched = true;
      markSwatch();
    });
  }
  color.addEventListener('input', function () { colorTouched = true; markSwatch(); });
  markSwatch();

  var catSelect = $('ec_category');
  if (catSelect) {
    catSelect.addEventListener('change', function () {
      var c = CAT_COLORS[catSelect.value];
      if (c && !colorTouched) { color.value = c; markSwatch(); }
    });
  }

  /* ---------------- All-day toggle ---------------- */
  var allDay = $('ec_all_day');
  var times = form.querySelector('.ec-times');
  function syncAllDay() { times.hidden = allDay.checked; }
  allDay.addEventListener('change', syncAllDay);
  syncAllDay();

  /* ---------------- End date follows start date ---------------- */
  var dFrom = $('ec_date_from');
  var dTo = $('ec_date_to');
  dFrom.addEventListener('change', function () {
    if (dTo.value && dTo.value < dFrom.value) { dTo.value = dFrom.value; }
    dTo.min = dFrom.value;
  });
  if (dFrom.value) { dTo.min = dFrom.value; }

  /* ---------------- Image field ---------------- */
  var imgInput = $('ec_image');
  var imgPreview = $('ec_img_preview');
  var imgName = $('ec_img_name');
  var imgClear = $('ec_img_clear');

  function urlFor(path) {
    return cfg.uploads + path.split('/').map(encodeURIComponent).join('/');
  }

  function setImage(path) {
    imgInput.value = path || '';
    imgPreview.textContent = '';
    if (path) {
      var im = document.createElement('img');
      im.src = urlFor(path);
      im.alt = '';
      imgPreview.appendChild(im);
    } else {
      var sp = document.createElement('span');
      sp.textContent = t('no_image', 'No image');
      imgPreview.appendChild(sp);
    }
    imgName.textContent = path || '';
    imgClear.hidden = !path;
  }

  imgClear.addEventListener('click', function () { setImage(''); });
  $('ec_img_choose').addEventListener('click', function () {
    var start = imgInput.value.indexOf('/') > -1 ? imgInput.value.replace(/\/[^\/]*$/, '') : '';
    openBrowser(start, setImage, this);
  });

  /* ---------------- Image browser ---------------- */
  function api(op, query, body) {
    var url = cfg.ajax + '&op=' + op + '&nonce=' + encodeURIComponent(cfg.nonce) + (query || '');
    var opts = { credentials: 'same-origin' };
    if (body) { opts.method = 'POST'; opts.body = body; }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return { error: t('img_bad_response', 'Unexpected response from the server.') }; });
    }).catch(function () { return { error: t('img_network', 'Network error.') }; });
  }

  function openBrowser(startDir, onPick, trigger) {
    var state = { dir: startDir || '', selected: null };

    var overlay = document.createElement('div');
    overlay.className = 'ec-modal-overlay';
    overlay.innerHTML =
      '<div class="ec-modal" role="dialog" aria-modal="true" aria-label="' + h(t('img_library', 'Image library')) + '" tabindex="-1">' +
        '<div class="ec-modal-head"><strong>' + h(t('img_library', 'Image library')) + '</strong>' +
          '<button type="button" class="ec-x" aria-label="' + h(t('close', 'Close')) + '">&times;</button></div>' +
        '<div class="ec-modal-bar">' +
          '<nav class="ec-crumbs" aria-label="Folder"></nav>' +
          '<span class="ec-bar-actions">' +
            '<button type="button" class="ec-btn ec-newfolder">' + h(t('img_new_folder', 'New folder')) + '</button> ' +
            '<button type="button" class="ec-btn ec-upload-btn">' + h(t('img_upload', 'Upload images')) + '</button>' +
            '<input type="file" class="ec-file" multiple hidden accept="image/jpeg,image/png,image/gif,image/webp">' +
          '</span>' +
        '</div>' +
        '<div class="ec-modal-body">' +
          '<div class="ec-files"></div>' +
          '<div class="ec-dropmsg">' + h(t('img_drop', 'Drop images to upload to this folder')) + '</div>' +
        '</div>' +
        '<div class="ec-modal-foot"><span class="ec-status" role="status" aria-live="polite"></span>' +
          '<span><button type="button" class="ec-btn ec-cancel">' + h(t('cancel', 'Cancel')) + '</button> ' +
          '<button type="button" class="ec-btn ec-btn-primary ec-choose" disabled>' + h(t('img_use', 'Use selected image')) + '</button></span>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);

    var modal = overlay.querySelector('.ec-modal');
    var body = overlay.querySelector('.ec-modal-body');
    var filesEl = overlay.querySelector('.ec-files');
    var crumbs = overlay.querySelector('.ec-crumbs');
    var status = overlay.querySelector('.ec-status');
    var chooseBtn = overlay.querySelector('.ec-choose');
    var fileInput = overlay.querySelector('.ec-file');

    function say(msg) { status.textContent = msg || ''; }

    function close() {
      document.removeEventListener('keydown', onKey);
      document.body.removeChild(overlay);
      if (trigger) { trigger.focus(); }
    }
    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);

    function select(path, tile) {
      state.selected = path;
      var tiles = filesEl.querySelectorAll('.ec-tile');
      for (var i = 0; i < tiles.length; i++) { tiles[i].classList.remove('is-selected'); }
      if (tile) { tile.classList.add('is-selected'); }
      chooseBtn.disabled = !path;
    }

    function pick() {
      if (!state.selected) { return; }
      onPick(state.selected);
      close();
    }

    function renderCrumbs() {
      crumbs.textContent = '';
      var parts = state.dir ? state.dir.split('/') : [];
      var acc = '';
      function add(label, path, last) {
        if (last) {
          var s = document.createElement('span');
          s.textContent = label;
          crumbs.appendChild(s);
        } else {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'ec-crumb';
          b.textContent = label;
          b.addEventListener('click', function () { load(path); });
          crumbs.appendChild(b);
          crumbs.appendChild(document.createTextNode(' / '));
        }
      }
      add(t('img_root', 'uploads'), '', parts.length === 0);
      parts.forEach(function (p, i) {
        acc += (acc ? '/' : '') + p;
        add(p, acc, i === parts.length - 1);
      });
    }

    function tile(cls, label) {
      var t = document.createElement('button');
      t.type = 'button';
      t.className = 'ec-tile ' + cls;
      var cap = document.createElement('span');
      cap.className = 'ec-cap';
      cap.textContent = label;
      t.appendChild(cap);
      return t;
    }

    function render(data, selectPath) {
      state.dir = data.dir;
      renderCrumbs();
      filesEl.textContent = '';
      select(null, null);

      if (data.parent !== null) {
        var up = tile('ec-folder', t('img_up', '.. (up)'));
        up.addEventListener('click', function () { load(data.parent); });
        filesEl.appendChild(up);
      }
      data.folders.forEach(function (f) {
        var t = tile('ec-folder', f.name);
        t.addEventListener('click', function () { load(f.path); });
        filesEl.appendChild(t);
      });
      data.images.forEach(function (im) {
        var t = tile('ec-image', im.name);
        var img = document.createElement('img');
        img.loading = 'lazy';
        img.alt = '';
        img.src = urlFor(im.path);
        t.insertBefore(img, t.firstChild);
        t.addEventListener('click', function () { select(im.path, t); });
        t.addEventListener('dblclick', function () { select(im.path, t); pick(); });
        filesEl.appendChild(t);
        if (selectPath && im.path === selectPath) { select(im.path, t); t.scrollIntoView({ block: 'nearest' }); }
      });
      if (!data.folders.length && !data.images.length) {
        var p = document.createElement('p');
        p.className = 'ec-empty';
        p.textContent = t('img_empty', 'This folder has no images yet.');
        filesEl.appendChild(p);
      }
      if (data.truncated) { say(t('img_truncated', 'Showing the first 500 items of this folder.')); }
    }

    function load(dir, selectPath) {
      say(t('img_loading', 'Loading...'));
      api('list', '&dir=' + encodeURIComponent(dir || '')).then(function (data) {
        if (data.error) { say(data.error); return; }
        say('');
        render(data, selectPath);
      });
    }

    function upload(fileList) {
      var fd = new FormData();
      var n = 0;
      for (var i = 0; i < fileList.length; i++) {
        if (/^image\//.test(fileList[i].type)) { fd.append('files[]', fileList[i]); n++; }
      }
      if (!n) { say(t('img_pick_files', 'Please choose image files.')); return; }
      say(t('img_uploading', 'Uploading...'));
      api('upload', '&dir=' + encodeURIComponent(state.dir), fd).then(function (data) {
        if (data.error) { say(data.error); return; }
        var okFiles = data.results.filter(function (r) { return r.ok; });
        var bad = data.results.filter(function (r) { return !r.ok; });
        var msg = t('img_uploaded', '%d uploaded.').replace('%d', okFiles.length);
        if (bad.length) { msg += ' ' + bad.map(function (r) { return r.name + ': ' + r.error; }).join(' '); }
        load(state.dir, okFiles.length ? okFiles[0].path : null);
        setTimeout(function () { say(msg); }, 300);
      });
    }

    overlay.querySelector('.ec-x').addEventListener('click', close);
    overlay.querySelector('.ec-cancel').addEventListener('click', close);
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { close(); } });
    chooseBtn.addEventListener('click', pick);

    overlay.querySelector('.ec-upload-btn').addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      if (fileInput.files.length) { upload(fileInput.files); }
      fileInput.value = '';
    });

    overlay.querySelector('.ec-newfolder').addEventListener('click', function () {
      var name = window.prompt(t('img_prompt', 'New folder name:'));
      if (!name) { return; }
      api('mkdir', '&dir=' + encodeURIComponent(state.dir) + '&name=' + encodeURIComponent(name), new FormData())
        .then(function (data) {
          if (data.error) { say(data.error); return; }
          load(data.path);
        });
    });

    // Drag and drop
    ['dragenter', 'dragover'].forEach(function (ev) {
      body.addEventListener(ev, function (e) { e.preventDefault(); body.classList.add('is-drop'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      body.addEventListener(ev, function (e) { e.preventDefault(); body.classList.remove('is-drop'); });
    });
    body.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files.length) { upload(e.dataTransfer.files); }
    });

    modal.focus();
    load(state.dir, imgInput.value || null);
  }
})();
