<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Fixtures;

/**
 * Loads canned Yoco API response payloads from disk so tests share a single
 * source of truth that mirrors realistic shapes (taken from Yoco docs).
 */
final class FixtureLoader
{
    public static function raw(string $name): string
    {
        $path = __DIR__.'/'.$name.'.json';

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Fixture not found: {$path}");
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    public static function asArray(string $name): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::raw($name), true, 64, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
