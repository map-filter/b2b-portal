<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Вход по email и паролю, выдаёт Bearer-токен.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => 'Неверный email или пароль']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Пользователь заблокирован']);
        }

        $token = $user->createToken($request->input('device', 'web'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($this->withProfile($user)),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($this->withProfile($request->user()));
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function withProfile(User $user): User
    {
        return $user->load(['company.manager', 'company.defaultContract.priceType']);
    }
}
