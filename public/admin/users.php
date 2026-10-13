<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\UserRepository;
use App\View;

$admin = Auth::requireAdmin();
$repo = new UserRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'user');
        if ($username === '' || $password === '') {
            flash('error', 'Username and password required.');
        } elseif ($repo->findByUsername($username)) {
            flash('error', 'Username already exists.');
        } else {
            $repo->create($username, $password, $role);
            flash('success', 'User created.');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['user_id'] ?? 0);
        if ($id === (int) $admin['id']) {
            flash('error', 'You cannot deactivate yourself.');
        } else {
            $target = $repo->findById($id);
            if ($target) {
                $activating = (int) $target['is_active'] !== 1;
                $repo->setActive($id, $activating);
                flash('success', $activating ? 'User activated.' : 'User deactivated.');
            }
        }
    } elseif ($action === 'reset_password') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        if ($password === '') {
            flash('error', 'Password required.');
        } else {
            $repo->setPassword($id, $password);
            flash('success', 'Password reset.');
        }
    } elseif ($action === 'set_role') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? 'user');
        if ($id === (int) $admin['id']) {
            flash('error', 'You cannot change your own role here.');
        } else {
            $repo->setRole($id, $role);
            flash('success', 'Role updated.');
        }
    }
    redirect('/admin/users.php');
}

$all = $repo->all();
ob_start();
?>
<a class="breadcrumb-dash" href="/admin/">&larr; Admin</a>
<div class="page-header">
    <p class="page-lede">Create accounts and manage roles, approvals, and passwords.</p>
</div>

<div class="panel">
    <h2 class="panel-title">Create user</h2>
    <form method="post" class="row g-3 align-items-end">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-md-3">
            <label class="form-label">Username</label>
            <input class="form-control" name="username" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Password</label>
            <input class="form-control" type="password" name="password" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">Role</label>
            <select class="form-select" name="role">
                <option value="user">user</option>
                <option value="admin">admin</option>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary" type="submit">Create</button>
        </div>
    </form>
</div>

<div class="panel panel-flush">
    <div class="panel-head d-flex justify-content-between align-items-center">
        <h2 class="panel-title mb-0">All users</h2>
        <span class="text-muted small"><?= count($all) ?> total</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Role</th>
                <th>Active</th>
                <th>Dropbox</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($all as $u): ?>
                <?php $active = (int) $u['is_active'] === 1; ?>
                <tr class="<?= $active ? '' : 'table-warning' ?>">
                    <td><?= (int) $u['id'] ?></td>
                    <td class="fw-semibold"><?= e($u['username']) ?></td>
                    <td>
                        <form method="post" class="d-flex gap-1">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="set_role">
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <select class="form-select form-select-sm" name="role">
                                <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>user</option>
                                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                            </select>
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Set</button>
                        </form>
                    </td>
                    <td><?= $active ? 'yes' : 'pending' ?></td>
                    <td>
                        <?php if (!empty($u['dropbox_access_token']) || !empty($u['dropbox_refresh_token'])): ?>
                            <?php if (!empty($u['dropbox_email'])): ?>
                                <span class="mono"><?= e((string) $u['dropbox_email']) ?></span>
                            <?php else: ?>
                                yes
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <a class="btn btn-sm btn-outline-secondary" href="/admin/files.php?user_id=<?= (int) $u['id'] ?>">Files</a>
                            <a class="btn btn-sm btn-outline-secondary" href="/admin/history.php?user_id=<?= (int) $u['id'] ?>">History</a>
                            <form method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <button class="btn btn-sm <?= $active ? 'btn-outline-warning' : 'btn-success' ?>" type="submit">
                                    <?= $active ? 'Disable' : 'Approve' ?>
                                </button>
                            </form>
                            <form method="post" class="d-flex gap-1">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <input class="form-control form-control-sm" type="password" name="password" placeholder="New password" required>
                                <button class="btn btn-sm btn-outline-primary" type="submit">Reset</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
View::render('Admin Users', ob_get_clean(), $admin);
