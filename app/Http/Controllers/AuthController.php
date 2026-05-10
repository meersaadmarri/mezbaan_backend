<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    // ─── Email + password (hall owner, customer, or admin) ──────────────────────
    // Admin accounts are not self-registered: create in DB/seed (role=admin), then use login.
    // Hall owners use register (default role business), then the same login endpoint.

    /**
     * Register a new user account (Mezban Business hall owner or customer — not admin).
     *
     * POST /api/auth/register
     * Body: { name, email, password, password_confirmation, phone_number?, role? }
     * role: optional; "business" (default, hall owner) or "customer".
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone_number' => 'nullable|string|max:20|unique:users,phone_number',
            'role' => ['nullable', Rule::in(['business', 'customer'])],
        ]);

        $role = $validated['role'] ?? 'business';

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phone_number' => $validated['phone_number'] ?? null,
            'role' => $role,
        ]);

        $token = $user->createToken($this->tokenAbilityName($role))->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'user' => $this->userResource($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Login with email + password (hall owner, admin, or customer with a password).
     *
     * POST /api/auth/login
     * POST /api/auth/admin/login (alias — identical)
     *
     * Body: { email, password }
     *
     * Response includes user.role: use "admin" vs "business" in the client to open the right app shell.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || $user->password === null || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'The email or password is incorrect.',
            ], 401);
        }

        // Keep existing tokens so partner phones stay signed in while testing from Postman / another device.
        // Call POST /auth/logout on each client to revoke that token only.
        $token = $user->createToken($this->tokenAbilityName($user->role))->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => $this->userResource($user),
            'token' => $token,
        ]);
    }

    // ─── Customer App ─────────────────────────────────────────────────────────────

    /**
     * Login or auto-register a customer by phone number.
     *
     * POST /api/auth/phone-login
     * Body: { phone_number, name? }
     */
    public function phoneLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => 'required|string|max:20',
            'name' => 'nullable|string|max:100',
        ]);

        $user = User::firstOrCreate(
            ['phone_number' => $validated['phone_number']],
            [
                'name' => $validated['name'] ?? 'Customer',
                'role' => 'customer',
            ]
        );

        // Always issue a fresh token on login
        $user->tokens()->delete();
        $token = $user->createToken('customer_app')->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => $this->userResource($user),
            'token' => $token,
        ]);
    }

    // ─── Shared ───────────────────────────────────────────────────────────────────

    /**
     * Return the authenticated user's profile.
     *
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userResource($request->user()),
        ]);
    }

    /**
     * Update the authenticated user's profile (consumer / any token user).
     *
     * PUT /api/auth/profile
     * Body: { name?, phone_number? }
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
        ]);

        $request->user()->update($validated);

        return response()->json([
            'message' => 'Profile updated.',
            'user' => $this->userResource($request->user()->fresh()),
        ]);
    }

    /**
     * Logout and revoke the current token.
     *
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    /**
     * Return a consistent user data shape across all endpoints.
     */
    private function userResource(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'role' => $user->role,
            'created_at' => $user->created_at?->toDateTimeString(),
        ];
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
