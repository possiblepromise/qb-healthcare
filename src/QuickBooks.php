<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare;

use QuickBooksOnline\API\DataService\DataService;

final class QuickBooks
{
    public function __construct(private string $clientId, private string $clientSecret)
    {
    }

    public function getDataService(string $accesstoken = null, string $refreshToken = null, string $realmId = null): DataService
    {
        $config = [
            'auth_mode' => 'oauth2',
            'ClientID' => $this->clientId,
            'ClientSecret' => $this->clientSecret,
            'RedirectURI' => 'https://developer.intuit.com/v2/OAuth2Playground/RedirectUrl',
            'scope' => 'com.intuit.quickbooks.accounting',
            'baseUrl' => 'production',
        ];

        if ($accesstoken && $refreshToken && $realmId) {
            $config['accessTokenKey'] = $accesstoken;
            $config['refreshTokenKey'] = $refreshToken;
            $config['QBORealmID'] = $realmId;
        }

        return DataService::Configure($config);
    }
}
