<?php

// Only the optional protocol's base type and markers are needed to exercise our schemas.
namespace Laravel\Mcp\Server {
    if (!class_exists(Tool::class)) {
        abstract class Tool {}
    }
}

namespace EvolutionCMS\eMCP\Contracts {
    if (!interface_exists(WritesSite::class)) {
        interface WritesSite {}
    }

    if (!interface_exists(DestructiveSite::class)) {
        interface DestructiveSite extends WritesSite {}
    }

    if (!interface_exists(ToolProvider::class)) {
        interface ToolProvider
        {
            public function server(): string;

            public function tools(): array;
        }
    }
}
