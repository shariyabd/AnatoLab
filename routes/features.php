<?php

declare(strict_types=1);

/*
|-------------------------------------------------------------------------------
| Feature route loader
|-------------------------------------------------------------------------------
|
| Every file in routes/features/ is required, in a stable alphabetical order.
| This is the mechanism that lets thirteen features add routes without any two
| of them editing the same file (docs/feature-plan.md §7.1).
|
| Sorted rather than left to glob's filesystem order so route:list output is
| identical on every machine and in CI — an unstable order makes a route
| collision look like a flaky test.
|
*/

$featureRoutes = glob(__DIR__.'/features/*.php');

if ($featureRoutes === false) {
    return;
}

sort($featureRoutes);

foreach ($featureRoutes as $featureRoute) {
    require $featureRoute;
}
