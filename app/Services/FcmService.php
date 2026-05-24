<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FcmService
{
    private const TOKEN_CACHE_PREFIX = 'fcm_google_access_token_';

    public function isConfigured(): bool
    {
        return $this->configuredProjectIds() !== [];
    }

    /**
     * @return list<string>
     */
    public function configuredProjectIds(): array
    {
        $ids = [];
        foreach (array_keys(config('firebase.projects', [])) as $projectId) {
            if ($this->credentialsPathForProject($projectId) !== null) {
                $ids[] = $projectId;
            }
        }

        $legacy = config('firebase.project_id');
        $legacyPath = config('firebase.credentials_path');
        if (is_string($legacy) && $legacy !== ''
            && is_string($legacyPath) && is_readable($legacyPath)) {
            $ids[] = $legacy;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int Number of devices successfully notified
     */
    public function sendToUser(User|int $user, string $title, string $body, array $data = []): int
    {
        $userId = $user instanceof User ? $user->id : (int) $user;

        $tokens = DeviceToken::query()
            ->where('user_id', $userId)
            ->get(['fcm_token', 'firebase_project_id', 'app']);

        return $this->sendTokenRecords($tokens, $title, $body, $data);
    }

    /**
     * @param  Collection<int, DeviceToken>|list<DeviceToken>  $records
     */
    private function sendTokenRecords(Collection|array $records, string $title, string $body, array $data): int
    {
        if (! $this->isConfigured()) {
            Log::warning('FCM skipped: no Firebase credentials on server. Add JSON files to storage/app/firebase/');

            return 0;
        }

        $collection = $records instanceof Collection ? $records : collect($records);
        if ($collection->isEmpty()) {
            Log::info('FCM skipped: recipient has no registered device tokens');

            return 0;
        }

        $success = 0;
        $grouped = $collection->groupBy(fn (DeviceToken $t) => $this->resolveProjectIdForToken($t));

        foreach ($grouped as $projectId => $group) {
            $tokenList = $group->pluck('fcm_token')->filter()->unique()->values()->all();
            $success += $this->sendToTokensForProject(
                (string) $projectId,
                $tokenList,
                $title,
                $body,
                $data,
            );
        }

        return $success;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function sendToTokensForProject(
        string $projectId,
        array $tokens,
        string $title,
        string $body,
        array $data,
    ): int {
        if ($tokens === []) {
            return 0;
        }

        $accessToken = $this->getAccessTokenForProject($projectId);
        if ($accessToken === null) {
            Log::warning('FCM skipped: no credentials for project', ['project_id' => $projectId]);

            return 0;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $stringData = [];
        foreach ($data as $key => $value) {
            $stringData[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        $channelId = ($stringData['type'] ?? '') === 'promotional'
            ? 'mezban_promotional'
            : 'mezban_messages';

        $success = 0;

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => $stringData,
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'channel_id' => $channelId,
                            'sound' => 'default',
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                                'badge' => 1,
                            ],
                        ],
                    ],
                ],
            ];

            try {
                $response = Http::withToken($accessToken)
                    ->timeout(15)
                    ->post($url, $payload);

                if ($response->successful()) {
                    $success++;
                    Log::info('FCM sent', [
                        'project_id' => $projectId,
                        'token_prefix' => Str::limit($token, 12, ''),
                    ]);

                    continue;
                }

                $errorBody = $response->json();
                $errorCode = data_get($errorBody, 'error.details.0.errorCode')
                    ?? data_get($errorBody, 'error.status');

                if ($this->isInvalidTokenError($errorCode, $response->body())) {
                    DeviceToken::query()->where('fcm_token', $token)->delete();
                }

                Log::warning('FCM send failed', [
                    'project_id' => $projectId,
                    'token_prefix' => Str::limit($token, 12, ''),
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            } catch (\Throwable $e) {
                Log::error('FCM send exception', [
                    'project_id' => $projectId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $success;
    }

    private function resolveProjectIdForToken(DeviceToken $token): string
    {
        if (is_string($token->firebase_project_id) && $token->firebase_project_id !== '') {
            return $token->firebase_project_id;
        }

        $app = $token->app ?? 'consumer';
        foreach (config('firebase.projects', []) as $projectId => $meta) {
            if (in_array($app, $meta['apps'] ?? [], true)) {
                return (string) $projectId;
            }
        }

        return (string) (config('firebase.project_id') ?: 'mezbaan-f0641');
    }

    private function credentialsPathForProject(string $projectId): ?string
    {
        $projects = config('firebase.projects', []);
        $path = $projects[$projectId]['credentials'] ?? null;

        if (is_string($path) && is_readable($path)) {
            return $path;
        }

        if (config('firebase.project_id') === $projectId) {
            $legacy = config('firebase.credentials_path');
            if (is_string($legacy) && is_readable($legacy)) {
                return $legacy;
            }
        }

        return null;
    }

    private function isInvalidTokenError(?string $code, string $body): bool
    {
        if ($code === 'UNREGISTERED' || $code === 'INVALID_ARGUMENT') {
            return true;
        }

        return str_contains($body, 'UNREGISTERED')
            || str_contains($body, 'not a valid FCM registration token');
    }

    private function getAccessTokenForProject(string $projectId): ?string
    {
        $path = $this->credentialsPathForProject($projectId);
        if ($path === null) {
            return null;
        }

        $cacheKey = self::TOKEN_CACHE_PREFIX.$projectId;

        return Cache::remember($cacheKey, 3300, function () use ($path) {
            $json = json_decode((string) file_get_contents($path), true);
            if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
                Log::warning('FCM credentials JSON invalid', ['path' => $path]);

                return null;
            }

            $now = time();
            $jwt = JWT::encode([
                'iss' => $json['client_email'],
                'sub' => $json['client_email'],
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            ], $json['private_key'], 'RS256');

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                Log::error('FCM OAuth token request failed', [
                    'path' => $path,
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json('access_token');
        });
    }
}
