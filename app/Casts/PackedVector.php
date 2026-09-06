<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A dense embedding, stored as packed little-endian float32.
 *
 * A cast rather than a helper on the store, so `$chunk->embedding` is a
 * `list<float>` everywhere and no caller has to remember the byte format. JSON
 * would be roughly six times the size and would have to be parsed for every row
 * a search scores; `pack()` is a memcpy in both directions.
 *
 * float32 rather than float64: cosine similarity over unit vectors needs about
 * six significant digits, float32 carries seven, and halving the blob halves
 * the bytes MySqlVectorStore reads per query — the thing that actually decides
 * whether the 50 ms budget holds (docs/architecture.md §8.1).
 *
 * @implements CastsAttributes<list<float>, list<float>>
 */
final class PackedVector implements CastsAttributes
{
    /**
     * Little-endian float32. Fixed rather than machine-dependent ('f'), because
     * a database written on one architecture must read back on another.
     */
    private const FORMAT = 'g';

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<float>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Some drivers hand a BLOB back as a stream rather than a string.
        if (is_resource($value)) {
            $value = (string) stream_get_contents($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $unpacked = unpack(self::FORMAT.'*', $value);

        if ($unpacked === false) {
            return null;
        }

        // `array_values` and nothing else. `unpack('g*')` already yields PHP
        // floats in order — it only needs re-indexing from 1 to 0 — so mapping
        // a `(float)` cast over it allocated a second array and made 1,536
        // closure calls per chunk to produce a value identical to its input.
        // MySqlVectorStore reads one of these for every row it scores, so that
        // was ~150,000 redundant calls per search and about a third of the
        // cast's cost (docs/architecture.md §8.1's 50 ms budget).
        /** @var list<float> $components */
        $components = array_values($unpacked);

        return $components;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value === []) {
            return null;
        }

        return pack(
            self::FORMAT.count($value),
            ...array_map(static fn (mixed $component): float => (float) $component, $value),
        );
    }
}
