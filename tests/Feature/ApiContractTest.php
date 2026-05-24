<?php

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Full HTTP contract tests for every route under /api (mezban + mezban_business clients).
 *
 * Run: php artisan test --filter ApiContractTest
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $partner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::factory()->create([
            'email' => 'admin@mezban.com',
            'role' => 'admin',
            'password' => 'password12345',
        ]);

        $this->partner = User::factory()->create([
            'email' => 'partner@mezban.com',
            'role' => 'business',
            'password' => 'password12345',
        ]);

        $this->customer = User::factory()->create([
            'email' => 'customer@mezban.com',
            'role' => 'customer',
            'password' => 'password12345',
        ]);
    }

    // ── Generic / public ──────────────────────────────────────────────────────

    public function test_health_returns_json_ok(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'time']);
    }

    public function test_public_halls_index_search_near_and_show(): void
    {
        $hall = $this->makeApprovedHall($this->partner, ['Valima']);

        $this->getJson('/api/halls')->assertOk()->assertJsonStructure(['halls']);
        $this->getJson('/api/halls/search?q='.$hall->city)->assertOk();
        $this->getJson('/api/halls/search?q=test&event=Valima')->assertOk();

        $this->getJson('/api/halls/near')->assertStatus(422);
        $this->getJson('/api/halls/near?lat=31.5&lng=74.3')->assertOk()->assertJsonStructure(['halls']);

        $this->getJson('/api/halls/'.$hall->id)->assertOk()->assertJsonPath('hall.id', $hall->id);

        $pending = Hall::create([
            'owner_id' => $this->partner->id,
            'name' => 'Pending X',
            'venue_type' => 'Hall',
            'address' => 'Addr',
            'city' => 'City',
            'capacity_min' => 10,
            'capacity_max' => 100,
            'status' => 'pending',
        ]);
        $this->getJson('/api/halls/'.$pending->id)->assertNotFound();
    }

    public function test_auth_register_login_admin_login_phone_login(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'New Partner',
            'email' => 'newbiz@example.com',
            'password' => 'password12345',
            'password_confirmation' => 'password12345',
            'role' => 'business',
        ])->assertCreated()->assertJsonStructure(['token', 'user']);

        $this->postJson('/api/auth/register', [
            'name' => 'New Cust',
            'email' => 'newcust@example.com',
            'password' => 'password12345',
            'password_confirmation' => 'password12345',
            'role' => 'customer',
        ])->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'wrong@example.com',
            'password' => 'password12345',
        ])->assertUnauthorized();

        $this->postJson('/api/auth/login', [
            'email' => 'admin@mezban.com',
            'password' => 'password12345',
        ])->assertOk()->assertJsonPath('user.role', 'admin');

        $this->postJson('/api/auth/admin/login', [
            'email' => 'admin@mezban.com',
            'password' => 'password12345',
        ])->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/auth/phone-login', [
            'phone_number' => '+923001112223',
            'name' => 'Phone User',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_auth_me_profile_logout_under_sanctum(): void
    {
        Sanctum::actingAs($this->customer);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', 'customer@mezban.com');

        $this->putJson('/api/auth/profile', [
            'name' => 'Cust Updated',
            'phone_number' => '+9200111222333',
        ])->assertOk();

        $this->postJson('/api/auth/logout', [])->assertOk();
    }

    public function test_protected_routes_return_401_without_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/halls/mine')->assertUnauthorized();
        $this->getJson('/api/bookings/mine')->assertUnauthorized();
        $this->postJson('/api/venues/register', [])->assertUnauthorized();
        $this->getJson('/api/admin/venues')->assertUnauthorized();
    }

    // ── Partner (mezban_business) ────────────────────────────────────────────

    public function test_partner_halls_mine_put_update_and_venues_register_multipart(): void
    {
        $hall = $this->makeApprovedHall($this->partner, ['Barat / Shaadi']);

        Sanctum::actingAs($this->partner);

        $this->getJson('/api/halls/mine')->assertOk()->assertJsonCount(1, 'halls');

        $this->putJson('/api/halls/'.$hall->id, [
            'name' => 'Renamed Hall',
            'city' => 'Karachi',
        ])->assertOk()->assertJsonPath('hall.name', 'Renamed Hall');

        $this->post('/api/venues/register', [
            'name' => 'Multipart Venue',
            'venue_type' => 'Marquee',
            'address' => 'Road 1',
            'city' => 'Islamabad',
            'capacity_min' => '50',
            'capacity_max' => '400',
            'has_parking' => '0',
            'has_valet' => '0',
            'is_segregated' => '0',
            'managed_events' => json_encode(['Valima']),
            'cnic' => UploadedFile::fake()->create('cnic.pdf', 100, 'application/pdf'),
            'license' => UploadedFile::fake()->create('lic.pdf', 100, 'application/pdf'),
            'live_photo' => UploadedFile::fake()->image('live.jpg'),
        ])->assertCreated()->assertJsonPath('hall.status', 'pending');
    }

    /** Ensures real Bearer tokens work with multipart (Flutter sends Authorization like Postman). */
    public function test_venues_register_multipart_with_plain_bearer_token(): void
    {
        $plain = $this->partner->createToken('business_app')->plainTextToken;

        $this->withToken($plain)
            ->post('/api/venues/register', [
                'name' => 'Bearer Token Venue',
                'venue_type' => 'Banquet',
                'address' => 'Street 9',
                'city' => 'Lahore',
                'capacity_min' => '80',
                'capacity_max' => '500',
                'has_parking' => '1',
                'has_valet' => '0',
                'is_segregated' => '0',
                'managed_events' => json_encode(['Barat / Shaadi']),
                'cnic' => UploadedFile::fake()->create('cnic.pdf', 100, 'application/pdf'),
                'license' => UploadedFile::fake()->create('lic.pdf', 100, 'application/pdf'),
                'live_photo' => UploadedFile::fake()->image('live.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('hall.status', 'pending');
    }

    /** Fallback when Authorization header is absent but access_token is in multipart fields (mobile). */
    public function test_venues_register_multipart_authenticates_via_access_token_field(): void
    {
        $plain = $this->partner->createToken('business_app')->plainTextToken;

        $this->post('/api/venues/register', [
            'sanctum_plain_token' => $plain,
            'name' => 'Body Token Only Venue',
            'venue_type' => 'Banquet',
            'address' => 'Street 2',
            'city' => 'Karachi',
            'capacity_min' => '100',
            'capacity_max' => '600',
            'has_parking' => '0',
            'has_valet' => '0',
            'is_segregated' => '0',
            'managed_events' => json_encode(['Valima']),
            'cnic' => UploadedFile::fake()->create('cnic.pdf', 100, 'application/pdf'),
            'license' => UploadedFile::fake()->create('lic.pdf', 100, 'application/pdf'),
            'live_photo' => UploadedFile::fake()->image('live.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('hall.status', 'pending');
    }

    // ── Customer bookings & chat (mezban user app) ─────────────────────────────

    public function test_customer_booking_interest_mine_messages_read_and_formal_booking(): void
    {
        $hall = $this->makeApprovedHall($this->partner, ['Valima']);

        Sanctum::actingAs($this->customer);

        $this->getJson('/api/bookings/mine')->assertOk()->assertJsonCount(0, 'bookings');

        $interest = $this->postJson('/api/bookings/interest', [
            'hall_id' => $hall->id,
            'message' => 'Availability?',
        ])->assertCreated();

        $bookingId = $interest->json('booking_id');

        $this->getJson('/api/bookings/mine')
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonStructure([
                'bookings' => [
                    [
                        'hall' => [
                            'owner' => [
                                'id',
                                'name',
                                'email',
                                'phone_number',
                                'role',
                                'fcm_token',
                                'created_at',
                            ],
                        ],
                    ],
                ],
            ]);

        $this->getJson("/api/bookings/{$bookingId}/messages")
            ->assertOk()
            ->assertJsonStructure(['messages', 'latest_id']);

        $this->getJson("/api/bookings/{$bookingId}/messages?since_id=0")
            ->assertOk();

        $this->postJson("/api/bookings/{$bookingId}/read", [])->assertOk()->assertJson(['ok' => true]);

        Sanctum::actingAs($this->partner);
        $this->postJson("/api/bookings/{$bookingId}/messages", [
            'content' => 'Yes we are open.',
        ])->assertCreated();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/bookings/{$bookingId}/messages", [
            'content' => 'Thanks!',
        ])->assertCreated();

        $tomorrow = now()->addDay()->toDateString();
        $this->postJson('/api/bookings', [
            'hall_id' => $hall->id,
            'customer_name' => 'customer@mezban.com',
            'booking_date' => $tomorrow,
            'slot' => 'Lunch',
            'guests' => 80,
        ])->assertCreated()->assertJsonStructure(['booking_id']);
    }

    public function test_partner_inbox_and_non_participant_forbidden_on_messages(): void
    {
        $hall = $this->makeApprovedHall($this->partner, ['Valima']);

        Sanctum::actingAs($this->customer);
        $bookingId = $this->postJson('/api/bookings/interest', [
            'hall_id' => $hall->id,
        ])->assertCreated()->json('booking_id');

        Sanctum::actingAs($this->partner);
        $this->getJson('/api/bookings/inbox')
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonStructure([
                'bookings' => [
                    [
                        'customer' => [
                            'id',
                            'name',
                            'email',
                            'phone_number',
                            'role',
                            'fcm_token',
                            'created_at',
                        ],
                    ],
                ],
            ]);

        $other = User::factory()->create(['role' => 'business', 'password' => 'password12345']);
        Sanctum::actingAs($other);
        $this->getJson("/api/bookings/{$bookingId}/messages")->assertForbidden();
    }

    // ── Admin (mezban_business admin dashboard) ─────────────────────────────────

    public function test_admin_venues_bookings_and_status_actions(): void
    {
        $pending = Hall::create([
            'owner_id' => $this->partner->id,
            'name' => 'Awaiting',
            'venue_type' => 'Hall',
            'address' => 'A',
            'city' => 'Lahore',
            'capacity_min' => 20,
            'capacity_max' => 200,
            'status' => 'pending',
        ]);

        $approved = $this->makeApprovedHall($this->partner, ['Mehndi']);

        Sanctum::actingAs($this->customer);
        $this->getJson('/api/admin/venues')->assertForbidden();

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/venues')->assertOk()->assertJsonStructure(['counts', 'halls']);
        $this->getJson('/api/admin/venues?status=pending')->assertOk();
        $this->getJson('/api/admin/venue-requests')->assertOk();
        $this->getJson('/api/admin/venues/pending-approvals')->assertOk();

        $this->postJson('/api/admin/venues/'.$pending->id.'/approve', [])->assertOk();

        $declineHall = Hall::create([
            'owner_id' => $this->partner->id,
            'name' => 'To Decline',
            'venue_type' => 'Hall',
            'address' => 'B',
            'city' => 'Lahore',
            'capacity_min' => 20,
            'capacity_max' => 200,
            'status' => 'pending',
        ]);
        $this->postJson('/api/admin/venues/'.$declineHall->id.'/decline', [
            'decline_reason' => 'Incomplete paperwork',
        ])->assertOk();

        $this->postJson('/api/admin/venues/'.$approved->id.'/suspend', [])->assertOk();
        $this->postJson('/api/admin/venues/'.$approved->id.'/unsuspend', [])->assertOk();

        $this->postJson('/api/admin/venues/'.$pending->id.'/status', [
            'status' => 'suspended',
        ])->assertOk();

        $this->getJson('/api/admin/bookings')->assertOk();
        $this->getJson('/api/admin/bookings?status=all')->assertOk();
    }

    public function test_partner_post_halls_alias_same_as_venues_register_requires_payload(): void
    {
        Sanctum::actingAs($this->partner);

        $this->postJson('/api/halls', [])->assertStatus(422);

        $this->post('/api/halls', [
            'name' => 'Quick Hall',
            'venue_type' => 'Hall',
            'address' => 'Street',
            'city' => 'Lahore',
            'capacity_min' => '30',
            'capacity_max' => '150',
            'cnic' => UploadedFile::fake()->create('c.pdf', 80, 'application/pdf'),
            'license' => UploadedFile::fake()->create('l.pdf', 80, 'application/pdf'),
            'live_photo' => UploadedFile::fake()->image('p.jpg'),
        ])->assertCreated();
    }

    /**
     * @param  array<int, string>  $events
     */
    private function makeApprovedHall(User $owner, array $events): Hall
    {
        return Hall::create([
            'owner_id' => $owner->id,
            'name' => 'Approved Test Hall',
            'venue_type' => 'Hall',
            'address' => 'Main Road',
            'city' => 'Lahore',
            'capacity_min' => 50,
            'capacity_max' => 500,
            'status' => 'approved',
            'managed_events' => $events,
        ]);
    }
}
