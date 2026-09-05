<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\KnowledgeDocument;
use App\Models\Lesson;
use App\Models\Mission;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;

/*
| Route-level authorization across the whole admin area.
|
| This is the *second* of the two checks docs/architecture.md §14 requires. The
| first — the policies themselves — is asserted without HTTP in
| tests/Unit/Admin/PolicyTest.php, deliberately, so that neither check can pass
| by borrowing the other's coverage.
|
| A non-admin gets 404 rather than 403: EnsureUserIsAdmin answers identically to
| a guest and to a logged-in student, so probing /admin reveals nothing about
| which admin routes exist.
*/

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->student = User::factory()->create();
    $this->organ = Organ::factory()->create();
});

/**
 * Every admin URL, with the verb it answers.
 *
 * Table-driven so a route added to routes/features/admin.php without a line
 * here is visible as a gap. `it covers every admin route` below asserts the
 * table is complete against the router itself.
 *
 * @return list<array{0: string, 1: string}>
 */
function adminRoutes(Organ $organ, AnatomicalStructure $structure, Lesson $lesson, Question $question, Mission $mission, KnowledgeDocument $document): array
{
    return [
        ['get', '/admin'],
        ['get', '/admin/analytics'],

        ['get', '/admin/organs'],
        ['get', '/admin/organs/create'],
        ['post', '/admin/organs'],
        ['get', "/admin/organs/{$organ->slug}/edit"],
        ['put', "/admin/organs/{$organ->slug}"],
        ['patch', "/admin/organs/{$organ->slug}/status"],
        ['delete', "/admin/organs/{$organ->slug}"],

        ['get', "/admin/organs/{$organ->slug}/author"],
        ['post', "/admin/organs/{$organ->slug}/structures"],
        ['put', "/admin/structures/{$structure->id}"],
        ['patch', "/admin/structures/{$structure->id}/anchor"],
        ['patch', "/admin/structures/{$structure->id}/status"],
        ['delete', "/admin/structures/{$structure->id}"],

        ['get', '/admin/lessons'],
        ['get', '/admin/lessons/create'],
        ['post', '/admin/lessons'],
        ['get', "/admin/lessons/{$lesson->slug}/edit"],
        ['put', "/admin/lessons/{$lesson->slug}"],
        ['patch', "/admin/lessons/{$lesson->slug}/status"],
        ['delete', "/admin/lessons/{$lesson->slug}"],

        ['get', '/admin/questions'],
        ['get', '/admin/questions/review'],
        ['get', '/admin/questions/create'],
        ['post', '/admin/questions'],
        ['get', "/admin/questions/{$question->id}/edit"],
        ['put', "/admin/questions/{$question->id}"],
        ['post', "/admin/questions/{$question->id}/publish"],
        ['patch', "/admin/questions/{$question->id}/status"],
        ['delete', "/admin/questions/{$question->id}"],

        ['get', '/admin/missions'],
        ['get', '/admin/missions/create'],
        ['post', '/admin/missions'],
        ['get', "/admin/missions/{$mission->slug}/edit"],
        ['put', "/admin/missions/{$mission->slug}"],
        ['patch', "/admin/missions/{$mission->slug}/status"],
        ['delete', "/admin/missions/{$mission->slug}"],

        ['get', '/admin/knowledge'],
        ['post', '/admin/knowledge'],
        ['post', "/admin/knowledge/{$document->id}/reingest"],
        ['delete', "/admin/knowledge/{$document->id}"],
    ];
}

function adminRouteFixtures(Organ $organ): array
{
    return [
        $organ,
        AnatomicalStructure::factory()->for($organ)->create(),
        Lesson::factory()->for($organ)->create(),
        Question::factory()->for($organ)->create(),
        Mission::factory()->for($organ)->create(),
        KnowledgeDocument::factory()->create(),
    ];
}

it('hides every admin route from a student', function (): void {
    // 404, not 403 — an authenticated student learns nothing about the shape of
    // the admin area.
    foreach (adminRoutes(...adminRouteFixtures($this->organ)) as [$verb, $url]) {
        $this->actingAs($this->student)
            ->call(strtoupper($verb), $url)
            ->assertNotFound("{$verb} {$url} was reachable by a student");
    }
});

it('hides every admin route from a guest', function (): void {
    foreach (adminRoutes(...adminRouteFixtures($this->organ)) as [$verb, $url]) {
        $response = $this->call(strtoupper($verb), $url);

        // `auth` runs before `admin`, so a guest is asked to log in rather than
        // being told the route does not exist.
        expect($response->getStatusCode())->toBeIn(
            [302, 401, 419],
            "{$verb} {$url} did not challenge a guest",
        );
    }
});

it('lets an admin reach every admin page', function (): void {
    $pages = [
        '/admin',
        '/admin/analytics',
        '/admin/organs',
        '/admin/organs/create',
        '/admin/lessons',
        '/admin/lessons/create',
        '/admin/questions',
        '/admin/questions/review',
        '/admin/questions/create',
        '/admin/missions',
        '/admin/missions/create',
        '/admin/knowledge',
    ];

    foreach ($pages as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk("{$url} was not reachable by an admin");
    }
});

it('covers every admin route in the table above', function (): void {
    // Guards the table: a route added to routes/features/admin.php without a
    // line in adminRoutes() would otherwise never be checked against a student.
    $declared = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'admin.'))
        ->map(fn ($route): string => $route->uri())
        ->unique()
        ->values();

    $tested = collect(adminRoutes(...adminRouteFixtures($this->organ)))
        ->map(fn (array $row): string => ltrim($row[1], '/'));

    foreach ($declared as $uri) {
        // Compare by shape. The placeholder is swapped in before quoting and
        // back out after, so preg_quote cannot escape the braces out from under
        // the replacement.
        $sentinel = "\x00PARAM\x00";
        $shape = preg_replace('#\{[a-zA-Z_]+\}#', $sentinel, $uri) ?? $uri;
        $pattern = '#^'.str_replace(preg_quote($sentinel, '#'), '[^/]+', preg_quote($shape, '#')).'$#';

        expect($tested->contains(fn (string $candidate): bool => preg_match($pattern, $candidate) === 1))
            ->toBeTrue("Route {$uri} is not covered by the authorization table");
    }
});

it('offers the admin nav entry to an admin only', function (): void {
    $adminNav = fn (User $user): array => $this->actingAs($user)
        ->get('/dashboard')
        ->viewData('page')['props']['navigation']['admin'] ?? [];

    expect($adminNav($this->admin))->toHaveCount(1)
        ->and($adminNav($this->student))->toBeEmpty();
});

it('decides the dashboard sections from the policies, not from the role', function (): void {
    $props = $this->actingAs($this->admin)->get('/admin')->viewData('page')['props'];

    expect($props['sections'])->not->toBeEmpty();

    foreach ($props['sections'] as $section) {
        expect($section)->toHaveKeys(['key', 'label', 'description', 'href', 'can', 'badge'])
            ->and($section['can'])->toBeTrue();
    }
});
