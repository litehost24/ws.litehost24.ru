<?php
namespace App\Http\Controllers;

use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SubscriptionController extends BaseController
{
    public function show(): View | RedirectResponse
    {
        if (!Auth::user()->isAdmin()) {
            return redirect()->route('home');
        }

        $subscriptions = Subscription::orderBy('created_at', 'desc')->get();

        return view('subscriptions.show', [
            'subscriptions' => $subscriptions,
        ]);
    }
}