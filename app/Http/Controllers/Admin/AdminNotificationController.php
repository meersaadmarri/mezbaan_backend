<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromotionalCampaign;
use App\Services\PromotionalNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminNotificationController extends Controller
{
    public function __construct(
        private readonly PromotionalNotificationService $promotional,
    ) {}

    /**
     * Send a promotional push immediately (broadcast).
     *
     * POST /api/admin/notifications/promotional
     * Body: { title, body, audience?: all|customers|business }
     */
    public function sendPromotional(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:1000',
            'audience' => ['nullable', Rule::in(['all', 'customers', 'business'])],
        ]);

        $campaign = PromotionalCampaign::create([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audience' => $validated['audience'] ?? 'all',
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        $campaign = $this->promotional->sendCampaign($campaign);

        return response()->json([
            'message' => 'Promotional notification processed.',
            'campaign' => $campaign,
        ]);
    }

    /**
     * Schedule or send later — if scheduled_at is omitted, sends immediately.
     *
     * POST /api/admin/notifications/campaigns
     */
    public function storeCampaign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:1000',
            'audience' => ['nullable', Rule::in(['all', 'customers', 'business'])],
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        $scheduledAt = isset($validated['scheduled_at'])
            ? \Carbon\Carbon::parse($validated['scheduled_at'])
            : null;

        $campaign = PromotionalCampaign::create([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audience' => $validated['audience'] ?? 'all',
            'scheduled_at' => $scheduledAt,
            'status' => $scheduledAt ? 'scheduled' : 'draft',
            'created_by' => $request->user()->id,
        ]);

        if (! $scheduledAt) {
            $campaign = $this->promotional->sendCampaign($campaign);
        }

        return response()->json([
            'message' => $scheduledAt
                ? 'Campaign scheduled.'
                : 'Campaign sent.',
            'campaign' => $campaign,
        ], 201);
    }

    /**
     * GET /api/admin/notifications/campaigns
     */
    public function indexCampaigns(): JsonResponse
    {
        $campaigns = PromotionalCampaign::query()
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['campaigns' => $campaigns]);
    }
}
