<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Anatomy\MeshIdentityReport;
use App\Services\Anatomy\MeshIdentityVerifier;
use Illuminate\Console\Command;

/**
 * `php artisan anatomy:verify-mesh-identity` — handover 17 Branch B.
 *
 * Every published structure with a non-null `model_object_name` must name a
 * node the organ's GLB actually contains. Nothing in the database can enforce
 * that: it is a string in one system naming a node in another, and a rename in
 * Blender orphans every structure that pointed at the old name without any
 * error anywhere. Handover 17 calls that the most likely failure in the lane,
 * which is why this is checked in and run in CI rather than run when someone
 * remembers.
 *
 * It verifies claims, and only claims. A structure that claims nothing is
 * dot-selected and correct; an organ where nothing claims anything cannot fail,
 * and a model file that is absent is only a problem for an organ claiming a
 * node inside it. That is what lets this run green today — when every structure
 * is dot-only and `.gitignore` excludes every GLB — and still bite the moment
 * an export lands.
 */
final class VerifyMeshIdentityCommand extends Command
{
    protected $signature = 'anatomy:verify-mesh-identity
                            {--organ= : Verify one organ by slug}';

    protected $description = 'Assert every structure that claims a mesh node names one its model contains';

    public function handle(MeshIdentityVerifier $verifier): int
    {
        $organSlug = $this->option('organ');
        $reports = $verifier->verifyAll();

        if (is_string($organSlug)) {
            $reports = array_values(array_filter(
                $reports,
                static fn (MeshIdentityReport $report): bool => $report->organSlug === $organSlug,
            ));

            if ($reports === []) {
                $this->components->error("No organ has the slug `{$organSlug}`.");

                return self::FAILURE;
            }
        }

        $converted = array_values(array_filter(
            $reports,
            static fn (MeshIdentityReport $report): bool => $report->isConverted(),
        ));

        $failed = array_values(array_filter(
            $reports,
            static fn (MeshIdentityReport $report): bool => ! $report->passes(),
        ));

        $this->newLine();

        if ($converted === []) {
            // Said out loud rather than reported as a silent pass. "Nothing
            // claims a mesh node" and "every claim checks out" are different
            // results, and a green tick that means the first is how a check
            // stops being read.
            $this->components->info(
                'No structure claims a mesh node yet — every organ is on anchor-position '
                .'selection. Nothing to verify (docs/mesh-strategy.md).'
            );

            return self::SUCCESS;
        }

        foreach ($converted as $report) {
            $this->reportOrgan($report);
        }

        $this->newLine();

        if ($failed !== []) {
            foreach ($failed as $report) {
                $detail = $report->modelIsReadable
                    ? 'orphaned: '.implode(', ', $report->orphans)
                    : "model is unreadable at {$report->modelPath}";

                $this->components->error("{$report->organSlug} — {$detail}");
            }

            $this->newLine();

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d organ%s verified, every claimed mesh node present.',
            count($converted),
            count($converted) === 1 ? '' : 's',
        ));
        $this->newLine();

        return self::SUCCESS;
    }

    private function reportOrgan(MeshIdentityReport $report): void
    {
        $status = $report->passes() ? '<info>✓</info>' : '<error>✗</error>';

        $this->line(sprintf(
            '  %s %-18s %d matched, %d orphaned, %d on dot fallback',
            $status,
            $report->organSlug,
            count($report->matched),
            count($report->orphans),
            count($report->dotOnly),
        ));

        // A warning, not a failure: an export that gained a structure the
        // seeder has not caught up with is content work, but it is the signal
        // that the model and the database are drifting apart.
        if ($report->unclaimedNodes !== []) {
            $this->line(sprintf(
                '      <comment>%d node%s in the model no structure points at: %s</comment>',
                count($report->unclaimedNodes),
                count($report->unclaimedNodes) === 1 ? '' : 's',
                implode(', ', $report->unclaimedNodes),
            ));
        }

        if ($report->isSingleMesh && $report->isConverted()) {
            $this->line(
                '      <comment>the model declares one mesh — per-structure picking will '
                .'degrade to dots</comment>'
            );
        }
    }
}
