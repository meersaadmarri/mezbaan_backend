<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVenueController extends Controller
{
    /**
     * Admin venue list (all halls + counts). Suspended/declined/pending included; public customer list only shows approved.
     *
     * GET /api/admin/venues
     * GET /api/admin/venue-requests
     *
     * Query: status=pending|approved|declined|suspended|all (default all)
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'all');

        $query = Hall::query()->with(['owner:id,name,email,phone_number']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $halls = $query->latest()->get();

        return response()->json([
            'counts' => $this->venueCounts(),
            'halls' => $halls,
        ]);
    }

    /**
     * Venues waiting for admin approval only (pending tab).
     *
     * GET /api/admin/venues/pending-approvals
     *
     * Same response shape as GET /api/admin/venues: counts (all statuses) + halls (only status=pending).
     */
    public function pendingApprovals(): JsonResponse
    {
        $halls = Hall::query()
            ->with(['owner:id,name,email,phone_number'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json([
            'counts' => $this->venueCounts(),
            'halls' => $halls,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function venueCounts(): array
    {
        return [
            'pending' => Hall::where('status', 'pending')->count(),
            'approved' => Hall::where('status', 'approved')->count(),
            'declined' => Hall::where('status', 'declined')->count(),
            'suspended' => Hall::where('status', 'suspended')->count(),
            'total' => Hall::count(),
        ];
    }

    /**
     * Approve a venue listing.
     * POST /api/admin/venues/{id}/approve
     */
    public function approve(int $id): JsonResponse
    {
        $hall = Hall::findOrFail($id);

        $hall->update([
            'status' => 'approved',
            'decline_reason' => null,
        ]);

        return response()->json([
            'message' => 'Venue approved.',
            'hall' => $hall->fresh()->load(['owner:id,name,email,phone_number']),
        ]);
    }

    /**
     * Decline a venue listing.
     * POST /api/admin/venues/{id}/decline
     */
    public function decline(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decline_reason' => 'required|string|max:2000',
        ]);

        $hall = Hall::findOrFail($id);

        $hall->update([
            'status' => 'declined',
            'decline_reason' => $validated['decline_reason'],
        ]);

        return response()->json([
            'message' => 'Venue declined.',
            'hall' => $hall->fresh()->load(['owner:id,name,email,phone_number']),
        ]);
    }

    /**
     * Update venue status (approve, decline, suspend, or return suspended venue to live).
     * POST /api/admin/venues/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:approved,declined,suspended',
            'decline_reason' => 'required_if:status,declined|nullable|string|max:2000',
        ]);

        $hall = Hall::findOrFail($id);

        if ($validated['status'] === 'declined' && empty(trim($validated['decline_reason'] ?? ''))) {
            return response()->json([
                'message' => 'A decline reason is required.',
            ], 422);
        }

        $payload = ['status' => $validated['status']];

        if ($validated['status'] === 'declined') {
            $payload['decline_reason'] = $validated['decline_reason'];
        } else {
            $payload['decline_reason'] = null;
        }

        $hall->update($payload);

        return response()->json([
            'message' => 'Venue status updated.',
            'hall' => $hall->fresh()->load(['owner:id,name,email,phone_number']),
        ]);
    }

    /**
     * Suspend a venue (hidden from public GET /api/halls until unsuspended).
     * POST /api/admin/venues/{id}/suspend
     */
    public function suspend(int $id): JsonResponse
    {
        $hall = Hall::findOrFail($id);

        $hall->update([
            'status' => 'suspended',
        ]);

        return response()->json([
            'message' => 'Venue suspended and removed from public listings.',
            'hall' => $hall->fresh()->load(['owner:id,name,email,phone_number']),
        ]);
    }

    /**
     * Restore a suspended venue to live (approved).
     * POST /api/admin/venues/{id}/unsuspend
     */
    public function unsuspend(int $id): JsonResponse
    {
        $hall = Hall::findOrFail($id);

        if ($hall->status !== 'suspended') {
            return response()->json([
                'message' => 'Only suspended venues can be unsuspended.',
            ], 422);
        }

        $hall->update([
            'status' => 'approved',
            'decline_reason' => null,
        ]);

        return response()->json([
            'message' => 'Venue is live again.',
            'hall' => $hall->fresh()->load(['owner:id,name,email,phone_number']),
        ]);
    }
}
