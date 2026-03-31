<x-pos-layout>

<div class="w-full overflow-x-hidden">
    <div class="px-4 sm:px-6 py-4 max-w-7xl mx-auto">
        @include('pos.dashboard-styles.' . ($dashboardStyle ?? 'default'))
        @include('pos.dashboard-styles._drafts-section')
    </div>
</div>

<script>
function draftsManager() {
    return {
        drafts: @json($drafts),
        init() {},
        timeAgo(dateStr) {
            if (!dateStr) return '';
            const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        },
        formatMethod(m) {
            return m ? m.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'Cash';
        },
        async deleteDraft(id) {
            if (!confirm('Delete this draft? This cannot be undone.')) return;
            try {
                const res = await fetch('/pos/api/draft/' + id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
                });
                if (res.ok) {
                    this.drafts = this.drafts.filter(d => d.id !== id);
                    window.dispatchEvent(new CustomEvent('draft-deleted', { detail: { count: this.drafts.length } }));
                } else {
                    alert('Failed to delete draft.');
                }
            } catch (e) {
                alert('Network error.');
            }
        },
        async deleteAllDrafts() {
            if (!confirm('Delete ALL drafts? This cannot be undone.')) return;
            let failed = 0;
            for (const draft of [...this.drafts]) {
                try {
                    const res = await fetch('/pos/api/draft/' + draft.id, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
                    });
                    if (res.ok) {
                        this.drafts = this.drafts.filter(d => d.id !== draft.id);
                    } else { failed++; }
                } catch (e) { failed++; }
            }
            window.dispatchEvent(new CustomEvent('draft-deleted', { detail: { count: this.drafts.length } }));
            if (failed > 0) alert(failed + ' draft(s) could not be deleted.');
        }
    };
}
</script>
</x-pos-layout>
