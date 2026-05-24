<?php

namespace App\Http\Controllers;

use App\Models\Hall;
use App\Services\VenueRegistrationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class HallController extends Controller
{
    public function __construct(
        private readonly VenueRegistrationService $venueRegistration,
    ) {}
    /**
     * List approved halls (consumer app — no auth).
     * GET /api/halls
     *
     * Query: event= or event_type= — matches managed_events JSON (exact chip) or custom_event LIKE.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Hall::query()->where('status', 'approved');
        $this->applyEventTypeFilter($query, $request);

        $halls = $query->latest()->get();

        return response()->json(['halls' => $halls]);
    }

    /**
     * Venues owned by the authenticated partner (any status).
     * Includes decline_reason when status is declined so the app can show it to the owner.
     *
     * GET /api/halls/mine
     */
    public function mine(Request $request): JsonResponse
    {
        $halls = Hall::where('owner_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json(['halls' => $halls]);
    }

    /**
     * Search halls by name, city, or address.
     * GET /api/halls/search?q=
     *
     * Query: event= or event_type= (same as index).
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q', '');

        $hallsQuery = Hall::query()->where('status', 'approved');

        if (is_string($query) && trim($query) !== '') {
            $term = trim($query);
            $hallsQuery->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', "%{$term}%")
                    ->orWhere('city', 'LIKE', "%{$term}%")
                    ->orWhere('address', 'LIKE', "%{$term}%");
            });
        }

        $this->applyEventTypeFilter($hallsQuery, $request);

        $halls = $hallsQuery->latest()->get();

        return response()->json(['halls' => $halls]);
    }

    /**
     * Approved halls near a coordinate (haversine), sorted by distance.
     * GET /api/halls/near?lat=&lng=&radius_km=&nearest_limit=
     *
     * Behaviour:
     * 1. Prefer venues with map pins (`location_lat` / `location_long`) within `radius_km`.
     * 2. If none match, return up to `nearest_limit` venues **anywhere that have pins**, still sorted
     *    closest-first (nearest available). This avoids an empty UI when approved halls are elsewhere.
     *
     * Optional: event= / event_type= to narrow by event (same as /halls).
     */
    public function near(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius_km' => 'nullable|numeric|min:1|max:12000',
            'nearest_limit' => 'nullable|integer|min:1|max:50',
        ]);

        $radius = (float) ($validated['radius_km'] ?? 100);
        $nearestLimit = (int) ($validated['nearest_limit'] ?? 25);
        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        $base = Hall::query()
            ->where('status', 'approved')
            ->whereNotNull('location_lat')
            ->whereNotNull('location_long');

        $this->applyEventTypeFilter($base, $request);

        $sortedWithDistance = $base->get()
            ->map(function (Hall $hall) use ($lat, $lng) {
                $d = $this->haversineKm(
                    $lat,
                    $lng,
                    (float) $hall->location_lat,
                    (float) $hall->location_long
                );
                $hall->setAttribute('distance_km', round($d, 2));

                return $hall;
            })
            ->sortBy('distance_km')
            ->values();

        if ($sortedWithDistance->isEmpty()) {
            return response()->json([
                'halls' => [],
                'near_mode' => 'empty',
                'radius_km' => $radius,
                'nearest_limit' => $nearestLimit,
                'hint' => 'No approved halls have GPS coordinates yet. Ask partners to pin the venue on the map.',
            ]);
        }

        $withinRadius = $sortedWithDistance->filter(function (Hall $hall) use ($radius) {
            return (float) ($hall->distance_km ?? 0) <= $radius;
        })->values();

        if ($withinRadius->isNotEmpty()) {
            return response()->json([
                'halls' => $withinRadius,
                'near_mode' => 'within_radius',
                'radius_km' => $radius,
                'nearest_limit' => $nearestLimit,
            ]);
        }

        $fallback = $sortedWithDistance->take($nearestLimit)->values();

        return response()->json([
            'halls' => $fallback,
            'near_mode' => 'nearest_fallback',
            'radius_km' => $radius,
            'nearest_limit' => $nearestLimit,
            'message' => sprintf(
                'No venues within %.0f km; showing the %d nearest by distance.',
                $radius,
                $fallback->count()
            ),
        ]);
    }

    /**
     * Get a single hall by ID.
     * GET /api/halls/{id}
     */
    public function show(int $id): JsonResponse
    {
        $hall = Hall::where('status', 'approved')->findOrFail($id);

        return response()->json(['hall' => $hall]);
    }

    /**
     * Create a new hall listing (REST alias for venue registration).
     * POST /api/halls
     */
    public function store(Request $request): JsonResponse
    {
        return $this->registerVenue($request);
    }

    /**
     * Full venue registration — matches mezban_business RegistrationWizard payload
     * (multipart: text fields + cnic, license, live_photo, photos[], videos[]).
     *
     * POST /api/venues/register
     */
    public function registerVenue(Request $request): JsonResponse
    {
        // Not validated / stored — only used when Authorization header is missing (see AppServiceProvider Sanctum callback).
        $request->request->remove('sanctum_plain_token');

        // Event-type chips: allow `event_types` JSON as alias for `managed_events` (FilterChip list in app).
        if (! $request->filled('managed_events') && $request->filled('event_types')) {
            $request->merge(['managed_events' => $request->input('event_types')]);
        }

        $this->normalizeVenueRegistrationInput($request);

        if ($videoUploadError = $this->venueRegistrationVideoUploadDiagnostics($request)) {
            return $videoUploadError;
        }

        $validated = $request->validate([
            // Phase 1 — Venue identity (registration_wizard.dart)
            'name' => 'required|string|max:255',
            'venue_type' => 'required|string|max:100',
            'address' => 'required|string',
            'city' => 'nullable|string|max:120',
            'location_lat' => 'nullable|numeric',
            'location_long' => 'nullable|numeric',
            'capacity_min' => 'required|integer|min:1',
            'capacity_max' => 'required|integer|min:1',
            'has_parking' => 'nullable|boolean',
            'parking_capacity' => 'nullable|integer|min:0',
            'has_valet' => 'nullable|boolean',
            'is_segregated' => 'nullable|boolean',
            'managed_events' => 'nullable|string',
            'event_types' => 'nullable|string', // alias only; merged into managed_events or ignored
            'custom_event' => 'nullable|string|max:255',
            // Phase 2 — Pricing & catering
            'business_model' => 'nullable|string|max:100',
            'hall_rent_per_slot' => 'nullable|integer|min:0',
            'advance_payment_percentage' => 'nullable|numeric|min:0|max:100',
            'is_tax_inclusive' => 'nullable|boolean',
            'tax_percentage' => 'nullable|integer|min:0|max:100',
            'allow_outside_catering' => 'nullable|boolean',
            'outside_catering_fee' => 'nullable|integer|min:0',
            'packages' => 'nullable|string',
            // Phase 3 — Decor & aesthetics
            'selected_themes' => 'nullable|string',
            'stage_features' => 'nullable|string',
            'fresh_flower_extra_cost' => 'nullable|integer|min:0',
            'detailed_decor_options' => 'nullable|string',
            // Phase 4 — Facilities & operations
            'power_backup' => 'nullable|string|max:255',
            'climate_control' => 'nullable|string',
            'ac_extra_cost' => 'nullable|integer|min:0',
            'heaters_extra_cost' => 'nullable|integer|min:0',
            'bridal_rooms' => 'nullable|integer|min:0',
            'waiter_ratio' => 'nullable|string|max:50',
            'staff_gender' => 'nullable|string',
            'has_photography' => 'nullable|boolean',
            'photography_extra_cost' => 'nullable|integer|min:0',
            // Phase 5 — Verification uploads
            'cnic' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'license' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'live_photo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:15360',
            'photos' => 'nullable|array',
            'photos.*' => 'file|mimes:jpg,jpeg,png,webp|max:15360',
            'videos' => 'nullable|array',
            // Android often sends application/octet-stream; validate extension + size, not strict mime.
            // max is kilobytes (102400 = 100 MB). Ensure PHP upload_max_filesize/post_max_size allow this.
            'videos.*' => [
                'file',
                'max:102400',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile || ! $value->isValid()) {
                        return;
                    }
                    $ext = strtolower((string) ($value->getClientOriginalExtension() ?: $value->guessExtension() ?: ''));
                    $allowed = ['mp4', 'mov', 'avi', 'webm', '3gp', 'mkv', 'm4v'];
                    if (! in_array($ext, $allowed, true)) {
                        $fail('Each venue video must use a known extension (.mp4, .mov, .webm, …).');

                        return;
                    }
                    $mime = (string) $value->getMimeType();
                    if (! str_starts_with($mime, 'video/') && $mime !== 'application/octet-stream') {
                        $fail('Each venue video must be a video file (got '.$mime.').');
                    }
                },
            ],
        ]);

        $hall = $this->venueRegistration->register($request->user(), $validated, $request);

        return response()->json([
            'message' => 'Venue registered successfully and pending approval.',
            'hall' => $this->venueRegistration->registrationPayload($hall),
        ], 201);
    }

    /**
     * Update a hall listing (owner only).
     * PUT /api/halls/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $hall = Hall::where('owner_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:150',
            'address' => 'sometimes|string',
            'city' => 'sometimes|string|max:100',
            'capacity_min' => 'sometimes|integer|min:1',
            'capacity_max' => 'sometimes|integer',
            'hall_rent_per_slot' => 'sometimes|numeric|min:0',
        ]);

        $hall->update($validated);

        return response()->json(['hall' => $hall]);
    }

    /**
     * Owner-only: set which calendar days are marked booked/unavailable (full-day blocks).
     * Only allowed when the venue is approved (shown on the public hall APIs).
     *
     * PUT /api/halls/{id}/booked-dates
     * Body: { "booked_dates": ["2026-05-08", "2026-05-09"] }
     */
    public function updateBookedDates(Request $request, int $id): JsonResponse
    {
        $hall = Hall::where('owner_id', $request->user()->id)->findOrFail($id);

        if ($hall->status !== 'approved') {
            return response()->json([
                'message' => 'You can manage booked dates only after your venue is approved.',
            ], 422);
        }

        $validated = $request->validate([
            'booked_dates' => 'required|array',
            'booked_dates.*' => 'required|date_format:Y-m-d',
        ]);

        $dates = collect($validated['booked_dates'])
            ->map(fn (string $d) => Carbon::createFromFormat('Y-m-d', $d)->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $hall->update(['booked_dates' => $dates]);

        return response()->json(['hall' => $hall->fresh()]);
    }

    /**
     * If PHP rejected a video part (size limits, partial upload, etc.), return a clear JSON
     * error before Laravel's generic "videos.0 failed to upload." validation message.
     */
    private function venueRegistrationVideoUploadDiagnostics(Request $request): ?JsonResponse
    {
        if (! $request->files->has('videos')) {
            return null;
        }

        $raw = $request->file('videos');
        $files = is_array($raw) ? $raw : [$raw];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            if ($file->isValid()) {
                continue;
            }

            return response()->json([
                'message' => $this->venuePhpUploadErrorMessage($file->getError()),
                'videos_upload_error_code' => $file->getError(),
                'php_upload_max_filesize' => ini_get('upload_max_filesize'),
                'php_post_max_size' => ini_get('post_max_size'),
                'hint' => 'From mezban_backend run: composer run serve-mobile (raises PHP limits). Or edit php.ini upload_max_filesize and post_max_size.',
            ], 422);
        }

        return null;
    }

    private function venuePhpUploadErrorMessage(int $code): string
    {
        return match ($code) {
            \UPLOAD_ERR_INI_SIZE => 'Video is larger than PHP upload_max_filesize allows on this server.',
            \UPLOAD_ERR_FORM_SIZE => 'Video exceeds POST / form size limits (often post_max_size or MAX_FILE_SIZE).',
            \UPLOAD_ERR_PARTIAL => 'Video upload was interrupted (network or timeout). Try again on stable Wi‑Fi or a smaller clip.',
            \UPLOAD_ERR_NO_FILE => 'No video file was received. Pick the video again.',
            \UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads.',
            \UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded video to disk.',
            \UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the video upload.',
            default => 'Video upload failed on the server before validation.',
        };
    }

    /**
     * Multipart optional numeric fields: treat "" as null so validation passes.
     */
    private function normalizeVenueRegistrationInput(Request $request): void
    {
        $nullableKeys = [
            'parking_capacity', 'hall_rent_per_slot', 'advance_payment_percentage',
            'tax_percentage', 'outside_catering_fee', 'fresh_flower_extra_cost',
            'ac_extra_cost', 'heaters_extra_cost', 'bridal_rooms',
            'photography_extra_cost', 'location_lat', 'location_long',
        ];

        $merge = [];
        foreach ($nullableKeys as $key) {
            if ($request->has($key) && $request->input($key) === '') {
                $merge[$key] = null;
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    /**
     * Collect uploaded files for a field (single file, array, or photos[]-style multipart).
     *
     * @return array<int, UploadedFile>
     */
    private function gatherUploadedFiles(Request $request, string $key): array
    {
        $bag = $request->file($key, []);
        $flat = Arr::flatten(Arr::wrap($bag));

        return array_values(array_filter(
            $flat,
            fn ($f) => $f instanceof UploadedFile && $f->isValid()
        ));
    }

    private function applyEventTypeFilter(Builder $query, Request $request): void
    {
        $event = $request->query('event') ?? $request->query('event_type');
        if (! is_string($event) || trim($event) === '') {
            return;
        }

        $event = trim($event);

        $query->where(function ($q) use ($event) {
            $q->where(function ($q2) use ($event) {
                $q2->whereNotNull('managed_events')
                    ->whereJsonContains('managed_events', $event);
            })->orWhere('custom_event', 'LIKE', '%'.$event.'%');
        });
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
