<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Symfony\Set\SymfonySetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withComposerBased(symfony: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        SymfonySetList::SYMFONY_CODE_QUALITY,
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
