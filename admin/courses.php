<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $cid    = (int) ($_POST['id'] ?? 0);

    if ($action === 'toggle') {
        q('UPDATE courses SET is_active = 1 - is_active WHERE id = ?', [$cid]);
        flash('success', 'Course visibility updated. Inactive courses no longer appear on the admission form.');
    } elseif ($action === 'delete') {
        if ((admin()['role'] ?? '') !== 'admin') {
            flash('danger', 'Only administrators can delete courses.');
        } else {
            $n = (int) q('SELECT COUNT(*) FROM applications WHERE course_id = ?', [$cid])->fetchColumn();
            if ($n > 0) {
                flash('warn', "This course has $n application(s), so it cannot be deleted. Deactivate it instead to hide it from the form.");
            } else {
                q('DELETE FROM courses WHERE id = ?', [$cid]);
                flash('success', 'Course deleted.');
            }
        }
    } elseif ($action === 'move') {
        $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        $ids = q('SELECT id FROM courses ORDER BY sort_order, title')->fetchAll(PDO::FETCH_COLUMN);
        $pos = array_search((string) $cid, array_map('strval', $ids), true);
        if ($pos !== false && isset($ids[$pos + $dir])) {
            [$ids[$pos], $ids[$pos + $dir]] = [$ids[$pos + $dir], $ids[$pos]];
            foreach ($ids as $order => $courseId) {
                q('UPDATE courses SET sort_order = ? WHERE id = ?', [$order + 1, $courseId]);
            }
        }
    }
    redirect(url('admin/courses.php'));
}

$courses =q('SELECT c.*,
                (SELECT COUNT(*) FROM applications a WHERE a.course_id = c.id) AS apps,
                (SELECT COUNT(*) FROM applications a WHERE a.course_id = c.id AND a.status = \'Admitted\') AS admitted
              FROM courses c ORDER BY c.sort_order, c.title')->fetchAll();

admin_header('Courses', 'courses');
?>
<div class="flex between center wrap gap mb-2">
  <p class="muted mb-0">Courses shown here appear in the admission form when they are <strong>Active</strong>.</p>
  <a class="btn" href="<?= e(url('admin/course_form.php')) ?>">+ Add course</a>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Order</th><th>Course</th><th>Duration</th><th class="text-right">Beginner</th><th class="text-right">Advanced</th><th class="text-right">Apps</th><th class="text-right">Admitted</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($courses as $c): ?>
        <tr>
          <td class="nowrap">
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-light" name="dir" value="up" type="submit" aria-label="Move up">&uarr;</button>
              <button class="btn btn-sm btn-light" name="dir" value="down" type="submit" aria-label="Move down">&darr;</button>
            </form>
          </td>
          <td>
            <strong><?= e($c['title']) ?></strong>
            <?php $topics = array_filter(array_map('trim', preg_split('/\R/', (string) $c['topics']) ?: [])); ?>
            <?php if ($topics): ?><div class="muted small"><?= e(implode(' · ', array_slice($topics, 0, 4))) ?></div><?php endif; ?>
          </td>
          <td class="nowrap"><?= (int) $c['duration_months'] ?> months</td>
          <td class="text-right nowrap"><?= e(number_format((int) $c['fee_beginner'])) ?></td>
          <td class="text-right nowrap"><?= e(number_format((int) $c['fee_advanced'])) ?></td>
          <td class="text-right"><?= (int) $c['apps'] ?></td>
          <td class="text-right"><?= (int) $c['admitted'] ?><?= $c['capacity'] ? ' / ' . (int) $c['capacity'] : '' ?></td>
          <td><?= $c['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
          <td class="actions">
            <a class="btn btn-sm btn-light" href="<?= e(url('admin/course_form.php?id=' . (int) $c['id'])) ?>">Edit</a>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-outline" type="submit"><?= $c['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
            <?php if ((admin()['role'] ?? '') === 'admin'): ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete &quot;<?= e($c['title']) ?>&quot;? This cannot be undone.">Delete</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$courses): ?><tr><td colspan="9" class="empty">No courses yet. Click &ldquo;Add course&rdquo; to create one.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_footer();
