<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('halls', function (Blueprint $table) {
            // Venue Identity
            $table->string('venue_type')->nullable()->after('name');
            $table->string('city')->nullable()->after('address');
            $table->decimal('location_lat', 10, 8)->nullable()->after('city');
            $table->decimal('location_long', 11, 8)->nullable()->after('location_lat');
            $table->integer('capacity_min')->nullable()->after('capacity');
            $table->integer('capacity_max')->nullable()->after('capacity_min');
            $table->boolean('has_parking')->default(false)->after('capacity_max');
            $table->integer('parking_capacity')->nullable()->after('has_parking');
            $table->boolean('has_valet')->default(false)->after('parking_capacity');
            $table->boolean('is_segregated')->default(false)->after('has_valet');
            $table->json('managed_events')->nullable()->after('is_segregated');
            $table->string('custom_event')->nullable()->after('managed_events');

            // Pricing & Catering
            $table->string('business_model')->nullable()->after('custom_event');
            $table->integer('hall_rent_per_slot')->nullable()->after('business_model');
            $table->decimal('advance_payment_percentage', 5, 2)->nullable()->after('hall_rent_per_slot');
            $table->boolean('is_tax_inclusive')->default(false)->after('advance_payment_percentage');
            $table->integer('tax_percentage')->nullable()->after('is_tax_inclusive');
            $table->boolean('allow_outside_catering')->default(false)->after('tax_percentage');
            $table->integer('outside_catering_fee')->nullable()->after('allow_outside_catering');
            $table->json('packages')->nullable()->after('outside_catering_fee');

            // Decor & Aesthetics
            $table->json('selected_themes')->nullable()->after('packages');
            $table->json('stage_features')->nullable()->after('selected_themes');
            $table->integer('fresh_flower_extra_cost')->nullable()->after('stage_features');
            $table->json('detailed_decor_options')->nullable()->after('fresh_flower_extra_cost');

            // Facilities & Operations
            $table->string('power_backup')->nullable()->after('detailed_decor_options');
            $table->json('climate_control')->nullable()->after('power_backup');
            $table->integer('ac_extra_cost')->nullable()->after('climate_control');
            $table->integer('heaters_extra_cost')->nullable()->after('ac_extra_cost');
            $table->integer('bridal_rooms')->nullable()->after('heaters_extra_cost');
            $table->string('waiter_ratio')->nullable()->after('bridal_rooms');
            $table->json('staff_gender')->nullable()->after('waiter_ratio');
            $table->boolean('has_photography')->default(false)->after('staff_gender');
            $table->integer('photography_extra_cost')->nullable()->after('has_photography');

            // Verification & Media
            $table->string('cnic_path')->nullable()->after('photography_extra_cost');
            $table->string('license_path')->nullable()->after('cnic_path');
            $table->string('live_venue_photo_path')->nullable()->after('license_path');
            $table->json('venue_videos')->nullable()->after('venue_photos');

            // Re-ordering status for clarity
            $table->string('decline_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('halls', function (Blueprint $table) {
            $table->dropColumn([
                'venue_type', 'city', 'location_lat', 'location_long',
                'capacity_min', 'capacity_max', 'has_parking', 'parking_capacity',
                'has_valet', 'is_segregated', 'managed_events', 'custom_event',
                'business_model', 'hall_rent_per_slot', 'advance_payment_percentage',
                'is_tax_inclusive', 'tax_percentage', 'allow_outside_catering',
                'outside_catering_fee', 'packages', 'selected_themes', 'stage_features',
                'fresh_flower_extra_cost', 'detailed_decor_options', 'power_backup',
                'climate_control', 'ac_extra_cost', 'heaters_extra_cost', 'bridal_rooms',
                'waiter_ratio', 'staff_gender', 'has_photography', 'photography_extra_cost',
                'cnic_path', 'license_path', 'live_venue_photo_path', 'venue_videos', 'decline_reason',
            ]);
        });
    }
};
