<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The empty shell the platform hands to the learning features.
 *
 * Handover 10 fills this with mastery, recommendations, and activity. It stays
 * deliberately hollow here — a dashboard with invented placeholder statistics
 * is harder to replace than an honest empty one.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard');
    }
}
