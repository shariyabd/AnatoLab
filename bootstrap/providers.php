<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,

    // APPEND ONLY. Order is load order, and a later bind() wins: AiServiceProvider
    // must come after AppServiceProvider so it can replace the platform's
    // baseline null bindings. Handover 11 appends RagServiceProvider below this
    // line; never reorder.
    App\Providers\AiServiceProvider::class,

    // Handover 11. After AiServiceProvider so it can rebind
    // VectorStoreInterface over the platform's baseline NullVectorStore without
    // disturbing the AIProviderInterface binding above it.
    App\Providers\RagServiceProvider::class,
];
