<?php

namespace App\Http\Controllers;

use App\Services\Domain\DomainAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DomainCheckController extends Controller
{
    public function show(Request $request, DomainAvailabilityService $domains): View
    {
        $query = trim((string) $request->query('domain', ''));

        return view('domain-check.show', [
            'query' => $query,
            'result' => $query === '' ? null : $domains->checkInput($query),
        ]);
    }
}
