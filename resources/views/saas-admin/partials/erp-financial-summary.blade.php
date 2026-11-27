<div class="grid gap-3 lg:grid-cols-2">
    <section>
        <h3 class="font-semibold">TaxNest ERP package billing</h3>
        @if($package = $summary['subscription'])
            <p>{{ number_format($package['invoice_count']) }} invoices issued · PKR {{ number_format($package['invoiced'], 2) }}</p>
            <p>{{ number_format($package['receipt_count']) }} receipts · PKR {{ number_format($package['received'], 2) }} received</p>
        @else
            <p>Package billing totals are unavailable for this selection.</p>
        @endif
    </section>
    @if(($summary['school_fees'] ?? null) !== null)
        <section>
            <h3 class="font-semibold">School ERP fees</h3>
            @include('saas-admin.partials.erp-financial-amounts', ['lane' => $summary['school_fees'], 'isSubscription' => false, 'reversalLabel' => 'Receipt reversals'])
        </section>
    @else
        <section>
            <h3 class="font-semibold">Company Healthcare billing</h3>
            @if($health = $summary['operations'])
                <p>{{ number_format($health['invoice_count']) }} finalized invoices · PKR {{ number_format($health['invoiced'], 2) }}</p>
                <p>{{ number_format($health['receipt_count']) }} receipts · PKR {{ number_format($health['received'], 2) }} received</p>
                <p>PKR {{ number_format($health['refunds'], 2) }} refunded · PKR {{ number_format($health['net_received'], 2) }} net received</p>
                <p>PKR {{ number_format($health['outstanding'], 2) }} currently outstanding on those invoices</p>
            @else
                <p>Healthcare totals are unavailable for this selection.</p>
            @endif
        </section>
    @endif
</div>
