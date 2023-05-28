<?php

declare(strict_types=1);

use Devbanana\FixerConfig\Configurator;
use Devbanana\FixerConfig\PhpVersion;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__)
    ->append([
        __FILE__,
        'qb-healthcare.php',
    ])
;

return Configurator::fromPhpVersion(PhpVersion::php81)
    ->withRiskyRulesEnabled()
    ->fixerConfig()
    ->setFinder($finder)
;
