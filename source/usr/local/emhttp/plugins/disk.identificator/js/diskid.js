/* Disk Identificator - adds locate LED buttons to the Dashboard and Main disk tables.
 *
 * Unraid re-renders these tables from nchan updates (Main replaces the whole tbody,
 * Dashboard re-appends tr.updated rows), so a MutationObserver re-adds the buttons
 * every time rows are replaced.
 */
(function($) {
  'use strict';

  var API = '/plugins/disk.identificator/include/api.php';
  var REFRESH_MS = 30000;
  var map = null;      // response of action=map
  var busy = {};       // disk name -> request in flight

  function diskName(tr) {
    var a = tr.querySelector('a[href*="Device?name="]');
    if (!a) return null;
    var m = /[?&]name=([^&#]+)/.exec(a.getAttribute('href'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  /* Optional location label (from the settings page), then the button. */
  function fill(cell, name) {
    var d = map && map.disks[name];
    if (d && d.label) {
      var lbl = document.createElement('span');
      lbl.className = 'diskid-label';
      lbl.textContent = d.label;
      lbl.title = 'Location ' + d.label;
      cell.appendChild(lbl);
    }
    cell.appendChild(makeButton(name));
  }

  function makeButton(name) {
    var d = map && map.disks[name];
    var el = document.createElement('span');
    if (!d) {
      el.className = 'diskid-na';
      el.title = 'Not on a supported SAS controller';
      el.textContent = '-';
      return el;
    }
    el.className = 'diskid-btn';
    el.setAttribute('role', 'button');
    el.setAttribute('tabindex', '0');
    el.setAttribute('data-diskid', name);
    el.innerHTML = '<i class="fa fa-lightbulb-o"></i>';
    paint(el);
    return el;
  }

  function paint(el) {
    var name = el.getAttribute('data-diskid');
    var d = map && map.disks[name];
    if (!d) return;
    var on = !!d.on;
    el.className = 'diskid-btn' + (on ? ' diskid-on diskid-' + d.color : '') + (busy[name] ? ' diskid-busy' : '');
    el.setAttribute('aria-pressed', on ? 'true' : 'false');
    // key "sas3-0:1:3" = sas3ircu controller 0, enclosure 1, slot 3
    var where = d.key.replace(/^sas(\d)-(\d+):(\d+):(\d+)$/, 'SAS$1 controller $2, bay $3:$4');
    if (d.target !== d.key) where += ' (LED ' + d.target.replace(/^[^:]+:/, '') + ')';
    el.title = (on ? 'Turn identification LED off' : 'Turn identification LED on') + '\n' + where + ' - /dev/' + d.device;
  }

  function repaintAll() {
    document.querySelectorAll('.diskid-btn').forEach(paint);
  }

  /* Main: extra column between Device and Identification. */
  function injectMain(table) {
    table.classList.add('diskid-main');
    var rows = table.querySelectorAll(':scope > thead > tr, :scope > tbody > tr');
    for (var i = 0; i < rows.length; i++) {
      var tr = rows[i];
      if (!tr.cells.length || tr.querySelector(':scope > .diskid-col')) continue;
      var td = document.createElement('td');
      td.className = 'diskid-col';
      if (tr.parentNode.tagName === 'THEAD') {
        td.textContent = 'ID';
      } else if (!tr.classList.contains('pool_header')) {
        var name = diskName(tr.cells[0]);
        if (name) fill(td, name);
      }
      tr.insertBefore(td, tr.cells[1] || null);
    }
  }

  /* Dashboard: extra span column at the end of each device row. */
  function injectDash(tbody) {
    tbody.classList.add('diskid-dash');
    var rows = tbody.querySelectorAll(':scope > tr');
    for (var i = 0; i < rows.length; i++) {
      var tr = rows[i];
      var cell = tr.cells[0];
      if (!cell || !cell.querySelector(':scope > span.w26') || cell.querySelector(':scope > .diskid-dcol')) continue;
      var span = document.createElement('span');
      span.className = 'diskid-dcol';
      if (tr.classList.contains('header')) {
        span.textContent = 'ID';
      } else {
        var name = diskName(cell);
        if (name) fill(span, name);
      }
      cell.appendChild(span);
    }
  }

  function targets() {
    var list = [];
    if (map.main) document.querySelectorAll('table.disk_status').forEach(function(t) { list.push([t, injectMain]); });
    if (map.dashboard) document.querySelectorAll('tbody#array_list, tbody[id^="pool_list"], tbody#devs_list').forEach(function(t) { list.push([t, injectDash]); });
    return list;
  }

  function watch() {
    targets().forEach(function(t) {
      var node = t[0], inject = t[1];
      inject(node);
      // Our own insertions trigger one more callback, which finds nothing left to do.
      new MutationObserver(function() { inject(node); }).observe(node, {childList: true, subtree: true});
    });
  }

  function toggle(el) {
    var name = el.getAttribute('data-diskid');
    var d = map && map.disks[name];
    if (!d || busy[name]) return;
    busy[name] = true;
    paint(el);
    $.post(API, {action: 'locate', name: name, on: d.on ? 0 : 1, csrf_token: csrf_token}, null, 'json')
      .done(function(res) {
        if (!res.ok) { alertError(res.error); return; }
        // Several disks can share one LED through the remap; keep them in sync.
        $.each(map.disks, function(n, x) { if (x.target === res.target) x.on = res.on; });
        // The server switches the LED off by itself after the configured timer; pick that up.
        if (res.timer > 0) setTimeout(function() { load(false); }, (res.timer + 2) * 1000);
      })
      .fail(function(xhr) { alertError(xhr.statusText || 'Request failed'); })
      .always(function() { delete busy[name]; repaintAll(); });
  }

  function alertError(msg) {
    if (typeof swal === 'function') swal({title: 'Disk identification failed', text: String(msg || ''), type: 'error'});
    else alert('Disk identification failed\n' + (msg || ''));
  }

  function load(first) {
    return $.getJSON(API, {action: 'map'}).done(function(res) {
      if (!res || !res.ok || !res.tool) return;
      map = res;
      if (first) watch(); else repaintAll();
    });
  }

  $(function() {
    if (!document.querySelector('table.disk_status, tbody#array_list, tbody[id^="pool_list"]')) return;
    $(document).on('click', '.diskid-btn', function(e) { e.preventDefault(); e.stopPropagation(); toggle(this); });
    $(document).on('keydown', '.diskid-btn', function(e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(this); }
    });
    load(true);
    // Pick up LED changes made from other tabs or the settings page.
    setInterval(function() { if (map && !document.hidden) load(false); }, REFRESH_MS);
  });
})(jQuery);
