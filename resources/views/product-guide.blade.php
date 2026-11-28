@php
    use App\Support\NestErps;

    $erpHub = route('erps.landing', [], false);
    $products = [
        'di' => [
            'name' => 'TaxNest Digital Invoice',
            'path' => route('di.landing', [], false),
            'fit' => 'Businesses preparing FBR digital invoices, including batch imports and consultant-managed companies.',
            'steps' => [
                'Prepare buyer and product records, then create a draft or import rows using the template inside your account.',
                'Review selling packs, base quantities and buyer details. Search FBR references for the applicable rate, schedule and unit. Confirm AI suggestions before saving.',
                'Test the workflow in Sandbox. Submit approved invoices using your configured production credentials and check the actual authority response.',
                'Review invoice status, download PDFs and reconcile sales, tax and payments.',
            ],
            'before' => 'Bring a small anonymised invoice sample. Ask for a demonstration of import mapping, a rejected row, its correction and the final PDF.',
            'scope' => 'AI usage is subject to the selected package allowance. White-label PDFs and API access depend on the package.',
            'image' => 'di-create.jpg',
            'alt' => 'Example Digital Invoice draft screen with blank buyer fields and Sandbox indicator',
        ],
        'pra' => [
            'name' => 'TaxNest PRA POS',
            'path' => route('pos.landing', [], false),
            'fit' => 'Restaurant and service counters, including businesses using local billing. The available screens depend on the business type and package.',
            'steps' => [
                'Choose your business type and package. Set up services or menu items, tax settings and permitted staff roles.',
                'Build a bill using customer lookup, products or deals. In restaurant mode, choose dine-in, takeaway or delivery where enabled.',
                'Use the available kitchen and table workflow, take payment, then review the saved receipt and its reporting status.',
                'Reconcile cash, card receipts, customer balances and rider settlements in the relevant reports.',
            ],
            'before' => 'Tell us your counter count, printer connections, paper size and kitchen workflow. Ask for a sample bill, kitchen ticket and day-close demonstration.',
            'scope' => 'Hotel businesses can use room and guest-folio workflows. Salon and workshop profiles provide their own service workflows. Review package features and optional reporting connections on the POS page.',
            'image' => 'pos-sale.jpg',
            'alt' => 'Example PRA restaurant sale screen showing menu items, order types and empty cart',
        ],
        'fbr' => [
            'name' => 'TaxNest FBR POS',
            'path' => route('fbrpos.landing', [], false),
            'fit' => 'Retail counters that manage sales and stock. School Fees POS and Hospital Collections POS have dedicated workflows for configured collection accounts.',
            'steps' => [
                'For retail, set up products, tax treatment, barcodes or SKUs, and opening stock. Configure the printer and the reporting connection for your account.',
                'Build a bill and take payment. If fiscal reporting is enabled, check the accepted receipt and the saved fiscal number.',
                'Review the saved status of queued or uncertain submissions before retrying.',
                'Use stock, purchases and sales reports. Demonstrate returns or credit notes against the original sale where supported.',
            ],
            'before' => 'For a retail demo, ask to see a sale, a receipt and a return. For a collection demo, ask for a partial payment, remaining balance and reprint.',
            'scope' => 'Pharmacy and kitchen-ticket features depend on the package. Confirm the specific counter and reporting workflow you need before setup.',
            'image' => 'fbr-stock.jpg',
            'alt' => 'Example FBR stock screen showing low-stock alerts, purchase entry and supplier fields',
            'workspaces_title' => 'Standalone collection workspaces',
            'workspaces' => [
                'school-standalone' => [
                    'name' => 'School Fees POS',
                    'description' => 'Find a student, choose a campus and fee period, and record a full or partial collection. Review the student ledger, outstanding fees and the saved receipt.',
                ],
                'hospital-standalone' => [
                    'name' => 'Hospital Collections POS',
                    'description' => 'Create a service invoice at the payment desk, record payments against it, and check the patient ledger, receipt and day-close totals.',
                ],
            ],
            'workspace_note' => 'Both collection desks use FBR POS sign-in for configured accounts. These are local collection records. Fiscal submission is blocked in the current School Fees and Hospital Collections workspaces.',
        ],
        'erps' => [
            'name' => NestErps::LABEL,
            'path' => $erpHub,
            'fit' => 'Hospital, Lab and School workspaces. Modules and access depend on your package and staff roles.',
            'steps' => [
                'Choose the workspace that matches your organisation, then confirm branches or campuses, modules and staff access.',
                'Review the workflows below with sample records. Agree which existing records should move before setting up the live workspace.',
            ],
            'before' => 'Bring a short list of the daily tasks and staff roles you want to see. Use fictional patient, sample or student records in the demo.',
            'scope' => 'The ERP page shows published prices and signup links when available for your workspace. Request a quote if rates are unpublished or online signup is closed.',
            'image' => null,
            'workspaces_title' => 'ERP workspaces',
            'workspaces' => [
                'hospital-erp' => [
                    'name' => 'Hospital ERP',
                    'description' => 'Register patients and manage outpatient or inpatient work, pharmacy and billing using the enabled Healthcare modules.',
                    'login' => route('health.login', [], false),
                    'login_label' => 'Hospital login',
                ],
                'lab-erp' => [
                    'name' => 'Lab ERP',
                    'description' => 'Use the Healthcare lab workspace for a standalone laboratory or hospital lab. Receive samples, record and review results, and prepare reports using the appropriate roles.',
                    'login' => route('health.login', [], false),
                    'login_label' => 'Lab login',
                ],
                'school-erp' => [
                    'name' => 'School ERP',
                    'description' => 'Manage student records, admissions, attendance and fees across campuses. Review gradebook and family access with the appropriate roles.',
                    'login' => route('school-erp.login', [], false),
                    'login_label' => 'School login',
                ],
            ],
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
    <meta name="description" content="Explore TaxNest Digital Invoice, PRA POS, FBR POS and Hospital, Lab and School ERP workflows. See example screens and prepare for a product demo.">
    <link rel="canonical" href="{{ route('product-guide') }}">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    @include('partials.font-css', ['fontFamilies' => 'playfair-display:400,700|inter:400,500,600,700'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .pg{font-family:Inter,system-ui,sans-serif;background:#FDFBF7;color:#1F2937;margin:0;line-height:1.65}
        .pg *{box-sizing:border-box}.pg a{color:#0A4D5C;text-underline-offset:3px}.pg a:focus-visible,.pg summary:focus-visible{outline:3px solid #E7BF3B;outline-offset:4px}
        .pg-wrap{max-width:1120px;margin:auto;padding:28px 20px}.pg-head{background:#052730;color:white}.pg-head a{color:white}.pg-nav{display:flex;flex-wrap:wrap;align-items:center;gap:18px}.pg-nav img{width:140px;height:auto}.pg h1,.pg h2{font-family:'Playfair Display',Georgia,serif;line-height:1.2}.pg h1{font-size:clamp(2rem,5vw,3.5rem);margin:35px 0 18px}.pg h2{font-size:2rem;margin:0 0 16px}.pg h3{font-size:1.1rem;font-weight:700;margin:20px 0 8px}.pg p{margin:10px 0}.pg-lead{max-width:760px;color:#D1D5DB}.pg-index{display:flex;flex-wrap:wrap;gap:10px;margin:25px 0}.pg-index a,.pg-button{display:inline-block;padding:10px 15px;border:1px solid #0A4D5C;border-radius:6px;text-decoration:none;font-weight:600}.pg-index a{background:white}.pg-card{scroll-margin-top:20px;padding:28px;margin:30px 0;background:white;border:1px solid #D1D5DB;border-radius:12px}.pg-cols{display:grid;grid-template-columns:1fr 1fr;gap:28px;align-items:start}.pg ol{list-style:decimal;padding-left:22px}.pg li{margin:10px 0}.pg-note{background:#F0F7F7;border-left:4px solid #0A4D5C;padding:14px 18px}.pg figure{margin:24px 0}.pg-screen{overflow:hidden;border:1px solid #D1D5DB;border-radius:8px}.pg-screen img{display:block;width:100%;height:auto}.pg-screen--pos img{margin-top:-4%}.pg figcaption{font-size:.85rem;color:#4B5563;margin-top:8px}.pg details{padding:14px 0;border-bottom:1px solid #D1D5DB}.pg summary{cursor:pointer;font-weight:600}.pg .pg-button{margin:10px 12px 0 0}.pg-small{font-size:.9rem;color:#4B5563}
        .pg-workspace-nav { display:flex; flex-wrap:wrap; gap:.5rem 1rem; margin:1rem 0; }
        .pg-workspace-nav a { font-size:.9rem; font-weight:600; text-decoration:underline; }
        .pg-workspaces { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(100%, 15rem), 1fr)); gap:.75rem; }
        .pg-workspace { padding:1rem; border:1px solid #D1D5DB; border-radius:8px; }
        .pg-workspace h4 { color:#052730; font-weight:700; }
        .pg-workspace p { margin:.4rem 0; }
        .pg-workspace[id] { scroll-margin-top:1rem; }
        .pg-workspace-links { display:flex; flex-wrap:wrap; gap:.55rem 1rem; margin-top:.7rem; font-size:.88rem; }
        .pg-workspace-links a { text-decoration:underline; }
        @media(max-width:700px){.pg-cols{grid-template-columns:1fr}.pg-card{padding:20px}.pg-wrap{padding:22px 16px}.pg h2{font-size:1.7rem}}
    </style>
</head>
<body class="pg">
<header class="pg-head"><div class="pg-wrap">
    <nav class="pg-nav" aria-label="Main">
        <a href="{{ url('/') }}"><img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest home"></a>
        <a href="{{ route('tutorials.page', [], false) }}">Video tutorials</a>
        <a href="{{ route('downloads.page', [], false) }}">Downloads</a>
        <a href="{{ route('contact', [], false) }}">Contact support</a>
    </nav>
    <h1>See the workflow before you choose.</h1>
    <p class="pg-lead">Start with the product that fits your business. These written walkthroughs explain the main steps, what to bring to a demo and which details to confirm before purchase.</p>
</div></header>
<main class="pg-wrap">
    <nav class="pg-index" aria-label="Product guides">
        @foreach($products as $key => $product)
            <a href="#{{ $key }}">{{ $product['name'] }}</a>
        @endforeach
    </nav>
    <p class="pg-small">Example screens show demonstration data. Package features and reporting options depend on your account.</p>
    @foreach($products as $key => $product)
        <article class="pg-card" id="{{ $key }}" data-product-guide="{{ $key }}">
            <h2>{{ $product['name'] }}</h2>
            <p>{{ $product['fit'] }}</p>
            @isset($product['workspaces'])
                <nav class="pg-workspace-nav" aria-label="{{ $product['workspaces_title'] }}">
                    @foreach($product['workspaces'] as $workspaceKey => $workspace)
                        <a href="#{{ $workspaceKey }}">{{ $workspace['name'] }}</a>
                    @endforeach
                </nav>
            @endisset
            <div class="pg-cols">
                <div>
                    <h3>A typical workflow</h3>
                    <ol>
                        @foreach($product['steps'] as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                </div>
                <div>
                    <h3>Make your demo useful</h3>
                    <p>{{ $product['before'] }}</p>
                    <h3>Package and setup scope</h3>
                    <p>{{ $product['scope'] }}</p>
                </div>
            </div>
            @isset($product['workspaces'])
                <section aria-labelledby="{{ $key }}-workspaces-title">
                    <h3 id="{{ $key }}-workspaces-title">{{ $product['workspaces_title'] }}</h3>
                    <div class="pg-workspaces">
                        @foreach($product['workspaces'] as $workspaceKey => $workspace)
                            <section id="{{ $workspaceKey }}" class="pg-workspace">
                                <h4>{{ $workspace['name'] }}</h4>
                                <p>{{ $workspace['description'] }}</p>
                                @isset($workspace['login'])
                                    <div class="pg-workspace-links">
                                        <a href="{{ $erpHub }}#{{ $workspaceKey }}">Explore {{ $workspace['name'] }}</a>
                                        <a href="{{ $workspace['login'] }}">{{ $workspace['login_label'] }}</a>
                                    </div>
                                @endisset
                            </section>
                        @endforeach
                    </div>
                    @isset($product['workspace_note'])
                        <p class="pg-small">{{ $product['workspace_note'] }}</p>
                        <a href="{{ route('fbrpos.login', [], false) }}">FBR POS login</a>
                    @endisset
                </section>
            @endisset
            @if($product['image'])
                <figure>
                    <div class="pg-screen {{ $key !== 'di' ? 'pg-screen--pos' : '' }}">
                        <img src="{{ asset('images/screenshots/'.$product['image']) }}" alt="{{ $product['alt'] }}" width="1280" loading="lazy">
                    </div>
                    <figcaption>{{ $product['alt'] }}.</figcaption>
                </figure>
            @endif
            <a class="pg-button" href="{{ $product['path'] }}">Explore {{ $product['name'] }}</a>
            <a class="pg-button" href="{{ $product['path'] }}#pricing">{{ $key === 'erps' ? 'Check ERP packages and signup' : 'Check current packages' }}</a>
            <a class="pg-button" href="{{ route('contact', [], false) }}">{{ $key === 'erps' ? 'Request an ERP quote' : 'Request a demo' }}</a>
        </article>
    @endforeach
    <section class="pg-card" id="getting-started"><h2>Agree the setup before going live.</h2>
        <details><summary>Data migration and opening balances</summary><p>Share a small anonymised sample first. Agree the supported columns, duplicate handling, opening stock or customer balances and how totals will be reconciled. Do not assume every file or historical record can be imported automatically. Confirm any migration charges with support.</p></details>
        <details><summary>Training, devices and printers</summary><p>Specify your roles, counters, branches, printer models and network arrangement. Ask for a demonstration of your required receipt, kitchen ticket and recovery workflow. Agree who will receive training and what is included before purchase.</p></details>
        <details><summary>WhatsApp, Caller ID and rider tracking</summary><p>Check package eligibility, any add-on cost and the currently published app on Downloads. Manual WhatsApp bill sharing and automatic delivery are separate capabilities. Confirm auto-send availability and setup with support. Caller ID depends on phone permissions; rider maps depend on location permission, connectivity and the age of the last update.</p></details>
        <details>
            <summary>Madadgar by product</summary>
            <p>PRA POS and FBR POS each have Madadgar chat in their signed-in panels. Open Madadgar Support to ask about the workflow for your account.</p>
            <p>Digital Invoice company admins can open the read-only Madadgar reports from Reports. Use the relevant Hospital, Lab or School ERP guide above for ERP setup and access.</p>
        </details>
        <details><summary>Human support and tutorials</summary><p>Published Urdu videos are available in the <a href="/tutorials">video library</a>; this guide supplies written starting steps for all four product lines. Use <a href="/contact">Contact</a> for current human support hours and demo arrangements. An in-panel AI assistant does not imply 24-hour human staffing. Agree any after-hours support separately.</p></details>
    </section>
    <p><a href="{{ route('privacy', [], false) }}">Privacy and data handling</a> · <a href="{{ route('contact', [], false) }}#responsibility">Software and business responsibilities</a></p>
</main>
<x-site-footer />
</body></html>
