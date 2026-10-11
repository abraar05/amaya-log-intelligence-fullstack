/* Amaya Log Intelligence — dashboard client.
   All figures come from api.php over the authenticated session. */
(function () {
'use strict';

var CSRF = '';
var USER = null;
var chart = null;
var lastRecords = [];

var ROUTES = [
  ['overview',     'Overview'],
  ['records',      'Records'],
  ['reconciliation','Reconciliation'],
  ['quality',      'Data quality'],
  ['sources',      'Sources'],
  ['activity',     'Activity log']
];

var ICONS = {
  overview:      '<path d="M3 13h8V3H3v10Zm10 8h8V11h-8v10ZM3 21h8v-6H3v6ZM13 9h8V3h-8v6Z"/>',
  records:       '<path d="M4 4h16v4H4zM4 10h16v4H4zM4 16h10v4H4z"/>',
  reconciliation:'<path d="M3 7h18M3 12h18M3 17h18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/><circle cx="8" cy="7" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="10" cy="17" r="2"/>',
  quality:       '<path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3Z"/>',
  sources:       '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
  activity:      '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/>'
};

function $(s, r) { return (r || document).querySelector(s); }
function esc(v) {
  return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
function nf(n) { return (Number(n) || 0).toLocaleString('en-US'); }

function toast(msg, isErr) {
  var t = $('#toast');
  t.textContent = msg;
  t.className = 'toast show' + (isErr ? ' err' : '');
  clearTimeout(window.__tt);
  window.__tt = setTimeout(function () { t.className = 'toast'; }, 3400);
}

/* ---------- API ---------- */
function api(action, opts) {
  opts = opts || {};
  var url = 'api.php?action=' + encodeURIComponent(action);
  var init = {
    method: opts.method || 'GET',
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin'
  };
  if (opts.body instanceof FormData) {
    init.body = opts.body;
  } else if (opts.body) {
    init.headers['Content-Type'] = 'application/json';
    init.headers['X-CSRF-Token'] = CSRF;
    init.body = JSON.stringify(opts.body);
  }
  if ((opts.method || 'GET') !== 'GET') { init.headers['X-CSRF-Token'] = CSRF; }

  return fetch(url, init).then(function (r) {
    return r.json().catch(function () { return { ok: false, error: 'Unexpected response (' + r.status + ')' }; })
      .then(function (data) {
        if (r.status === 401) { window.location = 'login.php'; throw new Error('unauth'); }
        if (!data.ok) { throw new Error(data.error || ('Request failed (' + r.status + ')')); }
        return data;
      });
  });
}

/* ---------- routing ---------- */
function route() {
  var name = (location.hash || '#overview').replace('#', '');
  if (!ROUTES.some(function (r) { return r[0] === name; })) { name = 'overview'; }
  ROUTES.forEach(function (r) {
    var el = $('#v-' + r[0]);
    if (el) { el.hidden = r[0] !== name; }
  });
  document.querySelectorAll('#nav a').forEach(function (a) {
    a.classList.toggle('on', a.dataset.route === name);
  });
  $('#crumb').textContent = (ROUTES.filter(function (r) { return r[0] === name; })[0] || [])[1] || 'Overview';
  load(name);
}

/* ---------- loaders ---------- */
function load(name) {
  if (name === 'overview')      return Promise.all([loadOverview(), loadActivity()]).catch(report);
  if (name === 'records')       return loadRecords().catch(report);
  if (name === 'reconciliation')return loadRecon().catch(report);
  if (name === 'quality')       return loadQuality().catch(report);
  if (name === 'sources')       return Promise.all([loadSources(), loadLinks()]).catch(report);
  if (name === 'activity')      return loadActivity().catch(report);
  return Promise.resolve();
}
function report(e) { if (e && e.message !== 'unauth') toast(e.message || 'Something went wrong', true); }

function loadOverview() {
  return api('overview').then(function (d) {
    var k = d.reconciliation, q = d.quality;
    $('#k-records').textContent = nf(d.records);
    $('#k-records-sub').textContent = d.sources + ' source' + (d.sources === 1 ? '' : 's') + ' · ' + d.departments + ' departments';
    $('#k-rate').textContent = (k.total_unique_imeis ? k.match_rate + '%' : '–');
    $('#k-rate-sub').textContent = k.total_unique_imeis
      ? (k.meets_target ? 'meets ' + k.target_rate + '% target' : 'below ' + k.target_rate + '% target')
      : 'import data to measure';
    $('#k-dupes').textContent = nf(k.duplicate_imeis);
    $('#k-quality').textContent = (q.score === null ? '–' : q.score + '%');
    $('#k-quality-sub').textContent = q.score === null ? 'no records to score' : (q.issues.length + ' issue categor' + (q.issues.length === 1 ? 'y' : 'ies'));

    var b = $('#banner');
    if (k.total_unique_imeis && !k.meets_target) {
      b.hidden = false;
      b.innerHTML = 'Reconciliation is at <b>' + k.match_rate + '%</b> against the ' + k.target_rate +
        '% target. Review the ' + nf(k.unmatched_imeis) + ' unmatched identifier' +
        (k.unmatched_imeis === 1 ? '' : 's') + ' first.';
    } else { b.hidden = true; }

    drawChart(k);
  });
}

function drawChart(k) {
  var c = $('#chart');
  if (!c || typeof Chart === 'undefined') { return; }
  var data = [k.matched_records, k.unmatched_imeis, k.duplicate_imeis];
  var labels = ['Matched', 'Unmatched', 'Duplicate'];
  var colors = ['#0f7a5f', '#c98a1e', '#b42318'];
  if (chart) { chart.destroy(); }
  chart = new Chart(c, {
    type: 'doughnut',
    data: { labels: labels, datasets: [{ data: data, backgroundColor: colors, borderWidth: 0 }] },
    options: {
      maintainAspectRatio: false,
      cutout: '62%',
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14, font: { size: 12 } } },
        tooltip: { backgroundColor: '#16202f', padding: 10 }
      }
    }
  });
}

