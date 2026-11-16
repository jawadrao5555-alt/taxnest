<x-dynamic-component :component="$admin ? 'admin-layout' : ($company->product_type==='fbrpos' ? 'fbr-pos-layout' : 'pos-layout')">
    @include('madadgar.usage-content')
</x-dynamic-component>
