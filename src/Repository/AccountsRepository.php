<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPAccount;

final class AccountsRepository
{
    use QbApiTrait;

    public function __construct(QuickBooks $qb)
    {
        $this->qb = $qb;
    }

    /**
     * @return IPPAccount[]
     */
    public function findAllServiceIncomeAccounts(): array
    {
        return $this->getDataService()->Query(
            "SELECT * FROM Account WHERE AccountSubType = 'ServiceFeeIncome'"
        );
    }

    public function findAllOtherCurrentAssetAccounts(): array
    {
        return $this->getDataService()->Query(
            "SELECT * FROM Account WHERE AccountSubType = 'OtherCurrentAssets'"
        );
    }
}
