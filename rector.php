<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Block',
        __DIR__ . '/Controller',
        __DIR__ . '/Helper',
        __DIR__ . '/Model',
        __DIR__ . '/Observer',
        __DIR__ . '/Plugin',
        __DIR__ . '/ViewModel',
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withSets([
        // Allgemeine Code-Qualität
        SetList::CODE_QUALITY,
        SetList::TYPE_DECLARATION,
        SetList::DEAD_CODE,
        // PHP 8.1+ Features nutzen
        SetList::PHP_81,
        SetList::PHP_82,
    ])
    ->withRules([
        // Magento-spezifische Rector-Regel aus magento-coding-standard
        \Magento2\Rector\Src\ReplacePregSplitNullLimit::class,
    ])
    ->withSkip([
        // Template-Dateien ausschließen
        __DIR__ . '/view',
        // Magento2 PHPCS requires @param and @var docblocks even with native type hints
        \Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector::class,
        \Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector::class,
    ]);
