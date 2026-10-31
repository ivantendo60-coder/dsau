<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
require_admin();

$id     = (int) ($_GET['id'] ?? 0);
$course = null;
if ($id) {
    $course = q('SELECT * FROM courses WHERE id = ?', [$id])->fetch() ?: null;
    if (!$course) {
        flash('danger', 'Course not found.');
        redirect(url('admin/courses.php'));
    }
}

$errors = [];
$v = $course ?? [
    'title' => '', 'description' => '', 'topics' => '', 'duration_months' => 3,
    'fee_beginner' => 300000, 'fee_advanced' => 400000, 'capacity' => '', 'is_active' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v = [
        'title'           => trim((string) ($_POST['title'] ?? '')),
        'description'     => trim((string) ($_POST['description'] ?? '')),
        'topics'          => trim((string) ($_POST['topics'] ?? '')),
        'duration_months' => (int) ($_POST['duration_months'] ?? 0),
        'fee_beginner'    => (int) preg_replace('/\D/', '', (string) ($_POST['fee_beginner'] ?? '0')),
        'fee_advanced'    => (int) preg_replace('/\D/', '', (string) ($_POST['fee_advanced'] ?? '0')),
        'capacity'        => trim((string) ($_POST['capacity'] ?? '')),
        'is_active'       => !empty($_POST['is_active']) ? 1 : 0,
    ];

    if ($v['title'] === '' || mb_strlen($v['title']) > 160) $errors['title'] = 'Enter a course title (max 160 characters).';
    if ($v['duration_months'] < 1 || $v['duration_months'] > 36) $errors['duration_months'] = 'Duration must be 1-36 months.';
    if ($v['fee_beginner'] < 0)                              $errors['fee_beginner'] = 'Enter a valid fee.';
    if ($v['fee_advanced'] < 0)                              $errors['fee_advanced'] = 'Enter a valid fee.';
    if ($v['capacity'] !== '' && !ctype_digit($v['capacity'])) $errors['capacity'] = 'Capacity must be a whole number or empty.';

    if (!$errors) {
        $capacity = $v['capacity'] === '' ? null : (int) $v['capacity'];
        // Normalise topics: one per line, no blanks
        $topics = implode("\n", array_filter(array_map('trim', preg_split('/\R/', $v['topics']) ?: [])));

        // Unique slug
        $slug = slugify($v['title']);
        $base = $slug;
        $n    = 2;
        while ((int) q('SELECT COUNT(*) FROM courses WHERE slug = ? AND id <> ?', [$slug, $id])->fetchColumn() > 0) {
            $slug = $base . '-' . $n++;
        }

        if ($id) {
            q('UPDATE courses SET title=?, slug=?, description=?, topics=?, duration_months=?, fee_beginner=?, fee_advanced=?, capacity=?, is_active=? WHERE id=?',
              [$v['title'], $slug, $v['description'] ?: null, $topics ?: null, $v['duration_months'], $v['fee_beginner'], $v['fee_advanced'], $capacity, $v['is_active'], $id]);
            flash('success', 'Course updated. New fees apply to future applications only.');
        } else {
            $order = (int) q('SELECT COALESCE(MAX(sort_order),0) + 1 FROM courses')->fetchColumn();
            q('INSERT INTO courses (title, slug, description, topics, duration_months, fee_beginner, fee_advanced, capacity, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$v['title'], $slug, $v['description'] ?: null, $topics ?: null, $v['duration_months'], $v['fee_beginner'], $v['fee_advanced'], $capacity, $v['is_active'], $order]);
            flash('success', 'Course added.');
        }
        redirect(url('admin/courses.php'));
    }
}

admin_header($id ? 'Edit course' : 'Add course', 'courses');
?>
<form method="post" class="card" style="max-width:860px" novalidate>
  <?= csrf_field() ?>
  <div class="form-grid">
    <div class="field full <?= isset($errors['title']) ? 'has-error' : '' ?>">
      <label for="title">Course title <span class="req">*</span></label>
      <input type="text" id="title" name="title" maxlength="160" value="<?= e($v['title']) ?>" required>
      <?= isset($errors['title']) ? '<div class="error-text">' . e($errors['title']) . '</div>' : '' ?>
    </div>
    <div class="field full">
      <label for="description">Short description</label>
      <textarea id="description" name="description" rows="3"><?= e($v['description']) ?></textarea>
    </div>
    <div class="field full">
      <label for="topics">Topics / modules</label>
      <textarea id="topics" name="topics" rows="6" placeholder="One topic per line"><?= e($v['topics']) ?></textarea>
      <div class="hint">One per line. Shown to applicants when they pick the course.</div>
    </div>
    <div class="field <?= isset($errors['fee_beginner']) ? 'has-error' : '' ?>">
      <label for="fee_beginner">Beginner fee (<?= e(cfg('currency')) ?>)</label>
      <input type="number" id="fee_beginner" name="fee_beginner" min="0" step="1" value="<?= (int) $v['fee_beginner'] ?>">
      <?= isset($errors['fee_beginner']) ? '<div class="error-text">' . e($errors['fee_beginner']) . '</div>' : '' ?>
    </div>
    <div class="field <?= isset($errors['fee_advanced']) ? 'has-error' : '' ?>">
      <label for="fee_advanced">Advanced fee (<?= e(cfg('currency')) ?>)</label>
      <input type="number" id="fee_advanced" name="fee_advanced" min="0" step="1" value="<?= (int) $v['fee_advanced'] ?>">
      <?= isset($errors['fee_advanced']) ? '<div class="error-text">' . e($errors['fee_advanced']) . '</div>' : '' ?>
    </div>
    <div class="field <?= isset($errors['duration_months']) ? 'has-error' : '' ?>">
      <label for="duration_months">Duration (months)</label>
      <input type="number" id="duration_months" name="duration_months" min="1" max="36" value="<?= (int) $v['duration_months'] ?>">
      <?= isset($errors['duration_months']) ? '<div class="error-text">' . e($errors['duration_months']) . '</div>' : '' ?>
    </div>
    <div class="field <?= isset($errors['capacity']) ? 'has-error' : '' ?>">
      <label for="capacity">Seats per intake (optional)</label>
      <input type="number" id="capacity" name="capacity" min="0" value="<?= e($v['capacity']) ?>">
      <?= isset($errors['capacity']) ? '<div class="error-text">' . e($errors['capacity']) . '</div>' : '' ?>
    </div>
    <div class="field full">
      <label class="choice" style="max-width:340px"><input type="checkbox" name="is_active" value="1" <?= $v['is_active'] ? 'checked' : '' ?>> Active (show on admission form)</label>
    </div>
  </div>
  <div class="flex gap mt-2">
    <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Add course' ?></button>
    <a class="btn btn-light" href="<?= e(url('admin/courses.php')) ?>">Cancel</a>
  </div>
</form>
<?php admin_footer();
