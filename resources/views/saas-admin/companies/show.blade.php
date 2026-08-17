<x-admin-layout>
<div class="p-4 sm:p-6 max-w-5xl mx-auto" x-data="{ showDeleteModal: false }">
    <div class="flex flex-wrap items-center gap-3 mb-6">
        <a href="{{ route('saas.admin.companies') }}" class="text-gray-500 dark:text-gray-400 hover:text-indigo-400 transition text-sm">&larr; Back</a>
        <h1 class="text-2xl font-bold text-white min-w-0 break-words">{{ $company->name }}</h1>
        @php
            $sc = ['approved' => 'bg-emerald-900/30 text-emerald-400', 'active' => 'bg-emerald-900/30 text-emerald-400', 'pending' => 'bg-amber-900/30 text-amber-400', 'suspended' => 'bg-red-900/30 text-red-400', 'rejected' => 'bg-gray-800 text-gray-400'];
            $tc = ['di' => 'bg-emerald-900/30 text-emerald-400', 'pos' => 'bg-purple-900/30 text-purple-400', 'fbrpos' => 'bg-blue-900/30 text-blue-400'];
            $typeLabels = ['di' => 'Digital Invoice', 'pos' => 'PRA POS', 'fbrpos' => 'FBR POS'];
        @endphp
        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $sc[$company->status] ?? 'bg-gray-800 text-gray-400' }}">{{ $company->status }}</span>
        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $tc[$company->product_type] ?? 'bg-gray-800 text-gray-400' }}">{{ $typeLabels[$company->product_type] ?? $company->product_type }}</span>
        @if(!$company->trashed())
        <div class="w-full sm:w-auto sm:ml-auto flex flex-wrap items-center gap-2">
            @if(($company->company_status ?? null) === 'active')
            <form method="POST" action="{{ route('saas.admin.companies.impersonate', $company->id) }}" onsubmit="return confirm('Open this company in VIEW-ONLY mode? You will see their panel exactly as they do, but cannot make any changes.');">
                @csrf
                <input type="hidden" name="mode" value="view">
                <button type="submit" class="flex items-center gap-1.5 px-3 py-1.5 bg-amber-500/20 hover:bg-amber-500/40 text-amber-400 text-xs font-medium rounded-lg transition border border-amber-700">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    View as Company
                </button>
            </form>
            <form method="POST" action="{{ route('saas.admin.companies.impersonate', $company->id) }}" onsubmit="return confirm('FULL ACCESS: you will act AS this company and any change you make (invoices, settings, and live FBR/PRA submissions) is REAL. Continue?');">
                @csrf
                <input type="hidden" name="mode" value="full">
                <button type="submit" class="flex items-center gap-1.5 px-3 py-1.5 bg-red-600/20 hover:bg-red-600/40 text-red-400 text-xs font-medium rounded-lg transition border border-red-700">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                    Manage as Company
                </button>
            </form>
            @endif
            <a href="{{ route('saas.admin.companies.edit', $company->id) }}" class="flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600/20 hover:bg-indigo-600/40 text-indigo-400 text-xs font-medium rounded-lg transition border border-indigo-800">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Edit Profile
            </a>
        </div>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Company Details</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Name</span><span class="text-white font-medium text-right min-w-0 break-words">{{ $company->name }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Owner</span><span class="text-white text-right min-w-0 break-words">{{ $company->owner_name ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">NTN</span><span class="text-white text-right min-w-0 break-all">{{ $company->ntn ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">CNIC</span><span class="text-white text-right min-w-0 break-all">{{ $company->cnic ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Email</span><span class="text-white text-right min-w-0 break-all">{{ $company->email ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Phone</span><span class="text-white text-right min-w-0 break-all">{{ $company->phone ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">City</span><span class="text-white text-right min-w-0 break-words">{{ $company->city ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Address</span><span class="text-white text-right min-w-0 break-words">{{ $company->address ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Province</span><span class="text-white text-right min-w-0 break-words">{{ $company->province ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Franchise</span><span class="text-white text-right min-w-0 break-words">{{ $company->franchise->name ?? 'None' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Created</span><span class="text-white text-right min-w-0">{{ $company->created_at->format('d M Y, h:i A') }}</span></div>
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">
                @if($company->product_type === 'di') FBR Integration
                @elseif($company->product_type === 'fbrpos') FBR POS Integration
                @else PRA Integration
                @endif
            </h3>
            <div class="space-y-2 text-sm">
                @if($company->product_type === 'di')
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">FBR Environment</span><span class="text-white text-right min-w-0 break-words">{{ ucfirst($company->fbr_environment ?? 'N/A') }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">FBR Reg No</span><span class="text-white text-right min-w-0 break-all">{{ $company->fbr_registration_no ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">FBR Business</span><span class="text-white text-right min-w-0 break-words">{{ $company->fbr_business_name ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Token Expiry</span><span class="text-white text-right min-w-0">{{ $company->token_expiry_date ? $company->token_expiry_date->format('d M Y') : '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Connection</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $company->fbr_connection_status === 'connected' ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $company->fbr_connection_status ?? 'N/A' }}</span>
                </div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Last Submission</span><span class="text-white text-right min-w-0">{{ $company->last_successful_submission ? $company->last_successful_submission->format('d M Y h:i A') : '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Invoice Prefix</span><span class="text-white text-right min-w-0 break-all">{{ $company->invoice_number_prefix ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Compliance Score</span><span class="text-white text-right min-w-0">{{ $company->compliance_score ?? '—' }}%</span></div>
                @elseif($company->product_type === 'fbrpos')
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">FBR POS Environment</span><span class="text-white text-right min-w-0 break-words">{{ ucfirst($company->fbr_pos_environment ?? 'N/A') }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">FBR POS ID</span><span class="text-white text-right min-w-0 break-all">{{ $company->fbr_pos_id ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">FBR Reporting</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $company->fbr_reporting_enabled ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $company->fbr_reporting_enabled ? 'Enabled' : 'Disabled' }}</span>
                </div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">FBR POS Module</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $company->fbr_pos_enabled ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $company->fbr_pos_enabled ? 'Enabled' : 'Disabled' }}</span>
                </div>
                @else
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">PRA Environment</span><span class="text-white text-right min-w-0 break-words">{{ ucfirst($company->pra_environment ?? 'N/A') }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">POS ID</span><span class="text-white text-right min-w-0 break-all">{{ $company->pra_pos_id ?? '—' }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">PRA Reporting</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $company->pra_reporting_enabled ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $company->pra_reporting_enabled ? 'Enabled' : 'Disabled' }}</span>
                </div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Inventory</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $company->inventory_enabled ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">{{ $company->inventory_enabled ? 'Enabled' : 'Disabled' }}</span>
                </div>
                @php
                    // Canonical liveness check (Task 1062) — one verdict everywhere.
                    $praAgentOnline = $company->agentOnline();
                    // Version vs latest release (cached 10 min) + last self-update attempt.
                    $__latestAgentTag = \App\Http\Controllers\AgentManagementController::latestReleaseInfo()['tag'] ?? null;
                    $__latestAgentVer = ($__latestAgentTag && preg_match('/^v?(\d{1,2})\.(\d+)\.(\d+)$/', $__latestAgentTag, $__lm))
                        ? "{$__lm[1]}.{$__lm[2]}.{$__lm[3]}" : null;
                    $__agentOutdated = $__latestAgentVer && $company->agent_version
                        && version_compare($company->agent_version, $__latestAgentVer, '<');
                    $__updateStuck = $__agentOutdated && !empty($company->agent_update_error ?? null);
                @endphp
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Desktop Agent</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs text-right min-w-0 break-words {{ $praAgentOnline ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">
                        {{ $praAgentOnline ? 'Online' : ($company->agent_last_seen ? 'Offline' : 'Never connected') }}{{ $company->agent_version ? ' · v' . $company->agent_version : '' }}
                    </span>
                </div>
                @if($company->agent_version && $__latestAgentVer)
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Agent Version</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs text-right min-w-0 break-words {{ $__agentOutdated ? ($__updateStuck ? 'bg-red-900/30 text-red-400' : 'bg-amber-900/30 text-amber-400') : 'bg-emerald-900/30 text-emerald-400' }}">
                        @if(!$__agentOutdated)
                            Up to date (v{{ $company->agent_version }})
                        @elseif($__updateStuck)
                            UPDATE STUCK · v{{ $company->agent_version }} → v{{ $__latestAgentVer }}
                        @else
                            Outdated · v{{ $company->agent_version }} (latest v{{ $__latestAgentVer }})
                        @endif
                    </span>
                </div>
                @endif
                @if($__agentOutdated && ($company->agent_update_target ?? null))
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Last Update Attempt</span>
                    <span class="text-right min-w-0 break-words text-xs {{ $company->agent_update_error ? 'text-red-400' : 'text-gray-300' }}">
                        v{{ $company->agent_update_target }}{{ $company->agent_update_stage ? ' · ' . $company->agent_update_stage : '' }}{{ $company->agent_update_at ? ' · ' . $company->agent_update_at->diffForHumans() : '' }}
                        @if($company->agent_update_error)
                            <br>{{ \Illuminate\Support\Str::limit($company->agent_update_error, 140) }}
                        @endif
                    </span>
                </div>
                @endif
                @if(!is_null($company->agent_offline_mode))
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Offline Mode</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs text-right min-w-0 break-words {{ $company->agent_offline_mode ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">
                        @if($company->agent_offline_mode)
                            ON · Snapshot {{ $company->agent_snapshot_at ? $company->agent_snapshot_at->diffForHumans() : 'not captured' }}
                        @else
                            OFF
                        @endif
                    </span>
                </div>
                @endif
                @endif
            </div>
        </div>
    </div>

    {{-- ============================================================
         LOGIN CREDENTIALS — main company admin account. Passwords are
         one-way hashed (bcrypt) and can NEVER be displayed; the super
         admin can only set a NEW password here. Audit-logged.
         ============================================================ --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ showPw: false }">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                <span class="text-emerald-400">🔑</span> Login Credentials
            </h3>
        </div>
        @if($companyAdmin)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="space-y-2 text-sm">
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Login Email</span><span class="text-white font-medium text-right min-w-0 break-all">{{ $companyAdmin->email }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Account Name</span><span class="text-white text-right min-w-0 break-words">{{ $companyAdmin->name ?? '—' }}</span></div>
                @if($companyAdmin->username)
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Username</span><span class="text-white text-right min-w-0 break-all">{{ $companyAdmin->username }}</span></div>
                @endif
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Current Password</span><span class="text-gray-500 italic text-right min-w-0 break-words">Hidden — one-way encrypted, cannot be shown</span></div>
                <p class="text-xs text-gray-500 pt-1">Passwords are stored one-way encrypted for security — nobody (including admins) can view them. To help a locked-out company, set a new password on the right and share it with the owner.</p>
            </div>
            <form method="POST" action="{{ route('saas.admin.companies.resetPassword', $company->id) }}"
                  onsubmit="return confirm('Change this company\'s login password? Their old password will stop working immediately.');"
                  class="space-y-3">
                @csrf
                <label class="block text-xs font-medium text-gray-400">Set New Password</label>
                <div class="relative">
                    <input :type="showPw ? 'text' : 'password'" name="new_password" required minlength="6" maxlength="100"
                           autocomplete="new-password" placeholder="Type new password (min 6 characters)"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 pr-16 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 placeholder-gray-500">
                    <button type="button" @click="showPw = !showPw"
                            class="absolute inset-y-0 right-0 px-3 text-xs text-gray-400 hover:text-white"
                            x-text="showPw ? 'Hide' : 'Show'"></button>
                </div>
                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium rounded-lg">Change Password</button>
            </form>
        </div>
        @else
        <div class="text-center py-4 text-xs text-gray-500 border border-dashed border-gray-700 rounded-lg">No admin login account found for this company.</div>
        @endif
    </div>

    @if($company->product_type === 'fbrpos')
    {{-- ============================================================
         VPS / FISCAL DEVICE SETUP (super-admin) — everything needed to
         install FBRIMS + the Desktop Sync Agent on the cloud VPS for
         this client without logging into their panel. Access Code
         reveal is audit-logged.
         ============================================================ --}}
    <script>
        function vpsSetupCard() {
            return {
                showKey: false, code: null, loading: false,
                async reveal() {
                    this.loading = true;
                    try {
                        const r = await fetch('{{ route('saas.admin.companies.revealAccessCode', $company->id) }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                        });
                        const d = await r.json();
                        if (r.ok && d.code) { this.code = d.code; } else { alert(d.error || 'Failed to reveal access code'); }
                    } catch (e) { alert('Failed to reveal access code'); }
                    this.loading = false;
                },
                copy(input, btn) {
                    navigator.clipboard.writeText(input.value).then(() => {
                        const t = btn.textContent; btn.textContent = 'Copied!';
                        setTimeout(() => btn.textContent = t, 1200);
                    });
                }
            };
        }
    </script>
    @php
        // Canonical liveness check (Task 1062) — one verdict everywhere.
        $vpsAgentOnline = $company->agentOnline();
        $__vpsLatestTag = \App\Http\Controllers\AgentManagementController::latestReleaseInfo()['tag'] ?? null;
        $__vpsLatestVer = ($__vpsLatestTag && preg_match('/^v?(\d{1,2})\.(\d+)\.(\d+)$/', $__vpsLatestTag, $__vm))
            ? "{$__vm[1]}.{$__vm[2]}.{$__vm[3]}" : null;
        $__vpsOutdated = $__vpsLatestVer && $company->agent_version
            && version_compare($company->agent_version, $__vpsLatestVer, '<');
    @endphp
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="vpsSetupCard()">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-white">VPS / Fiscal Device Setup</h3>
            <span class="inline-flex items-center gap-1.5">
                @if($__vpsOutdated)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ !empty($company->agent_update_error ?? null) ? 'bg-red-900/30 text-red-400' : 'bg-amber-900/30 text-amber-400' }}"
                      title="{{ $company->agent_update_error ? 'Last update attempt: ' . \Illuminate\Support\Str::limit($company->agent_update_error, 200) : 'Waiting for self-update' }}">
                    {{ !empty($company->agent_update_error ?? null) ? 'UPDATE STUCK' : 'Outdated' }} · v{{ $company->agent_version }} → v{{ $__vpsLatestVer }}
                </span>
                @endif
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $vpsAgentOnline ? 'bg-emerald-900/30 text-emerald-400' : 'bg-gray-800 text-gray-400' }}">
                    Agent {{ $vpsAgentOnline ? 'Online' : ($company->agent_enabled ? 'Offline' : 'Disabled') }}{{ $company->agent_version ? ' · v' . $company->agent_version : '' }}
                </span>
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-sm mb-4">
            <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Connection Mode</span><span class="text-white text-right min-w-0 break-words">{{ ($company->fbr_connection_mode ?? 'cloud') === 'fiscal_device' ? 'Fiscal Device (Agent)' : 'Cloud' }}</span></div>
            <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">FBR POS ID</span><span class="text-white font-mono text-right min-w-0 break-all">{{ $company->fbr_pos_id ?? '—' }}</span></div>
            <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Environment</span><span class="text-white text-right min-w-0 break-words">{{ ucfirst($company->fbr_pos_environment ?? 'sandbox') }}</span></div>
            <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Agent Last Seen</span><span class="text-white text-right min-w-0">{{ $company->agent_last_seen ? \Carbon\Carbon::parse($company->agent_last_seen)->format('d M Y h:i A') : 'Never' }}</span></div>
        </div>
        <div class="space-y-3 text-sm">
            <div>
                <label class="block text-xs text-gray-400 mb-1">Agent Server URL</label>
                <div class="flex gap-2">
                    <input type="text" readonly value="{{ url('/api/agent') }}" x-ref="srv" class="w-full min-w-0 rounded-lg bg-gray-800 border-gray-700 text-gray-200 text-xs font-mono">
                    <button type="button" @click="copy($refs.srv, $el)" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-200 text-xs rounded-lg">Copy</button>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Company ID</label>
                    <div class="flex gap-2">
                        <input type="text" readonly value="{{ $company->id }}" x-ref="cid" class="w-full min-w-0 rounded-lg bg-gray-800 border-gray-700 text-gray-200 text-xs font-mono">
                        <button type="button" @click="copy($refs.cid, $el)" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-200 text-xs rounded-lg">Copy</button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Agent API Key</label>
                    <div class="flex gap-2">
                        <input :type="showKey ? 'text' : 'password'" readonly value="{{ $company->agent_api_key ?? '' }}" placeholder="Not generated yet" x-ref="agkey" class="w-full min-w-0 rounded-lg bg-gray-800 border-gray-700 text-gray-200 text-xs font-mono">
                        <button type="button" @click="showKey = !showKey" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-200 text-xs rounded-lg" x-text="showKey ? 'Hide' : 'Show'"></button>
                        <button type="button" @click="copy($refs.agkey, $el)" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs rounded-lg">Copy</button>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">FBR Access Code (IRIS)</label>
                <template x-if="!code">
                    <div class="flex items-center gap-2">
                        <span class="text-gray-500 text-xs">{{ $company->fbr_access_code ? 'Saved (encrypted) — reveal to view' : 'Not saved yet — client adds it in FBR POS settings' }}</span>
                        @if($company->fbr_access_code)
                        <button type="button" @click="reveal()" :disabled="loading" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs rounded-lg" x-text="loading ? '...' : 'Reveal'"></button>
                        @endif
                    </div>
                </template>
                <template x-if="code">
                    <div class="flex gap-2">
                        <input type="text" readonly :value="code" x-ref="ac" class="w-full min-w-0 rounded-lg bg-gray-800 border-amber-700 text-amber-300 text-xs font-mono">
                        <button type="button" @click="copy($refs.ac, $el)" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs rounded-lg">Copy</button>
                    </div>
                </template>
                <p class="text-xs text-gray-500 mt-1">Reveal is audit-logged. Needed once while installing the FBR fiscal component (FBRIMS) on the VPS.</p>
            </div>
        </div>
    </div>
    @endif

    {{-- Team & Last Logins: stamped on every successful login (all panels). --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
        <h3 class="text-sm font-semibold text-white mb-3">Team &amp; Last Logins</h3>
        @if(($teamUsers ?? collect())->isEmpty())
        <p class="text-xs text-gray-500">No user accounts for this company.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-static">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b border-gray-800">
                        <th class="py-2 pr-4 font-medium">User</th>
                        <th class="py-2 pr-4 font-medium">Role</th>
                        <th class="py-2 pr-4 font-medium">Last Login</th>
                        <th class="py-2 font-medium">IP</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($teamUsers as $tu)
                    <tr class="border-b border-gray-800/60">
                        <td class="py-2 pr-4">
                            <span class="text-white font-medium">{{ $tu->name }}</span>
                            <span class="block text-xs text-gray-500">{{ $tu->email }}</span>
                        </td>
                        <td class="py-2 pr-4 text-gray-300">{{ ucwords(str_replace('_', ' ', $tu->pos_role ?: ($tu->role ?? '—'))) }}</td>
                        <td class="py-2 pr-4">
                            @if($tu->last_login_at)
                            <span class="text-emerald-400">{{ \Carbon\Carbon::parse($tu->last_login_at)->format('d M Y, h:i A') }}</span>
                            <span class="block text-xs text-gray-500">{{ \Carbon\Carbon::parse($tu->last_login_at)->diffForHumans() }}</span>
                            @else
                            <span class="text-gray-500">Never (not tracked before this feature)</span>
                            @endif
                        </td>
                        <td class="py-2 text-gray-400 text-xs font-mono">{{ $tu->last_login_ip ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Usage & Revenue</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Total Users</span><span class="text-white font-medium text-right min-w-0">{{ $extraStats['total_users'] }}</span></div>
                @if($company->product_type === 'di')
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Total Invoices</span><span class="text-white font-medium text-right min-w-0">{{ number_format($extraStats['total_invoices']) }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Locked (FBR)</span><span class="text-emerald-400 font-medium text-right min-w-0">{{ number_format($extraStats['locked_invoices']) }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Drafts</span><span class="text-amber-400 font-medium text-right min-w-0">{{ number_format($extraStats['draft_invoices']) }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Total Revenue</span><span class="text-emerald-400 font-bold text-right min-w-0">PKR {{ number_format($extraStats['total_revenue'], 0) }}</span></div>
                @else
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Transactions</span><span class="text-white font-medium text-right min-w-0">{{ number_format($extraStats['total_transactions']) }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Today's Txns</span><span class="text-white font-medium text-right min-w-0">{{ number_format($extraStats['today_transactions']) }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Today's Revenue</span><span class="text-white font-medium text-right min-w-0">PKR {{ number_format($extraStats['today_revenue'] ?? 0, 0) }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">This Month</span><span class="text-white font-medium text-right min-w-0">PKR {{ number_format($extraStats['month_revenue'] ?? 0, 0) }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Total Revenue</span><span class="text-purple-400 font-bold text-right min-w-0">PKR {{ number_format($extraStats['total_revenue'], 0) }}</span></div>
                <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Last Sale</span><span class="text-white text-right min-w-0">{{ !empty($extraStats['last_sale_at']) ? \Carbon\Carbon::parse($extraStats['last_sale_at'])->format('d M Y, h:i A') : '—' }}</span></div>
                @endif
                @if($extraStats['active_subscription'])
                <div class="pt-2 border-t border-gray-800">
                    <div class="flex justify-between gap-2 min-w-0"><span class="text-gray-400 shrink-0">Plan</span><span class="text-indigo-400 font-medium text-right min-w-0 break-words">{{ $extraStats['active_subscription']->pricingPlan->name ?? 'N/A' }}</span></div>
                    <div class="flex justify-between gap-2 min-w-0 mt-1"><span class="text-gray-400 shrink-0">Billing</span><span class="text-white text-right min-w-0">{{ ucfirst(str_replace('_', ' ', $extraStats['active_subscription']->billing_cycle ?? 'N/A')) }}</span></div>
                </div>
                @else
                <div class="pt-2 border-t border-gray-800">
                    <p class="text-xs text-amber-400">No active subscription</p>
                </div>
                @endif
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Limit Overrides</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Set custom limits. Leave empty to use plan defaults.</p>
            <form method="POST" action="{{ route('saas.admin.companies.limits', $company->id) }}" class="space-y-3">
                @csrf
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Invoice / Transaction Limit</label>
                    <input type="number" name="invoice_limit_override" value="{{ $company->invoice_limit_override }}" placeholder="Plan default" min="0" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">User Limit</label>
                    <input type="number" name="user_limit_override" value="{{ $company->user_limit_override }}" placeholder="Plan default" min="0" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <div>
                    <label class="text-xs text-gray-400 mb-1 block">Branch Limit</label>
                    <input type="number" name="branch_limit_override" value="{{ $company->branch_limit_override }}" placeholder="Plan default" min="0" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600">
                </div>
                <button type="submit" class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Update Limits</button>
            </form>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
        <h3 class="text-sm font-semibold text-white mb-3">Quick Actions</h3>
        {{-- Package picked at registration — approval activates exactly this plan for 1 year --}}
        @if($company->status === 'pending' && $company->requestedPlan)
        <div class="mb-3 px-3 py-2 rounded-lg bg-indigo-900/20 border border-indigo-800/40">
            <p class="text-xs text-indigo-300"><span class="font-semibold">Requested package:</span> {{ $company->requestedPlan->name }} — Rs {{ number_format((float) $company->requestedPlan->sale_price) }}/year. Approving will activate this package for 1 full year.</p>
        </div>
        @endif
        <div class="flex flex-wrap gap-2">
            @if(!$company->trashed())
                @if($company->status === 'pending')
                <form method="POST" action="{{ route('saas.admin.companies.approve', $company->id) }}">@csrf<button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg transition font-medium">Approve</button></form>
                <form method="POST" action="{{ route('saas.admin.companies.reject', $company->id) }}">@csrf<button class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded-lg transition font-medium">Reject</button></form>
                @elseif($company->status === 'approved' || $company->status === 'active')
                <form method="POST" action="{{ route('saas.admin.companies.suspend', $company->id) }}">@csrf<button class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm rounded-lg transition font-medium">Suspend</button></form>
                @elseif($company->status === 'suspended' || $company->status === 'rejected')
                <form method="POST" action="{{ route('saas.admin.companies.activate', $company->id) }}">@csrf<button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg transition font-medium">Activate</button></form>
                @endif

                <button @click="showDeleteModal = true" class="px-4 py-2 bg-red-900/30 hover:bg-red-900/50 text-red-400 text-sm rounded-lg transition font-medium border border-red-800">Move to Bin</button>
            @else
                <p class="text-sm text-red-400">This company is in the bin (deleted on {{ $company->deleted_at->format('d M Y') }}).</p>
                <form method="POST" action="{{ route('saas.admin.companies.restore', $company->id) }}">@csrf<button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg transition font-medium">Restore</button></form>
            @endif
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-white mb-3">Change Company Type</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Switch between Digital Invoice, NestPOS (PRA), and FBR POS.</p>
        <div class="flex flex-wrap items-center gap-3">
            @if($company->product_type !== 'di')
            <form method="POST" action="{{ route('saas.admin.companies.changeType', $company->id) }}">
                @csrf
                <input type="hidden" name="product_type" value="di">
                <button type="submit" onclick="return confirm('Are you sure? This will change the company type to Digital Invoice.')" class="px-4 py-2 bg-emerald-600/20 hover:bg-emerald-600/40 text-emerald-400 text-sm rounded-lg transition font-medium border border-emerald-800">Switch to Digital Invoice</button>
            </form>
            @endif
            @if($company->product_type !== 'pos')
            <form method="POST" action="{{ route('saas.admin.companies.changeType', $company->id) }}">
                @csrf
                <input type="hidden" name="product_type" value="pos">
                <button type="submit" onclick="return confirm('Are you sure? This will change the company type to NestPOS (PRA).')" class="px-4 py-2 bg-purple-600/20 hover:bg-purple-600/40 text-purple-400 text-sm rounded-lg transition font-medium border border-purple-800">Switch to NestPOS</button>
            </form>
            @endif
            @if($company->product_type !== 'fbrpos')
            <form method="POST" action="{{ route('saas.admin.companies.changeType', $company->id) }}">
                @csrf
                <input type="hidden" name="product_type" value="fbrpos">
                <button type="submit" onclick="return confirm('Are you sure? This will change the company type to FBR POS.')" class="px-4 py-2 bg-blue-600/20 hover:bg-blue-600/40 text-blue-400 text-sm rounded-lg transition font-medium border border-blue-800">Switch to FBR POS</button>
            </form>
            @endif
        </div>
    </div>

    {{-- ============================================================
         SUBSCRIPTION OVERRIDE + USAGE LIMIT — admin-only controls
         ============================================================ --}}
    @php
        $activeSub = \App\Models\Subscription::where('company_id', $company->id)->orderByDesc('id')->first();
        $overrideActive = $activeSub && method_exists($activeSub, 'hasActiveOverride') && $activeSub->hasActiveOverride();
    @endphp
    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 mt-6" x-data="{ open: null }">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-bold text-white">Subscription Override &amp; Usage Limit</h3>
                <p class="text-xs text-gray-500 mt-1">Lifetime (no limits) or Temporary (until a date, optional invoice limit). Always overrides expiry.</p>
            </div>
            @if($overrideActive)
                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-emerald-600/20 text-emerald-400 border border-emerald-700">
                    Active: {{ $activeSub->overrideLabel() }}
                </span>
            @else
                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-gray-800 text-gray-400 border border-gray-700">No Override</span>
            @endif
        </div>

        @if($activeSub && $activeSub->override_reason)
            <div class="mb-4 px-3 py-2 bg-gray-800/60 rounded-lg text-xs text-gray-300">
                <span class="text-gray-500">Reason:</span> {{ $activeSub->override_reason }}
                @if($activeSub->override_by)
                    <span class="text-gray-500"> · by admin #{{ $activeSub->override_by }}</span>
                @endif
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
            <button type="button" @click="open = open === 'lifetime' ? null : 'lifetime'" class="px-3 py-2 bg-emerald-600/20 hover:bg-emerald-600/40 text-emerald-400 text-xs font-semibold rounded-lg border border-emerald-800 transition">Grant Lifetime</button>
            <button type="button" @click="open = open === 'temporary' ? null : 'temporary'" class="px-3 py-2 bg-blue-600/20 hover:bg-blue-600/40 text-blue-400 text-xs font-semibold rounded-lg border border-blue-800 transition">Grant Temporary</button>
            @if($overrideActive)
            <form method="POST" action="{{ route('saas.admin.companies.override.remove', $company->id) }}" onsubmit="return confirm('Remove the active override?');">
                @csrf @method('DELETE')
                <button type="submit" class="w-full px-3 py-2 bg-red-600/20 hover:bg-red-600/40 text-red-400 text-xs font-semibold rounded-lg border border-red-800 transition">Remove Override</button>
            </form>
            @endif
        </div>

        {{-- Lifetime form --}}
        <div x-show="open === 'lifetime'" x-cloak class="mt-4 p-4 bg-gray-800/40 rounded-lg border border-gray-700">
            <form method="POST" action="{{ route('saas.admin.companies.override.lifetime', $company->id) }}" class="space-y-3">
                @csrf
                <label class="text-xs text-gray-400 block">Reason (optional)</label>
                <input type="text" name="reason" maxlength="255" placeholder="e.g. Internal account, partner deal, etc." class="w-full bg-gray-900 border border-gray-700 rounded-lg text-white text-sm px-3 py-2">
                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm rounded-lg font-medium transition">Confirm Lifetime Free</button>
            </form>
        </div>

        {{-- Temporary form --}}
        <div x-show="open === 'temporary'" x-cloak class="mt-4 p-4 bg-gray-800/40 rounded-lg border border-gray-700">
            <form method="POST" action="{{ route('saas.admin.companies.override.temporary', $company->id) }}" class="space-y-3">
                @csrf
                <label class="text-xs text-gray-400 block">Access until (date)</label>
                <input type="date" name="until" required min="{{ now()->addDay()->toDateString() }}" class="w-full bg-gray-900 border border-gray-700 rounded-lg text-white text-sm px-3 py-2">
                <label class="text-xs text-gray-400 block">Invoice Limit (optional — leave empty for unlimited)</label>
                <input type="number" name="free_invoice_limit" min="1" max="1000000" placeholder="Empty = unlimited" class="w-full bg-gray-900 border border-gray-700 rounded-lg text-white text-sm px-3 py-2">
                <label class="text-xs text-gray-400 block">Reason (optional)</label>
                <input type="text" name="reason" maxlength="255" class="w-full bg-gray-900 border border-gray-700 rounded-lg text-white text-sm px-3 py-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg font-medium transition">Grant Temporary</button>
            </form>
        </div>
    </div>

    @if($company->product_type === 'pos' && auth('admin')->user()?->isSuperAdmin())
    <div class="bg-gradient-to-br from-amber-950/40 to-slate-900 border border-amber-800/40 rounded-xl p-5 mt-6" x-data="{ showCreate: false, editingId: null }">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                    <span class="text-amber-400">🗄️</span> Local Bills Archive — Viewer Accounts
                </h3>
                <p class="text-xs text-gray-400 mt-0.5">Dedicated read-only logins for the Local Bills Archive Portal. Login at <code class="text-amber-300">/pos/login</code> (auto-detected). POS admin/cashier ko ye accounts nazar nahi aate.</p>
            </div>
            <button type="button" @click="showCreate = !showCreate" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded-lg transition">+ New Viewer</button>
        </div>

        <div x-show="showCreate" x-cloak class="bg-gray-900/60 border border-amber-800/30 rounded-lg p-4 mb-3">
            <form method="POST" action="{{ route('saas.admin.companies.archive-viewer.store', $company->id) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                @csrf
                <input type="text" name="name" placeholder="Full Name" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 placeholder-gray-500">
                <input type="email" name="email" placeholder="Email (login)" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 placeholder-gray-500">
                <input type="text" name="password" placeholder="Password (min 8 chars)" required minlength="8" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 placeholder-gray-500">
                <div class="sm:col-span-3 flex justify-end gap-2">
                    <button type="button" @click="showCreate = false" class="px-3 py-1.5 bg-gray-800 text-gray-300 text-xs rounded-lg hover:bg-gray-700">Cancel</button>
                    <button type="submit" class="px-4 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded-lg">Create Account</button>
                </div>
            </form>
        </div>

        @if($archiveViewers->isEmpty())
            <div class="text-center py-6 text-xs text-gray-500 border border-dashed border-gray-700 rounded-lg">No Archive Viewer accounts yet for this company.</div>
        @else
            <div class="space-y-2">
                @foreach($archiveViewers as $av)
                <div class="bg-gray-900/60 border border-gray-800 rounded-lg p-3" x-data="{ edit: false }">
                    <div x-show="!edit" class="flex items-center justify-between gap-3 flex-wrap">
                        <div class="flex-1 min-w-[180px]">
                            <div class="text-sm font-medium text-white">{{ $av->name }} <span class="text-xs text-gray-500">#{{ $av->id }}</span></div>
                            <div class="text-xs text-gray-400">{{ $av->email }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-wider px-2 py-0.5 rounded {{ $av->is_active ? 'bg-emerald-900/40 text-emerald-300' : 'bg-gray-800 text-gray-500' }}">{{ $av->is_active ? 'Active' : 'Inactive' }}</span>
                            <button type="button" @click="edit = true" class="text-xs px-2.5 py-1 rounded bg-gray-800 text-gray-300 hover:bg-gray-700">Edit</button>
                            <form method="POST" action="{{ route('saas.admin.companies.archive-viewer.toggle', [$company->id, $av->id]) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs px-2.5 py-1 rounded {{ $av->is_active ? 'bg-amber-900/40 text-amber-300' : 'bg-emerald-900/40 text-emerald-300' }} hover:opacity-80">{{ $av->is_active ? 'Disable' : 'Enable' }}</button>
                            </form>
                            <form method="POST" action="{{ route('saas.admin.companies.archive-viewer.delete', [$company->id, $av->id]) }}" class="inline" onsubmit="return confirm('Delete this Archive Viewer account? They will lose access immediately.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs px-2.5 py-1 rounded bg-red-900/40 text-red-300 hover:bg-red-900/60">Delete</button>
                            </form>
                        </div>
                    </div>
                    <form x-show="edit" x-cloak method="POST" action="{{ route('saas.admin.companies.archive-viewer.update', [$company->id, $av->id]) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-1">
                        @csrf
                        @method('PUT')
                        <input type="text" name="name" value="{{ $av->name }}" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2">
                        <input type="email" name="email" value="{{ $av->email }}" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2">
                        <input type="text" name="password" placeholder="New password (leave blank to keep)" minlength="8" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 placeholder-gray-500">
                        <div class="sm:col-span-3 flex justify-end gap-2">
                            <button type="button" @click="edit = false" class="px-3 py-1.5 bg-gray-800 text-gray-300 text-xs rounded-lg hover:bg-gray-700">Cancel</button>
                            <button type="submit" class="px-4 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded-lg">Save</button>
                        </div>
                    </form>
                </div>
                @endforeach
            </div>
        @endif
    </div>
    @endif

    @if($company->product_type === 'pos' && auth('admin')->user()?->isSuperAdmin() && $exemptInternalBills->isNotEmpty())
    <div class="bg-gradient-to-br from-amber-950/30 to-slate-900 border border-amber-800/40 rounded-xl p-5 mt-6">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <div>
                <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                    <span class="text-amber-400">⚠️</span> Exempt-Internal Bills — Never Submitted to PRA
                </h3>
                <p class="text-xs text-gray-400 mt-0.5">
                    Ye bills historical hain — Task 760 se pehle all-exempt (bottle) bills submit nahi hoti thin.
                    Ab zero-rated bills PRA ke saath kaam karti hain (TaxRate 0). "Re-queue" karne par Desktop Agent
                    inhe next poll mein submit karega.
                </p>
            </div>
        </div>

        @if(session('success'))
            <div class="mb-3 px-3 py-2 bg-emerald-900/40 border border-emerald-700/40 rounded-lg text-xs text-emerald-300">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-3 px-3 py-2 bg-red-900/40 border border-red-700/40 rounded-lg text-xs text-red-300">{{ session('error') }}</div>
        @endif

        <div class="overflow-x-auto rounded-lg border border-amber-900/30">
            <table class="w-full text-xs text-left">
                <thead class="bg-amber-950/40 text-amber-300 uppercase tracking-wider">
                    <tr>
                        <th class="px-3 py-2 w-8"><input type="checkbox" id="exempt-check-all" class="accent-amber-500 cursor-pointer"></th>
                        <th class="px-3 py-2">ID</th>
                        <th class="px-3 py-2">Invoice #</th>
                        <th class="px-3 py-2">Total (Rs)</th>
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-amber-900/20">
                    @foreach($exemptInternalBills as $bill)
                    <tr class="bg-gray-900/40 hover:bg-amber-950/20 transition">
                        <td class="px-3 py-2">
                            <input type="checkbox" name="exempt_bill_ids[]" value="{{ $bill->id }}" class="exempt-bill-check accent-amber-500 cursor-pointer" checked>
                        </td>
                        <td class="px-3 py-2 text-gray-300 font-mono">{{ $bill->id }}</td>
                        <td class="px-3 py-2 text-white">{{ $bill->invoice_number ?: '—' }}</td>
                        <td class="px-3 py-2 text-white">{{ number_format((float) $bill->total_amount, 2) }}</td>
                        <td class="px-3 py-2 text-gray-400">{{ \Carbon\Carbon::parse($bill->created_at)->format('d M Y, h:i A') }}</td>
                        <td class="px-3 py-2"><span class="px-2 py-0.5 rounded bg-amber-900/40 text-amber-300 text-[10px] uppercase tracking-wider">{{ $bill->pra_status }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('saas.admin.companies.requeueExemptInternal', $company->id) }}" id="exempt-requeue-form" class="mt-3 flex items-center gap-3 flex-wrap">
            @csrf
            {{-- Hidden inputs populated by JS from checked checkboxes --}}
            <div id="exempt-ids-container"></div>
            <button type="button" id="exempt-requeue-btn"
                class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold rounded-lg transition disabled:opacity-40 disabled:cursor-not-allowed">
                Re-queue Selected for PRA
            </button>
            <span class="text-xs text-gray-500" id="exempt-selected-count">{{ $exemptInternalBills->count() }} selected</span>
        </form>
    </div>

    <script>
    (function () {
        var checkAll = document.getElementById('exempt-check-all');
        var checks   = document.querySelectorAll('.exempt-bill-check');
        var countEl  = document.getElementById('exempt-selected-count');
        var btn      = document.getElementById('exempt-requeue-btn');
        var form     = document.getElementById('exempt-requeue-form');
        var container = document.getElementById('exempt-ids-container');

        function updateCount() {
            var selected = document.querySelectorAll('.exempt-bill-check:checked');
            countEl.textContent = selected.length + ' selected';
            btn.disabled = selected.length === 0;
        }

        checkAll.addEventListener('change', function () {
            checks.forEach(function (c) { c.checked = checkAll.checked; });
            updateCount();
        });
        checks.forEach(function (c) {
            c.addEventListener('change', function () {
                checkAll.checked = Array.from(checks).every(function (x) { return x.checked; });
                updateCount();
            });
        });

        form.addEventListener('submit', function (e) {
            var selected = Array.from(document.querySelectorAll('.exempt-bill-check:checked'));
            if (selected.length === 0) { e.preventDefault(); return; }
            if (!confirm('Re-queue ' + selected.length + ' bill(s) as pending? The Desktop Agent will submit them to PRA at TaxRate 0 (zero tax charged). This cannot be undone automatically.')) {
                e.preventDefault();
                return;
            }
            container.innerHTML = '';
            selected.forEach(function (c) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'ids[]';
                inp.value = c.value;
                container.appendChild(inp);
            });
        });

        updateCount();
    })();
    </script>
    @endif

    @if($company->product_type === 'pos' && auth('admin')->user()?->isSuperAdmin())
    <div class="bg-gradient-to-br from-violet-950/40 to-slate-900 border border-violet-800/40 rounded-xl p-5 mt-6" x-data="{ showCreateLv: false }">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                    <span class="text-violet-400">🧾</span> Local Bills Portal — Viewer Accounts
                </h3>
                <p class="text-xs text-gray-400 mt-0.5">Dedicated read-only logins for LIVE + archived local (non-PRA) bills — the ONLY jagah jahan local bills nazar aate hain. Login at <code class="text-violet-300">/pos/login</code> (auto-detected). POS admin/cashier ko ye accounts nazar nahi aate.</p>
            </div>
            <button type="button" @click="showCreateLv = !showCreateLv" class="px-3 py-1.5 bg-violet-600 hover:bg-violet-700 text-white text-xs font-medium rounded-lg transition">+ New Viewer</button>
        </div>

        <div x-show="showCreateLv" x-cloak class="bg-gray-900/60 border border-violet-800/30 rounded-lg p-4 mb-3">
            <form method="POST" action="{{ route('saas.admin.companies.local-viewer.store', $company->id) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                @csrf
                <input type="text" name="name" placeholder="Full Name" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 placeholder-gray-500">
                <input type="email" name="email" placeholder="Email (login)" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 placeholder-gray-500">
                <input type="text" name="password" placeholder="Password (min 8)" required minlength="8" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 placeholder-gray-500">
                <div class="sm:col-span-3 flex justify-end">
                    <button type="submit" class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white text-xs font-medium rounded-lg">Create Local Bills Viewer</button>
                </div>
            </form>
        </div>

        @if($localViewers->isEmpty())
            <p class="text-xs text-gray-500">No Local Bills Viewer accounts yet.</p>
        @else
            <div class="space-y-2">
                @foreach($localViewers as $lv)
                <div class="bg-gray-900/60 border border-gray-800 rounded-lg p-3" x-data="{ edit: false }">
                    <div x-show="!edit" class="flex items-center justify-between gap-3 flex-wrap">
                        <div class="flex-1 min-w-[180px]">
                            <div class="text-sm font-medium text-white">{{ $lv->name }} <span class="text-xs text-gray-500">#{{ $lv->id }}</span></div>
                            <div class="text-xs text-gray-400">{{ $lv->email }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-wider px-2 py-0.5 rounded {{ $lv->is_active ? 'bg-emerald-900/40 text-emerald-300' : 'bg-gray-800 text-gray-500' }}">{{ $lv->is_active ? 'Active' : 'Inactive' }}</span>
                            <button type="button" @click="edit = true" class="text-xs px-2.5 py-1 rounded bg-gray-800 text-gray-300 hover:bg-gray-700">Edit</button>
                            <form method="POST" action="{{ route('saas.admin.companies.local-viewer.toggle', [$company->id, $lv->id]) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs px-2.5 py-1 rounded {{ $lv->is_active ? 'bg-amber-900/40 text-amber-300' : 'bg-emerald-900/40 text-emerald-300' }} hover:opacity-80">{{ $lv->is_active ? 'Disable' : 'Enable' }}</button>
                            </form>
                            <form method="POST" action="{{ route('saas.admin.companies.local-viewer.delete', [$company->id, $lv->id]) }}" class="inline" onsubmit="return confirm('Delete this Local Bills Viewer account? They will lose access immediately.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs px-2.5 py-1 rounded bg-red-900/40 text-red-300 hover:bg-red-900/60">Delete</button>
                            </form>
                        </div>
                    </div>
                    <form x-show="edit" x-cloak method="POST" action="{{ route('saas.admin.companies.local-viewer.update', [$company->id, $lv->id]) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-1">
                        @csrf
                        @method('PUT')
                        <input type="text" name="name" value="{{ $lv->name }}" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2">
                        <input type="email" name="email" value="{{ $lv->email }}" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2">
                        <input type="text" name="password" placeholder="New password (leave blank to keep)" minlength="8" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 placeholder-gray-500">
                        <div class="sm:col-span-3 flex justify-end gap-2">
                            <button type="button" @click="edit = false" class="px-3 py-1.5 bg-gray-800 text-gray-300 text-xs rounded-lg hover:bg-gray-700">Cancel</button>
                            <button type="submit" class="px-4 py-1.5 bg-violet-600 hover:bg-violet-700 text-white text-xs font-medium rounded-lg">Save</button>
                        </div>
                    </form>
                </div>
                @endforeach
            </div>
        @endif
    </div>
    @endif

    <div x-show="showDeleteModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="showDeleteModal = false">
        <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 w-full max-w-md mx-4" @click.stop>
            <h3 class="text-lg font-bold text-white mb-2">Move to Bin</h3>
            <p class="text-sm text-gray-400 mb-4">This will soft-delete "{{ $company->name }}". You can restore it from the Bin later, or permanently delete it there.</p>
            <form method="POST" action="{{ route('saas.admin.companies.delete', $company->id) }}">
                @csrf
                <div class="mb-4">
                    <label class="text-xs text-gray-400 mb-1 block">Reason (optional)</label>
                    <input type="text" name="reason" placeholder="e.g. Inactive, Duplicate, etc." class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 placeholder-gray-600">
                </div>
                <div class="flex gap-2 justify-end">
                    <button type="button" @click="showDeleteModal = false" class="px-4 py-2 bg-gray-800 text-gray-300 text-sm rounded-lg hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded-lg font-medium transition">Move to Bin</button>
                </div>
            </form>
        </div>
    </div>
</div>
</x-admin-layout>
