<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
require_admin();

$me = admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'password') {
        $cur  = (string) ($_POST['current'] ?? '');
        $new  = (string) ($_POST['new'] ?? '');
        $new2 = (string) ($_POST['new2'] ?? '');
        $hash = (string) q('SELECT password_hash FROM admins WHERE id = ?', [$me['id']])->fetchColumn();
        if (!password_verify($cur, $hash)) {
            flash('danger', 'Your current password is incorrect.');
        } elseif (strlen($new) < 10) {
            flash('danger', 'The new password must be at least 10 characters.');
        } elseif ($new !== $new2) {
            flash('danger', 'The new passwords do not match.');
        } else {
            q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            flash('success', 'Password changed.');
        }
    } elseif ($action === 'add_user' && $me['role'] === 'admin') {
        $name  = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $role  = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';
        $pass  = (string) ($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 10) {
            flash('danger', 'Enter a name, a valid email and a password of at least 10 characters.');
        } elseif ((int) q('SELECT COUNT(*) FROM admins WHERE email = ?', [$email])->fetchColumn() > 0) {
            flash('danger', 'That email already has an account.');
        } else {
            q('INSERT INTO admins (name, email, password_hash, role) VALUES (?,?,?,?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
            flash('success', 'User created.');
        }
    } elseif ($action === 'delete_user' && $me['role'] === 'admin') {
        $uid = (int) ($_POST['id'] ?? 0);
        if ($uid === (int) $me['id']) {
            flash('danger', 'You cannot delete your own account.');
        } else {
            q('DELETE FROM admins WHERE id = ?', [$uid]);
            flash('success', 'User removed.');
        }
    }
    redirect(url('admin/settings.php'));
}

$users = $me['role'] === 'admin' ? q('SELECT id, name, email, role, last_login_at FROM admins ORDER BY name')->fetchAll() : [];

admin_header('Settings', 'settings');
?>
<div class="grid half">
  <section class="card">
    <h2>Change my password</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="field mb-2"><label for="current">Current password</label><input type="password" id="current" name="current" autocomplete="current-password" required></div>
      <div class="field mb-2"><label for="new">New password</label><input type="password" id="new" name="new" autocomplete="new-password" minlength="10" required><div class="hint">At least 10 characters.</div></div>
      <div class="field mb-2"><label for="new2">Confirm new password</label><input type="password" id="new2" name="new2" autocomplete="new-password" required></div>
      <button class="btn" type="submit">Update password</button>
    </form>
  </section>

  <?php if ($me['role'] === 'admin'): ?>
  <section class="card">
    <h2>Add a team member</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_user">
      <div class="field mb-2"><label for="u_name">Name</label><input type="text" id="u_name" name="name" required></div>
      <div class="field mb-2"><label for="u_email">Email</label><input type="email" id="u_email" name="email" required></div>
      <div class="field mb-2"><label for="u_role">Role</label>
        <select id="u_role" name="role"><option value="staff">Staff (cannot delete records)</option><option value="admin">Administrator</option></select></div>
      <div class="field mb-2"><label for="u_pass">Temporary password</label><input type="password" id="u_pass" name="password" autocomplete="new-password" minlength="10" required></div>
      <button class="btn" type="submit">Create user</button>
    </form>
  </section>
  <?php endif; ?>
</div>

<?php if ($users): ?>
<section class="card mt-2">
  <h2>Team</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last sign-in</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><strong><?= e($u['name']) ?></strong></td><td><?= e($u['email']) ?></td>
          <td><span class="badge badge-<?= $u['role'] === 'admin' ? 'primary' : 'muted' ?>"><?= e(ucfirst($u['role'])) ?></span></td>
          <td class="nowrap"><?= e(fmt_date($u['last_login_at'], 'd M Y, H:i')) ?></td>
          <td class="actions"><?php if ((int) $u['id'] !== (int) $me['id']): ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit" data-confirm="Remove this user?">Remove</button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php admin_footer();
