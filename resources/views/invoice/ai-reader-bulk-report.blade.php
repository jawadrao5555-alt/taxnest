@extends('reports.pdf-layout')

{{--
    Task 1330: printable hand-off summary of one Bulk AI Image Import batch.
    Only stored review data is rendered — the private source photo is never
    linked or embedded.
--}}

@section('content')
    <div class="report-title">
        <h2>Bulk AI Image Import — Review Summary</h2>
        <div class="period">
            Batch #{{ $report['batch']['id'] }} · {{ $report['batch']['status_label'] }} ·
            {{ $report['batch']['processed'] }} of {{ $report['batch']['total'] }} source invoices processed
            @if($report['batch']['started_at']) · Started {{ $report['batch']['started_at'] }} @endif
            @if($report['batch']['finished_at']) · Finished {{ $report['batch']['finished_at'] }} @endif
            @if($report['batch']['annexure_filename']) · Annexure: {{ $report['batch']['annexure_filename'] }} @endif
        </div>
    </div>

    <table class="summary-table">
        <tr>
            <td style="width:20%;">
                <div class="summary-card highlight">
                    <div class="value">{{ $report['counts']['ready'] }}</div>
                    <div class="label">Ready</div>
                </div>
            </td>
            <td style="width:20%;">
                <div class="summary-card warning">
                    <div class="value">{{ $report['counts']['needs_review'] }}</div>
                    <div class="label">Needs Review</div>
                </div>
            </td>
            <td style="width:20%;">
                <div class="summary-card">
                    <div class="value">{{ $report['counts']['duplicate'] }}</div>
                    <div class="label">Duplicate</div>
                </div>
            </td>
            <td style="width:20%;">
                <div class="summary-card">
                    <div class="value">{{ $report['counts']['failed'] }}</div>
                    <div class="label">Failed</div>
                </div>
            </td>
            <td style="width:20%;">
                <div class="summary-card">
                    <div class="value">{{ $report['counts']['pending'] }}</div>
                    <div class="label">Still Processing</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width:4%;" class="center">#</th>
                <th style="width:24%;">Source File</th>
                <th style="width:11%;" class="center">Status</th>
                <th style="width:38%;">Review Notes</th>
                <th style="width:13%;">Draft Invoice #</th>
                <th style="width:10%;">Processed</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['rows'] as $row)
            <tr>
                <td class="center">{{ $row['position'] }}</td>
                <td class="bold">{{ $row['filename'] }}</td>
                <td class="center">
                    <span class="badge {{ $row['status'] === 'ready' ? 'badge-green' : ($row['status'] === 'failed' ? 'badge-red' : 'badge-amber') }}">
                        {{ $row['status_label'] }}
                    </span>
                </td>
                <td>
                    @forelse($row['notes'] as $note)
                        {{ $note }}@if(!$loop->last)<br>@endif
                    @empty
                        —
                    @endforelse
                </td>
                <td class="bold">{{ $row['draft_number'] !== '' ? $row['draft_number'] : '—' }}</td>
                <td>{{ $row['processed_at'] !== '' ? $row['processed_at'] : '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="6" class="center">No source invoices in this batch.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="section-title">How to use this summary</div>
    <table class="data-table">
        <tbody>
            <tr><td style="width:14%;" class="bold">Ready</td><td>Draft saved in TaxNest. It still needs the usual review before submission.</td></tr>
            <tr><td class="bold">Needs review</td><td>A draft may exist, but the notes above must be resolved first.</td></tr>
            <tr><td class="bold">Duplicate</td><td>Repeats another photo in the same batch. No draft was created and no AI credit was charged.</td></tr>
            <tr><td class="bold">Failed</td><td>No draft was created. Retry the photo in the workspace.</td></tr>
            <tr><td class="bold">Queued / Reading</td><td>Still processing when this summary was generated.</td></tr>
        </tbody>
    </table>
    <p style="font-size:9px; color:#4b5563;">
        Resolve open rows in TaxNest under Invoices, AI Invoice Reader, Bulk photos. Nothing in this batch is sent to
        FBR automatically, and the private source photos are never included in or linked from this report.
    </p>
@endsection
