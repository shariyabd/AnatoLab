<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * Shared base for admin write requests.
 *
 * `authorize()` is deliberately NOT overridden to return `true`. Each subclass
 * names the policy ability it needs, so a request class cannot be reused on a
 * route whose authorization it never considered. The `admin` middleware still
 * guards the area; this guards the action (docs/architecture.md §14).
 *
 * `administrator()` is the invariant-1 seam: the services take a typed User,
 * and this is where the session becomes one, so no service ever reaches for
 * the authenticated-user helper itself.
 */
abstract class AdminRequest extends FormRequest
{
    /**
     * The authenticated administrator.
     *
     * Throws rather than returning null. Every route using one of these sits
     * behind `auth` and `admin`, so a missing user is a routing bug, and a
     * nullable return would push a meaningless null check into every caller.
     */
    public function administrator(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new RuntimeException('An admin request reached a controller without an authenticated administrator.');
        }

        return $user;
    }
}
