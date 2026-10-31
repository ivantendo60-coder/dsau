<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
require_admin();

$valid_date = fn(string $d): bool => $d === '' || (strtotime($d) && date('Y-m-d', strtotime($d)) === $d);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $iid    = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name     = trim((string) ($_POST['name'] ?? ''));
        $start    = trim((string) ($_POST['start_date'] ?? ''));
        $deadline = trim((string) ($_POST['application_deadline'] ?? ''));
        $open     = !empty($_POST['is_open']) ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 120 || !$valid_date($start) || !$valid_date($deadline)) {
            flash('danger', 'Enter an intake name and valid dates.');
        } elseif ($iid) {
            q('UPDATE intakes SET name=?, start_date=?, application_deadline=?, is_open=? WHERE id=?', [$name, $start ?: null, $deadline ?: null, $open, $iid]);
            flash('success', 'Intake updated.');
        } else {
            q('INSERT INTO intakes (name, start_date, application_deadline, is_open) VALUES (?,?,?,?)', [$name, $start ?: null, $deadline ?: null, $open]);
            flash('success', 'Intake created.');
        }
    } elseif ($action === 'toggle') {
        q('UPDATE intakes SET is_open = 1 - is_open WHERE id = ?', [$iid]);
        flash('success', 'Intake status changed.');
    } elseif ($action === 'delete' && (admin()['role'] ?? '') === 'admin') {
        $n = (int) q('SELECT COUNT(*) FROM applications WHERE intake_id = ?', [$iid])->fetchColumn();
        if ($n > 0) {
            flash('warn', "This intake has $n application(s) so it cannot be deleted. Close it instead.");
        } else {
            q('DELETE FROM intakes WHERE id = ?', [$iid]);
            flash('success', 'Intake deleted.');
        }
    }
    redirect(url('admin/intakes.php'));
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit   = $editId ? (q('SELECT * FROM intakes WHERE id = ?', [$editId])->fetch() ?: null) : null;
$intakes = q('SELECT i.*, (SELECT COUNT(*) FROM applications a WHERE a.intake_id = i.id) AS apps FROM intakes i ORDER BY i.id DESC')->fetchAll();

admin_header('Intakes', 'intakes');
?>
<div class="grid two-col">
  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Intake</th><th>Starts</th><th>Deadline</th><th class="text-right">Applications</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($intakes as $i): ?>
          <tr>
            <td><strong><?= e($i['name']) ?></strong></td>
            <td class="nowrap"><?= e(fmt_date($i['start_date'])) ?></td>
            <td class="nowrap"><?= e(fmt_date($i['application_deadline'])) ?></td>
            <td class="text-right"><?= (int) $i['apps'] ?></td>
            <td><?= $i['is_open'] ? '<span class="badge badge-success">Open</span>' : '<span class="badge badge-muted">Closed</span>' ?></td>
            <td class="actions">
              <a class="btn btn-sm btn-light" href="?edit=<?= (int) $i['id'] ?>">Edit</a>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                <button class="btn btn-sm btn-outline" type="submit"><?= $i['is_open'] ? 'Close' : 'Open' ?></button></form>
              <?php if ((admin()['role'] ?? '') === 'admin'): ?>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete this intake?">Delete</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$intakes): ?><tr><td colspan="6" class="empty">No intakes yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <aside class="card">
    <h2><?= $edit ? 'Edit intake' : 'New intake' ?></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="field mb-2"><label for="name">Name</label><input type="text" id="name" name="name" maxlength="120" placeholder="e.g. May 2027 Intake" value="<?= e($edit['name'] ?? '') ?>" required></div>
      <div class="field mb-2"><label for="start_date">Start date</label><input type="date" id="start_date" name="start_date" value="<?= e($edit['start_date'] ?? '') ?>"></div>
      <div class="field mb-2"><label for="application_deadline">Application deadline</label><input type="date" id="application_deadline" name="application_deadline" value="<?= e($edit['application_deadline'] ?? '') ?>">
        <div class="hint">The form closes automatically after this date.</div></div>
      <div class="field mb-2"><label class="choice"><input type="checkbox" name="is_open" value="1" <?= ($edit['is_open'] ?? 1) ? 'checked' : '' ?>> Open for applications</label></div>
      <div class="flex gap">
        <button class="btn" type="submit"><?= $edit ? 'Save changes' : 'Create intake' ?></button>
        <?php if ($edit): ?><a class="btn btn-light" href="<?= e(url('admin/intakes.php')) ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </aside>
</div>
<?php admin_footer();
