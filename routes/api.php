<?php

use App\Http\Controllers\Admin\AdminBookingController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminVenueController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\HallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
| Auth routes: /api/auth/*
| Hall routes: /api/halls/*
| Booking routes: /api/bookings/*
|
| Partner flow: POST /auth/register → POST /venues/register (hall status=pending)
| → Admin: GET /admin/venues/pending-approvals → approve/decline/suspend endpoints.
|
| Login: POST /auth/login (and alias POST /auth/admin/login) — same handler.
|   • Hall owner: email/password from POST /auth/register (role business).
|   • Admin: email/password from DB seed (e.g. admin@mezban.com); role admin in JSON.
|
| Consumer (Mezban user app): browse halls without login — GET /halls, /halls/search, /halls/near, /halls/{id}.
| Interest chat: POST /bookings/interest (auth) — creates inquiry + first message to hall owner.
| Chat (REST; poll GET …/messages): customer GET /bookings/mine, owner GET /bookings/inbox.
|
*/

// Smoke test (no auth) — use in Postman / monitors
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'time' => now()->toIso8601String(),
]));

// ── Authentication (public) ───────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/admin/login', [AuthController::class, 'login']);
    Route::post('/phone-login', [AuthController::class, 'phoneLogin']);
});

// ── Halls: public discovery (consumer app — no token required) ────────────────
Route::prefix('halls')->group(function () {
    Route::get('/', [HallController::class, 'index']);
    Route::get('/search', [HallController::class, 'search']);
    Route::get('/near', [HallController::class, 'near']);
});

// ── Protected routes (require Sanctum token) ──────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Venue registration (full wizard payload from mezban_business)
    Route::post('/venues/register', [HallController::class, 'registerVenue']);

    // Halls: partner-only (must register before /halls/{id} public route)
    Route::prefix('halls')->group(function () {
        Route::get('/mine', [HallController::class, 'mine']);
        Route::post('/', [HallController::class, 'store']);
        Route::put('/{id}/booked-dates', [HallController::class, 'updateBookedDates'])->whereNumber('id');
        Route::put('/{id}', [HallController::class, 'update'])->whereNumber('id');
    });

    // Push notification device tokens (FCM)
    Route::prefix('notifications')->group(function () {
        Route::post('/device-token', [DeviceTokenController::class, 'store']);
        Route::delete('/device-token', [DeviceTokenController::class, 'destroy']);
    });

    // Bookings & chat (REST — use polling or SSE; add Laravel Reverb later for WebSockets)
    Route::prefix('bookings')->group(function () {
        Route::get('/mine', [BookingController::class, 'mine']);
        Route::get('/inbox', [BookingController::class, 'inbox']);
        Route::post('/interest', [BookingController::class, 'sendInterest']);
        Route::post('/', [BookingController::class, 'store']);
        Route::post('/{id}/read', [BookingController::class, 'markRead'])->whereNumber('id');
        Route::get('/{id}/messages', [BookingController::class, 'messages'])->whereNumber('id');
        Route::post('/{id}/messages', [BookingController::class, 'sendMessage'])->whereNumber('id');
    });

    // Admin (role = admin)
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/venues/pending-approvals', [AdminVenueController::class, 'pendingApprovals']);
        Route::get('/venues', [AdminVenueController::class, 'index']);
        Route::get('/venue-requests', [AdminVenueController::class, 'index']);
        Route::post('/venues/{id}/approve', [AdminVenueController::class, 'approve']);
        Route::post('/venues/{id}/decline', [AdminVenueController::class, 'decline']);
        Route::post('/venues/{id}/suspend', [AdminVenueController::class, 'suspend']);
        Route::post('/venues/{id}/unsuspend', [AdminVenueController::class, 'unsuspend']);
        Route::post('/venues/{id}/status', [AdminVenueController::class, 'updateStatus']);

        Route::get('/bookings', [AdminBookingController::class, 'index']);

        Route::post('/notifications/promotional', [AdminNotificationController::class, 'sendPromotional']);
        Route::get('/notifications/campaigns', [AdminNotificationController::class, 'indexCampaigns']);
        Route::post('/notifications/campaigns', [AdminNotificationController::class, 'storeCampaign']);
    });
});

// ── Halls: single venue (public; registered after /halls/mine so "mine" is not captured as id)
Route::prefix('halls')->group(function () {
    Route::get('/{id}', [HallController::class, 'show'])->whereNumber('id');
});