function loadRecords() {
  return api('records', { body: null }).then(function (d) {
    lastRecords = d.records || [];
    paintRecords();
  });
}
function paintRecords() {
  var q = ($('#q').value || '').toLowerCase();
  var st = $('#fstatus').value;
  var rows = lastRecords.filter(function (r) {
    var text = [r.imei, r.device_model, r.invoice_number, r.department].join(' ').toLowerCase();
    return (!q || text.indexOf(q) >= 0) && (!st || r.match_status === st);
  });
  $('#recs').innerHTML = rows.length ? rows.map(function (r) {
    return '<tr><td><code>' + esc(r.imei) + '</code></td><td>' + esc(r.device_model || '—') + '</td>' +
      '<td>' + esc(r.invoice_number || '—') + '</td><td>' + esc(r.department || '—') + '</td>' +
      '<td>' + esc(r.record_date || '—') + '</td>' +
      '<td><span class="tag ' + esc(r.match_status) + '">' + esc(r.match_status) + '</span></td></tr>';
  }).join('') : '<tr><td colspan="6" class="empty">No records match this filter.</td></tr>';
  $('#recCount').textContent = rows.length + ' of ' + lastRecords.length + ' shown';
}

function loadRecon() {
  return api('reconciliation').then(function (d) {
    var k = d.kpis;
    $('#r-matched').textContent = nf(k.matched_records);
    $('#r-unmatched').textContent = nf(k.unmatched_imeis);
    $('#r-dupes').textContent = nf(k.duplicate_imeis);
    $('#r-eligible').textContent = nf(k.eligible_imeis);
    $('#dupes').innerHTML = (d.duplicates && d.duplicates.length)
      ? d.duplicates.map(function (x) {
          return '<tr><td><code>' + esc(x.imei) + '</code></td><td>' + x.count + '</td><td>' + x.excess +
            '</td><td>' + esc((x.sources || []).join(', ') || '—') + '</td></tr>';
        }).join('')
      : '<tr><td colspan="4" class="empty">No duplicate identifiers found.</td></tr>';
  });
}

