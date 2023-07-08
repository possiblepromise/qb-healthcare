<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

enum ProviderAdjustmentType: string
{
    case interest = 'interest';

    case origination_fee = 'origination fee';
}
