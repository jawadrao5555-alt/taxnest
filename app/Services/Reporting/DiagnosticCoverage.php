<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/** Capability inventory is not a successful check of tenant data. */
final class DiagnosticCoverage
{
    private const FEATURES = [
        'pos.sales' => ['pos', 'Sales, returns, split payments and discounts', 'get_company_billing_summary'],
        'pos.day_close' => ['pos', 'Day Close, opening and counter cash snapshots', 'get_pra_day_close_evidence'],
        'pos.restaurant' => ['pos', 'Orders, tables, KOT, riders and settlements', null],
        'pos.printing' => ['pos', 'Printing, desktop agent and offline synchronization', 'get_company_operational_summary'],
        'pos.stock_accounts' => ['pos', 'Inventory, purchases, expenses and accounts', 'get_company_operational_summary'],
        'pos.fiscal' => ['pos', 'PRA submission and stored fiscal evidence', 'get_company_billing_summary'],
        'di.billing' => ['di', 'Digital invoices, credit notes and tax items', 'get_company_billing_summary'],
        'di.connection' => ['di', 'FBR connection, scenarios, retries and reconciliation', null],
        'fbrpos.billing' => ['fbrpos', 'FBR POS sales, returns and stored fiscal evidence', 'get_company_billing_summary'],
        'fbrpos.operations' => ['fbrpos', 'FBR POS drawers, pharmacy, stock and offline operation', null],
        'health.operations' => ['health', 'Healthcare billing, pharmacy, appointments and clinical modules', null],
        'erp.adapters' => ['erp', 'Other ERP modules and future adapter registration', null],
        'admin.companies' => ['admin', 'Company lifecycle, subscription periods, suspension and history', null],
        'admin.billing' => ['admin', 'Plans, payment proofs, renewals, sales and partners', null],
        'shared.access' => ['shared', 'Access roles, tenant isolation and security events', null],
        'shared.support' => ['shared', 'Support, notifications, language and customer feedback', null],
        'shared.platform' => ['shared', 'Queues, jobs, backups, releases and infrastructure', null],
        'shared.retention' => ['shared', 'Durable seven-day evidence, deletion/archive history and disconnected uploads', null],
    ];

    public function catalog(array $args = []): array
    {
        if (array_diff(array_keys($args), ['product', 'offset', 'limit'])) {
            throw ValidationException::withMessages(['arguments' => 'Only product, offset and limit are supported.']);
        }
        $v = validator($args, ['product' => 'nullable|in:pos,di,fbrpos,health,erp,admin,shared', 'offset' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:50'])->validate();
        $product = $v['product'] ?? null;
        $features = $this->features($product);
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            $uri = $route->uri();
            $group = match (true) {
                str_starts_with($uri, 'pos/') => 'pos', str_starts_with($uri, 'fbr-pos/') => 'fbrpos',
                str_starts_with($uri, 'health/') => 'health', str_starts_with($uri, 'admin/') => 'admin',
                str_contains($action, 'InvoiceController@') => 'di', default => 'shared',
            };
            if ($product !== null && $product !== $group) {
                continue;
            }
            // Exact actions only. New sibling actions must remain visible as unmapped gaps.
            $feature = in_array($action, ['App\\Http\\Controllers\\PosController@dayCloseReport', 'App\\Http\\Controllers\\PosController@closeDayReport', 'App\\Http\\Controllers\\PosController@saveDayOpening', 'App\\Http\\Controllers\\PosController@closeCounter'], true) ? 'pos.day_close' : null;
            $methods = $route->methods();
            sort($methods);
            $id = hash('sha256', implode(',', $methods).' '.$uri.' '.$action);
            $routes[$id] = ['id' => $id, 'methods' => $methods, 'uri_template' => $uri, 'action' => $action, 'product_hint' => $group,
                'feature_id' => $feature, 'mapping' => $feature ? 'PARTIAL' : 'UNMAPPED'];
        }
        ksort($routes);
        $offset = (int) ($v['offset'] ?? 0);
        $limit = (int) ($v['limit'] ?? 25);
        $total = count($routes);

        return ['catalog_version' => 1, 'status' => 'PARTIAL', 'complete' => false, 'tenant_checks_run' => 0,
            'features' => $features, 'feature_count' => count($features), 'implemented_complete_features' => 0,
            'route_inventory' => ['total' => $total, 'unmapped' => count(array_filter($routes, fn ($r) => $r['mapping'] === 'UNMAPPED')),
                'fingerprint' => hash('sha256', implode('|', array_keys($routes))), 'rows' => array_slice(array_values($routes), $offset, $limit),
                'pagination' => ['offset' => $offset, 'limit' => $limit, 'next_offset' => $offset + $limit < $total ? $offset + $limit : null]],
            'notes' => ['This is a first feature-family inventory, not exhaustive feature coverage or tenant health.',
                'Route discovery lists registered HTTP templates only; no routes are executed. Product hints are routing hints, not authorization.',
                'Jobs, commands, settings, schema changes and disconnected clients still require individual adapters. Route inventory alone cannot establish A-to-Z coverage.',
                'An available operation covers only part of its feature family. No seven-day durable retention guarantee is established here.']];
    }

    public function assessment(?array $dayClose = null): array
    {
        $features = $this->features('pos');
        foreach ($features as &$feature) {
            $feature['result'] = $feature['operation'] ? 'PARTIAL' : 'NOT_IMPLEMENTED';
            if ($feature['id'] === 'pos.day_close') {
                $feature['result'] = $dayClose['coverage']['status'] ?? 'UNAVAILABLE';
            }
        }
        unset($feature);

        return ['status' => 'PARTIAL', 'complete' => false, 'registered_feature_families' => count($features),
            'fully_checked_features' => 0, 'retention_verified' => false, 'features' => $features,
            'reason' => 'Partial operational signals and saved snapshots only. Cash movements, deleted evidence, all features and seven-day retention are not fully checked.'];
    }

    private function features(?string $product): array
    {
        $out = [];
        foreach (self::FEATURES as $id => [$group, $label, $operation]) {
            if ($product !== null && $group !== $product) {
                continue;
            }
            $out[] = ['id' => $id, 'product' => $group, 'label' => $label, 'capability' => $operation ? 'PARTIAL' : 'NOT_IMPLEMENTED', 'operation' => $operation,
                'retention_verified' => false];
        }

        return $out;
    }
}
