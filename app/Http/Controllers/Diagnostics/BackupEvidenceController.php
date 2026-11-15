<?php
namespace App\Http\Controllers\Diagnostics;
use App\Http\Controllers\Controller;
use App\Services\Reporting\TenantBackupEvidence;
use Illuminate\Http\Request;
final class BackupEvidenceController extends Controller
{
    public function store(Request $request, string $source)
    {
        abort_if(strlen($request->getContent())>4096,413,'Evidence request too large.');
        return response()->json(app(TenantBackupEvidence::class)->ingest($source,(string)$request->bearerToken(),$request->json()->all()))
            ->header('Cache-Control','private, no-store');
    }
}
