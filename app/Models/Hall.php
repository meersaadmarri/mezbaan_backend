<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Hall extends Model
{
    protected $fillable = [
        'owner_id', 'name', 'venue_type', 'address', 'city', 'location_lat', 'location_long',
        'capacity', 'capacity_min', 'capacity_max', 'has_parking', 'parking_capacity',
        'has_valet', 'is_segregated', 'managed_events', 'custom_event',
        'business_model', 'hall_rent_per_slot', 'advance_payment_percentage',
        'is_tax_inclusive', 'tax_percentage', 'allow_outside_catering',
        'outside_catering_fee', 'packages', 'selected_themes', 'stage_features',
        'fresh_flower_extra_cost', 'detailed_decor_options', 'power_backup',
        'climate_control', 'ac_extra_cost', 'heaters_extra_cost', 'bridal_rooms',
        'waiter_ratio', 'staff_gender', 'has_photography', 'photography_extra_cost',
        'cnic_path', 'license_path', 'live_venue_photo_path', 'venue_photos', 'venue_videos',
        'status', 'decline_reason', 'price_per_plate', 'booked_dates',
    ];

    protected $hidden = [
        'cnic_path',
        'license_path',
        'live_venue_photo_path',
    ];

    protected $appends = [
        'cnic_url',
        'license_url',
        'live_venue_photo_url',
        'advance_percentage',
        'decor_themes',
        'event_types',
    ];

    protected $casts = [
        'venue_photos' => 'array',
        'venue_videos' => 'array',
        'managed_events' => 'array',
        'packages' => 'array',
        'selected_themes' => 'array',
        'stage_features' => 'array',
        'detailed_decor_options' => 'array',
        'climate_control' => 'array',
        'staff_gender' => 'array',
        'has_parking' => 'boolean',
        'has_valet' => 'boolean',
        'is_segregated' => 'boolean',
        'is_tax_inclusive' => 'boolean',
        'allow_outside_catering' => 'boolean',
        'has_photography' => 'boolean',
        'location_lat' => 'double',
        'location_long' => 'double',
        'booked_dates' => 'array',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function getCnicUrlAttribute(): ?string
    {
        return $this->cnic_path ? Storage::disk('public')->url($this->cnic_path) : null;
    }

    public function getLicenseUrlAttribute(): ?string
    {
        return $this->license_path ? Storage::disk('public')->url($this->license_path) : null;
    }

    public function getLiveVenuePhotoUrlAttribute(): ?string
    {
        return $this->live_venue_photo_path
            ? Storage::disk('public')->url($this->live_venue_photo_path)
            : null;
    }

    public function getAdvancePercentageAttribute(): ?float
    {
        return $this->advance_payment_percentage !== null
            ? (float) $this->advance_payment_percentage
            : null;
    }

    /**
     * Alias for selected_themes (admin UI expects decor_themes).
     *
     * @return array<int, mixed>|null
     */
    public function getDecorThemesAttribute(): ?array
    {
        return $this->coerceAppendedJsonArray('selected_themes');
    }

    /**
     * Event types selected as chips in the app (same data as managed_events).
     *
     * @return array<int, mixed>|null
     */
    public function getEventTypesAttribute(): ?array
    {
        return $this->coerceAppendedJsonArray('managed_events');
    }

    /**
     * Normalize JSON/array columns for appended accessors (handles double-encoded JSON from legacy/manual inserts).
     *
     * @return array<int, mixed>|null
     */
    private function coerceAppendedJsonArray(string $column): ?array
    {
        $value = $this->getAttribute($column);

        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            if (is_string($decoded)) {
                $inner = json_decode($decoded, true);

                return is_array($inner) ? $inner : null;
            }
        }

        return null;
    }

    /**
     * Expose gallery media as absolute URLs for mobile clients.
     */
    public function toArray()
    {
        $array = parent::toArray();

        foreach (['venue_photos', 'venue_videos'] as $key) {
            $paths = $this->{$key};
            if (is_array($paths) && $paths !== []) {
                $array[$key] = array_values(array_filter(array_map(
                    fn (?string $path) => $path ? Storage::disk('public')->url($path) : null,
                    $paths
                )));
            }
        }

        $array['booked_dates'] = is_array($array['booked_dates'] ?? null)
            ? array_values(array_unique($array['booked_dates']))
            : [];

        return $array;
    }
}
