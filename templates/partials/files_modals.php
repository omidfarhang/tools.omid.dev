<?php
/**
 * @var string $mode 'user'|'admin'
 * @var string $redirect
 * @var bool $cloudConnected
 * @var array{folder?:string, conflict?:string} $prefs
 */
$mode = $mode ?? 'user';
$isAdmin = $mode === 'admin';
$redirect = $redirect ?? ($isAdmin ? '/admin/files.php' : '/files.php');
$cloudConnected = $cloudConnected ?? false;
$prefs = $prefs ?? ['folder' => '', 'conflict' => 'rename'];
?>
<div class="modal fade" id="filesDeleteModal" tabindex="-1" aria-labelledby="filesDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/delete.php" data-delete-form>
                <?= \App\Csrf::field() ?>
                <input type="hidden" name="id" value="" data-delete-id>
                <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="filesDeleteModalLabel">Delete file</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2" data-delete-message>Delete this file?</p>
                    <p class="mono small mb-2" data-delete-name></p>
                    <p class="text-muted small mb-0">This cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="filesRenameModal" tabindex="-1" aria-labelledby="filesRenameModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/rename.php">
                <?= \App\Csrf::field() ?>
                <input type="hidden" name="id" value="" data-rename-id>
                <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="filesRenameModalLabel">Rename file</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label" for="files-rename-input">Filename</label>
                    <input class="form-control mono" type="text" name="original_name" id="files-rename-input"
                           data-rename-input required maxlength="255" autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="filesShareModal" tabindex="-1" aria-labelledby="filesShareModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/share.php">
                <?= \App\Csrf::field() ?>
                <input type="hidden" name="file_id" value="" data-share-id>
                <input type="hidden" name="action" value="enable">
                <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="filesShareModalLabel">Make public</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mono small mb-3" data-share-name></p>
                    <div class="mb-3">
                        <label class="form-label" for="files-share-expires">Link expires</label>
                        <select class="form-select" name="expires" id="files-share-expires" data-share-expires>
                            <option value="" selected>Never</option>
                            <option value="1h">In 1 hour</option>
                            <option value="24h">In 24 hours</option>
                            <option value="7d">In 7 days</option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="files-share-max">Max downloads</label>
                        <input class="form-control" type="number" name="max_downloads" id="files-share-max"
                               data-share-max min="1" step="1" placeholder="Unlimited" inputmode="numeric">
                        <div class="form-hint">Leave empty for unlimited downloads.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Enable link</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (!$isAdmin && $cloudConnected): ?>
<div class="modal fade" id="filesCloudModal" tabindex="-1" aria-labelledby="filesCloudModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/dropbox_send.php">
                <?= \App\Csrf::field() ?>
                <input type="hidden" name="file_id" value="" data-cloud-id>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="filesCloudModalLabel">Send to cloud</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mono small mb-3" data-cloud-name></p>
                    <div class="mb-3">
                        <label class="form-label" for="files-cloud-folder">Folder</label>
                        <input class="form-control mono" type="text" name="cloud_folder" id="files-cloud-folder"
                               value="<?= e((string) ($prefs['folder'] ?? '')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="files-cloud-conflict">If exists</label>
                        <select class="form-select" name="cloud_conflict" id="files-cloud-conflict">
                            <?php $conflict = (string) ($prefs['conflict'] ?? 'rename'); ?>
                            <option value="rename" <?= $conflict === 'rename' ? 'selected' : '' ?>>Rename</option>
                            <option value="overwrite" <?= $conflict === 'overwrite' ? 'selected' : '' ?>>Overwrite</option>
                            <option value="skip" <?= $conflict === 'skip' ? 'selected' : '' ?>>Skip</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" name="delete_after" id="files-cloud-delete">
                        <label class="form-check-label" for="files-cloud-delete">
                            Delete from host after success
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
