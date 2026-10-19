@php
    $products = [
        'di' => [
            'name' => 'Digital Invoice', 'path' => '/digital-invoice', 'pricing' => '/digital-invoice#pricing',
            'fit' => 'Businesses preparing FBR digital invoices, including batch imports and consultant-managed companies.',
            'steps' => ['Prepare buyer and product records, then create a draft or import rows using the template inside your account.', 'Review identifiers, quantities, HS classification, rates and validation errors. AI suggestions need your confirmation.', 'Test the workflow in Sandbox. Submit approved invoices using your configured production credentials and check the actual authority response.', 'Review invoice status, download PDFs and reconcile sales, tax and payments.'],
            'before' => 'Bring a small sample of your own invoice format with customer details removed. Ask us to demonstrate import mapping, a rejected row, its correction and the final PDF.',
            'scope' => 'AI usage is subject to the selected package allowance. White-label PDFs and API access depend on the package. Recurring automatic invoicing is not currently offered.',
            'image' => 'di-create.jpg', 'alt' => 'Example Digital Invoice draft screen with blank buyer fields and Sandbox indicator',
        ],
        'pra' => [
            'name' => 'NestPOS — PRA', 'path' => '/pos', 'pricing' => '/pos#pricing',
            'fit' => 'Restaurant and service businesses using the PRA product, with screens determined by business type, enabled settings and package.',
            'steps' => ['Choose your business type and package; set up services or menu items, tax settings and permitted staff roles.', 'Build a bill using customer lookup, products or deals. For restaurant workflows, choose dine-in, takeaway or delivery where enabled.', 'Use the available kitchen/table workflow, take payment and check the saved receipt and reporting status.', 'Reconcile cash, card receipts, customer balances and rider settlements in the relevant reports.'],
            'before' => 'Tell us your counter count, printer connections, paper size and kitchen workflow. Ask for a sample bill, kitchen ticket, preview and day-close demonstration.',
            'scope' => 'Hotel businesses can use room/stay and guest-folio workflows. Salon and workshop/service profiles provide relevant service workflows; they do not imply a separate complete ERP. Confirm your exact workflow in a demo. See the live package table for each entitlement.',
            'image' => 'pos-sale.jpg', 'alt' => 'Example PRA restaurant sale screen showing menu items, order types and empty cart',
        ],
        'fbr' => [
            'name' => 'FBR POS', 'path' => '/fbr-pos-landing', 'pricing' => '/fbr-pos-landing#pricing',
            'fit' => 'Goods retailers needing the FBR POS product, inventory, counter billing and associated reporting.',
            'steps' => ['Set up products, tax treatment, barcode or SKU and opening stock; configure the required integration and printers.', 'Build the customer bill, take payment and check the receipt and actual FBR response.', 'Review failed or queued submissions and resolve the specific error before retrying.', 'Use stock/purchase, transaction and sales reports; use returns or credit-note workflows against the original sale where supported.'],
            'before' => 'Bring an anonymised product list and the printer models you use. Ask us to show stock receipt, a sale, a return and how the authority response is tracked.',
            'scope' => 'Pharmacy and kitchen-ticket features depend on the selected package. Do not assume the PRA table board, kitchen display or public QR menu is included in FBR POS; confirm any required restaurant workflow before purchase.',
            'image' => 'fbr-stock.jpg', 'alt' => 'Example FBR stock screen showing low-stock alerts, purchase entry and supplier fields',
        ],
        'erps' => [
            'name' => 'Nest ERPS — Healthcare', 'path' => '/nest-erps', 'pricing' => '/contact',
            'fit' => 'Clinics, hospitals, laboratories and healthcare pharmacies. Other ERP verticals are enquiries for custom work.',
            'steps' => ['Agree organisation type, branches, departments, staff roles and the modules required for your organisation.', 'Use patient registration and appointments or OPD; enable clinical, lab, pharmacy or admission workflows as required.', 'Record services, prescriptions, results or admissions within the enabled modules and authorised user scope.', 'Reconcile billing and accounts reports. Agree your migration, training and acceptance checks before moving existing records.'],
            'before' => 'Request a guided demonstration with fictional patient data. Specify departments, user roles, daily patient volume and any existing data that needs migration.',
            'scope' => 'Healthcare modules are available according to company configuration and package. Registration availability is controlled separately. A school, factory or other custom ERP requires an agreed scope and delivery estimate; it is not an instant activation.',
            'image' => null, 'alt' => '',
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#052730">
    <title>Product guide, demos &amp; getting started — TaxNest</title>
    <meta name="description" content="Compare TaxNest Digital Invoice, PRA POS, FBR POS and Healthcare workflows. See example screens and prepare for a practical product demo.">
    <link rel="canonical" href="https://taxnest.pk/product-guide">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    @include('partials.font-css', ['fontFamilies' => 'playfair-display:400,700|inter:400,500,600,700'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .pg{font-family:Inter,system-ui,sans-serif;background:#FDFBF7;color:#1F2937;margin:0;line-height:1.65}
        .pg *{box-sizing:border-box}.pg a{color:#0A4D5C;text-underline-offset:3px}.pg a:focus-visible,.pg summary:focus-visible{outline:3px solid #E7BF3B;outline-offset:4px}
        .pg-wrap{max-width:1120px;margin:auto;padding:28px 20px}.pg-head{background:#052730;color:white}.pg-head a{color:white}.pg-nav{display:flex;flex-wrap:wrap;align-items:center;gap:18px}.pg-nav img{width:140px;height:auto}.pg h1,.pg h2{font-family:'Playfair Display',Georgia,serif;line-height:1.2}.pg h1{font-size:clamp(2rem,5vw,3.5rem);margin:35px 0 18px}.pg h2{font-size:2rem;margin:0 0 16px}.pg h3{font-size:1.1rem;font-weight:700;margin:20px 0 8px}.pg p{margin:10px 0}.pg-lead{max-width:760px;color:#D1D5DB}.pg-index{display:flex;flex-wrap:wrap;gap:10px;margin:25px 0}.pg-index a,.pg-button{display:inline-block;padding:10px 15px;border:1px solid #0A4D5C;border-radius:6px;text-decoration:none;font-weight:600}.pg-index a{background:white}.pg-card{scroll-margin-top:20px;padding:28px;margin:30px 0;background:white;border:1px solid #D1D5DB;border-radius:12px}.pg-cols{display:grid;grid-template-columns:1fr 1fr;gap:28px;align-items:start}.pg ol{list-style:decimal;padding-left:22px}.pg li{margin:10px 0}.pg-note{background:#F0F7F7;border-left:4px solid #0A4D5C;padding:14px 18px}.pg figure{margin:24px 0}.pg-screen{overflow:hidden;border:1px solid #D1D5DB;border-radius:8px}.pg-screen img{display:block;width:100%;height:auto}.pg-screen--pos img{margin-top:-4%}.pg figcaption{font-size:.85rem;color:#4B5563;margin-top:8px}.pg details{padding:14px 0;border-bottom:1px solid #D1D5DB}.pg summary{cursor:pointer;font-weight:600}.pg .pg-button{margin:10px 12px 0 0}.pg-small{font-size:.9rem;color:#4B5563}
        @media(max-width:700px){.pg-cols{grid-template-columns:1fr}.pg-card{padding:20px}.pg-wrap{padding:22px 16px}.pg h2{font-size:1.7rem}}
    </style>
</head>
<body class="pg">
<header class="pg-head"><div class="pg-wrap">
    <nav class="pg-nav" aria-label="Main"><a href="/"><img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest home"></a><a href="/tutorials">Video tutorials</a><a href="/download">Downloads</a><a href="/contact">Contact support</a></nav>
    <h1>See the workflow before you choose.</h1>
    <p class="pg-lead">Start with the product that fits your business. These written walkthroughs explain the main steps, what to bring to a demo and which details to confirm before purchase.</p>
</div></header>
<main class="pg-wrap">
    <nav class="pg-index" aria-label="Product guides">@foreach($products as $key => $product)<a href="#{{ $key }}">{{ $product['name'] }}</a>@endforeach</nav>
    <p class="pg-small">Example screens show existing demonstration data and may differ from your current theme or enabled modules. They are not current price lists, tax-rate advice or evidence of authority approval.</p>
    @foreach($products as $key => $product)
    <article class="pg-card" id="{{ $key }}" data-product-guide="{{ $key }}">
        <h2>{{ $product['name'] }}</h2><p>{{ $product['fit'] }}</p>
        <div class="pg-cols"><div><h3>A typical workflow</h3><ol>@foreach($product['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol></div>
        <div><h3>Make your demo useful</h3><p>{{ $product['before'] }}</p><h3>Package and setup scope</h3><p>{{ $product['scope'] }}</p></div></div>
        @if($product['image'])
        <figure><div class="pg-screen {{ $key !== 'di' ? 'pg-screen--pos' : '' }}"><img src="{{ asset('images/screenshots/'.$product['image']) }}" alt="{{ $product['alt'] }}" width="1280" loading="lazy"></div><figcaption>{{ $product['alt'] }}. Illustrative interface; check the current package table for permissions and prices.</figcaption></figure>
        @else
        <p class="pg-note">For Healthcare, request a guided demo using fictional records so you can review the relevant modules without exposing patient information.</p>
        @endif
        <a class="pg-button" href="{{ $product['path'] }}">Explore {{ $product['name'] }}</a><a class="pg-button" href="{{ $product['pricing'] }}">{{ $key === 'erps' ? 'Discuss scope and pricing' : 'Check current packages' }}</a><a class="pg-button" href="/contact">Request a demo</a>
    </article>
    @endforeach
    <section class="pg-card" id="getting-started"><h2>Agree the setup before going live.</h2>
        <details><summary>Data migration and opening balances</summary><p>Share a small anonymised sample first. Agree the supported columns, duplicate handling, opening stock or customer balances and how totals will be reconciled. Do not assume every file or historical record can be imported automatically. Confirm any migration charges with support.</p></details>
        <details><summary>Training, devices and printers</summary><p>Specify your roles, counters, branches, printer models and network arrangement. Ask for a demonstration of your required receipt, kitchen ticket and recovery workflow. Agree who will receive training and what is included before purchase.</p></details>
        <details><summary>WhatsApp, Caller ID and rider tracking</summary><p>Check package eligibility, any add-on cost and the currently published app on Downloads. Manual WhatsApp bill sharing and automatic delivery are separate capabilities. Confirm auto-send availability and setup with support. Caller ID depends on phone permissions; rider maps depend on location permission, connectivity and the age of the last update.</p></details>
        <details><summary>Human support and tutorials</summary><p>Published Urdu videos are available in the <a href="/tutorials">video library</a>; this guide supplies written starting steps for all four product lines. Use <a href="/contact">Contact</a> for current human support hours and demo arrangements. An in-panel AI assistant does not imply 24-hour human staffing. Agree any after-hours support separately.</p></details>
    </section>
    <p><a href="/privacy">Privacy and data handling</a> · <a href="/contact#responsibility">Software and business responsibilities</a></p>
</main>
<x-site-footer />
</body></html>
