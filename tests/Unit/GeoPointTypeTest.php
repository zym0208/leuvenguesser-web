<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Doctrine\Types\GeoPointType;
use App\Geo\GeoPoint;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use PHPUnit\Framework\TestCase;

final class GeoPointTypeTest extends TestCase
{
    private GeoPointType $type;
    private MySQL80Platform $platform;

    protected function setUp(): void
    {
        $this->type = new GeoPointType();
        $this->platform = new MySQL80Platform();
    }

    public function testGeoPointStoresLatitudeAndLongitude(): void
    {
        $point = new GeoPoint(50.8798, 4.7005);

        self::assertSame(50.8798, $point->lat);
        self::assertSame(4.7005, $point->lng);
    }

    public function testConvertGeoPointToDatabaseWkt(): void
    {
        $value = $this->type->convertToDatabaseValue(new GeoPoint(50.8798, 4.7005), $this->platform);

        self::assertSame('POINT(50.879800 4.700500)', $value);
    }

    public function testConvertNullToDatabaseValue(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }

    public function testRejectsNonGeoPointDatabaseValues(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToDatabaseValue('50.8798,4.7005', $this->platform);
    }

    public function testConvertWktStringToGeoPoint(): void
    {
        $point = $this->type->convertToPHPValue('POINT(50.879800 4.700500)', $this->platform);

        self::assertInstanceOf(GeoPoint::class, $point);
        self::assertSame(50.8798, $point->lat);
        self::assertSame(4.7005, $point->lng);
    }

    public function testInvalidPhpValueReturnsNull(): void
    {
        self::assertNull($this->type->convertToPHPValue('not a point', $this->platform));
    }
}
