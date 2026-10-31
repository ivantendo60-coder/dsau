<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/notifications.php';
require __DIR__ . '/../includes/layout.php';
require_admin();

$courseId = dashboard_course_id();

/* ---------- Bulk status update ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    csrf_check();
    $ids    = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $status = (string) ($_POST['bulk_status'] ?? '');
    if ($ids && in_array($status, APP_STATUSES, true)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
      $previousStatuses = q("SELECT id, status FROM applications WHERE id IN ($in)", $ids)->fetchAll(PDO::FETCH_KEY_PAIR);
        q("UPDATE applications SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id IN ($in)", array_merge([$status, admin()['id']], $ids));
      foreach ($previousStatuses as $applicationId => $previousStatus) {
        if ($previousStatus !== $status) {
          notify_application_status_changed((int) $applicationId, (string) $previousStatus);
        }
      }
        flash('success', count($ids) . ' application(s) marked as ' . $status . '.');
    } else {
        flash('warn', 'Select at least one application and a status.');
    }
    redirect(url('admin/applications.php?' . http_build_query(array_filter($_GET))));
}

$perPage = 15;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$filterInput = $_GET;
$filterInput['course'] = $courseId;
[$where, $params] = build_application_filters($filterInput);

$total = (int) q('SELECT COUNT(*) ' . applications_base_sql() . ' ' . $where, $params)->fetchColumn();
$offset = ($page - 1) * $perPage;
$rows = q('SELECT a.id, a.reference, a.full_name, a.phone, a.email, a.level, a.status, a.fee_due, a.created_at,
                  c.title AS course_title, i.name AS intake_name, COALESCE(p.paid,0) AS paid '
        . applications_base_sql() . " $where ORDER BY a.created_at DESC, a.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset, $params)->fetchAll();

$intakes = q('SELECT id, name FROM intakes ORDER BY id DESC')->fetchAll();
$query   = array_filter($_GET, fn($v, $k) => $v !== '' && $k !== 'page', ARRAY_FILTER_USE_BOTH);
$query['course'] = $courseId;

admin_header('Applications', 'applications');
?>
<form method="get" class="card mb-2 no-print">
  <div class="filters">
    <div class="field grow"><label for="q">Search</label><input type="text" id="q" name="q" placeholder="Name, email, phone or reference" value="<?= e($_GET['q'] ?? '') ?>"></div>
    <div class="field"><label for="intake">Intake</label>
      <select id="intake" name="intake"><option value="">All intakes</option>
        <?php foreach ($intakes as $i): ?><option value="<?= (int) $i['id'] ?>" <?= (int) ($_GET['intake'] ?? 0) === (int) $i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="status">Status</label>
      <select id="status" name="status"><option value="">Any status</option>
        <?php foreach (APP_STATUSES as $s): ?><option <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="level">Level</label>
      <select id="level" name="level"><option value="">Any level</option>
        <?php foreach (['Beginner', 'Advanced'] as $l): ?><option <?= ($_GET['level'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="payment">Payment</label>
      <select id="payment" name="payment"><option value="">Any</option>
        <?php foreach (['Unpaid', 'Partial', 'Paid'] as $l): ?><option <?= ($_GET['payment'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($_GET['from'] ?? '') ?>"></div>
    <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($_GET['to'] ?? '') ?>"></div>
    <div class="flex gap">
      <button class="btn" type="submit">Filter</button>
      <a class="btn btn-light" href="<?= e(url('admin/applications.php')) ?>">Reset</a>
    </div>
  </div>
</form>

<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk">
  <div class="card-head">
    <h2><?= number_format($total) ?> application<?= $total === 1 ? '' : 's' ?></h2>
    <div class="flex gap wrap center no-print">
      <select name="bulk_status" style="width:auto" aria-label="Bulk status">
        <option value="">Mark selected as&hellip;</option>
        <?php foreach (APP_STATUSES as $s): ?><option><?= e($s) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline" type="submit" data-confirm="Update the status of all selected applications?">Apply</button>
      <a class="btn btn-sm btn-light" href="<?= e(url('admin/export.php?' . http_build_query($query))) ?>">Export CSV</a>
    </div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th><input type="checkbox" id="chk-all" aria-label="Select all" onclick="document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked)"></th>
        <th>Applicant</th><th>Course</th><th>Level</th><th>Status</th><th>Payment</th><th class="hide-sm">Applied</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $ps = pay_status((int) $r['fee_due'], (int) $r['paid']); ?>
        <tr>
          <td><input class="chk" type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="Select <?= e($r['full_name']) ?>"></td>
          <td>
            <a href="<?= e(url('admin/application_view.php?id=' . (int) $r['id'])) ?>"><strong><?= e($r['full_name']) ?></strong></a>
            <div class="muted small"><?= e($r['reference']) ?> &middot; <?= e($r['phone']) ?></div>
          </td>
          <td><?= e($r['course_title']) ?><div class="muted small"><?= e($r['intake_name']) ?></div></td>
          <td><?= e($r['level']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td><?= pay_badge($ps) ?><div class="muted small nowrap"><?= e(number_format((int) $r['paid'])) ?> / <?= e(number_format((int) $r['fee_due'])) ?></div></td>
          <td class="hide-sm nowrap"><?= e(fmt_date($r['created_at'])) ?></td>
          <td class="actions"><a class="btn btn-sm btn-light" href="<?= e(url('admin/application_view.php?id=' . (int) $r['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="empty">No applications match your filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $page, $perPage, $query) ?>
</form>
<?php admin_footer();
