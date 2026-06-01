<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

use Rector\CodeQuality\Rector as CodeQuality;
use Rector\CodingStyle\Rector as CodingStyle;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector as DeadCode;
use Rector\EarlyReturn\Rector as EarlyReturn;
use Rector\TypeDeclaration\Rector as TypeDeclaration;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/config',
        __DIR__ . '/sync.php',
    ])
    // No argument: Rector picks the target PHP version from composer.json
    // (require.php's lower bound, else config.platform.php), both kept at 8.3 by
    // the org sync, so the level sets track the declared floor instead of a
    // hardcoded one that can drift.
    ->withPhpSets()
    ->withRules([
        CodeQuality\BooleanNot\ReplaceMultipleBooleanNotRector::class,
        CodeQuality\Foreach_\UnusedForeachValueToArrayKeysRector::class,
        CodeQuality\FuncCall\ChangeArrayPushToArrayAssignRector::class,
        CodeQuality\FuncCall\CompactToVariablesRector::class,
        CodeQuality\Identical\SimplifyArraySearchRector::class,
        CodeQuality\Identical\SimplifyConditionsRector::class,
        CodeQuality\Identical\StrlenZeroToIdenticalEmptyStringRector::class,
        CodeQuality\LogicalAnd\LogicalToBooleanRector::class,
        CodeQuality\NotEqual\CommonNotEqualRector::class,
        CodeQuality\Ternary\SimplifyTautologyTernaryRector::class,
        CodeQuality\Ternary\SwitchNegatedTernaryRector::class,
        CodingStyle\ClassMethod\MakeInheritedMethodVisibilitySameAsParentRector::class,
        DeadCode\ClassMethod\RemoveUselessParamTagRector::class,
        DeadCode\ClassMethod\RemoveUselessReturnTagRector::class,
        DeadCode\MethodCall\RemoveNullArgOnNullDefaultParamRector::class,
        DeadCode\Property\RemoveUselessVarTagRector::class,
        EarlyReturn\If_\ChangeNestedIfsToEarlyReturnRector::class,
        EarlyReturn\If_\RemoveAlwaysElseRector::class,
        Rector\CodingStyle\Rector\FuncCall\ConsistentImplodeRector::class,
        Rector\Php80\Rector\Class_\StringableForToStringRector::class,
        Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector::class,
        Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector::class,
        TypeDeclaration\ClassMethod\ReturnNeverTypeRector::class,
        TypeDeclaration\StmtsAwareInterface\SafeDeclareStrictTypesRector::class,
    ])
    ->withConfiguredRule(Rector\Php82\Rector\Param\AddSensitiveParameterAttributeRector::class, [
        'sensitive_parameters' => ['token', 'apiKey', 'password'],
    ]);
