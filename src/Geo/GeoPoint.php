<?php

declare(strict_types=1);

namespace App\Geo;

/** WKT for MySQL: POINT(lat lng) with SRID 4326. */
final readonly class GeoPoint
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {
    }
}
