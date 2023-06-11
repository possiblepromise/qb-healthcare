<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\Entity\Payer;
use PossiblePromise\QbHealthcare\QuickBooks;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Facades\Customer;

final class CustomersRepository
{
    use QbApiTrait;

    public function __construct(QuickBooks $qb)
    {
        $this->qb = $qb;
    }

    /**
     * @return IPPCustomer[]
     */
    public function findAll(): array
    {
        /** @var IPPCustomer[]|null $customers */
        $customers = $this->getDataService()->Query('SELECT * FROM Customer');

        if ($customers === null) {
            return [];
        }

        return $customers;
    }

    public function findById(string $customerId): IPPCustomer
    {
        return $this->getDataService()->FindById($customerId, 'Customer');
    }

    public function createFromPayer(string $name, Payer $payer): IPPCustomer
    {
        $customerAttributes = [
            'CompanyName' => $name,
            'DisplayName' => $name,
        ];

        if ($payer->getAddress() || $payer->getCity() || $payer->getState() || $payer->getZip()) {
            $customerAttributes['BillAddr'] = [];
            if ($payer->getAddress()) {
                $customerAttributes['BillAddr']['Line1'] = $payer->getAddress();
            }
            if ($payer->getCity()) {
                $customerAttributes['BillAddr']['City'] = $payer->getCity();
            }
            if ($payer->getState()) {
                $customerAttributes['BillAddr']['CountrySubDivisionCode'] = $payer->getState();
            }
            if ($payer->getZip()) {
                $customerAttributes['BillAddr']['PostalCode'] = $payer->getZip();
            }
        }

        if ($payer->getEmail()) {
            $customerAttributes['PrimaryEmailAddr'] = ['Address' => $payer->getEmail()];
        }

        if ($payer->getPhone()) {
            $customerAttributes['PrimaryPhone'] = ['FreeFormNumber' => $payer->getPhone()];
        }

        $result = $this->getDataService()->add(Customer::create($customerAttributes));
        \assert($result instanceof IPPCustomer);

        return $result;
    }
}
