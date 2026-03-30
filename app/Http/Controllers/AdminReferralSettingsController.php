<?php

namespace App\Http\Controllers;

use App\Models\ProjectSetting;
use App\Services\ReferralPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AdminReferralSettingsController extends Controller
{
    public function index(): View
    {
        $pct = ProjectSetting::getInt('referral_project_cut_pct', ReferralPricingService::DEFAULT_PROJECT_CUT_PCT);

        return view('admin.referral-settings', [
            'projectCutPct' => $pct,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_cut_pct' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        ProjectSetting::setValue(
            'referral_project_cut_pct',
            (string) $data['project_cut_pct'],
            Auth::id() ? (int) Auth::id() : null
        );

        return redirect()->back()->with('status', 'saved');
    }
}
