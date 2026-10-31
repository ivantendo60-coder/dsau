<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/notifications.php';
require __DIR__ . '/../includes/layout.php';
require_admin();

$courseId = dashboard_course_id();
$id = (int) ($_GET['id'] ?? 0);

function load_application(int $id): ?array
{
    $row = q('SELECT a.*, c.title AS course_title, c.duration_months, i.name AS intake_name, ad.name AS reviewer_name
              FROM applications a
              JOIN courses c ON c.id = a.course_id
              JOIN intakes i ON i.id = a.intake_id
              LEFT JOIN admins ad ON ad.id = a.reviewed_by
              WHERE a.id = ?', [$id])->fetch();
    return $row ?: null;
}

$app = load_application($id);
if (!$app || ($courseId > 0 && (int) $app['course_id'] !== $courseId)) {
    flash('danger', 'Application not found.');
    redirect(url('admin/applications.php?' . http_build_query(['course' => $courseId])));
}
$self = url('admin/application_view.php?id=' . $id);

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'review') {
        $status = (string) ($_POST['status'] ?? '');
        $notes  = trim((string) ($_POST['admin_notes'] ?? ''));
        if (in_array($status, APP_STATUSES, true)) {
            q('UPDATE applications SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
              [$status, $notes !== '' ? $notes : null, admin()['id'], $id]);
            if ($status !== $app['status']) {
                notify_application_status_changed($id, (string) $app['status']);
            }
            flash('success', 'Application updated to "' . $status . '".');
        }
    } elseif ($action === 'add_payment') {
        $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
        $method = (string) ($_POST['method'] ?? '');
        $refNo  = trim((string) ($_POST['reference_no'] ?? ''));
        $paidOn = (string) ($_POST['paid_on'] ?? '');
        $notes  = trim((string) ($_POST['notes'] ?? ''));
        $okDate = (bool) strtotime($paidOn) && date('Y-m-d', strtotime($paidOn)) === $paidOn;
        if ($amount > 0 && in_array($method, ['Mobile Money', 'Bank Transfer', 'Cash', 'Other'], true) && $okDate) {
            q('INSERT INTO payments (application_id, amount, method, reference_no, paid_on, notes, recorded_by) VALUES (?,?,?,?,?,?,?)',
              [$id, $amount, $method, $refNo !== '' ? mb_substr($refNo, 0, 80) : null, $paidOn, $notes !== '' ? mb_substr($notes, 0, 255) : null, admin()['id']]);
            notify_application_payment_status($id, 'A payment of ' . money($amount) . ' was recorded.');
            flash('success', 'Payment of ' . money($amount) . ' recorded.');
        } else {
            flash('danger', 'Enter a valid amount, method and date for the payment.');
        }
    } elseif ($action === 'delete_payment') {
      $deleted = q('DELETE FROM payments WHERE id = ? AND application_id = ?', [(int) ($_POST['payment_id'] ?? 0), $id])->rowCount();
      if ($deleted > 0) {
        notify_application_payment_status($id, 'A payment record on your account was adjusted.');
        flash('success', 'Payment removed.');
      }
    } elseif ($action === 'update_fee') {
        $fee = (int) preg_replace('/\D/', '', (string) ($_POST['fee_due'] ?? '0'));
        q('UPDATE applications SET fee_due = ? WHERE id = ?', [$fee, $id]);
      if ($fee !== (int) $app['fee_due']) {
        notify_application_payment_status($id, 'Your tuition fee was updated.');
      }
        flash('success', 'Fee updated.');
    } elseif ($action === 'delete_application') {
        if ((admin()['role'] ?? '') === 'admin') {
            q('DELETE FROM applications WHERE id = ?', [$id]);
            flash('success', 'Application deleted.');
            redirect(url('admin/applications.php'));
        }
        flash('danger', 'Only administrators can delete applications.');
    }
    redirect($self);
}

