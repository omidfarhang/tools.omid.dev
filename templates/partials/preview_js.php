<script>
(function () {
    if (typeof tabler === 'undefined') return;

    const previewModalEl = document.getElementById('filesPreviewModal');
    if (!previewModalEl) return;

    const previewModal = tabler.Modal.getOrCreateInstance(previewModalEl);

    /** @type {Record<string, string>} */
    const PREVIEW_EXT = {
        jpg: 'image', jpeg: 'image', png: 'image', gif: 'image', webp: 'image',
        pdf: 'pdf',
        mp4: 'video', webm: 'video', ogv: 'video',
        mp3: 'audio', m4a: 'audio', ogg: 'audio', oga: 'audio', wav: 'audio', aac: 'audio',
        txt: 'text', log: 'text', csv: 'text', json: 'text', xml: 'text', md: 'text', nfo: 'text'
    };

    window.previewKindFromName = function (filename) {
        const base = String(filename || '').split(/[\\/]/).pop() || '';
        const dot = base.lastIndexOf('.');
        if (dot < 0) return null;
        const ext = base.slice(dot + 1).toLowerCase();
        return PREVIEW_EXT[ext] || null;
    };

    window.filePreviewLinkHtml = function (fileId, name) {
        const label = name && name !== '—' ? name : 'file';
        const kind = window.previewKindFromName(label);
        if (!kind || !fileId) {
            return '<a class="filename" href="/download.php?id=' + encodeURIComponent(String(fileId)) + '">'
                + escapePreviewHtml(label) + '</a>';
        }
        return '<button type="button" class="filename btn btn-link p-0 align-baseline"'
            + ' data-action-preview'
            + ' data-file-id="' + escapePreviewAttr(String(fileId)) + '"'
            + ' data-file-name="' + escapePreviewAttr(label) + '"'
            + ' data-preview-kind="' + escapePreviewAttr(kind) + '">'
            + escapePreviewHtml(label) + '</button>';
    };

    function escapePreviewHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapePreviewAttr(str) {
        return escapePreviewHtml(str).replace(/'/g, '&#39;');
    }

    function clearPreviewStage() {
        const stage = previewModalEl.querySelector('[data-preview-stage]');
        if (!stage) return;
        stage.querySelectorAll('video, audio').forEach(function (el) {
            try { el.pause(); } catch (err) { /* ignore */ }
            el.removeAttribute('src');
            if (typeof el.load === 'function') el.load();
        });
        stage.innerHTML = '<p class="text-secondary small mb-0 p-3" data-preview-empty>Select a file to preview.</p>';
    }

    function showPreview(opts) {
        const id = opts.id || '';
        const name = opts.name || '';
        const kind = opts.kind || '';
        const url = '/preview.php?id=' + encodeURIComponent(id);
        const titleEl = document.getElementById('filesPreviewModalLabel');
        const downloadEl = previewModalEl.querySelector('[data-preview-download]');
        const stage = previewModalEl.querySelector('[data-preview-stage]');
        if (titleEl) titleEl.textContent = name || 'Preview';
        if (downloadEl) downloadEl.href = '/download.php?id=' + encodeURIComponent(id);
        if (!stage) {
            previewModal.show();
            return;
        }

        clearPreviewStage();
        stage.innerHTML = '';

        if (kind === 'image') {
            const img = document.createElement('img');
            img.className = 'files-preview-media';
            img.src = url;
            img.alt = name;
            stage.appendChild(img);
        } else if (kind === 'pdf') {
            const frame = document.createElement('iframe');
            frame.className = 'files-preview-frame';
            frame.src = url;
            frame.title = name || 'PDF preview';
            stage.appendChild(frame);
        } else if (kind === 'video') {
            const video = document.createElement('video');
            video.className = 'files-preview-media';
            video.controls = true;
            video.preload = 'metadata';
            video.src = url;
            stage.appendChild(video);
        } else if (kind === 'audio') {
            const wrap = document.createElement('div');
            wrap.className = 'files-preview-audio';
            const audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'metadata';
            audio.src = url;
            wrap.appendChild(audio);
            stage.appendChild(wrap);
        } else if (kind === 'text') {
            const pre = document.createElement('pre');
            pre.className = 'files-preview-text mono mb-0';
            pre.textContent = 'Loading…';
            stage.appendChild(pre);
            fetch(url, { credentials: 'same-origin' }).then(function (res) {
                if (!res.ok) throw new Error('Preview failed');
                return res.text();
            }).then(function (text) {
                pre.textContent = text;
            }).catch(function () {
                pre.textContent = 'Could not load text preview.';
            });
        } else {
            stage.innerHTML = '<p class="text-secondary small mb-0 p-3">Preview not available for this file type.</p>';
        }

        previewModal.show();
    }

    previewModalEl.addEventListener('hidden.bs.modal', clearPreviewStage);

    document.addEventListener('click', function (e) {
        const previewBtn = e.target.closest('[data-action-preview]');
        if (!previewBtn) return;
        showPreview({
            id: previewBtn.getAttribute('data-file-id') || '',
            name: previewBtn.getAttribute('data-file-name') || '',
            kind: previewBtn.getAttribute('data-preview-kind') || ''
        });
    });
})();
</script>
