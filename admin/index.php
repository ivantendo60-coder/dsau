<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
require_admin();

$courseId = dashboard_course_id();
$intakes  = q('SELECT id, name FROM intakes ORDER BY id DESC')->fetchAll();
$intakeId = (int) ($_GET['intake'] ?? 0);
if ($intakeId && !in_array($intakeId, array_map('intval', array_column($intakes, 'id')), true)) {
    $intakeId = 0;
}
$filters = [];
$pA = [];
if ($intakeId) {
    $filters[] = 'a.intake_id = ?';
    $pA[] = $intakeId;
}
if ($courseId) {
    $filters[] = 'a.course_id = ?';
    $pA[] = $courseId;
}
$wA = $filters ? 'WHERE ' . implode(' AND ', $filters) : '';

/* ---------- KPI totals ---------- */
$t = q("SELECT COUNT(*) total,
               SUM(a.status='Pending') pending, SUM(a.status='Under Review') review,
               SUM(a.status='Admitted') admitted, SUM(a.status='Waitlisted') waitlisted,
               SUM(a.status='Rejected') rejected
        FROM applications a $wA", $pA)->fetch();
$t = array_map('intval', $t ?: []);
$t += ['total' => 0, 'pending' => 0, 'review' => 0, 'admitted' => 0, 'waitlisted' => 0, 'rejected' => 0];

$collected = (int) q("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN applications a ON a.id = p.application_id $wA", $pA)->fetchColumn();

/* ---------- Course performance ---------- */
$joinFilters = [];
$courseParams = [];
if ($intakeId) {
    $joinFilters[] = 'a.intake_id = ?';
    $courseParams[] = $intakeId;
}
$courseWhere = [];
if ($courseId) {
    $courseWhere[] = '(c.is_active = 1 OR a.id IS NOT NULL OR c.id = ?)';
    $courseParams[] = $courseId;
    $courseWhere[] = 'c.id = ?';
    $courseParams[] = $courseId;
} else {
    $courseWhere[] = '(c.is_active = 1 OR a.id IS NOT NULL)';
}
$joinIntake = $joinFilters ? 'AND ' . implode(' AND ', $joinFilters) : '';
$courseRows = q("SELECT c.id, c.title,
        COUNT(a.id) total,
        COALESCE(SUM(a.status='Pending'),0) pending,
        COALESCE(SUM(a.status='Under Review'),0) review,
        COALESCE(SUM(a.status='Admitted'),0) admitted,
        COALESCE(SUM(a.status='Waitlisted'),0) waitlisted,
        COALESCE(SUM(a.status='Rejected'),0) rejected,
        COALESCE(SUM(CASE WHEN a.status='Admitted' THEN a.fee_due END),0) expected,
        COALESCE(SUM(COALESCE(pp.paid,0)),0) collected,
        COALESCE(SUM(CASE WHEN a.status='Admitted' THEN GREATEST(a.fee_due - COALESCE(pp.paid,0), 0) END),0) outstanding
    FROM courses c
    LEFT JOIN applications a ON a.course_id = c.id $joinIntake
    LEFT JOIN (SELECT application_id, SUM(amount) paid FROM payments GROUP BY application_id) pp ON pp.application_id = a.id
    WHERE " . implode(' AND ', $courseWhere) . "
    GROUP BY c.id, c.title, c.sort_order
    ORDER BY c.sort_order, c.title", $courseParams)->fetchAll();

$outstanding = 0;
foreach ($courseRows as $r) {
    $outstanding += (int) $r['outstanding'];
}
$admitRate = $t['total'] > 0 ? round($t['admitted'] / $t['total'] * 100, 1) : 0;

/* ---------- Trend: last 30 days ---------- */
$from = date('Y-m-d', strtotime('-29 days'));
$trendFilters = ['a.created_at >= ?'];
$trendParams = [$from];
if ($intakeId) {
    $trendFilters[] = 'a.intake_id = ?';
    $trendParams[] = $intakeId;
}
if ($courseId) {
    $trendFilters[] = 'a.course_id = ?';
    $trendParams[] = $courseId;
}
$trendRaw = q("SELECT DATE(a.created_at) d, COUNT(*) n FROM applications a
               WHERE " . implode(' AND ', $trendFilters) . "
               GROUP BY DATE(a.created_at)", $trendParams)->fetchAll(PDO::FETCH_KEY_PAIR);
$trendLabels = $trendValues = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('d M', strtotime($d));
    $trendValues[] = (int) ($trendRaw[$d] ?? 0);
}

/* ---------- Level split & heard-about ---------- */
$levels = q("SELECT a.level, COUNT(*) n FROM applications a $wA GROUP BY a.level", $pA)->fetchAll(PDO::FETCH_KEY_PAIR);
$heard  = [];
foreach (q("SELECT a.heard_about FROM applications a $wA", $pA)->fetchAll(PDO::FETCH_COLUMN) as $csv) {
    foreach (array_filter(array_map('trim', explode(',', (string) $csv))) as $h) {
        $heard[$h] = ($heard[$h] ?? 0) + 1;
    }
}
arsort($heard);

/* ---------- Recent applications ---------- */
$recent = q("SELECT a.id, a.reference, a.full_name, a.status, a.created_at, c.title course_title, a.level
             FROM applications a JOIN courses c ON c.id = a.course_id $wA
             ORDER BY a.created_at DESC, a.id DESC LIMIT 8", $pA)->fetchAll();

$chartData = [
    'courses'   => array_column($courseRows, 'title'),
    'status'    => [
        'Pending'      => array_map('intval', array_column($courseRows, 'pending')),
        'Under Review' => array_map('intval', array_column($courseRows, 'review')),
        'Admitted'     => array_map('intval', array_column($courseRows, 'admitted')),
        'Waitlisted'   => array_map('intval', array_column($courseRows, 'waitlisted')),
        'Rejected'     => array_map('intval', array_column($courseRows, 'rejected')),
    ],
    'rate'      => array_map(fn($r) => (int) $r['total'] > 0 ? round((int) $r['admitted'] / (int) $r['total'] * 100, 1) : 0, $courseRows),
    'collected' => array_map('intval', array_column($courseRows, 'collected')),
    'outstanding' => array_map('intval', array_column($courseRows, 'outstanding')),
    'totals'    => [$t['pending'], $t['review'], $t['admitted'], $t['waitlisted'], $t['rejected']],
    'trendLabels' => $trendLabels,
    'trendValues' => $trendValues,
    'heardLabels' => array_keys(array_slice($heard, 0, 8, true)),
    'heardValues' => array_values(array_slice($heard, 0, 8, true)),
    'currency'  => cfg('currency'),
];

admin_header('Dashboard', 'dashboard');
?>
<form method="get" class="flex center gap wrap mb-2 no-print">
  <input type="hidden" name="course" value="<?= $courseId ?>">
  <label for="intake" class="muted small">Intake:</label>
  <select id="intake" name="intake" style="max-width:260px" onchange="this.form.submit()">
    <option value="0">All intakes</option>
    <?php foreach ($intakes as $i): ?>
      <option value="<?= (int) $i['id'] ?>" <?= $intakeId === (int) $i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <a class="btn btn-sm btn-light" href="<?= e(url('admin/applications.php')) ?>">View all applications</a>
</form>

<section class="grid kpis">
  <div class="card kpi"><div class="label">Applications</div><div class="value"><?= number_format($t['total']) ?></div><div class="sub"><?= $t['pending'] ?> awaiting review</div></div>
  <div class="card kpi success"><div class="label">Admitted</div><div class="value"><?= number_format($t['admitted']) ?></div><div class="sub"><?= $admitRate ?>% admission rate</div></div>
  <div class="card kpi accent"><div class="label">Fees collected</div><div class="value" style="font-size:1.45rem"><?= e(money($collected)) ?></div><div class="sub">All recorded payments</div></div>
  <div class="card kpi danger"><div class="label">Outstanding</div><div class="value" style="font-size:1.45rem"><?= e(money($outstanding)) ?></div><div class="sub">Balance from admitted</div></div>
  <div class="card kpi warn"><div class="label">Beginner / Advanced</div><div class="value"><?= (int) ($levels['Beginner'] ?? 0) ?> / <?= (int) ($levels['Advanced'] ?? 0) ?></div><div class="sub">Applications by level</div></div>
</section>

<?php if ($t['total'] === 0): ?>
  <div class="alert alert-info">No applications yet. Share the admission form link <strong><?= e(url('index.php')) ?></strong>
    (or run <code>php tools/seed_demo.php</code> to load demo data and preview the charts).</div>
<?php endif; ?>

<section class="grid charts-3">
  <div class="card">
    <div class="card-head"><h2>Applications by course</h2><span class="muted small">Stacked by status</span></div>
    <div class="chart-box"><canvas id="chCourseStatus" aria-label="Applications by course and status" role="img"></canvas></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Status overview</h2></div>
    <div class="chart-box"><canvas id="chStatus" aria-label="Application status breakdown" role="img"></canvas></div>
  </div>
</section>

<section class="grid charts-2 mt-2">
  <div class="card">
    <div class="card-head"><h2>Admission rate by course</h2><span class="muted small">Admitted &divide; applications</span></div>
    <div class="chart-box sm"><canvas id="chRate" aria-label="Admission rate by course" role="img"></canvas></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Payments by course</h2><span class="muted small">Collected vs outstanding (<?= e(cfg('currency')) ?>)</span></div>
    <div class="chart-box sm"><canvas id="chRevenue" aria-label="Payments by course" role="img"></canvas></div>
  </div>
</section>

<section class="grid charts-2 mt-2">
  <div class="card">
    <div class="card-head"><h2>Applications &ndash; last 30 days</h2></div>
    <div class="chart-box sm"><canvas id="chTrend" aria-label="Applications per day" role="img"></canvas></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>How applicants heard about us</h2></div>
    <div class="chart-box sm"><canvas id="chHeard" aria-label="Referral sources" role="img"></canvas></div>
  </div>
</section>

<section class="grid two-col mt-2">
  <div class="card">
    <div class="card-head"><h2>Course performance</h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Course</th><th class="text-right">Apps</th><th class="text-right">Admitted</th><th class="text-right">Rate</th><th class="text-right">Collected</th><th class="text-right">Outstanding</th></tr></thead>
        <tbody>
        <?php foreach ($courseRows as $r): $rate = (int) $r['total'] > 0 ? round((int) $r['admitted'] / (int) $r['total'] * 100) : 0; ?>
          <tr>
            <td><strong><?= e($r['title']) ?></strong></td>
            <td class="text-right"><?= (int) $r['total'] ?></td>
            <td class="text-right"><?= (int) $r['admitted'] ?></td>
            <td class="text-right"><?= $rate ?>%</td>
            <td class="text-right nowrap"><?= e(money($r['collected'])) ?></td>
            <td class="text-right nowrap"><?= e(money($r['outstanding'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$courseRows): ?><tr><td colspan="6" class="empty">No courses yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Latest applications</h2><a class="small" href="<?= e(url('admin/applications.php')) ?>">See all</a></div>
    <?php foreach ($recent as $r): ?>
      <a href="<?= e(url('admin/application_view.php?id=' . (int) $r['id'])) ?>" style="display:block;color:inherit;text-decoration:none;padding:10px 0;border-bottom:1px solid var(--border)">
        <div class="flex between center gap"><strong><?= e($r['full_name']) ?></strong><?= status_badge($r['status']) ?></div>
        <div class="muted small"><?= e($r['course_title']) ?> &middot; <?= e($r['level']) ?> &middot; <?= e(fmt_date($r['created_at'], 'd M, H:i')) ?></div>
      </a>
    <?php endforeach; ?>
    <?php if (!$recent): ?><div class="empty">Nothing yet.</div><?php endif; ?>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const D = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
(function () {
  if (typeof Chart === 'undefined') {
    document.querySelectorAll('.chart-box').forEach(function (b) {
      b.innerHTML = '<div class="empty">Charts need an internet connection to load Chart.js.</div>';
    });
    return;
  }
  const css = getComputedStyle(document.documentElement);
  const v = n => css.getPropertyValue(n).trim();
  const C = { primary: v('--primary'), accent: v('--accent'), warn: v('--warn'), danger: v('--danger'),
              success: v('--success'), purple: v('--purple'), info: v('--info'), navy: v('--navy-700'), muted: v('--muted'), border: v('--border') };
  const statusColors = { 'Pending': C.warn, 'Under Review': C.info, 'Admitted': C.success, 'Waitlisted': C.purple, 'Rejected': C.danger };
  Chart.defaults.font.family = v('--font');
  Chart.defaults.color = C.muted;
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  const grid = { color: C.border, drawBorder: false };
  const base = { responsive: true, maintainAspectRatio: false };
  const nf = n => Number(n).toLocaleString('en-US');
  const wrap = s => s.length > 16 ? s.split(' ').reduce((a, w) => { const l = a[a.length - 1]; if ((l + ' ' + w).trim().length > 16) a.push(w); else a[a.length - 1] = (l + ' ' + w).trim(); return a; }, ['']) : s;

  // 1. Applications by course (stacked, by status)
  new Chart('chCourseStatus', {
    type: 'bar',
    data: { labels: D.courses.map(wrap), datasets: Object.keys(D.status).map(k => ({ label: k, data: D.status[k], backgroundColor: statusColors[k], borderRadius: 4, borderSkipped: false })) },
    options: { ...base, scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid } },
               plugins: { legend: { position: 'bottom' } } }
  });

  // 2. Status doughnut
  new Chart('chStatus', {
    type: 'doughnut',
    data: { labels: Object.keys(D.status), datasets: [{ data: D.totals, backgroundColor: Object.keys(D.status).map(k => statusColors[k]), borderWidth: 2, borderColor: '#fff' }] },
    options: { ...base, cutout: '64%', plugins: { legend: { position: 'bottom' } } }
  });

  // 3. Admission rate
  new Chart('chRate', {
    type: 'bar',
    data: { labels: D.courses.map(wrap), datasets: [{ label: 'Admission rate (%)', data: D.rate, backgroundColor: C.primary, borderRadius: 6 }] },
    options: { ...base, scales: { y: { beginAtZero: true, max: 100, ticks: { callback: x => x + '%' }, grid }, x: { grid: { display: false } } },
               plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.parsed.y + '% admitted' } } } }
  });

  // 4. Payments by course
  new Chart('chRevenue', {
    type: 'bar',
    data: { labels: D.courses.map(wrap), datasets: [
      { label: 'Collected', data: D.collected, backgroundColor: C.accent, borderRadius: 6 },
      { label: 'Outstanding', data: D.outstanding, backgroundColor: C.danger, borderRadius: 6 } ] },
    options: { ...base, scales: { y: { beginAtZero: true, ticks: { callback: x => x >= 1e6 ? (x / 1e6) + 'M' : (x >= 1e3 ? (x / 1e3) + 'K' : x) }, grid }, x: { grid: { display: false } } },
               plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + D.currency + ' ' + nf(c.parsed.y) } } } }
  });

  // 5. Trend
  new Chart('chTrend', {
    type: 'line',
    data: { labels: D.trendLabels, datasets: [{ label: 'Applications', data: D.trendValues, borderColor: C.primary, backgroundColor: C.primary + '22', fill: true, tension: .35, pointRadius: 2 }] },
    options: { ...base, scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid }, x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } } }, plugins: { legend: { display: false } } }
  });

  // 6. Heard about
  new Chart('chHeard', {
    type: 'bar',
    data: { labels: D.heardLabels, datasets: [{ label: 'Applicants', data: D.heardValues, backgroundColor: C.navy, borderRadius: 6 }] },
    options: { ...base, indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid }, y: { grid: { display: false } } }, plugins: { legend: { display: false } } }
  });
})();
</script>
<?php admin_footer();
