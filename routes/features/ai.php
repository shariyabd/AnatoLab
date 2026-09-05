<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\TutorController;
use App\Http\Controllers\Web\TutorPanelController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| AI tutor — Handover 08
|-------------------------------------------------------------------------------
|
| Rate limits are declared here rather than inside the controller so that
| `route:list` shows what protects each endpoint (docs/engineering.md §10).
| The named limiters are registered in AiServiceProvider from config/ai.php:
|
|   ai-tutor        20/min  ask and explain
|   ai-tutor-daily 200/day  every tutor call, so a slow drip still has a ceiling
|   ai-hint         30/min  hints are cheaper and asked in bursts while stuck
|
| No `web` middleware is applied: routes/features/*.php is required from
| routes/web.php, so these already sit inside the web group with the session and
| CSRF that session-cookie authentication needs (docs/architecture.md §2).
|
*/

Route::prefix('api/v1/ai')
    ->name('api.v1.ai.')
    ->middleware('auth')
    ->group(function (): void {
        Route::post('/tutor/ask', [TutorController::class, 'ask'])
            ->middleware(['throttle:ai-tutor', 'throttle:ai-tutor-daily'])
            ->name('tutor.ask');

        Route::post('/tutor/explain', [TutorController::class, 'explain'])
            ->middleware(['throttle:ai-tutor', 'throttle:ai-tutor-daily'])
            ->name('tutor.explain');

        Route::post('/tutor/hint', [TutorController::class, 'hint'])
            ->middleware(['throttle:ai-hint', 'throttle:ai-tutor-daily'])
            ->name('tutor.hint');

        // Reads, so the shared api limiter is enough — these touch no provider.
        Route::get('/conversations', [ConversationController::class, 'index'])
            ->middleware('throttle:api')
            ->name('conversations.index');

        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])
            ->middleware('throttle:api')
            ->whereNumber('conversation')
            ->name('conversations.show');
    });

/*
| The panel's own page. No entry is added to config/navigation.php: Handover 08
| is not one of that file's declared writers
| (docs/handovers/parallel-execution-plan.md §3 C4), and the panel's real home
| is the slot Handover 05 leaves in Explore.vue. This route is how the panel is
| reachable and demonstrable in the meantime.
*/
Route::middleware('auth')
    ->get('/tutor', TutorPanelController::class)
    ->name('tutor.index');
