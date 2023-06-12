<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Exception\ServiceException;

trait QbApiTrait
{
    private QuickBooks $qb;

    private function getDataService(): DataService
    {
        $dataService = $this->qb->getDataService();
        $dataService->throwExceptionOnError(true);

        return $dataService;
    }

    /**
     * @return never
     *
     * @throws ServiceException
     */
    private function throwIntuitError(mixed $error): void
    {
        throw new ServiceException(
            $error->getIntuitErrorCode() . ': ' . $error->getIntuitErrorMessage()
        );
    }
}
