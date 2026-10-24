<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.querySelector('[data-files-table]');
    if (!table || typeof tabler === 'undefined') return;

    table.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
        tabler.Dropdown.getOrCreateInstance(el, {
            popperConfig: function (defaultConfig) {
                return Object.assign({}, defaultConfig, { strategy: 'fixed' });
            }
        });
    });

    const selectAll = document.querySelector('[data-files-select-all]');
    const bulkBar = document.querySelector('[data-files-bulk]');
    const bulkCount = document.querySelector('[data-files-bulk-count]');
    const bulkForm = document.querySelector('[data-bulk-form]');
    const bulkAction = document.querySelector('[data-bulk-action]');
    const bulkIds = document.querySelector('[data-bulk-ids]');

    const deleteModalEl = document.getElementById('filesDeleteModal');
    const renameModalEl = document.getElementById('filesRenameModal');
    const shareModalEl = document.getElementById('filesShareModal');
    const cloudModalEl = document.getElementById('filesCloudModal');
    const deleteModal = deleteModalEl ? tabler.Modal.getOrCreateInstance(deleteModalEl) : null;
    const renameModal = renameModalEl ? tabler.Modal.getOrCreateInstance(renameModalEl) : null;
    const shareModal = shareModalEl ? tabler.Modal.getOrCreateInstance(shareModalEl) : null;
    const cloudModal = cloudModalEl ? tabler.Modal.getOrCreateInstance(cloudModalEl) : null;
    const cloudProviderSelect = cloudModalEl ? cloudModalEl.querySelector('#files-cloud-provider') : null;
    const cloudFolderInput = cloudModalEl ? cloudModalEl.querySelector('#files-cloud-folder') : null;
    const cloudConflictSelect = cloudModalEl ? cloudModalEl.querySelector('#files-cloud-conflict') : null;

    function applyCloudProviderDefaults(sourceEl) {
        if (!sourceEl || !cloudFolderInput || !cloudConflictSelect) return;
        let folder = sourceEl.getAttribute('data-folder');
        let conflict = sourceEl.getAttribute('data-conflict');
        if (sourceEl.tagName === 'SELECT') {
            const opt = sourceEl.options[sourceEl.selectedIndex];
            if (opt) {
                folder = opt.getAttribute('data-folder');
                conflict = opt.getAttribute('data-conflict');
            }
        }
        if (folder !== null) {
            cloudFolderInput.value = folder;
        }
        if (conflict !== null) {
            cloudConflictSelect.value = conflict;
        }
    }

    if (cloudProviderSelect) {
        cloudProviderSelect.addEventListener('change', function () {
            applyCloudProviderDefaults(cloudProviderSelect);
        });
    }

    let pendingBulkDelete = null;

    function visibleRows() {
        return Array.from(table.querySelectorAll('[data-file-row]'));
    }

    function checkedBoxes() {
        return visibleRows().flatMap(function (row) {
            const box = row.querySelector('[data-file-check]');
            return box && box.checked ? [box] : [];
        });
    }

    function updateBulkBar() {
        const checked = checkedBoxes();
        const n = checked.length;
        const hasSelection = n > 0;
        if (bulkBar) {
            bulkBar.classList.toggle('is-active', hasSelection);
        }
        if (bulkCount) {
            bulkCount.textContent = n === 1 ? '1 selected' : n + ' selected';
        }
        document.querySelectorAll('[data-bulk-revoke], [data-bulk-delete]').forEach(function (btn) {
            btn.disabled = !hasSelection;
        });
        if (selectAll) {
            const rows = visibleRows();
            const allChecked = rows.length > 0 && rows.every(function (row) {
                const box = row.querySelector('[data-file-check]');
                return box && box.checked;
            });
            selectAll.checked = allChecked;
            selectAll.indeterminate = n > 0 && !allChecked;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            visibleRows().forEach(function (row) {
                const box = row.querySelector('[data-file-check]');
                if (box) box.checked = selectAll.checked;
            });
            updateBulkBar();
        });
    }

    table.addEventListener('change', function (e) {
        if (e.target && e.target.matches('[data-file-check]')) {
            updateBulkBar();
        }
    });

    function fillBulkIds(ids) {
        if (!bulkIds) return;
        bulkIds.innerHTML = '';
        ids.forEach(function (id) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = String(id);
            bulkIds.appendChild(input);
        });
    }

    function showDeleteModal(opts) {
        if (!deleteModal || !deleteModalEl) return;
        const form = deleteModalEl.querySelector('[data-delete-form]');
        const idInput = deleteModalEl.querySelector('[data-delete-id]');
        const nameEl = deleteModalEl.querySelector('[data-delete-name]');
        const msgEl = deleteModalEl.querySelector('[data-delete-message]');
        const titleEl = document.getElementById('filesDeleteModalLabel');
        const submitBtn = deleteModalEl.querySelector('[type="submit"]');

        pendingBulkDelete = opts.bulkIds || null;

        if (opts.bulkIds) {
            if (form) form.action = '#';
            if (idInput) idInput.value = '';
            if (titleEl) titleEl.textContent = 'Delete files';
            if (msgEl) msgEl.textContent = 'Delete ' + opts.bulkIds.length + ' files?';
            if (nameEl) {
                const preview = (opts.names || []).slice(0, 3).join(', ');
                nameEl.textContent = preview + ((opts.names || []).length > 3 ? '…' : '');
            }
            if (submitBtn) submitBtn.textContent = 'Delete selected';
        } else {
            if (form) form.action = '/delete.php';
            if (idInput) idInput.value = opts.id || '';
            if (titleEl) titleEl.textContent = 'Delete file';
            if (msgEl) msgEl.textContent = 'Delete this file?';
            if (nameEl) nameEl.textContent = opts.name || '';
            if (submitBtn) submitBtn.textContent = 'Delete';
        }
        deleteModal.show();
    }

    if (deleteModalEl) {
        const form = deleteModalEl.querySelector('[data-delete-form]');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!pendingBulkDelete) return;
                e.preventDefault();
                if (!bulkForm || !bulkAction) return;
                fillBulkIds(pendingBulkDelete);
                bulkAction.value = 'delete';
                deleteModal.hide();
                bulkForm.submit();
            });
        }
    }

    const bulkDeleteBtn = document.querySelector('[data-bulk-delete]');
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function () {
            const boxes = checkedBoxes();
            if (!boxes.length) return;
            const ids = boxes.map(function (b) { return b.value; });
            const names = boxes.map(function (b) {
                const row = b.closest('[data-file-row]');
                const link = row ? row.querySelector('.filename') : null;
                return link ? link.textContent : '';
            });
            showDeleteModal({ bulkIds: ids, names: names });
        });
    }

    const bulkRevokeBtn = document.querySelector('[data-bulk-revoke]');
    if (bulkRevokeBtn) {
        bulkRevokeBtn.addEventListener('click', function () {
            const boxes = checkedBoxes();
            if (!boxes.length || !bulkForm || !bulkAction) return;
            fillBulkIds(boxes.map(function (b) { return b.value; }));
            bulkAction.value = 'revoke';
            bulkForm.submit();
        });
    }

    document.addEventListener('click', function (e) {
        const copyBtn = e.target.closest('[data-copy-share]');
        if (copyBtn) {
            const url = copyBtn.getAttribute('data-share-url') || '';
            if (url && navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function () {
                    const prev = copyBtn.textContent;
                    copyBtn.textContent = 'Copied';
                    setTimeout(function () { copyBtn.textContent = prev; }, 1200);
                });
            }
            return;
        }

        const renameBtn = e.target.closest('[data-action-rename]');
        if (renameBtn && renameModal && renameModalEl) {
            const id = renameBtn.getAttribute('data-file-id') || '';
            const name = renameBtn.getAttribute('data-file-name') || '';
            const idInput = renameModalEl.querySelector('[data-rename-id]');
            const nameInput = renameModalEl.querySelector('[data-rename-input]');
            if (idInput) idInput.value = id;
            if (nameInput) {
                nameInput.value = name;
                setTimeout(function () { nameInput.focus(); nameInput.select(); }, 200);
            }
            renameModal.show();
            return;
        }

        const shareBtn = e.target.closest('[data-action-share]');
        if (shareBtn && shareModal && shareModalEl) {
            const id = shareBtn.getAttribute('data-file-id') || '';
            const name = shareBtn.getAttribute('data-file-name') || '';
            const idInput = shareModalEl.querySelector('[data-share-id]');
            const nameEl = shareModalEl.querySelector('[data-share-name]');
            const expiresEl = shareModalEl.querySelector('[data-share-expires]');
            const maxEl = shareModalEl.querySelector('[data-share-max]');
            if (idInput) idInput.value = id;
            if (nameEl) nameEl.textContent = name;
            if (expiresEl) expiresEl.value = '';
            if (maxEl) maxEl.value = '';
            shareModal.show();
            return;
        }

        const cloudBtn = e.target.closest('[data-action-cloud]');
        if (cloudBtn && cloudModal && cloudModalEl) {
            const id = cloudBtn.getAttribute('data-file-id') || '';
            const name = cloudBtn.getAttribute('data-file-name') || '';
            const idInput = cloudModalEl.querySelector('[data-cloud-id]');
            const nameEl = cloudModalEl.querySelector('[data-cloud-name]');
            if (idInput) idInput.value = id;
            if (nameEl) nameEl.textContent = name;
            if (cloudProviderSelect) {
                applyCloudProviderDefaults(cloudProviderSelect);
            } else {
                const hiddenProvider = cloudModalEl.querySelector('input[name="provider"]');
                applyCloudProviderDefaults(hiddenProvider);
            }
            cloudModal.show();
            return;
        }

        const deleteBtn = e.target.closest('[data-action-delete]');
        if (deleteBtn) {
            showDeleteModal({
                id: deleteBtn.getAttribute('data-file-id') || '',
                name: deleteBtn.getAttribute('data-file-name') || ''
            });
        }
    });
});
</script>
<?php include __DIR__ . '/preview_js.php'; ?>
