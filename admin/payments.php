<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/notifications.php';
require __DIR__ . '/../includes/layout.php';
require_admin();

$courseId = dashboard_course_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $appId  = (int) ($_POST['application_id'] ?? 0);
        $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
        $method = (string) ($_POST['method'] ?? '');
        $paidOn = (string) ($_POST['paid_on'] ?? '');
        $refNo  = trim((string) ($_POST['reference_no'] ?? ''));
        $notes  = trim((string) ($_POST['notes'] ?? ''));
        $okDate = (bool) strtotime($paidOn) && date('Y-m-d', strtotime($paidOn)) === $paidOn;
        $exists = (int) q('SELECT COUNT(*) FROM applications WHERE id = ?', [$appId])->fetchColumn() > 0;

        if ($exists && $amount > 0 && $okDate && in_array($method, ['Mobile Money', 'Bank Transfer', 'Cash', 'Other'], true)) {
            q('INSERT INTO payments (application_id, amount, method, reference_no, paid_on, notes, recorded_by) VALUES (?,?,?,?,?,?,?)',
              [$appId, $amount, $method, $refNo !== '' ? mb_substr($refNo, 0, 80) : null, $paidOn, $notes !== '' ? mb_substr($notes, 0, 255) : null, admin()['id']]);
            notify_application_payment_status($appId, 'A payment of ' . money($amount) . ' was recorded.');
            flash('success', 'Payment of ' . money($amount) . ' recorded.');
        } else {
            flash('danger', 'Choose an applicant and enter a valid amount, method and date.');
        }
    } elseif ($action === 'delete') {
      $paymentId = (int) ($_POST['payment_id'] ?? 0);
      $applicationId = (int) q('SELECT application_id FROM payments WHERE id = ?', [$paymentId])->fetchColumn();
      $deleted = q('DELETE FROM payments WHERE id = ?', [$paymentId])->rowCount();
      if ($deleted > 0 && $applicationId > 0) {
        notify_application_payment_status($applicationId, 'A payment record on your account was adjusted.');
        flash('success', 'Payment removed.');
      }
    }
    redirect(url('admin/payments.php'));
}

