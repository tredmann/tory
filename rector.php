<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php85\Rector\Property\AddOverrideAttributeToOverriddenPropertiesRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpSets()
    ->withComposerBased(laravel: true)
    ->withPreparedSets(
        deadCode: true,
        typeDeclarations: true,
        privatization: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
    ])
    ->withImportNames(importShortClasses: false)
    ->withSkip([
        // Would mark Filament's static properties but not the overridden methods next to them.
        AddOverrideAttributeToOverriddenPropertiesRector::class,
    ]);
