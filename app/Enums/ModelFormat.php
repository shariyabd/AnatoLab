<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The container format of an organ's 3D asset.
 *
 * Mirrors OrganDto.modelFormat in resources/js/anatomy/types.ts, which is a
 * `'glb' | 'gltf'` literal union. Adding a case here without adding it there
 * emits a value the viewer's types say cannot happen.
 */
enum ModelFormat: string
{
    case Glb = 'glb';
    case Gltf = 'gltf';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
