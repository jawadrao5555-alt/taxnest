<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\{AdminAuditLog, AdminUser, Company};
use App\Services\SaasLocalBillRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};

class AdminLocalBillRecordController extends Controller
{
    private function company(int $companyId): Company
    {
        AdminUser::requireSuperAdministrator();
        abort_unless(Schema::hasTable('saas_local_bill_records'), 503);
        return Company::where('product_type', 'pos')->findOrFail($companyId);
    }

    public function index(int $companyId)
    {
        $company = $this->company($companyId);
        $records = DB::table('saas_local_bill_records')->where('company_id', $companyId)->orderByDesc('id')->paginate(50);
        $retain = DB::table('saas_local_bill_retention')->where('company_id', $companyId)->value('retain') ?? true;
        return response()->view('saas-admin.local-bill-records', compact('company', 'records', 'retain'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function download(int $companyId, int $id, SaasLocalBillRecord $archive)
    {
        $this->company($companyId);
        $data = $archive->read($companyId, $id);
        AdminAuditLog::log(auth('admin')->id(), 'SaaS local bill record exported', 'Company', $companyId, ['record_id' => $id]);
        return response()->json($data)->header('Cache-Control', 'private, no-store')
            ->header('Content-Disposition', 'attachment; filename="taxnest-local-record-'.$id.'.json"');
    }

    public function update(Request $request, int $companyId)
    {
        $this->company($companyId);
        $data = $request->validate(['retain' => ['required', 'boolean']]);
        DB::transaction(function () use ($companyId, $data) {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            DB::table('saas_local_bill_retention')->updateOrInsert(['company_id' => $companyId],
                ['retain' => (bool) $data['retain'], 'created_at' => now(), 'updated_at' => now()]);
            AdminAuditLog::log(auth('admin')->id(), 'SaaS local bill retention changed', 'Company', $companyId, ['retain' => (bool) $data['retain']]);
        });
        return back()->with('success', 'SaaS retention preference saved. Existing records are unchanged.');
    }

    public function destroy(int $companyId, int $id)
    {
        $this->company($companyId);
        DB::transaction(function () use ($companyId, $id) {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $deleted = DB::table('saas_local_bill_records')->where('company_id', $companyId)->where('id', $id)->delete();
            abort_unless($deleted, 404);
            AdminAuditLog::log(auth('admin')->id(), 'SaaS local bill record deleted', 'Company', $companyId, ['record_id' => $id]);
        });
        return back()->with('success', 'Selected SaaS bill record deleted. Financial summaries and independent backups are unchanged.');
    }
}