$payments = q('SELECT p.*, ad.name AS recorder FROM payments p LEFT JOIN admins ad ON ad.id = p.recorded_by WHERE p.application_id = ? ORDER BY p.paid_on DESC, p.id DESC', [$id])->fetchAll();
$paid     = (int) array_sum(array_column($payments, 'amount'));
$due      = (int) $app['fee_due'];
$balance  = max($due - $paid, 0);
$pct      = $due > 0 ? min(100, (int) round($paid / $due * 100)) : 0;

$edu   = $app['education'] === 'Other' && $app['education_other'] ? 'Other: ' . $app['education_other'] : $app['education'];
$emp   = $app['employment'] === 'Other' && $app['employment_other'] ? 'Other: ' . $app['employment_other'] : $app['employment'];
$heard = $app['heard_about'] . ($app['heard_about_other'] ? ' (' . $app['heard_about_other'] . ')' : '');
$age   = (int) date_diff(date_create($app['date_of_birth']), date_create('today'))->y;
$wa    = 'https://wa.me/' . preg_replace('/\D/', '', (string) preg_replace('/^0/', '256', $app['phone']));

admin_header('Application ' . $app['reference'], 'applications');
?>
<div class="flex between center wrap gap mb-2 no-print">
  <a class="btn btn-sm btn-light" href="<?= e(url('admin/applications.php?' . http_build_query(['course' => $courseId]))) ?>">&larr; All applications</a>
  <div class="flex gap wrap">
    <a class="btn btn-sm btn-accent" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp applicant</a>
    <a class="btn btn-sm btn-light" href="mailto:<?= e($app['email']) ?>">Email</a>
    <button class="btn btn-sm btn-light" type="button" onclick="window.print()">Print</button>
  </div>
</div>

