<?php
declare(strict_types=1);

/**
 * Authenticated dashboard.
 *
 * The markup is static; every figure on the page is fetched from api.php
 * over the authenticated session, so nothing sensitive is embedded in HTML.
 */

$config = require dirname(__DIR__) . '/bootstrap.php';

use App\Http\Session;

Session::start($config);
Session::requireLogin($config);

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Amaya Log Intelligence</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="shell">
  <aside class="side" id="side">
    <a class="brand" href="#overview"><span class="mk">A</span><span><b>AMAYA</b><small>LOG INTELLIGENCE</small></span></a>
    <div class="who"><div class="av" id="whoAv">–</div><span><b id="whoName">…</b><small id="whoRole">loading</small></span></div>
    <nav id="nav"></nav>
    <div class="side-foot">
      <a class="out" href="logout.php"><span>Sign out</span></a>
    </div>
  </aside>

  <main class="main">
    <header class="top">
      <button class="ib" id="menu" aria-label="Menu"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg></button>
      <h1 id="crumb">Overview</h1>
      <div class="spacer"></div>
      <button class="ib" id="refresh" title="Refresh"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
    </header>

    <div class="wrap">
      <div id="banner" class="banner" hidden></div>

      <!-- OVERVIEW -->
      <section class="view" id="v-overview">
        <div class="grid4">
          <div class="kpi"><span>Records processed</span><b id="k-records">–</b><small id="k-records-sub">no data</small></div>
          <div class="kpi"><span>Reconciliation rate</span><b id="k-rate">–</b><small id="k-rate-sub">target 98%</small></div>
          <div class="kpi"><span>Duplicate IMEIs</span><b id="k-dupes">–</b><small>need deduplication review</small></div>
          <div class="kpi"><span>Quality score</span><b id="k-quality">–</b><small id="k-quality-sub">no scan yet</small></div>
        </div>
        <div class="grid2">
          <div class="panel"><h2>Match status breakdown</h2><div class="chart-wrap"><canvas id="chart"></canvas></div></div>
          <div class="panel"><h2>Recent activity</h2><ul class="log" id="act"></ul></div>
        </div>
      </section>

      <!-- RECORDS -->
      <section class="view" id="v-records" hidden>
        <div class="panel">
          <div class="ph"><h2>Imported records</h2>
            <div class="tools">
              <input id="q" type="search" placeholder="Search IMEI, invoice, device…">
              <select id="fstatus"><option value="">All statuses</option><option value="matched">Matched</option><option value="unmatched">Unmatched</option><option value="duplicate">Duplicate</option></select>
            </div>
          </div>
          <div class="tw"><table><thead><tr><th>IMEI / Serial</th><th>Device</th><th>Invoice</th><th>Department</th><th>Date</th><th>Status</th></tr></thead><tbody id="recs"></tbody></table></div>
          <div class="tf"><span id="recCount">–</span><button class="btn" id="exportRecs">Export CSV</button></div>
        </div>
      </section>

      <!-- SOURCES -->
      <section class="view" id="v-sources" hidden>
        <div class="panel" id="srcPanel">
          <div class="ph"><h2>Data sources</h2>
            <div class="tools">
              <button class="btn" id="importBtn">Import CSV</button>
              <button class="btn" id="addSrc">Add source</button>
            </div>
          </div>
          <div class="tw"><table><thead><tr><th>Source</th><th>Department</th><th>Records</th><th>Last sync</th><th>Status</th><th></th></tr></thead><tbody id="srcs"></tbody></table></div>
          <input type="file" id="csv" accept=".csv,text/csv" hidden>
        </div>
        <div class="panel"><h2>Linked systems</h2><p class="hint">Registry imported from the operations sheet.</p><ul class="links" id="links"></ul></div>
      </section>

      <!-- RECONCILIATION -->
      <section class="view" id="v-reconciliation" hidden>
        <div class="grid4">
          <div class="kpi"><span>Matched</span><b id="r-matched">–</b><small>unique + referenced</small></div>
          <div class="kpi"><span>Unmatched</span><b id="r-unmatched">–</b><small>no invoice reference</small></div>
          <div class="kpi"><span>Duplicate identifiers</span><b id="r-dupes">–</b><small>appear more than once</small></div>
          <div class="kpi"><span>Eligible</span><b id="r-eligible">–</b><small>excludes duplicates</small></div>
        </div>
        <div class="panel"><h2>Duplicate identifiers</h2>
          <p class="hint">Duplicates are reported separately so they never deflate the match rate.</p>
          <div class="tw"><table><thead><tr><th>IMEI</th><th>Occurrences</th><th>Excess rows</th><th>Sources</th></tr></thead><tbody id="dupes"></tbody></table></div>
        </div>
      </section>

      <!-- QUALITY -->
      <section class="view" id="v-quality" hidden>
        <div class="panel">
          <div class="ph"><h2>Data quality</h2><button class="btn" id="scan">Run scan</button></div>
          <div id="qIssues"></div>
        </div>
      </section>

      <!-- ACTIVITY -->
      <section class="view" id="v-activity" hidden>
        <div class="panel"><h2>Audit log</h2>
          <div class="tw"><table><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Detail</th><th>Result</th></tr></thead><tbody id="audit"></tbody></table></div>
        </div>
      </section>
    </div>
  </main>
</div>

<div class="modal" id="modal" hidden>
  <div class="mbox">
    <h2 id="mtitle">Add source</h2>
    <label>Name<input id="f_name" required placeholder="Daily IMEI Movement"></label>
    <label>Department<select id="f_dept"></select></label>
    <label>Type<select id="f_type"><option>Manual CSV upload</option><option>Google Sheets CSV URL</option></select></label>
    <label>URL<input id="f_url" type="url" placeholder="https://docs.google.com/spreadsheets/d/…"></label>
    <div class="acts"><button class="btn ghost" id="mCancel">Cancel</button><button class="btn" id="mSave">Save</button></div>
  </div>
</div>

<div class="toast" id="toast"></div>
<script src="assets/app.js"></script>
</body></html>