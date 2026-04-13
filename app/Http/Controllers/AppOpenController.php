<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class AppOpenController extends Controller
{
    public function show(Request $request): View
    {
        return view('app.open', [
            'token' => trim((string) $request->query('t', '')),
        ]);
    }
}
