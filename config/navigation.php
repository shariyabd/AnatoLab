<?php

declare(strict_types=1);

/*
|-------------------------------------------------------------------------------
| Navigation registry
|-------------------------------------------------------------------------------
|
| The base layout renders this array. Features add their own entry here and
| never edit a layout component, so two lanes adding navigation cannot collide
| in a Vue file (docs/feature-plan.md §7.4).
|
| APPEND ONLY. Add your entry at the end of its section and give it an `order`;
| the layout sorts by `order`, so appending never forces you to reposition
| someone else's line and a merge conflict stays a two-line conflict.
|
| Each entry:
|   key     stable identifier, also the active-state key
|   label   display text
|   route   named route — must exist, or route:list and the nav test fail
|   icon    icon name resolved by the frontend Icon component
|   roles   which users.role values see it
|   order   ascending sort position; leave gaps of 10 for later insertions
|
*/

return [

    'main' => [

        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'route' => 'dashboard',
            'icon' => 'home',
            'roles' => ['student', 'admin'],
            'order' => 10,
        ],

        [
            'key' => 'explore',
            'label' => 'Explore',
            'route' => 'explore.index',
            'icon' => 'cube',
            'roles' => ['student', 'admin'],
            'order' => 20,
        ],

        // F06 → lessons · F07 → quizzes · F09 → missions · F10 → progress · F12 → simulations

    ],

    'admin' => [

        // F13 → content management, AI review queue, hotspot authoring

    ],

];
