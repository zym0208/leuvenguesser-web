<?php

declare(strict_types=1);

namespace App\Doctrine\Types;

use App\Geo\GeoPoint;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

final class GeoPointType extends Type
{
    public const NAME = 'geo_point';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'POINT SRID 4326';
    }

    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return $platform instanceof AbstractMySQLPlatform ? ['point'] : [];
    }

    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf('ST_GeomFromText(%s, 4326)', $sqlExpr);
        }

        return $sqlExpr;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!$value instanceof GeoPoint) {
            throw InvalidType::new($value, self::NAME, [GeoPoint::class]);
        }

        return sprintf('POINT(%F %F)', $value->lat, $value->lng);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof GeoPoint) {
            return $value;
        }

        if (is_string($value) && preg_match(
            '/POINT\s*\(\s*([+-]?\d*\.?\d+(?:[eE][+-]?\d+)?)\s+([+-]?\d*\.?\d+(?:[eE][+-]?\d+)?)\s*\)/',
            $value,
            $m
        ) === 1) {
            return new GeoPoint((float) $m[1], (float) $m[2]);
        }

        if (is_string($value)) {
            return $this->parseWkbPoint($value);
        }

        return null;
    }

    private function parseWkbPoint(string $binary): ?GeoPoint
    {
        foreach ([0, 4] as $start) {
            $point = $this->parseWkbPointAtOffset($binary, $start);
            if ($point !== null) {
                return $point;
            }
        }

        return null;
    }

    private function parseWkbPointAtOffset(string $binary, int $start): ?GeoPoint
    {
        $len = strlen($binary);
        if ($len < $start + 21) {
            return null;
        }

        $offset = $start;
        $byteOrder = ord($binary[$offset]);
        if ($byteOrder !== 0 && $byteOrder !== 1) {
            return null;
        }
        $offset += 1;
        $unpack = $byteOrder === 1 ? 'V' : 'N';
        // MySQL WKB stores native IEEE doubles (use d/G, not removed PHP 8.5 <d/>d modifiers).
        $doubleUnpack = $byteOrder === 1 ? 'd' : 'G';
        $type = unpack($unpack, substr($binary, $offset, 4));
        if ($type === false || !isset($type[1])) {
            return null;
        }
        $wkbType = (int) $type[1];
        if (($wkbType & 0xFF) !== 1) {
            return null;
        }
        $offset += 4;
        if (($wkbType & 0x20000000) !== 0) {
            $offset += 4;
        }

        if ($len < $offset + 16) {
            return null;
        }

        $x = unpack($doubleUnpack, substr($binary, $offset, 8));
        $offset += 8;
        $y = unpack($doubleUnpack, substr($binary, $offset, 8));
        if ($x === false || $y === false || !isset($x[1], $y[1])) {
            return null;
        }

        // WKB coordinate pair is (longitude, latitude); GeoPoint is (lat, lng).
        return new GeoPoint((float) $y[1], (float) $x[1]);
    }
}
