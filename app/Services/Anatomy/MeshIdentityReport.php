<?php

declare(strict_types=1);

namespace App\Services\Anatomy;

/**
 * What one organ's mesh identity looks like right now.
 *
 * A value object next to the service that produces it, rather than in
 * `app/Contracts`: that directory holds the DTOs the two swappable
 * abstractions exchange, and handover 17 requires it to stay unchanged. This
 * crosses no seam — it is a service's return type, read by one command.
 *
 * `orphans` is the field that matters. A structure claiming a node the model
 * does not contain is silently unselectable, and handover 17 calls a rename in
 * Blender that orphans forty structures the most likely failure in the lane.
 */
final readonly class MeshIdentityReport
{
    /**
     * @param  list<string>  $orphans  structures naming a node the model lacks
     * @param  list<string>  $matched  structures whose node was found
     * @param  list<string>  $dotOnly  structures with no claim, selected by anchor
     * @param  list<string>  $unclaimedNodes  structure-shaped nodes no row points at
     */
    public function __construct(
        public string $organSlug,
        public string $modelPath,
        public bool $modelIsReadable,
        public bool $isSingleMesh,
        public array $orphans = [],
        public array $matched = [],
        public array $dotOnly = [],
        public array $unclaimedNodes = [],
    ) {}

    /**
     * An organ nothing claims a mesh node on cannot fail.
     *
     * This is what keeps the command honest before F17's assets land: today
     * every structure is dot-only, so every organ passes — and it passes because
     * it claims nothing, not because anything was verified. A missing model file
     * is only a failure for an organ that claims a node inside it.
     */
    public function passes(): bool
    {
        if ($this->orphans !== []) {
            return false;
        }

        return $this->modelIsReadable || $this->claimCount() === 0;
    }

    public function claimCount(): int
    {
        return count($this->matched) + count($this->orphans);
    }

    /**
     * Whether this organ has been through F17's export at all.
     *
     * Reported rather than inferred at the call site: "mixed mode" is an
     * expected migration state, not a defect, and only the fully-unconverted
     * organs are worth listing separately in a summary.
     */
    public function isConverted(): bool
    {
        return $this->claimCount() > 0;
    }
}