/* ---------- Filters ---------- */
$where  = [];
$params = [];
if (($s = trim((string) ($_GET['q'] ?? ''))) !== '') {
    $where[] = '(a.full_name LIKE ? OR a.reference LIKE ? OR p.reference_no LIKE ?)';
    array_push($params, "%$s%", "%$s%", "%$s%");
}
if ($courseId > 0) {
    $where[] = 'a.course_id = ?';
    $params[] = $courseId;
}
if (!empty($_GET['method']) && in_array($_GET['method'], ['Mobile Money', 'Bank Transfer', 'Cash', 'Other'], true)) {
    $where[] = 'p.method = ?';
    $params[] = $_GET['method'];
}
if (!empty($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['from'])) {
    $where[] = 'p.paid_on >= ?';
    $params[] = $_GET['from'];
}
if (!empty($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['to'])) {
    $where[] = 'p.paid_on <= ?';
    $params[] = $_GET['to'];
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 20;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$from    = 'FROM payments p JOIN applications a ON a.id = p.application_id JOIN courses c ON c.id = a.course_id';

$total    = (int) q("SELECT COUNT(*) $from $w", $params)->fetchColumn();
$sum      = (int) q("SELECT COALESCE(SUM(p.amount),0) $from $w", $params)->fetchColumn();
$payments = q("SELECT p.*, a.full_name, a.reference, a.id AS app_id, c.title AS course_title $from $w ORDER BY p.paid_on DESC, p.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage), $params)->fetchAll();

// Applicants who can still owe money (not rejected) for the "record payment" selector
$payableSql = "SELECT a.id, a.full_name, a.reference, a.fee_due, c.title, COALESCE(pp.paid,0) AS paid
              FROM applications a JOIN courses c ON c.id = a.course_id
              LEFT JOIN (SELECT application_id, SUM(amount) paid FROM payments GROUP BY application_id) pp ON pp.application_id = a.id
              WHERE a.status <> 'Rejected' AND COALESCE(pp.paid,0) < a.fee_due";
$payableParams = [];
if ($courseId > 0) {
    $payableSql .= ' AND a.course_id = ?';
    $payableParams[] = $courseId;
}
$payable = q($payableSql . " ORDER BY (a.status = 'Admitted') DESC, a.full_name LIMIT 500", $payableParams)->fetchAll();
$query = array_filter($_GET, fn($v, $k) => $v !== '' && $k !== 'page', ARRAY_FILTER_USE_BOTH);
$query['course'] = $courseId;

admin_header('Payments', 'payments');
?>
<div class="payments-page">
  <div class="payments-intro">
    <div>
      <h2>Payment records</h2>
      <p class="muted mb-0">Review payments received and record applicant installments.</p>
    </div>
    <a class="btn" href="#record-payment">+ Record payment</a>
  </div>

  <section class="grid payment-summary" aria-label="Payment summary">
    <div class="card payment-stat">
      <span class="muted small">Payments found</span>
      <strong><?= number_format($total) ?></strong>
      <span class="muted small">Matching current filters</span>
    </div>
    <div class="card payment-stat received">
      <span class="muted small">Amount received</span>
      <strong><?= e(money($sum)) ?></strong>
      <span class="muted small">Matching current filters</span>
    </div>
    <div class="card payment-stat balance">
      <span class="muted small">Applicants with a balance</span>
      <strong><?= number_format(count($payable)) ?><?= count($payable) === 500 ? '+' : '' ?></strong>
      <span class="muted small">Available to record a payment</span>
    </div>
  </section>

  <div class="grid two-col payments-layout">
    <div class="grid payments-history">
      <form method="get" class="card payment-filters no-print">
      <div class="payment-section-heading">
        <div><h3>Find payments</h3><p class="muted small mb-0">Filter by applicant, method, or payment date.</p></div>
      </div>
      <div class="filters">
        <div class="field grow"><label for="q">Search</label><input type="text" id="q" name="q" placeholder="Applicant, reference or receipt no." value="<?= e($_GET['q'] ?? '') ?>"></div>
        <div class="field"><label for="method">Method</label><select id="method" name="method"><option value="">All</option>
          <?php foreach (['Mobile Money', 'Bank Transfer', 'Cash', 'Other'] as $m): ?><option <?= ($_GET['method'] ?? '') === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($_GET['from'] ?? '') ?>"></div>
        <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($_GET['to'] ?? '') ?>"></div>
        <div class="flex gap wrap"><button class="btn" type="submit">Apply filters</button><a class="btn btn-light" href="<?= e(url('admin/payments.php?' . http_build_query(['course' => $courseId]))) ?>">Clear</a></div>
      </div>
      </form>

      <section class="card payment-history-card">
      <div class="card-head">
        <div><h3>Payment history</h3><span class="muted small"><?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?></span></div>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Date</th><th>Applicant</th><th class="hide-sm">Course</th><th class="text-right">Amount</th><th>Method</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td class="nowrap"><?= e(fmt_date($p['paid_on'])) ?></td>
              <td><a href="<?= e(url('admin/application_view.php?id=' . (int) $p['app_id'])) ?>"><strong><?= e($p['full_name']) ?></strong></a>
                  <div class="muted small"><?= e($p['reference']) ?><?= $p['reference_no'] ? ' · ' . e($p['reference_no']) : '' ?></div></td>
              <td class="hide-sm"><?= e($p['course_title']) ?></td>
              <td class="text-right nowrap"><?= e(number_format((int) $p['amount'])) ?></td>
              <td><?= e($p['method']) ?></td>
              <td class="actions"><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-sm btn-light" type="submit" data-confirm="Remove this payment record?">Remove</button></form></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$payments): ?><tr><td colspan="6" class="empty">
            <strong>No payments found</strong><br><span class="muted small">Try changing your filters or record a new payment.</span>
          </td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?= pagination($total, $page, $perPage, $query) ?>
      </section>
    </div>

  <aside class="card payment-entry no-print" id="record-payment">
    <div class="payment-section-heading">
      <div class="payment-entry-icon" aria-hidden="true">+</div>
      <div><h3>Record a payment</h3><p class="muted small mb-0">Add an installment to an applicant's account.</p></div>
    </div>
    <form method="post" class="payment-entry-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div class="field mb-2"><label for="application_id">Applicant</label>
        <select id="application_id" name="application_id" required>
          <option value="">Select applicant with a balance</option>
          <?php foreach ($payable as $a): ?>
            <option value="<?= (int) $a['id'] ?>"><?= e($a['full_name']) ?> &mdash; <?= e($a['title']) ?> (balance <?= e(number_format((int) $a['fee_due'] - (int) $a['paid'])) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="field mb-2"><label for="amount">Amount (<?= e(cfg('currency')) ?>)</label><input type="number" id="amount" name="amount" min="1" step="1" required></div>
      <div class="field mb-2"><label for="paid_on">Date paid</label><input type="date" id="paid_on" name="paid_on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
      <div class="field mb-2"><label for="method2">Method</label>
        <select id="method2" name="method"><?php foreach (['Mobile Money', 'Bank Transfer', 'Cash', 'Other'] as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
      <div class="field mb-2"><label for="reference_no">Transaction / receipt no.</label><input type="text" id="reference_no" name="reference_no" maxlength="80"></div>
      <div class="field mb-2"><label for="notes">Note</label><input type="text" id="notes" name="notes" maxlength="255" placeholder="e.g. 2nd installment"></div>
      <button class="btn btn-block" type="submit">Save payment</button>
    </form>
    <?php if (!$payable): ?><p class="payment-entry-note muted small">No eligible applicants with an outstanding balance were found for this course.</p><?php endif; ?>
  </aside>
</div>
</div>
<?php admin_footer();
