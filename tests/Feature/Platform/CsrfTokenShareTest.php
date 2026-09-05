<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
| The shared CSRF token (handover 14).
|
| app.blade.php renders <meta name="csrf-token"> once, when the document loads.
| Logging in and registering both regenerate the session, and both are Inertia
| visits — so without this prop the meta tag keeps the token from before the
| rotation and every fetch in resources/js/composables starts failing with 419
| the moment a student signs in. resources/js/app.ts writes this value back into
| the tag after each visit.
|
| Found by the PRD §44 journey: a newly registered student could not ask the
| tutor, answer a question, run a mission, step a simulation, or record a lesson
| step, because every one of those is such a fetch.
*/

it('shares the current session token with every page', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('csrfToken', session()->token())
    );
});

it('shares the token rotated by registration, not the one before it', function (): void {
    $before = session()->token();

    $response = $this->post('/register', [
        'name' => 'New Student',
        'email' => 'rotates@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'education_level' => 'high_school',
        'difficulty_preference' => 'beginner',
    ]);

    $response->assertRedirect('/dashboard');

    $after = session()->token();

    expect($after)->not->toBe($before);

    // A real browser follows the redirect in a new request, which re-reads the
    // user from the database. In-process the guard still holds the instance
    // User::create() returned, whose `role` was never loaded because the column
    // takes its value from a database default. Forgetting the guard reproduces
    // the browser's second request rather than asserting against a state no
    // deployment ever has.
    $this->app->make('auth')->forgetGuards();

    $this->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('csrfToken', $after)
    );
});

it('shares the token rotated by logging in', function (): void {
    $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

    $before = session()->token();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
    ])->assertRedirect();

    expect(session()->token())->not->toBe($before);

    $this->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('csrfToken', session()->token())
    );
});
