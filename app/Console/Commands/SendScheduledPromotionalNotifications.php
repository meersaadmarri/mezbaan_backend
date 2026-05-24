<?php

namespace App\Console\Commands;

use App\Models\PromotionalCampaign;
use App\Services\PromotionalNotificationService;
use Illuminate\Console\Command;

class SendScheduledPromotionalNotifications extends Command
{
    protected $signature = 'notifications:send-scheduled';

    protected $description = 'Send promotional push campaigns whose scheduled_at time has passed';

    public function handle(PromotionalNotificationService $promotional): int
    {
        $due = PromotionalCampaign::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No scheduled campaigns due.');

            return self::SUCCESS;
        }

        foreach ($due as $campaign) {
            $this->info("Sending campaign #{$campaign->id}: {$campaign->title}");
            $promotional->sendCampaign($campaign);
        }

        $this->info("Processed {$due->count()} campaign(s).");

        return self::SUCCESS;
    }
}
