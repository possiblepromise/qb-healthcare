<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Entity;

enum ClaimStatus: string
{
    case pending = 'pending';

    case processed = 'processed';

    case paid = 'paid';
}
