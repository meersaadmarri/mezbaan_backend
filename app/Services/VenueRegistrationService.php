<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class VenueRegistrationService
{
    /**
     * Create or refresh the partner's pending hall listing (one pending row per owner).
     */
    public function register(User $user, array $validated, Request $request): Hall
    {
        $data = $this->prepareHallData($validated, $user->id);
        $data = $this->attachUploadedMedia($request, $data);

        $existing = Hall::query()
            ->where('owner_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            $existing->update($data);

            return $existing->fresh();
        }

        return Hall::create($data);
    }

    /**
     * Lightweight JSON for mobile — avoids heavy toArray() URL rewriting on large galleries.
     *
     * @return array<string, mixed>
     */
    public function registrationPayload(Hall $hall): array
    {
        return [
            'id' => $hall->id,
            'name' => $hall->name,
            'venue_type' => $hall->venue_type,
            'address' => $hall->address,
            'city' => $hall->city,
            'status' => $hall->status,
            'decline_reason' => $hall->decline_reason,
            'capacity_min' => $hall->capacity_min,
            'capacity_max' => $hall->capacity_max,
            'hall_rent_per_slot' => $hall->hall_rent_per_slot,
            'venue_photos' => $hall->venue_photos ?? [],
            'venue_videos' => $hall->venue_videos ?? [],
            'booked_dates' => $hall->booked_dates ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function prepareHallData(array $validated, int $ownerId): array
    {
        $data = $validated;
        $data['owner_id'] = $ownerId;
        $data['status'] = 'pending';

        unset(
            $data['cnic'],
            $data['license'],
            $data['live_photo'],
            $data['photos'],
            $data['videos'],
            $data['event_types'],
        );

        foreach ([
            'managed_events', 'packages', 'selected_themes', 'stage_features',
            'detailed_decor_options', 'climate_control', 'staff_gender',
        ] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $decoded = json_decode($data[$field], true);
                $data[$field] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attachUploadedMedia(Request $request, array $data): array
    {
        if ($request->hasFile('cnic')) {
            $data['cnic_path'] = $request->file('cnic')->store('halls/docs', 'public');
        }
        if ($request->hasFile('license')) {
            $data['license_path'] = $request->file('license')->store('halls/docs', 'public');
        }
        if ($request->hasFile('live_photo')) {
            $data['live_venue_photo_path'] = $request->file('live_photo')->store('halls/photos', 'public');
        }

        $photoFiles = $this->gatherUploadedFiles($request, 'photos');
        if ($photoFiles !== []) {
            $data['venue_photos'] = $this->storePhotos($photoFiles);
        }

        $videoFiles = $this->gatherUploadedFiles($request, 'videos');
        if ($videoFiles !== []) {
            $data['venue_videos'] = $this->storeVideos($videoFiles);
        }

        return $data;
    }

    /**
     * @param  list<UploadedFile>  $photos
     * @return list<string>
     */
    private function storePhotos(array $photos): array
    {
        $paths = [];
        foreach ($photos as $photo) {
            $paths[] = $photo->store('halls/photos', 'public');
        }

        return $paths;
    }

    /**
     * @param  list<UploadedFile>  $videos
     * @return list<string>
     */
    private function storeVideos(array $videos): array
    {
        $paths = [];
        foreach ($videos as $video) {
            $ext = strtolower((string) ($video->getClientOriginalExtension() ?: $video->guessExtension() ?: 'mp4'));
            if (! preg_match('/^[a-z0-9]{2,10}$/', $ext)) {
                $ext = 'mp4';
            }
            $filename = Str::uuid()->toString().'.'.$ext;
            $paths[] = $video->storeAs('halls/videos', $filename, 'public');
        }

        return $paths;
    }

    /**
     * @return list<UploadedFile>
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
}
