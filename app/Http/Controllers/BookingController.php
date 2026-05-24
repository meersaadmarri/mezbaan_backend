<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hall;
use App\Models\Message;
use App\Models\User;
use App\Support\UserApi;
use App\Services\ChatPushNotificationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    public function __construct(
        private readonly ChatPushNotificationService $chatPush,
    ) {}
    /**
     * Customer: all bookings / chat threads (venue requests + formal bookings).
     * GET /api/bookings/mine
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $bookings = Booking::query()
            ->where('customer_id', $user->id)
            ->with([
                'hall:id,name,city,venue_photos,status,address,owner_id',
                'hall.owner',
            ])
            ->withCount([
                'messages as unread_count' => function ($q) use ($user) {
                    $q->where('sender_id', '!=', $user->id)->where('is_read', false);
                },
            ])
            ->orderByDesc('updated_at')
            ->get();

        $this->attachLatestMessages($bookings);

        return response()->json(['bookings' => $this->bookingsMinePayload($bookings)]);
    }

    /**
     * Hall owner: incoming chats / bookings for their venues.
     * GET /api/bookings/inbox
     */
    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();

        $bookings = Booking::query()
            ->whereHas('hall', function ($q) use ($user) {
                $q->where('owner_id', $user->id);
            })
            ->with([
                'hall:id,name,city,venue_photos,status,address',
                'customer',
            ])
            ->withCount([
                'messages as unread_count' => function ($q) use ($user) {
                    $q->where('sender_id', '!=', $user->id)->where('is_read', false);
                },
            ])
            ->orderByDesc('updated_at')
            ->get();

        $this->attachLatestMessages($bookings);

        return response()->json(['bookings' => $this->bookingsPayload($bookings)]);
    }

    /**
     * Send interest from venue detail → opens or continues chat with the hall owner.
     *
     * POST /api/bookings/interest
     */
    public function sendInterest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hall_id' => [
                'required',
                'integer',
                Rule::exists('halls', 'id')->where('status', 'approved'),
            ],
            'message' => 'nullable|string|max:2000',
            'customer_name' => 'nullable|string|max:100',
            'customer_email' => 'nullable|email',
            'customer_phone' => 'nullable|string|max:20',
        ]);

        $hall = Hall::where('status', 'approved')->findOrFail($validated['hall_id']);
        $user = $request->user();

        $name = $validated['customer_name'] ?? $user->name ?? 'Guest';
        $email = $validated['customer_email'] ?? $user->email;
        $phone = $validated['customer_phone'] ?? $user->phone_number;

        $body = $validated['message'] ?? "I'm interested in \"{$hall->name}\". Please share availability and packages.";

        $booking = DB::transaction(function () use ($hall, $user, $name, $email, $phone, $body) {
            $existing = Booking::query()
                ->where('hall_id', $hall->id)
                ->where('customer_id', $user->id)
                ->where('status', 'inquiry')
                ->first();

            if ($existing) {
                $existing->update([
                    'customer_name' => $name,
                    'customer_email' => $email,
                    'customer_phone' => $phone,
                ]);
                $msg = Message::create([
                    'booking_id' => $existing->id,
                    'sender_id' => $user->id,
                    'content' => $body,
                    'is_read' => false,
                ]);
                $existing->touch();
                $this->chatPush->notifyNewMessage($msg, $existing);

                return $existing->fresh();
            }

            $booking = Booking::create([
                'hall_id' => $hall->id,
                'customer_id' => $user->id,
                'customer_name' => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'booking_date' => now()->toDateString(),
                'slot' => 'Inquiry',
                'guests' => 1,
                'status' => 'inquiry',
            ]);

            $msg = Message::create([
                'booking_id' => $booking->id,
                'sender_id' => $user->id,
                'content' => $body,
                'is_read' => false,
            ]);
            $booking->touch();
            $this->chatPush->notifyNewMessage($msg, $booking);

            return $booking;
        });

        return response()->json([
            'message' => 'Your message was sent to the venue.',
            'booking_id' => $booking->id,
            'hall_id' => $hall->id,
            'hall_name' => $hall->name,
            'status' => $booking->status,
        ], 201);
    }

    /**
     * Submit a new booking request.
     * POST /api/bookings
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hall_id' => [
                'required',
                'integer',
                Rule::exists('halls', 'id')->where('status', 'approved'),
            ],
            'customer_name' => 'required|string|max:100',
            'customer_email' => 'nullable|email',
            'customer_phone' => 'nullable|string|max:20',
            'booking_date' => 'required|date|after_or_equal:today',
            'slot' => 'required|in:Lunch,Dinner',
            'guests' => 'required|integer|min:1',
        ]);

        $booking = Booking::create([
            ...$validated,
            'customer_id' => $request->user()->id,
            'status' => 'pending',
        ]);

        return response()->json(['booking_id' => $booking->id], 201);
    }

    /**
     * List messages (customer or hall owner only). Poll every few seconds or use since_id.
     * GET /api/bookings/{id}/messages?since_id=
     */
    public function messages(Request $request, int $id): JsonResponse
    {
        $this->assertBookingParticipant($request->user(), $id);

        $query = Message::query()->where('booking_id', $id);

        if ($request->filled('since_id')) {
            $query->where('id', '>', (int) $request->query('since_id'));
        }

        $messages = $query->orderBy('id')
            ->limit(500)
            ->get(['id', 'booking_id', 'sender_id', 'content', 'is_read', 'created_at']);

        $latestId = (int) (Message::where('booking_id', $id)->max('id') ?? 0);

        return response()->json([
            'messages' => $messages,
            'latest_id' => $latestId,
        ]);
    }

    /**
     * Send chat message.
     * POST /api/bookings/{id}/messages
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $this->assertBookingParticipant($request->user(), $id);

        $validated = $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        $booking = Booking::findOrFail($id);

        $message = Message::create([
            'booking_id' => $id,
            'sender_id' => $request->user()->id,
            'content' => $validated['content'],
            'is_read' => false,
        ]);

        $booking->touch();

        $this->chatPush->notifyNewMessage($message, $booking);

        return response()->json([
            'message_id' => $message->id,
            'latest_id' => $message->id,
        ], 201);
    }

    /**
     * Mark incoming messages as read (the other party's messages).
     * POST /api/bookings/{id}/read
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->assertBookingParticipant($user, $id);

        Message::query()
            ->where('booking_id', $id)
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['ok' => true]);
    }

    /**
     * Latest message per booking (avoids SQLite + latestOfMany ambiguous column bug; same JSON shape as eager load).
     */
    private function attachLatestMessages(EloquentCollection $bookings): void
    {
        if ($bookings->isEmpty()) {
            return;
        }

        $bookingIds = $bookings->pluck('id');
        $maxIds = Message::query()
            ->whereIn('booking_id', $bookingIds)
            ->selectRaw('max(id) as max_id')
            ->groupBy('booking_id')
            ->pluck('max_id');

        $messages = $maxIds->isEmpty()
            ? collect()
            : Message::query()
                ->whereIn('id', $maxIds)
                ->get(['id', 'booking_id', 'sender_id', 'content', 'created_at'])
                ->keyBy('booking_id');

        foreach ($bookings as $booking) {
            $booking->setRelation('latestMessage', $messages->get($booking->id));
        }
    }

    private function assertBookingParticipant(User $user, int $bookingId): void
    {
        $booking = Booking::query()->with('hall:id,owner_id')->find($bookingId);

        if (! $booking) {
            abort(404, 'Booking not found.');
        }

        if ($booking->customer_id === $user->id) {
            return;
        }

        if ($booking->hall && (int) $booking->hall->owner_id === (int) $user->id) {
            return;
        }

        abort(403, 'You cannot access this conversation.');
    }

    /**
     * Ensure nested `customer` always includes `fcm_token` (null when unset).
     *
     * @return list<array<string, mixed>>
     */
    private function bookingsPayload(EloquentCollection $bookings): array
    {
        return $bookings->map(function (Booking $booking) {
            $row = $booking->toArray();

            if ($booking->customer_id === null) {
                $row['customer'] = null;

                return $row;
            }

            $customer = $booking->relationLoaded('customer')
                ? $booking->customer
                : null;

            $row['customer'] = $customer !== null
                ? UserApi::array($customer)
                : [
                    'id' => $booking->customer_id,
                    'name' => $booking->customer_name,
                    'email' => $booking->customer_email,
                    'phone_number' => $booking->customer_phone,
                    'role' => 'customer',
                    'fcm_token' => null,
                    'created_at' => null,
                ];

            return $row;
        })->values()->all();
    }

    /**
     * Customer bookings: ensure hall.owner includes fcm_token (null when unset).
     *
     * @return list<array<string, mixed>>
     */
    private function bookingsMinePayload(EloquentCollection $bookings): array
    {
        return $bookings->map(function (Booking $booking) {
            $row = $booking->toArray();

            if ($booking->relationLoaded('hall') && $booking->hall !== null) {
                $hallRow = $booking->hall->toArray();
                $owner = $booking->hall->relationLoaded('owner') ? $booking->hall->owner : null;
                $hallRow['owner'] = $owner !== null
                    ? UserApi::array($owner)
                    : null;
                $row['hall'] = $hallRow;
            }

            return $row;
        })->values()->all();
    }
}
