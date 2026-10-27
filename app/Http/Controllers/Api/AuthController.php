<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\DesktopApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $user = $request->user();
        $user->tokens()->where('name', 'desktop')->delete();
        $token = $user->createToken('desktop')->plainTextToken;

        Auth::guard('web')->logout();

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => DesktopApiPresenter::user($user->fresh(['company', 'department', 'primaryRole'])),
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => DesktopApiPresenter::user($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Signed out successfully.',
        ]);
    }
}
