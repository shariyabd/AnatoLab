<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AnalyticsAdminController;
use App\Http\Controllers\Admin\HotspotAuthoringController;
use App\Http\Controllers\Admin\KnowledgeAdminController;
use App\Http\Controllers\Admin\LessonAdminController;
use App\Http\Controllers\Admin\MissionAdminController;
use App\Http\Controllers\Admin\OrganAdminController;
use App\Http\Controllers\Admin\QuestionAdminController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Admin & content management — Handover 13
|-------------------------------------------------------------------------------
|
| `auth` then `admin`. The order matters: EnsureUserIsAdmin aborts 404 for a
| non-admin, and running it before `auth` would 404 a guest who should be
| redirected to the login form.
|
| This group is the coarse gate only. **Every route in it also authorizes
| against a policy** — in the controller for reads, in the FormRequest for
| writes. Middleware answers "may you be in this area", a policy answers "may
| you do this to this record" (docs/architecture.md §14, docs/engineering.md
| §10). tests/Unit/Admin/PolicyTest.php asserts the policies with no HTTP at
| all, so neither check can pass by borrowing the other's coverage.
|
| No `web` middleware: routes/features/*.php is required from routes/web.php,
| so these already sit inside the web group with the session and CSRF that
| session-cookie authentication needs.
|
| Slug wildcards are constrained to the slug character set so a malformed URL
| is a router 404 rather than a database lookup for a key that cannot exist.
|
*/

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('index');

        Route::get('/analytics', AnalyticsAdminController::class)->name('analytics');

        /*
        | Organs. Bound by slug (Organ::getRouteKeyName), like every other
        | organ URL in the application.
        */
        Route::get('/organs', [OrganAdminController::class, 'index'])->name('organs.index');
        Route::get('/organs/create', [OrganAdminController::class, 'create'])->name('organs.create');
        Route::post('/organs', [OrganAdminController::class, 'store'])->name('organs.store');

        Route::prefix('/organs/{organ}')->where(['organ' => '[a-z0-9-]+'])->group(function (): void {
            Route::get('/edit', [OrganAdminController::class, 'edit'])->name('organs.edit');
            Route::put('/', [OrganAdminController::class, 'update'])->name('organs.update');
            Route::patch('/status', [OrganAdminController::class, 'status'])->name('organs.status');
            Route::delete('/', [OrganAdminController::class, 'destroy'])->name('organs.destroy');

            /*
            | The hotspot authoring tool. A GET that loads the model and the
            | organ's structures, and a POST that turns a click into a row.
            |
            | Nested under the organ because a coordinate is meaningless
            | without one: FIT_SIZE pivot space is per-model (invariant 5).
            */
            Route::get('/author', [HotspotAuthoringController::class, 'show'])->name('authoring.show');
            Route::post('/structures', [HotspotAuthoringController::class, 'store'])->name('structures.store');
        });

        /*
        | Structures are addressed by id, not slug: a structure slug is unique
        | only within its organ, so it cannot identify a row on its own.
        */
        Route::prefix('/structures/{structure}')->where(['structure' => '[0-9]+'])->group(function (): void {
            Route::put('/', [HotspotAuthoringController::class, 'update'])->name('structures.update');

            // The `author:point` write, on its own route and its own policy
            // ability. JSON, because the page moves one marker without
            // reloading the model.
            Route::patch('/anchor', [HotspotAuthoringController::class, 'anchor'])->name('structures.anchor');

            Route::patch('/status', [HotspotAuthoringController::class, 'status'])->name('structures.status');
            Route::delete('/', [HotspotAuthoringController::class, 'destroy'])->name('structures.destroy');
        });

        /*
        | Lessons.
        */
        Route::get('/lessons', [LessonAdminController::class, 'index'])->name('lessons.index');
        Route::get('/lessons/create', [LessonAdminController::class, 'create'])->name('lessons.create');
        Route::post('/lessons', [LessonAdminController::class, 'store'])->name('lessons.store');

        Route::prefix('/lessons/{lesson}')->where(['lesson' => '[a-z0-9-]+'])->group(function (): void {
            Route::get('/edit', [LessonAdminController::class, 'edit'])->name('lessons.edit');
            Route::put('/', [LessonAdminController::class, 'update'])->name('lessons.update');
            Route::patch('/status', [LessonAdminController::class, 'status'])->name('lessons.status');
            Route::delete('/', [LessonAdminController::class, 'destroy'])->name('lessons.destroy');
        });

        /*
        | Questions and the AI review queue (PRD §24).
        |
        | `review` is declared before `{question}` so the literal segment is
        | not swallowed by the wildcard — the id constraint would reject it
        | anyway, but relying on that makes the route order load-bearing for a
        | reason a reader cannot see.
        */
        Route::get('/questions', [QuestionAdminController::class, 'index'])->name('questions.index');
        Route::get('/questions/review', [QuestionAdminController::class, 'review'])->name('questions.review');
        Route::get('/questions/create', [QuestionAdminController::class, 'create'])->name('questions.create');
        Route::post('/questions', [QuestionAdminController::class, 'store'])->name('questions.store');

        Route::prefix('/questions/{question}')->where(['question' => '[0-9]+'])->group(function (): void {
            Route::get('/edit', [QuestionAdminController::class, 'edit'])->name('questions.edit');
            Route::put('/', [QuestionAdminController::class, 'update'])->name('questions.update');

            // Approval. The only path a question takes to `published`.
            Route::post('/publish', [QuestionAdminController::class, 'publish'])->name('questions.publish');

            Route::patch('/status', [QuestionAdminController::class, 'status'])->name('questions.status');
            Route::delete('/', [QuestionAdminController::class, 'destroy'])->name('questions.destroy');
        });

        /*
        | Missions.
        */
        Route::get('/missions', [MissionAdminController::class, 'index'])->name('missions.index');
        Route::get('/missions/create', [MissionAdminController::class, 'create'])->name('missions.create');
        Route::post('/missions', [MissionAdminController::class, 'store'])->name('missions.store');

        Route::prefix('/missions/{mission}')->where(['mission' => '[a-z0-9-]+'])->group(function (): void {
            Route::get('/edit', [MissionAdminController::class, 'edit'])->name('missions.edit');
            Route::put('/', [MissionAdminController::class, 'update'])->name('missions.update');
            Route::patch('/status', [MissionAdminController::class, 'status'])->name('missions.status');
            Route::delete('/', [MissionAdminController::class, 'destroy'])->name('missions.destroy');
        });

        /*
        | Knowledge documents. Upload calls F11's KnowledgeService, which
        | writes to a private disk and queues the ingest.
        */
        Route::get('/knowledge', [KnowledgeAdminController::class, 'index'])->name('knowledge.index');
        Route::post('/knowledge', [KnowledgeAdminController::class, 'store'])->name('knowledge.store');

        Route::prefix('/knowledge/{document}')->where(['document' => '[0-9]+'])->group(function (): void {
            Route::post('/reingest', [KnowledgeAdminController::class, 'reingest'])->name('knowledge.reingest');
            Route::delete('/', [KnowledgeAdminController::class, 'destroy'])->name('knowledge.destroy');
        });
    });
