<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudRegistry;
use App\Csrf;
use App\FileRepository;
use App\TrustedDeviceRepository;
use App\UserRepository;
use App\View;
use App\WebAuthnCredentialRepository;

$admin = Auth::requireAdmin();
$repo = new UserRepository();
$files = new FileRepository();
$passkeys = new WebAuthnCredentialRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'user');
        $quotaRaw = trim((string) ($_POST['quota_mib'] ?? ''));
        if ($username === '' || $password === '') {
            flash('error', 'Username and password required.');
        } elseif ($repo->findByUsername($username)) {
            flash('error', 'Username already exists.');
        } else {
            if ($quotaRaw === '') {
                $quotaBytes = $repo->defaultQuotaBytes();
            } else {
                $quotaBytes = UserRepository::parseQuotaMibInput($quotaRaw);
                if ($quotaBytes === false) {
                    flash('error', 'Quota must be a whole number of MiB, or blank for the site default.');
                    redirect('/admin/users.php');
                }
            }
            $repo->create($username, $password, $role, true, $quotaBytes);
            flash('success', 'User created.');
        }
    } elseif ($action === 'set_quota') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $quotaBytes = UserRepository::parseQuotaMibInput($_POST['quota_mib'] ?? null);
        if ($id <= 0 || $repo->findById($id) === null) {
            flash('error', 'User not found.');
        } elseif ($quotaBytes === false) {
            flash('error', 'Quota must be a whole number of MiB, or blank for unlimited.');
        } else {
            $repo->setQuota($id, $quotaBytes);
            flash('success', 'Quota updated.');
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
    } elseif ($action === 'reset_2fa') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $target = $repo->findById($id);
        if ($target === null) {
            flash('error', 'User not found.');
        } elseif (!Auth::mfaRequired($target)) {
            flash('error', 'Two-factor authentication is not enabled for that user.');
        } else {
            $repo->disableTotp($id);
            $passkeys->deleteAllForUser($id);
            (new TrustedDeviceRepository())->revokeAll($id);
            flash('success', 'Two-factor authentication reset. The user can set it up again from Account.');
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
$defaultQuota = $repo->defaultQuotaBytes();
$cloudRegistry = CloudRegistry::default();
ob_start();
?>
<p class="text-secondary mb-3">Create accounts and manage roles, approvals, quotas, and passwords.</p>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <h2 class="card-title mb-0">All users</h2>
            <span class="text-muted small"><?= count($all) ?> total</span>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
            Create user
        </button>
    </div>
    <div class="table-responsive">
        <table class="table card-table table-sm align-middle mb-0" data-users-table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>2FA</th>
                <th>Cloud</th>
                <th class="text-end">Storage</th>
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
                $quotaBytes = UserRepository::effectiveQuotaBytes($u);
                $fileCount = (int) ($u['file_count'] ?? 0);
                $downloadCount = (int) ($u['download_count'] ?? 0);
                $isAdminRole = $u['role'] === 'admin';
                $totpOn = UserRepository::totpEnabled($u);
                $passkeyCount = $passkeys->countForUser($userId);
                $mfaOn = $totpOn || $passkeyCount > 0;
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
                        <?php if ($mfaOn): ?>
                            <span class="badge bg-green-lt">On</span>
                            <span class="text-secondary small d-block">
                                <?php
                                $bits = [];
                                if ($totpOn) {
                                    $bits[] = 'TOTP';
                                }
                                if ($passkeyCount > 0) {
                                    $bits[] = $passkeyCount === 1 ? '1 passkey' : "{$passkeyCount} passkeys";
                                }
                                echo e(implode(' · ', $bits));
                                ?>
                            </span>
                        <?php else: ?>
                            <span class="text-secondary">Off</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $connectedClouds = [];
                        foreach ($cloudRegistry->all() as $provider) {
                            if (!$provider->isConnected($u)) {
                                continue;
                            }
                            $details = $provider->connectionDetails($u);
                            $connectedClouds[] = [
                                'label' => $provider->label(),
                                'email' => (string) ($details['email'] ?? ''),
                                'display_name' => (string) ($details['display_name'] ?? ''),
                                'account_id' => (string) ($details['account_id'] ?? ''),
                            ];
                        }
                        $cloudCount = count($connectedClouds);
                        ?>
                        <?php if ($cloudCount > 0): ?>
                            <button type="button"
                                    class="btn btn-link p-0 text-reset text-decoration-none"
                                    data-bs-toggle="modal"
                                    data-bs-target="#cloudDetailsModal"
                                    data-username="<?= e($u['username']) ?>"
                                    data-clouds="<?= e(json_encode($connectedClouds, JSON_THROW_ON_ERROR)) ?>"
                                    title="View connected clouds">
                                <span class="badge bg-azure-lt"><?= $cloudCount ?></span>
                            </button>
                        <?php else: ?>
                            <span class="text-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a class="text-reset text-decoration-none" href="/admin/files.php?user_id=<?= $userId ?>" title="<?= $fileCount ?> file<?= $fileCount === 1 ? '' : 's' ?>">
                            <span class="mono"><?= e(UserRepository::formatUsageLabel($usedBytes, $quotaBytes)) ?></span>
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
                                            data-bs-target="#setQuotaModal"
                                            data-user-id="<?= $userId ?>"
                                            data-username="<?= e($u['username']) ?>"
                                            data-quota-mib="<?= e(UserRepository::quotaBytesToMib($quotaBytes)) ?>">
                                        Set quota…
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#resetPasswordModal"
                                            data-user-id="<?= $userId ?>"
                                            data-username="<?= e($u['username']) ?>">
                                        Reset password…
                                    </button>
                                </li>
                                <?php if ($mfaOn): ?>
                                    <li>
                                        <form method="post" onsubmit="return confirm('Reset 2FA for this user? Authenticator, passkeys, and trusted devices will be cleared.');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="reset_2fa">
                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                            <button class="dropdown-item text-warning" type="submit">Reset 2FA</button>
                                        </form>
                                    </li>
                                <?php endif; ?>
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

<div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createUserModalLabel">Create user</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="createUsername">Username</label>
                        <input class="form-control" type="text" name="username" id="createUsername"
                               required autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="createPassword">Password</label>
                        <input class="form-control" type="password" name="password" id="createPassword"
                               required autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="createRole">Role</label>
                        <select class="form-select" name="role" id="createRole">
                            <option value="user">user</option>
                            <option value="admin">admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="createQuota">Quota (MiB)</label>
                        <input class="form-control mono" type="text" inputmode="numeric" pattern="[0-9]*"
                               name="quota_mib" id="createQuota" placeholder="Default" autocomplete="off">
                        <div class="form-text">
                            Blank = site default (<?= e(UserRepository::formatQuotaLabel($defaultQuota)) ?>); 0 = unlimited.
                            <a href="/admin/settings/storage.php">Edit default</a>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create user</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="setQuotaModal" tabindex="-1" aria-labelledby="setQuotaModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="set_quota">
                <input type="hidden" name="user_id" value="" id="setQuotaUserId">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="setQuotaModalLabel">Set storage quota</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-secondary mb-3">Quota for <span class="fw-semibold" id="setQuotaUsername"></span>.</p>
                    <label class="form-label" for="setQuotaInput">Quota (MiB)</label>
                    <input class="form-control mono" type="text" inputmode="numeric" pattern="[0-9]*"
                           name="quota_mib" id="setQuotaInput" placeholder="Unlimited" autocomplete="off">
                    <div class="form-text">Leave blank for unlimited. Example: 1024 = 1 GiB.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save quota</button>
                </div>
            </form>
        </div>
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

<div class="modal fade" id="cloudDetailsModal" tabindex="-1" aria-labelledby="cloudDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="cloudDetailsModalLabel">Connected clouds</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary mb-3">Cloud accounts for <span class="fw-semibold" id="cloudDetailsUsername"></span>.</p>
                <div id="cloudDetailsList"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
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

    var createModal = document.getElementById('createUserModal');
    if (createModal) {
        createModal.addEventListener('shown.bs.modal', function () {
            var input = document.getElementById('createUsername');
            if (input) input.focus();
        });
        createModal.addEventListener('hidden.bs.modal', function () {
            var form = createModal.querySelector('form');
            if (form) form.reset();
        });
    }

    var quotaModal = document.getElementById('setQuotaModal');
    if (quotaModal) {
        quotaModal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('setQuotaUserId').value = btn.getAttribute('data-user-id') || '';
            document.getElementById('setQuotaUsername').textContent = btn.getAttribute('data-username') || '';
            var input = document.getElementById('setQuotaInput');
            if (input) input.value = btn.getAttribute('data-quota-mib') || '';
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

    var cloudModal = document.getElementById('cloudDetailsModal');
    if (cloudModal) {
        cloudModal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) return;
            document.getElementById('cloudDetailsUsername').textContent = btn.getAttribute('data-username') || '';
            var list = document.getElementById('cloudDetailsList');
            if (!list) return;
            list.replaceChildren();
            var clouds = [];
            try {
                clouds = JSON.parse(btn.getAttribute('data-clouds') || '[]');
            } catch (e) {
                clouds = [];
            }
            if (!Array.isArray(clouds) || clouds.length === 0) {
                var empty = document.createElement('p');
                empty.className = 'text-secondary mb-0';
                empty.textContent = 'No connected clouds.';
                list.appendChild(empty);
                return;
            }
            clouds.forEach(function (cloud, index) {
                if (index > 0) {
                    var hr = document.createElement('hr');
                    hr.className = 'my-3';
                    list.appendChild(hr);
                }
                var title = document.createElement('div');
                title.className = 'fw-semibold mb-2';
                title.textContent = cloud.label || 'Cloud';
                list.appendChild(title);
                var dl = document.createElement('dl');
                dl.className = 'row mb-0';
                [
                    ['Name', cloud.display_name],
                    ['Email', cloud.email],
                    ['Account', cloud.account_id]
                ].forEach(function (row) {
                    var value = row[1];
                    if (!value) return;
                    var dt = document.createElement('dt');
                    dt.className = 'col-sm-3 text-secondary';
                    dt.textContent = row[0];
                    var dd = document.createElement('dd');
                    dd.className = 'col-sm-9 mono mb-1';
                    dd.textContent = value;
                    dl.appendChild(dt);
                    dl.appendChild(dd);
                });
                if (!dl.children.length) {
                    var none = document.createElement('p');
                    none.className = 'text-secondary small mb-0';
                    none.textContent = 'Connected (no account details stored).';
                    list.appendChild(none);
                } else {
                    list.appendChild(dl);
                }
            });
        });
    }
});
</script>
<?php
View::render('Users', ob_get_clean(), $admin, [
    ['label' => 'Admin', 'href' => '/admin/'],
    ['label' => 'Users'],
]);