<div class="grid two-col">
  <div class="grid">
    <section class="card">
      <div class="card-head">
        <div><h2 class="mb-0"><?= e($app['full_name']) ?></h2><div class="muted small"><?= e($app['reference']) ?> &middot; applied <?= e(fmt_date($app['created_at'], 'd M Y, H:i')) ?></div></div>
        <?= status_badge($app['status']) ?>
      </div>
      <dl class="detail-grid mb-0">
        <div><dt>Course</dt><dd><?= e($app['course_title']) ?></dd></div>
        <div><dt>Level &amp; intake</dt><dd><?= e($app['level']) ?> &middot; <?= e($app['intake_name']) ?></dd></div>
        <div><dt>Phone / WhatsApp</dt><dd><?= e($app['phone']) ?></dd></div>
        <div><dt>Email</dt><dd><?= e($app['email']) ?></dd></div>
        <div><dt>Gender</dt><dd><?= e($app['gender']) ?></dd></div>
        <div><dt>Date of birth</dt><dd><?= e(fmt_date($app['date_of_birth'])) ?> (<?= $age ?> yrs)</dd></div>
        <div><dt>Location</dt><dd><?= e($app['location']) ?></dd></div>
        <div><dt>Highest education</dt><dd><?= e($edu) ?></dd></div>
        <div><dt>Employment status</dt><dd><?= e($emp) ?></dd></div>
        <div><dt>Heard about DSAU via</dt><dd><?= e($heard) ?></dd></div>
      </dl>
    </section>

    <section class="card">
      <h3>Entry requirements &amp; acknowledgements</h3>
      <div class="flex gap wrap">
        <?php foreach (['has_laptop' => 'Laptop', 'has_internet' => 'Steady internet', 'has_smartphone' => 'Smartphone', 'has_basic_skills' => 'Basic computer skills'] as $k => $label): ?>
          <span class="badge badge-<?= $app[$k] === 'Yes' ? 'success' : 'danger' ?>"><?= e($label) ?>: <?= e($app[$k]) ?></span>
        <?php endforeach; ?>
      </div>
      <p class="small muted mb-0 mt-1">
        Fees acknowledged: <strong><?= $app['ack_fees'] ? 'Yes' : 'No' ?></strong> &middot;
        Understands installments: <strong><?= $app['ack_installments'] ? 'Yes' : 'No' ?></strong>
      </p>
    </section>

    <section class="card">
      <div class="card-head"><h3>Payments</h3><?= pay_badge(pay_status($due, $paid)) ?></div>
      <div class="flex between small muted mb-0"><span>Paid <?= e(money($paid)) ?></span><span>Fee <?= e(money($due)) ?></span></div>
      <div class="progress mt-1" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div style="width:<?= $pct ?>%"></div></div>
      <p class="mt-1"><strong>Balance: <?= e(money($balance)) ?></strong></p>

      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Date</th><th class="text-right">Amount</th><th>Method</th><th>Reference</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td class="nowrap"><?= e(fmt_date($p['paid_on'])) ?></td>
              <td class="text-right nowrap"><?= e(number_format((int) $p['amount'])) ?></td>
              <td><?= e($p['method']) ?></td>
              <td><?= e($p['reference_no'] ?: '-') ?><?php if ($p['notes']): ?><div class="muted small"><?= e($p['notes']) ?></div><?php endif; ?></td>
              <td class="actions">
                <form method="post" class="inline-form"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn-sm btn-light" type="submit" data-confirm="Remove this payment record?">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$payments): ?><tr><td colspan="5" class="empty">No payments recorded yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>

      <form method="post" class="mt-2 no-print">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_payment">
        <h3>Record a payment</h3>
        <div class="form-grid">
          <div class="field"><label for="amount">Amount (<?= e(cfg('currency')) ?>)</label><input type="number" id="amount" name="amount" min="1" step="1" value="<?= $balance ?: '' ?>" required></div>
          <div class="field"><label for="paid_on">Date paid</label><input type="date" id="paid_on" name="paid_on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
          <div class="field"><label for="method">Method</label>
            <select id="method" name="method"><?php foreach (['Mobile Money', 'Bank Transfer', 'Cash', 'Other'] as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="reference_no">Transaction / receipt no.</label><input type="text" id="reference_no" name="reference_no" maxlength="80"></div>
          <div class="field full"><label for="notes">Note</label><input type="text" id="notes" name="notes" maxlength="255" placeholder="e.g. 1st installment"></div>
        </div>
        <button class="btn mt-1" type="submit">Save payment</button>
      </form>
    </section>
  </div>

  <aside class="grid no-print">
    <section class="card">
      <h3>Admission decision</h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="review">
        <div class="field mb-2"><label for="status">Status</label>
          <select id="status" name="status"><?php foreach (APP_STATUSES as $s): ?><option <?= $app['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div class="field mb-2"><label for="admin_notes">Internal notes</label><textarea id="admin_notes" name="admin_notes" placeholder="Visible to staff only"><?= e($app['admin_notes']) ?></textarea></div>
        <button class="btn btn-block" type="submit">Save decision</button>
        <?php if ($app['reviewed_at']): ?><p class="muted small mb-0 mt-1">Last reviewed <?= e(fmt_date($app['reviewed_at'], 'd M Y, H:i')) ?><?= $app['reviewer_name'] ? ' by ' . e($app['reviewer_name']) : '' ?></p><?php endif; ?>
      </form>
    </section>

    <section class="card">
      <h3>Tuition fee</h3>
      <form method="post" class="flex gap">
        <?= csrf_field() ?><input type="hidden" name="action" value="update_fee">
        <input type="number" name="fee_due" min="0" step="1000" value="<?= $due ?>" aria-label="Fee due">
        <button class="btn btn-light" type="submit">Update</button>
      </form>
      <p class="hint">Use this for discounts or custom pricing. Set from the course fee when the applicant applied.</p>
    </section>

    <?php if ((admin()['role'] ?? '') === 'admin'): ?>
    <section class="card">
      <h3>Danger zone</h3>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_application">
        <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete this application and all its payments? This cannot be undone.">Delete application</button>
      </form>
    </section>
    <?php endif; ?>
  </aside>
</div>
<?php admin_footer();
