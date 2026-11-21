@php
    // The production deploy stamps this line from the checked-out release.
    // Ignore the source-controlled cache key and keep the lookup local.
    $worker = public_path('sw.js');
    $header = is_readable($worker) ? @file_get_contents($worker, false, null, 0, 1024) : false;
    $hasRelease = is_string($header) && preg_match(
        "~^const CACHE_VERSION = '(taxnest-[0-9]{8}-[0-9a-f]{8})'; // stamped on live by live-remote-apply\\.sh from the deployed SHA\\r?$~m",
        $header,
        $release
    );
@endphp

@if($hasRelease)
    <p class="no-print px-4 py-3 text-xs text-gray-600 dark:text-gray-400 print:hidden" data-software-version>
        <span>Software Version (Cloud)</span>
        <code class="select-all">{{ $release[1] }}</code>
    </p>
@endif
