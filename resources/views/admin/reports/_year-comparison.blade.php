@isset($comparison)
    <section aria-label="Year comparison" class="overflow-x-auto mb-6">
        <h3 class="font-bold">Year comparison</h3>
        <table class="w-full text-sm text-left">
            <thead>
                <tr>
                    <th scope="col">Metric</th>
                    <th scope="col">{{ $from }} to {{ $to }}</th>
                    <th scope="col">{{ $comparison['from'] }} to {{ $comparison['to'] }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($totals as $metric => $total)
                    <tr>
                        <th scope="row">{{ str($metric)->replace('_', ' ')->headline() }}</th>
                        <td>{{ $total }}</td>
                        <td>{{ $comparison['totals'][$metric] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endisset
