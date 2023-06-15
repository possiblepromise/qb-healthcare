<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPTerm;

final class TermsRepository
{
    use QbApiTrait;

    public function __construct(QuickBooks $qb)
    {
        $this->qb = $qb;
    }

    /**
     * @return IPPTerm[]
     *
     * @throws \Exception
     */
    public function findAll(): array
    {
        return $this->getDataService()->Query(
            'SELECT * FROM Term'
        );
    }
}
