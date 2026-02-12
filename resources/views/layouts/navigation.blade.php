<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" class="flex items-center space-x-2">
                        <svg class="h-8 w-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />
                        </svg>
                        <span class="text-xl font-bold text-gray-800 dark:text-white">TaxNest</span>
                    </a>
                </div>

                <div class="hidden space-x-6 sm:-my-px sm:ms-8 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        Dashboard
                    </x-nav-link>
                    @if(auth()->user()->role !== 'super_admin' || auth()->user()->company_id)
                    <x-nav-link href="/invoices" :active="request()->is('invoices*') || request()->is('invoice*')">
                        Invoices
                    </x-nav-link>
                    <x-nav-link href="/customer-profiles" :active="request()->is('customer-profiles*')">
                        Customers
                    </x-nav-link>
                    <x-nav-link href="/customers" :active="request()->is('customers*')">
                        Ledger
                    </x-nav-link>
                    <x-nav-link href="/billing/plans" :active="request()->is('billing*')">
                        Billing
                    </x-nav-link>
                    <x-nav-link href="/mis" :active="request()->is('mis*')">
                        MIS Reports
                    </x-nav-link>
                    @if(auth()->user()->role === 'company_admin')
                    <x-nav-link href="/branches" :active="request()->is('branches*')">
                        Branches
                    </x-nav-link>
                    <x-nav-link href="/company/users" :active="request()->is('company/users*')">
                        Team
                    </x-nav-link>
                    <x-nav-link href="/tax-overrides" :active="request()->is('tax-overrides*')">
                        Tax Rules
                    </x-nav-link>
                    <x-nav-link href="/company/fbr-settings" :active="request()->is('company/fbr*') || request()->is('company/profile*')">
                        Settings
                    </x-nav-link>
                    @endif
                    @endif
                    @if(auth()->user()->role === 'super_admin')
                    <x-nav-link href="/admin/dashboard" :active="request()->is('admin*')">
                        Admin Panel
                    </x-nav-link>
                    <x-nav-link href="/admin/companies" :active="request()->is('admin/companies*')">
                        Companies
                    </x-nav-link>
                    <x-nav-link href="/admin/users" :active="request()->is('admin/users*')">
                        Users
                    </x-nav-link>
                    <x-nav-link href="/admin/system-health" :active="request()->is('admin/system-health*')">
                        System Health
                    </x-nav-link>
                    <x-nav-link href="/admin/anomalies" :active="request()->is('admin/anomalies*')">
                        Anomalies
                    </x-nav-link>
                    @endif
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <div class="me-4">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if(auth()->user()->role === 'super_admin') bg-purple-100 text-purple-800
                        @elseif(auth()->user()->role === 'company_admin') bg-blue-100 text-blue-800
                        @elseif(auth()->user()->role === 'employee') bg-green-100 text-green-800
                        @else bg-gray-100 text-gray-800
                        @endif">
                        {{ ucfirst(str_replace('_', ' ', auth()->user()->role)) }}
                    </span>
                </div>
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>
                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            Profile
                        </x-dropdown-link>
                        <form method="POST" action="{{ route('toggle.dark-mode') }}">
                            @csrf
                            <x-dropdown-link href="#" onclick="event.preventDefault(); this.closest('form').submit();">
                                {{ auth()->user()->dark_mode ? 'Light Mode' : 'Dark Mode' }}
                            </x-dropdown-link>
                        </form>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                Log Out
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                Dashboard
            </x-responsive-nav-link>
            @if(auth()->user()->role !== 'super_admin' || auth()->user()->company_id)
            <x-responsive-nav-link href="/invoices" :active="request()->is('invoices*')">
                Invoices
            </x-responsive-nav-link>
            <x-responsive-nav-link href="/customer-profiles" :active="request()->is('customer-profiles*')">
                Customers
            </x-responsive-nav-link>
            <x-responsive-nav-link href="/customers" :active="request()->is('customers*')">
                Ledger
            </x-responsive-nav-link>
            <x-responsive-nav-link href="/billing/plans" :active="request()->is('billing*')">
                Billing
            </x-responsive-nav-link>
            <x-responsive-nav-link href="/mis" :active="request()->is('mis*')">
                MIS Reports
            </x-responsive-nav-link>
            @endif
            @if(auth()->user()->role === 'super_admin')
            <x-responsive-nav-link href="/admin/dashboard" :active="request()->is('admin*')">
                Admin Panel
            </x-responsive-nav-link>
            @endif
        </div>
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    Profile
                </x-responsive-nav-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                        Log Out
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
