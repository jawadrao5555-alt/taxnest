<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit trail for FULL-ACCESS "Manage as Company" impersonation.
 *
 * When a super-admin is impersonating a company WITH write access
 * (session('impersonation') set and NOT read-only), every successful
 * state-changing request inside the company panels is recorded against the
 * admin in AdminAuditLog, so live changes made on a company's behalf stay
 * attributable. Read-only sessions have their writes blocked upstream by
 * ReadOnlyImpersonation, so there is nothing to log there; the admin panel
 * itself (admin/*) is the admin's own surface, not impersonation activity.
 *
 * Sits on the `web` group AFTER ReadOnlyImpersonation (runs after StartSession).
 */
class LogImpersonatedWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        // Snapshot the impersonation context BEFORE the request runs — a full-access
        // write could mutate or clear the session (e.g. anything that touches
        // session()->invalidate()), which would otherwise drop the audit row or
        // misattribute it. ReadOnlyImpersonation already blocks login/logout swaps,
        // but reading it here first keeps attribution correct regardless.
        $imp = $request->session()->get('impersonation');

        $response = $next($request);

        // Only full-access (non read-only) impersonation is audited here.
        if (!is_array($imp) || !empty($imp['readonly'])) {
            return $response;
        }

        // Reads never change state.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $response;
        }

        // The admin panel is the admin's own surface, not the impersonated company.
        $path = ltrim($request->path(), '/');
        if ($path === 'admin' || str_starts_with($path, 'admin/')) {
            return $response;
        }

        // Only record changes that actually went through.
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        AdminAuditLog::log(
            $imp['admin_id'] ?? auth('admin')->id(),
            'Full-access change while impersonating',
            'Company',
            $imp['company_id'] ?? null,
            [
                'company' => $imp['company_name'] ?? null,
                'method' => $request->method(),
                'path' => $path,
                'status' => $response->getStatusCode(),
            ]
        );

        return $response;
    }
}
