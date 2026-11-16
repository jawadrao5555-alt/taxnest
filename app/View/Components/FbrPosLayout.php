<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class FbrPosLayout extends Component
{
    public function render(): View
    {
        $company = auth('fbrpos')->user()?->company;
        if ($company?->isSchoolFeePilot()) {
            return view('fbr-pos.school-fees.layout', ['company' => $company,
                'schoolCanConfigure' => auth('fbrpos')->user()?->role === 'company_admin']);
        }
        if ($company?->isHospitalCollectionPilot()) {
            return view('fbr-pos.hospital.layout', ['company' => $company,
                'hospitalIsOwner' => auth('fbrpos')->user()?->role === 'company_admin']);
        }
        return view('layouts.fbr-pos-app');
    }
}
