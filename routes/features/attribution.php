<?php

declare(strict_types=1);

use App\Http\Controllers\Web\AttributionController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Attribution — Handover 14
|-------------------------------------------------------------------------------
|
| Public, with no middleware group beyond the web stack the loader runs inside.
| Credit and licence status have to be readable by someone who has not signed
| up — a judge, or the owner of an asset checking how their work is credited
| (PRD §42, docs/asset-register.md §6).
|
*/

Route::get('/attribution', AttributionController::class)->name('attribution');