function loadQuality() {
  return api('quality').then(function (d) {
    paintQuality(d.quality);
  });
}
function paintQuality(q) {
  if (q.score === null) {
    $('#qIssues').innerHTML = '<p class="empty">No records to scan yet. Import a CSV first.</p>';
    return;
  }
  var html = '<p class="hint">Score <b>' + q.score + '%</b> — computed as <code>' + esc(q.formula) + '</code>. ' +
    q.issues.length + ' issue categor' + (q.issues.length === 1 ? 'y' : 'ies') + ' detected.</p>';
  html += q.issues.length
    ? '<div class="tw"><table><thead><tr><th>Severity</th><th>Issue</th><th>Detail</th><th>Affected</th></tr></thead><tbody>' +
      q.issues.map(function (i) {
        return '<tr><td><span class="tag ' + (i.severity === 'high' ? 'bad' : 'unmatched') + '">' + esc(i.severity) + '</span></td>' +
          '<td><b>' + esc(i.title) + '</b><br><small>' + esc(i.department) + '</small></td>' +
          '<td style="white-space:normal">' + esc(i.description) + '</td><td>' + nf(i.affected) + '</td></tr>';
      }).join('') + '</tbody></table></div>'
    : '<p class="empty">No issues detected.</p>';
  $('#qIssues').innerHTML = html;
}

function loadSources() {
  return api('sources').then(function (d) {
    $('#srcs').innerHTML = (d.sources && d.sources.length)
      ? d.sources.map(function (s) {
          return '<tr><td><b>' + esc(s.name) + '</b><br><small>' + esc(s.source_type) + '</small></td>' +
            '<td>' + esc(s.department_name || '—') + '</td><td>' + nf(s.record_count) + '</td>' +
            '<td>' + esc(s.last_synced_at || 'never') + '</td>' +
            '<td><span class="tag ' + (s.last_status === 'private' ? 'private' : s.last_status === 'connected' ? 'connected' : 'info') + '">' +
            esc(s.last_status) + '</span></td>' +
            '<td><button class="btn ghost" data-sync="' + s.id + '">Sync</button></td></tr>';
        }).join('')
      : '<tr><td colspan="6" class="empty">No sources registered.</td></tr>';

    $('#srcs').querySelectorAll('[data-sync]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-sync');
        b.disabled = true;
        api('import.sheet', { method: 'POST', body: { source_id: id } })
          .then(function () { toast('Sheet synced'); return loadSources(); })
          .catch(function (e) { toast(e.message, true); })
          .finally(function () { b.disabled = false; });
      });
    });
  });
}

function loadLinks() {
  return api('links').then(function (d) {
    $('#links').innerHTML = (d.links && d.links.length)
      ? d.links.map(function (l) {
          return '<li><b>' + esc(l.label) + '</b><a href="' + esc(l.url) + '" target="_blank" rel="noopener">' +
            esc(l.url.length > 46 ? l.url.slice(0, 46) + '…' : l.url) + '</a></li>';
        }).join('')
      : '<li class="empty">No links registered.</li>';
  });
}

function loadActivity() {
  return api('activity').then(function (d) {
    var rows = d.activity || [];
    $('#audit').innerHTML = rows.length ? rows.map(function (a) {
      return '<tr><td>' + esc(String(a.created_at || '').replace('T', ' ').slice(0, 19)) + '</td>' +
        '<td>' + esc(a.actor) + '</td><td><b>' + esc(a.action) + '</b></td>' +
        '<td style="white-space:normal">' + esc(a.details || '') + '</td>' +
        '<td><span class="tag ' + (a.result === 'Success' ? 'ok' : a.result === 'Review' ? 'unmatched' : 'bad') + '">' +
        esc(a.result) + '</span></td></tr>';
    }).join('') : '<tr><td colspan="5" class="empty">No activity recorded yet.</td></tr>';

    if ($('#act')) {
      $('#act').innerHTML = rows.length ? rows.slice(0, 8).map(function (a) {
        return '<li><span class="dot"></span><span><b>' + esc(a.action) + '</b>' +
          '<small>' + esc(a.details || '') + ' · ' + esc(a.actor) + '</small></span></li>';
      }).join('') : '<li class="empty">No activity yet.</li>';
    }
  });
}

