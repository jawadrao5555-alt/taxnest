@once
<style>
    .pos-report-document .section-title {
        color: #1e1b4b;
        border-bottom: 1.5px solid currentColor;
        font-weight: 700;
        margin: 0.75rem 0 0.375rem;
        padding-bottom: 0.25rem;
    }
    .pos-report-document table.data {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0.75rem;
    }
    .pos-report-document table.data thead th {
        background-color: #1e1b4b;
        color: #fff;
    }
    .pos-report-document table.data tbody td {
        border-bottom: 1px solid #d1d5db;
    }
    .pos-report-document table.data:not(.table-cards) :is(td, th) {
        padding: 0.375rem 0.5rem;
    }
    .pos-report-document table.data .r {
        text-align: right;
        font-weight: 700;
    }
    .pos-report-document table.data .c {
        text-align: center;
    }
    .pos-report-document table.data tfoot td {
        border-top: 2px solid #1e1b4b;
        font-weight: 700;
    }
    .pos-report-document table.data tbody tr:nth-child(even) {
        background-color: #f5f3ff;
    }
    .dark .pos-report-document .section-title {
        color: #ddd6fe;
    }
    .dark .pos-report-document table.data tbody td {
        border-color: #475569;
    }
    .dark .pos-report-document table.data tbody tr:nth-child(even) {
        background-color: #25213a;
    }
    .dark .pos-report-document table.data tfoot td {
        border-color: #a78bfa;
    }
</style>
@endonce
