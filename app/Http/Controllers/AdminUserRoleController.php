<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserRoleController extends Controller
{
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'in:spy,user,partner,admin'],
        ]);

        $user->forceFill([
            'role' => $data['role'],
        ])->save();

        return response()->json([
            'ok' => true,
            'role' => $user->role,
        ]);
    }
}
