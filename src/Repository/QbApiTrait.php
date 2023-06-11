<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\DataService\DataService;

trait QbApiTrait
{
    private QuickBooks $qb;

    private function getDataService(): DataService
    {
        $dataService = $this->qb->getDataService();
        $dataService->throwExceptionOnError(true);

        return $dataService;
    }
}
