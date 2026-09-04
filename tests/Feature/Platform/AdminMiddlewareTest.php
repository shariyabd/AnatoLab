<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware(['web', 'auth', 'admin'])
        ->get('/__test__/admin-area', fn (): string => 'admin ok');
});

it('lets an administrator through', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/__test__/admin-area')
        ->assertOk()
        ->assertSee('admin ok');
});

it('hides the admin area from a student', function (): void {
    // 404, not 403: a student probing admin URLs learns nothing about which
    // ones exist (app/Http/Middleware/EnsureUserIsAdmin.php).
    $this->actingAs(User::factory()->create())
        ->get('/__test__/admin-area')
        ->assertNotFound();
});

it('sends a guest to the login screen', function (): void {
    $this->get('/__test__/admin-area')->assertRedirect('/login');
});

it('gates the Horizon dashboard on the admin role', function (): void {
    $student = User::factory()->create();
    $admin = User::factory()->admin()->create();

    expect($student->can('viewHorizon'))->toBeFalse()
        ->and($admin->can('viewHorizon'))->toBeTrue();
});
