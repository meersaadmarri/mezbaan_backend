<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\PromotionalCampaign;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PromotionalNotificationService
{
    public function __construct(
        private readonly FcmService $fcm,
    ) {}

    public function sendCampaign(PromotionalCampaign $campaign): PromotionalCampaign
    {
        if (! $this->fcm->isConfigured()) {
            $campaign->update([
                'status' => 'failed',
                'last_error' => 'Firebase is not configured on the server (FIREBASE_PROJECT_ID / credentials file).',
            ]);

            return $campaign->fresh();
        }

        $userIds = $this->resolveAudienceUserIds($campaign->audience);
        $tokens = DeviceToken::query()
            ->whereIn('user_id', $userIds)
            ->pluck('fcm_token', 'user_id')
            ->all();

        $uniqueTokens = array_values(array_unique(array_filter(array_values($tokens))));

        $success = $this->fcm->sendToTokens($uniqueTokens, $campaign->title, $campaign->body, [
            'type' => 'promotional',
            'campaign_id' => (string) $campaign->id,
        ]);

        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipients_count' => count($uniqueTokens),
            'success_count' => $success,
            'last_error' => $success === 0 && count($uniqueTokens) > 0
                ? 'No devices accepted the notification (check FCM tokens / Firebase setup).'
                : null,
        ]);

        Log::info('Promotional campaign sent', [
            'campaign_id' => $campaign->id,
            'recipients' => count($uniqueTokens),
            'success' => $success,
        ]);

        return $campaign->fresh();
    }

    /**
     * @return list<int>
     */
    private function resolveAudienceUserIds(string $audience): array
    {
        $query = User::query()->whereHas('deviceTokens');

        return match ($audience) {
            'customers' => $query->where('role', 'customer')->pluck('id')->all(),
            'business' => $query->where('role', 'business')->pluck('id')->all(),
            default => $query->pluck('id')->all(),
        };
    }
}
