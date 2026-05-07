<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HsRateLinksController extends Controller
{
    private array $schedules = ['3rd_schedule', '8th_schedule', 'standard', 'reduced', 'zero_rated', 'exempt', 'fixed'];

    public function index(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $schedule = $request->input('schedule', '');
        $status = $request->input('status', '');

        $query = DB::table('fbr_hs_rate_links');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('hs_code', 'like', "%{$q}%")
                  ->orWhere('sro_number', 'like', "%{$q}%")
                  ->orWhere('notes', 'like', "%{$q}%");
            });
        }
        if ($schedule !== '') $query->where('schedule_type', $schedule);
        if ($status === 'active') $query->where('is_active', 1);
        if ($status === 'inactive') $query->where('is_active', 0);

        $rows = $query->orderBy('hs_code')->paginate(50)->appends($request->query());

        $stats = [
            'total'    => DB::table('fbr_hs_rate_links')->count(),
            'active'   => DB::table('fbr_hs_rate_links')->where('is_active', 1)->count(),
            'inactive' => DB::table('fbr_hs_rate_links')->where('is_active', 0)->count(),
            'auto_learned' => Schema::hasTable('hs_usage_patterns') ? DB::table('hs_usage_patterns')->count() : 0,
        ];

        return view('admin.hs-rate-links.index', [
            'rows'      => $rows,
            'stats'     => $stats,
            'schedules' => $this->schedules,
            'filters'   => compact('q', 'schedule', 'status'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'hs_code'       => 'required|string|max:20',
            'schedule_type' => 'required|string|in:'.implode(',', $this->schedules),
            'tax_rate'      => 'nullable|numeric|min:0|max:100',
            'rate_label'    => 'nullable|string|max:50',
            'sale_type'     => 'nullable|string|max:100',
            'sro_number'    => 'nullable|string|max:100',
            'sr_no'         => 'nullable|string|max:50',
            'uom'           => 'nullable|string|max:50',
            'notes'         => 'nullable|string|max:500',
        ]);
        $data['is_active'] = 1;
        $data['created_at'] = now();
        $data['updated_at'] = now();

        DB::table('fbr_hs_rate_links')->updateOrInsert(
            ['hs_code' => $data['hs_code'], 'schedule_type' => $data['schedule_type']],
            $data
        );

        return back()->with('success', "Mapping saved for HS {$data['hs_code']}");
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'tax_rate'   => 'nullable|numeric|min:0|max:100',
            'rate_label' => 'nullable|string|max:50',
            'sale_type'  => 'nullable|string|max:100',
            'sro_number' => 'nullable|string|max:100',
            'sr_no'      => 'nullable|string|max:50',
            'uom'        => 'nullable|string|max:50',
            'notes'      => 'nullable|string|max:500',
            'is_active'  => 'nullable|boolean',
        ]);
        $data['updated_at'] = now();
        DB::table('fbr_hs_rate_links')->where('id', $id)->update($data);
        return back()->with('success', 'Mapping updated.');
    }

    public function destroy($id)
    {
        DB::table('fbr_hs_rate_links')->where('id', $id)->delete();
        return back()->with('success', 'Mapping deleted.');
    }

    public function toggle($id)
    {
        $row = DB::table('fbr_hs_rate_links')->find($id);
        if (!$row) return back()->with('error', 'Not found.');
        DB::table('fbr_hs_rate_links')->where('id', $id)
            ->update(['is_active' => $row->is_active ? 0 : 1, 'updated_at' => now()]);
        return back()->with('success', 'Status toggled.');
    }

    public function exportCsv()
    {
        $rows = DB::table('fbr_hs_rate_links')->orderBy('hs_code')->get();
        $filename = 'fbr_hs_rate_links_'.date('Ymd_His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];
        return response()->stream(function () use ($rows) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['hs_code','schedule_type','tax_rate','rate_label','sale_type','sro_number','sr_no','uom','notes','is_active']);
            foreach ($rows as $r) {
                fputcsv($h, [$r->hs_code,$r->schedule_type,$r->tax_rate,$r->rate_label,$r->sale_type,$r->sro_number,$r->sr_no,$r->uom,$r->notes,$r->is_active]);
            }
            fclose($h);
        }, 200, $headers);
    }

    public function sampleCsv()
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="hs_rate_links_sample.csv"',
        ];
        return response()->stream(function () {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['hs_code','schedule_type','tax_rate','rate_label','sale_type','sro_number','sr_no','uom','notes']);
            fputcsv($h, ['3105.3000','3rd_schedule','5','5%','3rd Schedule Goods','3rd Schedule goods','51','KG','Fertilizers (DAP)']);
            fputcsv($h, ['8517.1300','3rd_schedule','18','18%','3rd Schedule Goods','3rd Schedule goods','50','NO','Smartphones']);
            fputcsv($h, ['0902','standard','18','18%','Goods at standard rate (default)','','','KG','Tea — standard rated']);
            fputcsv($h, ['0401','exempt','','Exempt','Exempt goods','6th Schedule','','Litre','Milk and cream']);
            fclose($h);
        }, 200, $headers);
    }

    public function importCsv(Request $request)
    {
        $request->validate([
            'csv_file'      => 'required|file|mimes:csv,txt|max:10240',
            'duplicate_mode'=> 'required|in:update,skip',
        ]);

        $path = $request->file('csv_file')->getRealPath();
        $h = fopen($path, 'r');
        if (!$h) return back()->with('error', 'Could not read CSV.');

        $header = fgetcsv($h);
        if (!$header) { fclose($h); return back()->with('error', 'Empty CSV.'); }

        $expected = ['hs_code','schedule_type','tax_rate','rate_label','sale_type','sro_number','sr_no','uom','notes'];
        $missing = array_diff($expected, $header);
        if (!empty($missing)) {
            fclose($h);
            return back()->with('error', 'Missing columns: '.implode(', ', $missing));
        }

        $idx = array_flip($header);
        $inserted = 0; $updated = 0; $skipped = 0; $errors = [];
        $line = 1;
        $duplicateMode = $request->input('duplicate_mode');

        while (($row = fgetcsv($h)) !== false) {
            $line++;
            $hsCode   = trim((string) ($row[$idx['hs_code']] ?? ''));
            $schedule = trim((string) ($row[$idx['schedule_type']] ?? ''));
            if ($hsCode === '' || $schedule === '') { $errors[] = "Line $line: missing hs_code or schedule_type"; continue; }
            if (!in_array($schedule, $this->schedules)) { $errors[] = "Line $line: invalid schedule '$schedule'"; continue; }

            $rate = $row[$idx['tax_rate']] ?? null;
            $rate = ($rate === '' || $rate === null) ? null : (float) $rate;

            $payload = [
                'hs_code'       => $hsCode,
                'schedule_type' => $schedule,
                'tax_rate'      => $rate,
                'rate_label'    => $row[$idx['rate_label']] ?? null,
                'sale_type'     => $row[$idx['sale_type']] ?? null,
                'sro_number'    => $row[$idx['sro_number']] ?? null,
                'sr_no'         => $row[$idx['sr_no']] ?? null,
                'uom'           => $row[$idx['uom']] ?? null,
                'notes'         => $row[$idx['notes']] ?? null,
                'is_active'     => 1,
                'updated_at'    => now(),
            ];

            $existing = DB::table('fbr_hs_rate_links')
                ->where('hs_code', $hsCode)->where('schedule_type', $schedule)->first();

            if ($existing) {
                if ($duplicateMode === 'skip') { $skipped++; continue; }
                DB::table('fbr_hs_rate_links')->where('id', $existing->id)->update($payload);
                $updated++;
            } else {
                $payload['created_at'] = now();
                DB::table('fbr_hs_rate_links')->insert($payload);
                $inserted++;
            }
        }
        fclose($h);

        $msg = "Imported: {$inserted} new, {$updated} updated, {$skipped} skipped";
        if (!empty($errors)) $msg .= '. Errors: '.implode(' | ', array_slice($errors, 0, 5));

        return back()->with('success', $msg);
    }
}
