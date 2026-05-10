<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hall;
use App\Models\Message;
use Illuminate\Http\Request;

class MainController extends Controller
{
    /**
     * Get all approved halls
     */
    public function getHalls(Request $request)
    {
        $halls = Hall::where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($halls);
    }

    /**
     * Search halls by name
     */
    public function searchHalls(Request $request)
    {
        $query = $request->query('q');
        $halls = Hall::where('status', 'approved')
            ->where('name', 'LIKE', "%{$query}%")
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($halls);
    }

    /**
     * Submit a booking request
     */
    public function submitBooking(Request $request)
    {
        $request->validate([
            'hall_id' => 'required|exists:halls,id',
            'customer_name' => 'required|string',
            'customer_email' => 'nullable|email',
            'customer_phone' => 'nullable|string',
            'booking_date' => 'required|date',
            'slot' => 'required|string',
            'guests' => 'required|integer',
        ]);

        $booking = Booking::create([
            'hall_id' => $request->hall_id,
            'customer_id' => $request->user()?->id,
            'customer_name' => $request->customer_name,
            'customer_email' => $request->customer_email,
            'customer_phone' => $request->customer_phone,
            'booking_date' => $request->booking_date,
            'slot' => $request->slot,
            'guests' => $request->guests,
            'status' => 'pending',
        ]);

        return response()->json([
            'status' => 'success',
            'booking_id' => $booking->id,
        ]);
    }

    /**
     * Send a message
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'content' => 'required|string',
        ]);

        $message = Message::create([
            'booking_id' => $request->booking_id,
            'sender_id' => $request->user()->id,
            'content' => $request->content,
            'is_read' => false,
        ]);

        return response()->json([
            'status' => 'success',
            'message_id' => $message->id,
        ]);
    }

    /**
     * Get messages for a booking
     */
    public function getMessages($bookingId)
    {
        $messages = Message::where('booking_id', $bookingId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages);
    }
}
