<?php

declare(strict_types=1);

use App\Http\Controllers\Web\DesignTokensController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Design tokens — Handover 15
|-------------------------------------------------------------------------------
|
| Public, and with no middleware beyond the web stack the loader runs inside.
| The page renders no student data, no organ, no model URL and no request — it
| is a specification sheet for the stylesheet — so there is nothing here for a
| login to protect, and requiring one would put a session between a reviewer and
| a page whose whole job is to be looked at.
|
*/

Route::get('/design-tokens', DesignTokensController::class)->name('design.tokens');
