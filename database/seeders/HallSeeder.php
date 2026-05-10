<?php

namespace Database\Seeders;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class HallSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create a Test Owner if not exists
        $owner = User::firstOrCreate(
            ['email' => 'owner@mezban.com'],
            [
                'name' => 'Meer Baloch',
                'password' => Hash::make('password123'),
                'role' => 'business',
                'phone_number' => '03001234567',
            ]
        );

        // 2. Create a Comprehensive Hall Record
        Hall::create([
            'owner_id' => $owner->id,
            'name' => 'The Grand Palace',
            'venue_type' => 'Marquee',
            'address' => 'Gulshan-e-Iqbal, Block 13, Karachi',
            'city' => 'Karachi',
            'location_lat' => 24.9190,
            'location_long' => 67.0883,
            'capacity_min' => 200,
            'capacity_max' => 1200,
            'has_parking' => true,
            'parking_capacity' => 150,
            'has_valet' => true,
            'is_segregated' => true,
            'managed_events' => ['Wedding', 'Valima', 'Corporate', 'Engagement'],
            'custom_event' => 'Qawwali Night',
            'business_model' => 'Hall + Food',
            'hall_rent_per_slot' => 150000,
            'advance_payment_percentage' => 25.00,
            'is_tax_inclusive' => true,
            'tax_percentage' => 13,
            'allow_outside_catering' => false,
            'outside_catering_fee' => 0,
            'packages' => [
                'Economy' => ['price' => 1500, 'details' => 'Chicken Biryani, Raita, Salad, Soft Drink'],
                'Standard' => ['price' => 2500, 'details' => 'Mutton Karahi, Biryani, 2 Desserts, Soup'],
                'VIP' => ['price' => 4500, 'details' => 'Full Mutton, BBQ, 4 Desserts, Fresh Juice Bar'],
            ],
            'selected_themes' => ['Royal Gold', 'Floral Garden', 'Midnight Star'],
            'stage_features' => ['LED Screen' => true, 'Fresh Flowers' => true, 'Cold Fire Entry' => true],
            'fresh_flower_extra_cost' => 25000,
            'detailed_decor_options' => [
                'Entrance Decor' => true,
                'Table Centerpieces' => true,
                'Ambiance Lighting' => true,
            ],
            'power_backup' => '100% Generator Backup',
            'climate_control' => ['AC' => true, 'Heaters' => true],
            'ac_extra_cost' => 35000,
            'heaters_extra_cost' => 15000,
            'bridal_rooms' => 2,
            'waiter_ratio' => '1:10',
            'staff_gender' => ['Male' => true, 'Female' => true],
            'has_photography' => true,
            'photography_extra_cost' => 50000,
            'status' => 'approved', // Automatically approve for testing
            'venue_photos' => [
                'https://images.unsplash.com/photo-1519167758481-83f550bb49b3',
                'https://images.unsplash.com/photo-1511795409834-ef04bbd61622',
            ],
        ]);
    }
}
