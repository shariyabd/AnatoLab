<?php

declare(strict_types=1);

namespace App\Services\Anatomy;

use JsonException;
use RuntimeException;

/**
 * Node names out of a GLB's JSON chunk.
 *
 * One reason to exist: turn glTF binary into a list of names. Deciding whether
 * those names are the right ones is `MeshIdentityVerifier`'s job.
 *
 * No dependency, deliberately. A GLB is a twelve-byte header followed by
 * length-prefixed chunks, the first of which is JSON by specification; reading
 * it is about forty lines. There is no maintained PHP glTF library worth a
 * Composer entry and a licence review for that, and `docs/engineering.md` §5
 * requires a justification for each new dependency rather than a habit.
 *
 * It reads names only. Geometry, accessors and buffers are never touched, so
 * this stays cheap enough to run over the whole model set in CI — the binary
 * chunk, which is all of the file's weight, is skipped entirely.
 */
final class GlbNodeReader
{
    /** `glTF`, little-endian, at byte 0 of every GLB. */
    private const MAGIC = 'glTF';

    /** Chunk type `JSON`, as the uint32 the container stores. */
    private const CHUNK_TYPE_JSON = 0x4E4F534A;

    private const HEADER_BYTES = 12;

    private const CHUNK_HEADER_BYTES = 8;

    /**
     * Every named node in the file, in declaration order.
     *
     * Returns names rather than node indices: callers match against
     * `anatomical_structures.model_object_name`, which is a name, and an index
     * would be one more thing to keep in step with an export.
     *
     * @return list<string>
     */
    public function readNodeNames(string $binary): array
    {
        /** @var array<string, mixed> $gltf */
        $gltf = $this->readJsonChunk($binary);

        /** @var list<array<string, mixed>> $nodes */
        $nodes = is_array($gltf['nodes'] ?? null) ? $gltf['nodes'] : [];

        $names = [];

        foreach ($nodes as $node) {
            $name = $node['name'] ?? null;

            // An unnamed node is legal glTF and is usually a transform holder.
            // It can never be a structure, because a structure is addressed by
            // name, so it is dropped rather than reported as an empty string.
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * How many meshes the file declares.
     *
     * The single-mesh test. Handover 17 keeps dot selection working for organs
     * that have not been re-exported, and this is how an organ is recognised as
     * one of them without inspecting node names for a `tripo_` prefix that only
     * one supplier happens to use.
     */
    public function countMeshes(string $binary): int
    {
        /** @var array<string, mixed> $gltf */
        $gltf = $this->readJsonChunk($binary);

        return is_array($gltf['meshes'] ?? null) ? count($gltf['meshes']) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonChunk(string $binary): array
    {
        if (strlen($binary) < self::HEADER_BYTES + self::CHUNK_HEADER_BYTES) {
            throw new RuntimeException('Not a GLB: the file is shorter than a glTF header.');
        }

        if (substr($binary, 0, 4) !== self::MAGIC) {
            throw new RuntimeException('Not a GLB: the file does not begin with the glTF magic.');
        }

        $offset = self::HEADER_BYTES;

        // Walk the chunks rather than assuming JSON is first. The specification
        // requires it to be, and a file that breaks that rule is exactly the
        // one worth failing on a clear message instead of a byte-offset error.
        while ($offset + self::CHUNK_HEADER_BYTES <= strlen($binary)) {
            /** @var array{length: int, type: int}|false $header */
            $header = unpack('Vlength/Vtype', substr($binary, $offset, self::CHUNK_HEADER_BYTES));

            if ($header === false) {
                break;
            }

            $offset += self::CHUNK_HEADER_BYTES;

            if ($header['type'] === self::CHUNK_TYPE_JSON) {
                return $this->decode(substr($binary, $offset, $header['length']));
            }

            $offset += $header['length'];
        }

        throw new RuntimeException('Not a usable GLB: it contains no JSON chunk.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "The GLB's JSON chunk is not valid JSON: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return $decoded;
    }
}
