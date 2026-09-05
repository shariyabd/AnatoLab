<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Services\Content\AssetRegisterDocument;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The attribution page (handover 14; handover 02's Definition of Done, §6).
 *
 * Public, and deliberately so: attribution that requires an account is not
 * attribution. It is also the page that states the licence position plainly,
 * which is why the release gate travels with it rather than being described in
 * prose the page could contradict.
 *
 * Thin (invariant 7): no validation to do, one service call, one Inertia
 * response.
 */
final class AttributionController
{
    public function __invoke(AssetRegisterDocument $register): Response
    {
        return Inertia::render('Attribution', [
            'register' => $register->render(),
        ]);
    }
}
