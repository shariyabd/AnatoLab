<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props shared with every Inertia page.
 *
 * Keep this small and non-secret. Everything here is serialised into the HTML
 * of every response, so it is public by definition: no API keys, no provider
 * names, no answer keys (docs/engineering.md §10, invariant 4).
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                // Explicit field list rather than the model: $hidden protects
                // the password, but an accidental new column would otherwise
                // ship to the browser the moment it is added.
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'educationLevel' => $user->education_level->value,
                    'difficultyPreference' => $user->difficulty_preference->value,
                    'xp' => $user->xp,
                    'level' => $user->level,
                ] : null,
            ],

            // Features register nav entries in config/navigation.php and never
            // edit the layout (docs/feature-plan.md §7.4).
            'navigation' => $this->navigationFor($user),

            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Only entries this user's role may see, sorted by their declared order so
     * appending to the config never depends on line position.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function navigationFor(?User $user): array
    {
        $role = $user?->role->value;

        $sections = [];

        /** @var array<string, list<array<string, mixed>>> $configured */
        $configured = config('navigation', []);

        foreach ($configured as $section => $items) {
            $visible = array_values(array_filter(
                $items,
                static function (array $item) use ($role): bool {
                    /** @var list<string> $roles */
                    $roles = $item['roles'] ?? [];

                    return $role !== null && in_array($role, $roles, strict: true);
                },
            ));

            usort(
                $visible,
                static fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0),
            );

            $sections[$section] = array_map(
                static fn (array $item): array => [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'href' => route($item['route']),
                    'icon' => $item['icon'] ?? null,
                ],
                $visible,
            );
        }

        return $sections;
    }
}
