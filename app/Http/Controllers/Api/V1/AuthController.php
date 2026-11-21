<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($user->isLocked()) {
            abort(403, __('This account is locked.'));
        }

        if (! $user->hasVerifiedEmail()) {
            abort(403, __('Please confirm your email address first.'));
        }

        return response()->json([
            'token' => $user->createToken($request->string('device_name'))->plainTextToken,
        ], 201);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function user(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
