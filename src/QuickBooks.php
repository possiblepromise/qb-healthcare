<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare;

use QuickBooksOnline\API\DataService\DataService;

final class QuickBooks
{
    public function __construct(private string $clientId, private string $clientSecret)
    {
    }

    public function getDataService(): DataService
    {
        return DataService::Configure([
            'auth_mode' => 'oauth2',
            'ClientID' => $this->clientId,
            'ClientSecret' => $this->clientSecret,
            'RedirectURI' => 'https://developer.intuit.com/v2/OAuth2Playground/RedirectUrl',
            'scope' => 'com.intuit.quickbooks.accounting',
            'baseUrl' => 'development',
        ]);
    }
}
