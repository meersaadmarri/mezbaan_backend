<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class DeviceTokenController extends Controller
{
    /**
     * Register or refresh an FCM device token for the authenticated user.
     *
     * POST /api/notifications/device-token
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => 'required|string|min:20|max:512',
            'platform' => ['nullable', Rule::in(['android', 'ios'])],
            'app' => ['nullable', Rule::in(['consumer', 'business'])],
            'firebase_project_id' => 'nullable|string|max:64',
        ]);

        $user = $request->user();
        $token = trim($validated['fcm_token']);

        $projectId = $validated['firebase_project_id'] ?? $this->defaultProjectForApp($validated['app'] ?? null);

        DeviceToken::query()->updateOrCreate(
            ['fcm_token' => $token],
            [
                'user_id' => $user->id,
                'platform' => $validated['platform'] ?? null,
                'app' => $validated['app'] ?? null,
                'firebase_project_id' => $projectId,
            ],
        );

        $user->update(['fcm_token' => $token]);

        Log::info('FCM device token registered', [
            'user_id' => $user->id,
            'app' => $validated['app'] ?? null,
            'firebase_project_id' => $projectId,
            'fcm_token' => $token,
        ]);

        return response()->json([
            'message' => 'Device token registered.',
            'ok' => true,
            'firebase_project_id' => $projectId,
            'fcm_token' => $token,
            'user' => $user->fresh()->toApiArray(),
        ]);
    }

    private function defaultProjectForApp(?string $app): ?string
    {
        foreach (config('firebase.projects', []) as $projectId => $meta) {
            if ($app !== null && in_array($app, $meta['apps'] ?? [], true)) {
                return (string) $projectId;
            }
        }

        return config('firebase.project_id');
    }

    /**
     * Remove token on logout or when FCM invalidates it.
     *
     * DELETE /api/notifications/device-token
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => 'required|string|min:20|max:512',
        ]);

        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('fcm_token', trim($validated['fcm_token']))
            ->delete();

        return response()->json(['ok' => true]);
    }
}
