<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Services\FcmService;
use Illuminate\Console\Command;

class FcmHealthCheck extends Command
{
    protected $signature = 'fcm:health';

    protected $description = 'Verify Firebase push notification setup (credentials + device tokens)';

    public function handle(FcmService $fcm): int
    {
        $this->info('Mezban FCM health check');
        $this->newLine();

        $projects = $fcm->configuredProjectIds();
        if ($projects === []) {
            $this->error('No Firebase credentials found.');
            $this->line('Place service-account JSON files at:');
            $this->line('  storage/app/firebase/mezbaan-f0641.json  (consumer app)');
            $this->line('  storage/app/firebase/mezbaan-5ca22.json  (business app)');
            $this->line('Download from Firebase Console → Project settings → Service accounts.');

            return self::FAILURE;
        }

        $this->info('Configured Firebase projects: '.implode(', ', $projects));

        $tokens = DeviceToken::query()->count();
        $this->info("Registered device tokens in DB: {$tokens}");

        if ($tokens > 0) {
            $rows = DeviceToken::query()
                ->selectRaw('app, firebase_project_id, count(*) as c')
                ->groupBy('app', 'firebase_project_id')
                ->get();
            foreach ($rows as $row) {
                $this->line("  • {$row->app} / {$row->firebase_project_id}: {$row->c}");
            }
        } else {
            $this->warn('No device tokens yet — open each app, log in, allow notifications.');
        }

        $this->newLine();
        $this->info('Push notifications will work once tokens exist AND credentials match the app Firebase project.');

        return self::SUCCESS;
    }
}
