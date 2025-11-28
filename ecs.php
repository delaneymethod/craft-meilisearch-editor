<?php

declare(strict_types=1);

use craft\ecs\SetList;
use PhpCsFixer\Fixer\FunctionNotation\FunctionDeclarationFixer;
use PhpCsFixer\Fixer\Operator\ConcatSpaceFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function (ECSConfig $ecsConfig): void {
    $ecsConfig->parallel();

    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    // Craft 5: keep using the Craft 4 set for now
    $ecsConfig->sets([
        SetList::CRAFT_CMS_4,
    ]);

    $ecsConfig->ruleWithConfiguration(ConcatSpaceFixer::class, [
        'spacing' => 'one',
    ]);

    $ecsConfig->skip([
        'no_useless_concat_operator',
    ]);

    $ecsConfig->ruleWithConfiguration(FunctionDeclarationFixer::class, [
        'closure_function_spacing' => 'one',
    ]);
};
