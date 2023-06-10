<?php

namespace PossiblePromise\QbHealthcare\Repository;

use PossiblePromise\QbHealthcare\Database\MongoClient;
use PossiblePromise\QbHealthcare\ValueObject\Company;
use PossiblePromise\QbHealthcare\ValueObject\Token;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2AccessToken;

class CompaniesRepository
{
    private \MongoDB\Collection $companies;

    public function __construct(MongoClient $client)
    {
        $this->companies = $client->getDatabase()->companies;
    }

    /**
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress InvalidReturnStatement
     */
    public function findActiveCompany(): ?Company
    {
        return $this->companies->findOne([
            'active' => true,
        ]);
    }

    public function save(Company $company): void
    {
        $this->inactivateAllExcept($company->realmId);

        $this->companies->updateOne(
            ['_id' => $company->realmId],
            ['$set' => $company],
            ['upsert' => true]
        );
    }

    /**
     * @psalm-suppress UndefinedDocblockClass
     */
    public function updateTokens(OAuth2AccessToken $newAccessToken): void {
        /** @var string $accessTokenExpiration */
        $accessTokenExpiration = $newAccessToken->getAccessTokenExpiresAt();
        /** @var string $refreshTokenExpiresAt */
        $refreshTokenExpiresAt = $newAccessToken->getRefreshTokenExpiresAt();

        $this->companies->updateOne(
            ['_id' => $newAccessToken->getRealmID()],
            ['$set' => [
                'accessToken' => Token::fromOauth($newAccessToken->getAccessToken(), $accessTokenExpiration),
                'refreshtoken' => Token::fromOauth($newAccessToken->getRefreshToken(), $refreshTokenExpiresAt),
            ]]
        );
    }

    private function inactivateAllExcept(string $realmId): void
    {
        $this->companies->updateMany(
            ['_id' => ['$ne' => $realmId]],
            ['$set' => ['active' => false]]
        );
    }
}
