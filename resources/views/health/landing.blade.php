@php
    use App\Models\Subscription;
    use App\Services\ErpPublicPricing;
    use App\Services\HealthModuleService;
    use App\Support\HealthPanel;
    use App\Support\NestErps;

    $healthUser = auth('health')->user();
    $healthOwner = in_array($healthUser?->role, ['company_admin', 'super_admin'], true);
    $healthLogin = url(NestErps::loginPath(NestErps::HEALTH));
    $healthSignup = NestErps::registerPath(NestErps::HEALTH);
    $canStartHealthcare = !$healthUser && HealthPanel::registrationOpen() && $publicPlans->get(NestErps::HEALTH, collect())->isNotEmpty();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#092a33">
    <title>{{ NestErps::LABEL }} — Hospital, Laboratory and School</title>
    <meta name="description" content="Explore {{ NestErps::LABEL }} for hospitals and laboratories, see published Healthcare packages, and enquire about School ERP or a migration.">
    @include('partials.font-css', ['fontFamilies' => 'inter:400,500,600,700|playfair-display:600,700'])
    @vite(['resources/css/app.css'])
    <style>
        .erp-hub {
            --erp-petrol: #092a33;
            --erp-paper: #f7f8f5;
            --erp-teal: #09665e;
            --erp-muted: #526769;
            --erp-line: #d1ded9;
            --erp-focus: #075952;
            font-family: Inter, system-ui, sans-serif;
            color: var(--erp-petrol);
            background: var(--erp-paper);
        }
        .erp-hub :is(h1, h2) { font-family: "Playfair Display", Georgia, serif; }
        .erp-hub :is(a, button):focus-visible { outline: 2px solid var(--erp-focus); outline-offset: 3px; }
        .erp-hub :is(.erp-hub-hero, .erp-hub-upgrade, footer) { --erp-focus: #4fd6bd; }
        .erp-hub footer :is(h4, p) { color: #b8cfcb; }
        .erp-hub :is(section, article)[id] { scroll-margin-top: 5.5rem; }
        .erp-hub-width { width: min(100% - 2rem, 74rem); margin-inline: auto; }
        .erp-hub-header { position: sticky; top: 0; z-index: 10; background: #fff; border-bottom: 1px solid var(--erp-line); }
        .erp-hub-bar, .erp-hub-actions, .erp-hub-nav, .erp-hub-brand {
            display: flex; align-items: center; gap: .75rem;
        }
        .erp-hub-bar { min-height: 4rem; justify-content: space-between; }
        .erp-hub-brand { color: var(--erp-petrol); font-weight: 700; min-width: 0; gap: .55rem; }
        .erp-hub-brand img { display: block; width: 2rem; height: 2rem; flex: none; }
        .erp-hub-hero { background: radial-gradient(ellipse at right top, #175359 0, var(--erp-petrol) 67%); color: #fff; }
        .erp-hub-hero .erp-hub-width { padding-block: clamp(2.25rem, 7vw, 5rem); }
        .erp-hub h1 { max-width: 16ch; font-size: clamp(2.1rem, 6vw, 4.3rem); font-weight: 700; line-height: 1.08; }
        .erp-hub h2 { font-size: clamp(1.6rem, 3vw, 2.4rem); font-weight: 700; line-height: 1.2; }
        .erp-hub h3 { font-size: 1.125rem; font-weight: 700; }
        .erp-hub-hero p { max-width: 60ch; margin-block: .9rem 1.25rem; color: #dcece8; }
        .erp-hub-nav { flex-wrap: wrap; margin-top: 1.1rem; gap: .25rem 1rem; }
        .erp-hub-nav a { color: #b8e6db; display: inline-flex; align-items: center; min-height: 2.75rem; }
        .erp-hub-nav a, .erp-hub-text-link { text-decoration: underline; text-underline-offset: .22em; }
        .erp-hub-button {
            display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; max-width: 100%;
            color: #fff; background: var(--erp-teal); border: 1px solid var(--erp-teal); border-radius: .6rem;
            padding: .6rem .95rem; font-size: .875rem; font-weight: 700; text-align: center;
        }
        .erp-hub-button:hover { background: #075952; }
        .erp-hub-button-outline { background: #fff; border-color: #52716e; color: var(--erp-petrol); }
        .erp-hub-button-outline:hover { background: #ecf7f3; }
        .erp-hub-hero .erp-hub-button, .erp-hub-upgrade .erp-hub-button { border-color: #8bbdb1; }
        .erp-hub-section { padding-block: clamp(1.55rem, 5vw, 3.25rem); }
        .erp-hub-grid, .erp-hub-plans { display: grid; gap: 1rem; margin-top: 1rem; }
        .erp-hub-card {
            min-width: 0; background: #fff; border: 1px solid var(--erp-line); border-radius: .8rem;
            padding: clamp(1rem, 3vw, 1.35rem); overflow-wrap: anywhere;
        }
        .erp-hub-grid .erp-hub-card { display: flex; flex-direction: column; }
        .erp-hub-status { margin: .25rem 0 .65rem; color: var(--erp-teal); font-size: .875rem; font-weight: 700; }
        .erp-hub-muted { color: var(--erp-muted); }
        .erp-hub-card .erp-hub-actions { margin-top: auto; padding-top: .85rem; }
        .erp-hub-actions { flex-wrap: wrap; }
        .erp-hub-text-link { display: inline-flex; align-items: center; min-height: 2.75rem; color: var(--erp-teal); font-weight: 700; }
        .erp-hub-rates { margin-top: .75rem; }
        .erp-hub-rates > div {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: .25rem .8rem; padding-block: .35rem; border-top: 1px solid var(--erp-line);
        }
        .erp-hub-rates dd { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .65rem; font-weight: 700; }
        .erp-hub-rates .erp-hub-text-link { min-height: 2.75rem; }
        .erp-hub-pricing > section + section { margin-top: 1.1rem; }
        .erp-hub-pricing section h3 { margin-top: .65rem; }
        .erp-hub-plan-facts { display: flex; flex-wrap: wrap; gap: .25rem 1rem; margin-block: .4rem; }
        .erp-hub-upgrade { background: var(--erp-petrol); color: #fff; border-radius: .85rem; padding: clamp(1.1rem, 4vw, 2rem); margin-bottom: 2rem; }
        .erp-hub-upgrade p { max-width: 62ch; margin-block: .65rem 1rem; color: #dcece8; }
        @media (min-width: 42rem) {
            .erp-hub-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .erp-hub-plans { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body class="erp-hub">
<header class="erp-hub-header">
    <div class="erp-hub-width erp-hub-bar">
        <a href="{{ url('/') }}" class="erp-hub-brand" aria-label="{{ NestErps::LABEL }} home">
            <img src="{{ asset('images/brand/taxnest-mark.svg') }}" alt="" width="32" height="32">
            {{ NestErps::LABEL }}
        </a>
        <a href="{{ $healthLogin }}" class="erp-hub-button erp-hub-button-outline">{{ $healthUser ? 'Open Healthcare' : 'Healthcare login' }}</a>
    </div>
</header>

<main>
    <section class="erp-hub-hero" aria-labelledby="erp-hub-heading">
        <div class="erp-hub-width">
            <h1 id="erp-hub-heading">ERP for hospitals, labs and schools</h1>
            <p>Use the Healthcare panel for hospitals, labs, clinics and pharmacies. Enquire about School ERP or plan a move from standalone to ERP.</p>
            <div class="erp-hub-actions">
                <a href="#pricing" class="erp-hub-button">See ERP packages</a>
                <a href="#erp-upgrade" class="erp-hub-button erp-hub-button-outline">Plan an ERP move</a>
            </div>
            <nav class="erp-hub-nav" aria-label="ERP products">
                <a href="#hospital-erp">Hospital ERP</a>
                <a href="#lab-erp">Lab ERP</a>
                <a href="#school-erp">School ERP</a>
            </nav>
        </div>
    </section>

    <section id="verticals" class="erp-hub-width erp-hub-section" aria-labelledby="erp-options-heading">
        <h2 id="erp-options-heading">ERP options</h2>
        <div class="erp-hub-grid">
            <article id="hospital-erp" class="erp-hub-card" aria-labelledby="hospital-erp-title">
                <h3 id="hospital-erp-title">Hospital ERP</h3>
                <p class="erp-hub-status">Healthcare panel</p>
                <p class="erp-hub-muted">Patient records, outpatient and inpatient work, pharmacy and billing, with modules set by your package.</p>
                <div class="erp-hub-actions">
                    <a href="#pricing" class="erp-hub-text-link">Healthcare packages</a>
                    <a href="{{ $healthLogin }}" class="erp-hub-text-link">{{ $healthUser ? 'Open Healthcare' : 'Hospital login' }}</a>
                </div>
            </article>
            <article id="lab-erp" class="erp-hub-card" aria-labelledby="lab-erp-title">
                <h3 id="lab-erp-title">Lab ERP</h3>
                <p class="erp-hub-status">Shared Healthcare workspace</p>
                <p class="erp-hub-muted">A lab workspace for standalone laboratories and hospital labs with the relevant package and roles.</p>
                <div class="erp-hub-actions">
                    <a href="#pricing" class="erp-hub-text-link">Healthcare packages</a>
                    <a href="{{ $healthLogin }}" class="erp-hub-text-link">{{ $healthUser ? 'Open Healthcare' : 'Lab login' }}</a>
                </div>
            </article>
            <article id="school-erp" class="erp-hub-card" aria-labelledby="school-erp-title">
                <h3 id="school-erp-title">School ERP</h3>
                <p class="erp-hub-status">Enquiry only</p>
                <p class="erp-hub-muted">Talk to us about student records, admissions, terms and campuses.</p>
                <div class="erp-hub-actions">
                    <a href="{{ route('contact') }}" class="erp-hub-text-link">Enquire about School ERP</a>
                </div>
            </article>
        </div>
    </section>

    <section id="pricing" class="erp-hub-width erp-hub-section erp-hub-pricing" aria-label="ERP package pricing">
        <h2>Packages and pricing</h2>
        @forelse($publicPlans as $vertical => $packages)
            <section aria-labelledby="erp-package-{{ $vertical }}">
                <h3 id="erp-package-{{ $vertical }}">{{ NestErps::verticalLabel($vertical) }} package prices</h3>
                <p class="erp-hub-muted">Prices in PKR for the full billing period.</p>
                <div class="erp-hub-plans">
                    @foreach($packages as $plan)
                        <article class="erp-hub-card">
                            <h4 class="font-bold">{{ $plan->name }}</h4>
                            @if($plan->branch_limit !== null || $plan->user_limit !== null || $plan->health_department_limit !== null)
                                <p class="erp-hub-plan-facts erp-hub-muted">
                                    @if($plan->branch_limit !== null)<span>Branches: {{ $plan->getBranchLimitDisplay() }}</span>@endif
                                    @if($plan->user_limit !== null)<span>Users: {{ $plan->getUserLimitDisplay() }}</span>@endif
                                    @if($vertical === NestErps::HEALTH && $plan->health_department_limit !== null)<span>Departments: {{ $plan->health_department_limit === -1 ? 'Unlimited' : $plan->health_department_limit }}</span>@endif
                                </p>
                            @endif
                            @if($vertical === NestErps::HEALTH && $plan->health_modules)
                                <p class="erp-hub-muted">{{ collect(HealthModuleService::normalize($plan->health_modules))->map(fn ($module) => __(HealthModuleService::moduleLabelKey($module), [], 'en'))->join(' · ') }}</p>
                            @endif
                            <dl class="erp-hub-rates">
                                @foreach(ErpPublicPricing::prices($plan) as $cycle => $total)
                                    @php
                                        $period = Subscription::getCycleLabel($cycle);
                                        $selection = ['plan_id' => $plan->id, 'billing_cycle' => $cycle];
                                        $periodLink = match (true) {
                                            $vertical === NestErps::HEALTH && $healthOwner => route('health.package-checkout', $selection),
                                            $vertical === NestErps::HEALTH && $canStartHealthcare => url($healthSignup).'?'.http_build_query($selection),
                                            default => null,
                                        };
                                    @endphp
                                    <div>
                                        <dt>{{ $period }}</dt>
                                        <dd>
                                            <span>PKR {{ number_format($total, 2) }}</span>
                                            @if($periodLink)<a href="{{ $periodLink }}" class="erp-hub-text-link" aria-label="Choose {{ $plan->name }}, {{ $period }}">Choose</a>@endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                            <a href="{{ route('contact') }}" class="erp-hub-text-link">Enquire about {{ $plan->name }}</a>
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <p class="erp-hub-muted">Contact us for a package and price suited to your organisation.</p>
        @endforelse
        <div class="erp-hub-actions">
            <a href="{{ route('contact') }}" class="erp-hub-text-link">Request a quote</a>
            @if($canStartHealthcare)
                <a href="{{ $healthSignup }}" class="erp-hub-text-link">Start Healthcare signup</a>
            @endif
        </div>
    </section>

    <section id="erp-upgrade" class="erp-hub-width erp-hub-upgrade" aria-labelledby="erp-upgrade-heading">
        <h2 id="erp-upgrade-heading">Move from standalone to ERP</h2>
        <p>We can help plan your move from TaxNest POS, standalone billing or another system. Tell us what records need to carry over and which branches, roles and modules you need.</p>
        <a href="{{ route('contact', ['topic' => 'erp-upgrade']) }}" class="erp-hub-button">Plan your ERP move</a>
    </section>

    @include('partials.product-guide-link', ['productGuideKey' => 'erps'])
</main>
<x-site-footer :loginUrl="$healthLogin" loginLabel="Healthcare login" />
</body>
</html>