/* ---------- actions ---------- */
function exportCsv() {
  if (!lastRecords.length) { toast('Nothing to export', true); return; }
  var head = ['imei', 'device_model', 'invoice_number', 'department', 'record_date', 'match_status'];
  var lines = [head.join(',')].concat(lastRecords.map(function (r) {
    return head.map(function (k) { return '"' + String(r[k] == null ? '' : r[k]).replace(/"/g, '""') + '"'; }).join(',');
  }));
  var blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'imei-records.csv';
  a.click();
  URL.revokeObjectURL(a.href);
}

function uploadCsv(file) {
  if (!file) { return; }
  var fd = new FormData();
  fd.append('file', file);
  var btn = $('#importBtn');
  btn.disabled = true;
  btn.textContent = 'Importing…';
  api('import.csv', { method: 'POST', body: fd })
    .then(function (d) {
      toast('Imported ' + d.inserted + ' rows' + (d.skipped ? ', ' + d.skipped + ' skipped' : ''));
      return Promise.all([loadSources(), loadOverview()]);
    })
    .catch(function (e) { toast(e.message, true); })
    .finally(function () { btn.disabled = false; btn.textContent = 'Import CSV'; });
}

/* ---------- boot ---------- */
function init() {
  $('#nav').innerHTML = ROUTES.map(function (r) {
    return '<a href="#' + r[0] + '" data-route="' + r[0] + '"><svg viewBox="0 0 24 24" fill="currentColor">' +
      ICONS[r[0]] + '</svg>' + r[1] + '</a>';
  }).join('');

  $('#menu').addEventListener('click', function () { $('#side').classList.toggle('open'); });
  $('#refresh').addEventListener('click', function () { route(); toast('Refreshed'); });
  window.addEventListener('hashchange', route);

  $('#q').addEventListener('input', paintRecords);
  $('#fstatus').addEventListener('change', paintRecords);
  $('#exportRecs').addEventListener('click', exportCsv);

  $('#importBtn').addEventListener('click', function () { $('#csv').click(); });
  $('#csv').addEventListener('change', function (e) { uploadCsv(e.target.files[0]); e.target.value = ''; });

  $('#scan').addEventListener('click', function () {
    var b = this; b.disabled = true;
    api('quality', { method: 'POST', body: {} })
      .then(function (d) { paintQuality(d.quality); toast('Scan complete'); })
      .catch(function (e) { toast(e.message, true); })
      .finally(function () { b.disabled = false; });
  });

  var modal = $('#modal');
  $('#addSrc').addEventListener('click', function () {
    api('links').catch(function () {});
    modal.hidden = false;
  });
  $('#mCancel').addEventListener('click', function () { modal.hidden = true; });
  $('#mSave').addEventListener('click', function () {
    api('source.create', {
      method: 'POST',
      body: {
        name: $('#f_name').value.trim(),
        department: $('#f_dept').value,
        source_type: $('#f_type').value,
        source_url: $('#f_url').value.trim()
      }
    }).then(function () { modal.hidden = true; $('#f_name').value = ''; $('#f_url').value = ''; toast('Source added'); return loadSources(); })
      .catch(function (e) { toast(e.message, true); });
  });
  modal.addEventListener('click', function (e) { if (e.target === modal) { modal.hidden = true; } });

  api('session').then(function (d) {
    CSRF = d.csrf;
    USER = d.user;
    $('#whoName').textContent = d.user.username;
    $('#whoRole').textContent = d.user.role;
    $('#whoAv').textContent = d.user.username.slice(0, 2).toUpperCase();
    return api('links');
  }).then(function (d) {
    $('#f_dept').innerHTML = ['Logistics', 'Sales', 'Finance', 'Human resources', 'Customer service', 'Operations']
      .map(function (n) { return '<option>' + n + '</option>'; }).join('');
  }).catch(function () { /* redirect handled in api() */ });

  route();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else { init(); }
})();