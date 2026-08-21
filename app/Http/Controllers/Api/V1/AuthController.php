<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Http\Requests\Api\UpdateAvatarRequest;
use App\Http\Requests\Api\UpdatePasswordRequest;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends ApiController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $user = User::where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password) || ! $user->is_active) {
            event(new Failed('web', $user, $credentials));

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        event(new Login('web', $user, $request->boolean('remember')));

        $token = $user->createToken('portal')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => (new UserResource($user))->resolve(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        event(new Logout('web', $user));

        return response()->json(['data' => ['message' => 'Logged out.']]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['data' => ['message' => __($status)]]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['data' => ['message' => __($status)]]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['password' => $request->string('password')->value()])->save();

        // Revoke every other token so stale sessions cannot linger.
        $current = $user->currentAccessToken();
        $tokens = $user->tokens();
        if ($current instanceof PersonalAccessToken) {
            $tokens->whereKeyNot($current->getKey());
        }
        $tokens->delete();

        return response()->json(['data' => ['message' => 'Password updated.']]);
    }

    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();

        $user->fill($request->only('name', 'phone',  'headline'))->save();

        return new UserResource($user);
    }

    public function updateAvatar(UpdateAvatarRequest $request): UserResource
    {
        $user = $request->user();

        $path = $request->file('avatar')->store('avatars', 'public');

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->forceFill(['avatar_path' => $path])->save();

        return new UserResource($user);
    }
}
