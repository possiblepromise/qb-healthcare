<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare;

use PossiblePromise\QbHealthcare\Repository\CompaniesRepository;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper;
use QuickBooksOnline\API\DataService\DataService;

final class QuickBooks
{
    private ?ValueObject\Company $activeCompany = null;

    public function __construct(private string $clientId, private string $clientSecret, private CompaniesRepository $companies)
    {
    }

    public function getDataService(bool $newAuth = false): DataService
    {
        $config = [
            'auth_mode' => 'oauth2',
            'ClientID' => $this->clientId,
            'ClientSecret' => $this->clientSecret,
            'RedirectURI' => 'https://developer.intuit.com/v2/OAuth2Playground/RedirectUrl',
            'scope' => 'com.intuit.quickbooks.accounting',
            'baseUrl' => 'production',
        ];

        $company = $this->getActiveCompany(true);

        if ($newAuth === false) {
            if ($company === null) {
                throw new \InvalidArgumentException('No currently active company found.');
            }

            $config['accessTokenKey'] = $company->accessToken->token;
            $config['refreshTokenKey'] = $company->refreshToken->token;
            $config['QBORealmID'] = $company->realmId;
        }

        $dataService = DataService::Configure($config);

        $this->refresh($company, $dataService);

        return $dataService;
    }

    public function getActiveCompany($fetch = false): ?ValueObject\Company
    {
        if ($this->activeCompany === null && $fetch === true) {
            $this->activeCompany = $this->companies->findActiveCompany();
        }

        return $this->activeCompany;
    }

    private function refresh(?ValueObject\Company $company, DataService $dataService): void
    {
        if ($company && new \DateTimeImmutable() > $company->accessToken->expires) {
            /** @var OAuth2LoginHelper $loginHelper */
            $loginHelper = $dataService->getOAuth2LoginHelper();
            $newAccessToken = $loginHelper->refreshToken();
            $dataService->updateOAuth2Token($newAccessToken);
            $this->companies->updateTokens($newAccessToken);
        }
    }
}
