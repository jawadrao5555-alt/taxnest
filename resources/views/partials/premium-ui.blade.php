{{-- Premium shell assets. Include after legacy head styles so the scoped visual layer wins without altering behaviour. --}}
<link rel="stylesheet" href="{{ asset('css/taxnest-premium.css') }}?v={{ @filemtime(public_path('css/taxnest-premium.css')) }}">
<link rel="stylesheet" href="{{ asset('css/premium-native.css') }}?v={{ @filemtime(public_path('css/premium-native.css')) }}">