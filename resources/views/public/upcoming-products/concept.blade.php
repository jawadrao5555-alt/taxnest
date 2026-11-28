@php
    $labels = match($concept) {
        'staff' => ['Shift roster', 'Check-in', 'Approval'],
        'distribution' => ['Warehouse', 'Dispatch', 'Recovery'],
        'migration' => ['Source fields', 'Map records', 'Reconcile'],
        'portfolio' => ['Legal entities', 'Installations', 'Group view'],
        'laboratory' => ['Specimen', 'Worklist', 'Review'],
        default => ['Intake', 'Review', 'Outcome'],
    };
@endphp
<figure class="tn-roadmap-concept">
    <figcaption>Concept illustration</figcaption>
    <ol aria-label="Illustrated workflow">
        @foreach($labels as $label)
            <li><span aria-hidden="true"></span>{{ $label }}</li>
        @endforeach
    </ol>
</figure>
