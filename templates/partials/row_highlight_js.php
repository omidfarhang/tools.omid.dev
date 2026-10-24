<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.querySelector('.files-row-highlight, .history-row-highlight');
    if (!el) return;
    if (typeof el.scrollIntoView === 'function') {
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
});
</script>
