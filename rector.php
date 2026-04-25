<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Basic PHP 8.3 migration
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
    ])
    // Powerful built-in sets for code health
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        doctrineCodeQuality: true,
        symfonyCodeQuality: true
    )
    ->withImportNames(importShortClasses: false)
    ->withSkip([
        __DIR__ . '/src/Kernel.php',
    ]);
