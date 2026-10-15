<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\FileRepository;
use App\UserRepository;
use App\View;

$admin = Auth::requireAdmin();
$repo = new UserRepository();
$files = new FileRepository();

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
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['user_id'] ?? 0);
        if ($id === (int) $admin['id']) {
            flash('error', 'You cannot delete yourself.');
        } else {
            $target = $repo->findById($id);
            if ($target === null) {
                flash('error', 'User not found.');
            } elseif ((int) $target['is_active'] === 1) {
                flash('error', 'Deactivate the user before deleting.');
            } else {
                foreach ($files->forUser($id) as $file) {
                    $path = FileRepository::absolutePath((string) $file['stored_name']);
                    if ($path !== null && is_file($path)) {
                        unlink($path);
                    }
                }
                if ($repo->deleteInactive($id)) {
                    flash('success', 'User deleted.');
                } else {
                    flash('error', 'Could not delete user.');
                }
            }
        }
    }
    redirect('/admin/users.php');
}

$all = $repo->all();
ob_start();
?>
<a class="d-inline-block mb-3" href="/admin/">&larr; Admin</a>
<p class="text-secondary mb-3">Create accounts and manage roles, approvals, and passwords.</p>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="card-title">Create user</h2>
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
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">All users</h2>
        <span class="text-muted small"><?= count($all) ?> total</span>
    </div>
    <div class="table-responsive">
        <table class="table card-table table-sm align-middle mb-0" data-users-table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>Dropbox</th>
                <th class="text-end">Used</th>
                <th class="text-end">Downloads</th>
                <th class="text-end w-1"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($all as $u): ?>
                <?php
                $active = (int) $u['is_active'] === 1;
                $userId = (int) $u['id'];
                $isSelf = $userId === (int) $admin['id'];
                $usedBytes = (int) ($u['used_bytes'] ?? 0);
                $fileCount = (int) ($u['file_count'] ?? 0);
                $downloadCount = (int) ($u['download_count'] ?? 0);
                $isAdminRole = $u['role'] === 'admin';
                ?>
                <tr class="<?= $active ? '' : 'table-warning' ?>">
                    <td class="text-secondary"><?= $userId ?></td>
                    <td class="fw-semibold"><?= e($u['username']) ?></td>
                    <td>
                        <?php if ($isAdminRole): ?>
                            <span class="badge bg-azure-lt">admin</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-lt">user</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($active): ?>
                            <span class="badge bg-green-lt">Active</span>
                        <?php else: ?>
                            <span class="badge bg-yellow-lt">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($u['dropbox_access_token']) || !empty($u['dropbox_refresh_token'])): ?>
                            <?php if (!empty($u['dropbox_email'])): ?>
                                <span class="mono small"><?= e((string) $u['dropbox_email']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-teal-lt text-teal">Connected</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a class="text-reset text-decoration-none" href="/admin/files.php?user_id=<?= $userId ?>" title="<?= $fileCount ?> file<?= $fileCount === 1 ? '' : 's' ?>">
                            <span class="mono"><?= e(human_filesize($usedBytes)) ?></span>
                            <?php if ($fileCount > 0): ?>
                                <span class="d-block text-secondary small"><?= $fileCount ?> file<?= $fileCount === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        </a>
                    </td>
                    <td class="text-end">
                        <a class="mono text-reset text-decoration-none" href="/admin/history.php?user_id=<?= $userId ?>">
                            <?= $downloadCount ?>
                        </a>
                    </td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false"
                                    aria-label="Actions for <?= e($u['username']) ?>">
                                ···
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="/admin/files.php?user_id=<?= $userId ?>">Files</a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="/admin/history.php?user_id=<?= $userId ?>">History</a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <?php if (!$isSelf): ?>
                                    <li>
                                        <form method="post">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                            <button class="dropdown-item <?= $active ? 'text-warning' : 'text-success' ?>" type="submit">
                                                <?= $active ? 'Disable' : 'Approve' ?>
                                            </button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="post">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="set_role">
                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                            <input type="hidden" name="role" value="<?= $isAdminRole ? 'user' : 'admin' ?>">
                                            <button class="dropdown-item" type="submit">
                                                Make <?= $isAdminRole ? 'user' : 'admin' ?>
                                            </button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                                <li>
                                    <button type="button" class="dropdown-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#resetPasswordModal"
                                            data-user-id="<?= $userId ?>"
                                            data-username="<?= e($u['username']) ?>">
                                        Reset password…
                                    </button>
                                </li>
                                <?php if (!$isSelf && !$active): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button type="button" class="dropdown-item text-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteUserModal"
                                                data-user-id="<?= $userId ?>"
                                                data-username="<?= e($u['username']) ?>"
                                                data-file-count="<?= $fileCount ?>">
                                            Delete user…
                                        </button>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" value="" id="resetPasswordUserId">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="resetPasswordModalLabel">Reset password</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-secondary mb-3">Set a new password for <span class="fw-semibold" id="resetPasswordUsername"></span>.</p>
                    <label class="form-label" for="resetPasswordInput">New password</label>
                    <input class="form-control" type="password" name="password" id="resetPasswordInput" required autocomplete="new-password">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Reset password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="" id="deleteUserId">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="deleteUserModalLabel">Delete user</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Delete <span class="fw-semibold" id="deleteUserUsername"></span>?</p>
                    <p class="text-secondary small mb-0" id="deleteUserFilesNote"></p>
                    <p class="text-muted small mb-0 mt-2">This cannot be undone. History, shares, and cloud jobs for this account are removed too.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete user</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var table = document.querySelector('[data-users-table]');
    if (table && typeof tabler !== 'undefined') {
        table.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
            tabler.Dropdown.getOrCreateInstance(el, {
                popperConfig: function (defaultConfig) {
                    return Object.assign({}, defaultConfig, { strategy: 'fixed' });
                }
            });
        });
    }

    var resetModal = document.getElementById('resetPasswordModal');
    if (resetModal) {
        resetModal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('resetPasswordUserId').value = btn.getAttribute('data-user-id') || '';
            document.getElementById('resetPasswordUsername').textContent = btn.getAttribute('data-username') || '';
            var input = document.getElementById('resetPasswordInput');
            if (input) input.value = '';
        });
    }

    var deleteModal = document.getElementById('deleteUserModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('deleteUserId').value = btn.getAttribute('data-user-id') || '';
            document.getElementById('deleteUserUsername').textContent = btn.getAttribute('data-username') || '';
            var n = parseInt(btn.getAttribute('data-file-count') || '0', 10);
            var note = document.getElementById('deleteUserFilesNote');
            if (note) {
                note.textContent = n > 0
                    ? (n === 1 ? 'Their 1 stored file will be removed from disk.' : 'Their ' + n + ' stored files will be removed from disk.')
                    : 'They have no stored files on disk.';
            }
        });
    }
});
</script>
<?php
View::render('Admin Users', ob_get_clean(), $admin);
