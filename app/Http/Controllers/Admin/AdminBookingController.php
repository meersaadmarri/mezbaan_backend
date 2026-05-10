<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBookingController extends Controller
{
    /**
     * All platform bookings (admin). Matches mezban_business admin_dashboard booking card shape.
     *
     * GET /api/admin/bookings
     *
     * Query: status=pending|...|all (optional), per_page (optional, default 100, max 500)
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'all');
        $perPage = min(500, max(1, (int) $request->query('per_page', 100)));

        $query = Booking::query()
            ->with(['hall:id,name,city,status']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $bookings = $query->latest()->limit($perPage)->get();

        $rows = $bookings->map(function (Booking $booking) {
            $row = $booking->toArray();
            // Flutter admin_dashboard uses booking['halls']?['name'] (legacy key).
            $row['halls'] = $booking->hall;

            return $row;
        });

        return response()->json([
            'bookings' => $rows,
            'counts' => [
                'total' => Booking::count(),
                'pending' => Booking::where('status', 'pending')->count(),
            ],
        ]);
    }
}
