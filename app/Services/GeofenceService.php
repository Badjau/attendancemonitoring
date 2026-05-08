<?php

namespace App\Services;

use App\Models\Zone;
use Illuminate\Support\Collection;

class GeofenceService
{
    public function distanceInMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    public function isInsideZone(float $lat, float $lon, Zone $zone): bool
    {
        $distance = $this->distanceInMeters($lat, $lon, $zone->latitude, $zone->longitude);

        return $distance <= $zone->radius_meters;
    }

    public function findMatchingZone(float $lat, float $lon, Collection $zones): ?Zone
    {
        foreach ($zones as $zone) {
            if ($this->isInsideZone($lat, $lon, $zone)) {
                return $zone;
            }
        }

        return null;
    }

    public function hasStrictZone(Collection $zones): bool
    {
        return $zones->contains(fn (Zone $zone): bool => $zone->policy === 'strict');
    }
}
