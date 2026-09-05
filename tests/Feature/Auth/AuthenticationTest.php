<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

it('renders the login screen', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Login'));
});

it('logs a user in with the right password', function (): void {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('rejects the wrong password without saying which field was wrong', function (): void {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'not-the-password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();

    // A distinct "no such account" message would turn the login form into an
    // oracle for which email addresses are registered.
    $unknown = $this->post('/login', [
        'email' => 'nobody@example.test',
        'password' => 'not-the-password',
    ]);

    expect(session('errors')?->first('email'))
        ->toBe(__('auth.failed'));

    $unknown->assertSessionHasErrors('email');
});

it('throttles repeated failed logins', function (): void {
    RateLimiter::clear('throttled@example.test|127.0.0.1');

    $user = User::factory()->create(['email' => 'throttled@example.test']);

    foreach (range(1, 5) as $ignored) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $this->post('/login', [
        'email' => $user->email,
        // Even the CORRECT password must be refused once the limiter trips,
        // otherwise the limit does nothing against a password spray.
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();

    RateLimiter::clear('throttled@example.test|127.0.0.1');
});

it('persists the session across requests', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('auth.user.email', $user->email)
        );
});

it('logs a user out and ends the session', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();

    $this->get('/dashboard')->assertRedirect('/login');
});

it('keeps the dashboard behind authentication', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('never sends the password hash to the browser', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('auth.user.password'));
});
