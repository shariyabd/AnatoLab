<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\QuizController as QuizApiController;
use App\Http\Controllers\Web\QuizController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Assessment — Handover 07
|-------------------------------------------------------------------------------
|
| `{quiz}` is an organ slug. There is no `quizzes` table
| (docs/architecture.md §6 has none); a quiz is the published question set for
| one published organ, and App\Services\Assessment\Quiz explains why deriving
| it beats inventing a table Handover 13 would then have to administer.
|
| Authenticated throughout. An attempt has to belong to somebody — mastery is
| per student — and the quiz payload embeds the same OrganDto the anatomy API
| gates behind a login until the licence gate closes (docs/licence-log.md §3).
|
| No `web` middleware is applied: routes/features/*.php is required from
| routes/web.php, so these already sit inside the web group with the session
| and CSRF that session-cookie authentication needs (docs/architecture.md §7).
|
| The wildcard is constrained to the slug character set so `/quizzes/anything`
| is a router 404 rather than a cache lookup for a key that cannot exist.
|
*/

Route::prefix('api/v1/quizzes')
    ->name('api.v1.quiz.')
    ->middleware(['auth', 'throttle:api'])
    ->group(function (): void {
        Route::get('/{quiz}', [QuizApiController::class, 'show'])
            ->where('quiz', '[a-z0-9-]+')
            ->name('show');

        Route::post('/{quiz}/attempt', [QuizApiController::class, 'attempt'])
            ->where('quiz', '[a-z0-9-]+')
            ->name('attempt');
    });

/*
| The pages. `index` is the nav entry (config/navigation.php), `show` is the
| round itself and the deep link a lesson or a recommendation will point at.
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/quizzes', [QuizController::class, 'index'])
        ->name('quiz.index');

    Route::get('/quizzes/{quiz}', [QuizController::class, 'show'])
        ->where('quiz', '[a-z0-9-]+')
        ->name('quiz.show');
});
