<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withRules([AddNameToLiteralArgumentRector::class])
    ->withSkip([
        // `@var mixed` on assignments from mixed-returning generators is
        // load-bearing: it suppresses Psalm's MixedAssignment. Not useless.
        RemoveUselessVarTagRector::class,
    ]);
