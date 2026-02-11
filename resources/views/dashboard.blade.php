@extends('layouts.app')

@section('content')
<div class="mb-8 flex justify-between items-center">
    <h2 class="text-2xl font-bold">Company Dashboard</h2>
    <a href="/invoice/create" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition">Create Invoice</a>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white p-6 rounded shadow border-l-4 border-blue-600">
        <h3 class="text-gray-500 text-sm font-bold uppercase">Total Invoices</h3>
        <p class="text-3xl font-bold">{{ $invoices->count() }}</p>
    </div>
    <div class="bg-white p-6 rounded shadow border-l-4 border-green-600">
        <h3 class="text-gray-500 text-sm font-bold uppercase">Submitted</h3>
        <p class="text-3xl font-bold">{{ $invoices->where('status', 'submitted')->count() + $invoices->where('status', 'locked')->count() }}</p>
    </div>
    <div class="bg-white p-6 rounded shadow border-l-4 border-yellow-600">
        <h3 class="text-gray-500 text-sm font-bold uppercase">Drafts</h3>
        <p class="text-3xl font-bold">{{ $invoices->where('status', 'draft')->count() }}</p>
    </div>
</div>

<!-- Analytics Chart -->
<div class="bg-white p-6 rounded shadow mb-8">
    <h3 class="text-xl font-bold mb-4">Invoice Submission Trends</h3>
    <canvas id="invoiceChart" height="100"></canvas>
</div>

<!-- Invoice List -->
<div class="bg-white rounded shadow overflow-hidden">
    <table class="min-w-full">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @foreach($invoices as $invoice)
            <tr>
                <td class="px-6 py-4">#{{ $invoice->id }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-bold rounded {{ $invoice->status == 'locked' ? 'bg-green-100 text-green-800' : ($invoice->status == 'submitted' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800') }}">
                        {{ strtoupper($invoice->status) }}
                    </span>
                </td>
                <td class="px-6 py-4 space-x-2">
                    @if($invoice->status == 'draft')
                    <form method="POST" action="/invoice/{{ $invoice->id }}/submit" class="inline">
                        @csrf
                        <button type="submit" class="text-blue-600 hover:underline">Submit to FBR</button>
                    </form>
                    @endif
                    <a href="/invoice/{{ $invoice->id }}/pdf" class="text-gray-600 hover:underline">PDF</a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('invoiceChart');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: {!! json_encode($monthlyData->keys()) !!},
        datasets: [{
            label: 'Invoices Per Month',
            data: {!! json_encode($monthlyData->values()) !!},
            backgroundColor: 'rgba(37, 99, 235, 0.2)',
            borderColor: 'rgba(37, 99, 235, 1)',
            borderWidth: 2
        }]
    },
    options: {
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1 }
            }
        }
    }
});
</script>
@endsection
