<?php

namespace App\Http\Controllers;

/**
 * FBR-panel Biometric Hazri (Aug 2026).
 *
 * Thin panel binding over PosBiometricController: the admin setup / PIN
 * mapping / Excel import pages are identical in behaviour, only the guard,
 * route names, views and mappable staff roles differ. The public ADMS
 * ingest endpoints (handshake / punch upload) stay on the shared parent —
 * they are token/serial-scoped and panel-agnostic, so an FBR company's
 * device pushes through the exact same URLs as a PRA company's.
 *
 * FBR team roles are pos_admin / pos_manager / pos_cashier only (no
 * waiter/kitchen/rider on this panel).
 */
class FbrPosBiometricController extends PosBiometricController
{
    protected function panelGuard(): string
    {
        return 'fbrpos';
    }

    protected function panelRoute(string $suffix): string
    {
        return 'fbrpos.bio-sync.' . $suffix;
    }

    protected function panelView(string $view): string
    {
        return 'fbr-pos.' . $view;
    }

    protected function mappableRoles(): array
    {
        return ['pos_admin', 'pos_manager', 'pos_cashier'];
    }
}
