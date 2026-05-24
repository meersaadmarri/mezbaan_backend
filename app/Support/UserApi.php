<?php

namespace App\Support;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserApi
{
    /** Eloquent `with()` select list for nested user relations (owner, customer). */
    public const RELATION_SELECT = 'id,name,email,phone_number,fcm_token,role';

    /**
     * Standard user payload for all API responses.
     *
     * @return array<string, mixed>
     */
    public static function array(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'role' => $user->role,
            'fcm_token' => $user->fcm_token,
            'created_at' => $user->created_at?->toDateTimeString(),
        ];
    }

    public static function persistFcmToken(User $user, string $fcmToken, Request $request): void
    {
        $fcmToken = trim($fcmToken);
        if ($fcmToken === '') {
            return;
        }

        $user->update(['fcm_token' => $fcmToken]);

        $app = $request->input('app');
        if ($app === null) {
            $app = match ($user->role) {
                'business', 'admin' => 'business',
                default => 'consumer',
            };
        }

        DeviceToken::query()->updateOrCreate(
            ['fcm_token' => $fcmToken],
            [
                'user_id' => $user->id,
                'platform' => $request->input('platform'),
                'app' => $app,
                'firebase_project_id' => $request->input('firebase_project_id'),
            ],
        );

        Log::info('FCM token saved for user', [
            'user_id' => $user->id,
            'email' => $user->email,
            'app' => $app,
            'fcm_token' => $fcmToken,
        ]);
    }
}
