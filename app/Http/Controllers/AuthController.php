<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\UserApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Register a new user account (Mezban Business hall owner or customer — not admin).
     *
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone_number' => 'nullable|string|max:20|unique:users,phone_number',
            'role' => ['nullable', Rule::in(['business', 'customer'])],
            'fcm_token' => 'nullable|string|min:20|max:512',
            'platform' => ['nullable', Rule::in(['android', 'ios'])],
            'app' => ['nullable', Rule::in(['consumer', 'business'])],
            'firebase_project_id' => 'nullable|string|max:64',
        ]);

        $role = $validated['role'] ?? 'business';

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phone_number' => $validated['phone_number'] ?? null,
            'role' => $role,
        ]);

        if (! empty($validated['fcm_token'])) {
            UserApi::persistFcmToken($user, $validated['fcm_token'], $request);
            $user->refresh();
        }

        $token = $user->createToken($this->tokenAbilityName($role))->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'user' => UserApi::array($user),
            'token' => $token,
            'fcm_token' => $user->fcm_token,
        ], 201);
    }

    /**
     * Login with email + password.
     *
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'fcm_token' => 'nullable|string|min:20|max:512',
            'platform' => ['nullable', Rule::in(['android', 'ios'])],
            'app' => ['nullable', Rule::in(['consumer', 'business'])],
            'firebase_project_id' => 'nullable|string|max:64',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || $user->password === null || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'The email or password is incorrect.',
            ], 401);
        }

        if (! empty($validated['fcm_token'])) {
            UserApi::persistFcmToken($user, $validated['fcm_token'], $request);
        }

        $token = $user->createToken($this->tokenAbilityName($user->role))->plainTextToken;

        $user->refresh();

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => UserApi::array($user),
            'token' => $token,
            'fcm_token' => $user->fcm_token,
        ]);
    }

    /**
     * Login or auto-register a customer by phone number.
     *
     * POST /api/auth/phone-login
     */
    public function phoneLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => 'required|string|max:20',
            'name' => 'nullable|string|max:100',
            'fcm_token' => 'nullable|string|min:20|max:512',
            'platform' => ['nullable', Rule::in(['android', 'ios'])],
            'app' => ['nullable', Rule::in(['consumer', 'business'])],
            'firebase_project_id' => 'nullable|string|max:64',
        ]);

        $user = User::firstOrCreate(
            ['phone_number' => $validated['phone_number']],
            [
                'name' => $validated['name'] ?? 'Customer',
                'role' => 'customer',
            ]
        );

        if (! empty($validated['fcm_token'])) {
            UserApi::persistFcmToken($user, $validated['fcm_token'], $request);
        }

        $user->tokens()->delete();
        $token = $user->createToken('customer_app')->plainTextToken;

        $user->refresh();

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => UserApi::array($user),
            'token' => $token,
            'fcm_token' => $user->fcm_token,
        ]);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserApi::array($request->user()),
        ]);
    }

    /**
     * PUT /api/auth/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'phone_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'phone_number')->ignore($request->user()->id),
            ],
            'fcm_token' => 'sometimes|nullable|string|min:20|max:512',
            'platform' => ['sometimes', 'nullable', Rule::in(['android', 'ios'])],
            'app' => ['sometimes', 'nullable', Rule::in(['consumer', 'business'])],
            'firebase_project_id' => 'sometimes|nullable|string|max:64',
        ]);

        $fcmToken = $validated['fcm_token'] ?? null;
        unset($validated['fcm_token'], $validated['platform'], $validated['app'], $validated['firebase_project_id']);

        $user = $request->user();
        $user->update($validated);

        if (is_string($fcmToken) && $fcmToken !== '') {
            UserApi::persistFcmToken($user, $fcmToken, $request);
        }

        $user->refresh();

        return response()->json([
            'message' => 'Profile updated.',
            'user' => UserApi::array($user),
            'fcm_token' => $user->fcm_token,
        ]);
    }

  /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function tokenAbilityName(?string $role): string
    {
        return match ($role) {
            'admin' => 'admin_app',
            'business' => 'business_app',
            default => 'customer_app',
        };
    }
}
