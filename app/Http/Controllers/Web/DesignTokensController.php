<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The atelier design tokens — handover 15 Phase 0.
 *
 * The review surface for the visual language: every colour, type step, radius,
 * shadow and component state on one page. It has no props because it has no
 * data — the page reads the tokens the browser resolved from theme.css, so a
 * value passed from here would be a second copy of the palette and the one that
 * goes stale.
 */
final class DesignTokensController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('DesignTokens');
    }
}
