<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\TutorialVideo;

/**
 * Urdu tutorial video library (owner request, 2-3 Aug 2026):
 * - Public page /tutorials linked from the marketing top nav — shows ONLY
 *   videos the super admin has allowed (show_public), grouped inside product
 *   folders (NestPOS for now).
 * - In-app page /pos/tutorials for every company login (pos.auth only, NO
 *   company.approval — pending companies may learn while they wait, same
 *   precedent as the Madadgar bot). A company only sees videos whose
 *   required_feature its subscription actually includes.
 */
class TutorialController extends Controller
{
    public function publicIndex()
    {
        TutorialVideo::applyOwnerControls();

        $videos = TutorialVideo::publicVisible()
            ->orderBy('sort')->orderBy('id')->get();

        // Product folders in fixed order; unknown products go last.
        $products = [];
        $byProduct = $videos->groupBy(fn ($v) => $v->product ?: 'nestpos');
        foreach (TutorialVideo::PRODUCTS as $key => $label) {
            if ($byProduct->has($key) && $byProduct[$key]->isNotEmpty()) {
                $products[$key] = [
                    'label' => $label,
                    'count' => $byProduct[$key]->count(),
                    'groups' => TutorialVideo::groupedFrom($byProduct[$key]),
                ];
            }
        }
        foreach ($byProduct as $key => $list) {
            if (!isset(TutorialVideo::PRODUCTS[$key])) {
                $products[$key] = [
                    'label' => ucfirst((string) $key),
                    'count' => $list->count(),
                    'groups' => TutorialVideo::groupedFrom($list),
                ];
            }
        }

        return view('tutorials', ['products' => $products]);
    }

    public function posIndex()
    {
        TutorialVideo::applyOwnerControls();

        $user = auth('pos')->user();
        // PROD-safe: never lazy-access $user->company (Model::preventLazyLoading
        // is ON live) — resolve by id like FbrPosAuth/PosAuth do.
        $company = Company::find($user?->company_id ?? (app()->bound('currentCompanyId') ? app('currentCompanyId') : null));

        // NestPOS panel sirf apne product ki videos dikhaye (owner, 6 Aug 2026:
        // "NestPOS ki alag, FBR POS ki alag") — NULL product = legacy nestpos rows.
        $videos = TutorialVideo::published()
            ->where(fn ($q) => $q->where('product', 'nestpos')->orWhereNull('product'))
            ->orderBy('sort')->orderBy('id')->get()
            ->filter(fn (TutorialVideo $v) => $v->visibleToCompany($company) && $v->visibleToRole($user))
            ->values();

        return view('pos.tutorials', [
            'groups' => TutorialVideo::groupedFrom($videos),
        ]);
    }

    /**
     * FBR POS panel ki apni tutorials page (owner, 6 Aug 2026) — sirf 'fbrpos'
     * product ki videos. Same precedent as posIndex: auth only, NO
     * company.approval (pending companies may learn while they wait).
     */
    public function fbrIndex()
    {
        TutorialVideo::applyOwnerControls();

        $user = auth('fbrpos')->user();
        // PROD-safe: never lazy-access $user->company (Model::preventLazyLoading
        // is ON live) — resolve by id like FbrPosAuth/PosAuth do.
        $company = Company::find($user?->company_id ?? (app()->bound('currentCompanyId') ? app('currentCompanyId') : null));

        $videos = TutorialVideo::published()
            ->where('product', 'fbrpos')
            ->orderBy('sort')->orderBy('id')->get()
            ->filter(fn (TutorialVideo $v) => $v->visibleToCompany($company) && $v->visibleToRole($user))
            ->values();

        return view('fbr-pos.tutorials', [
            'groups' => TutorialVideo::groupedFrom($videos),
        ]);
    }
}
