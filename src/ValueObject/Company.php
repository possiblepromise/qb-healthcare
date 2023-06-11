<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\ValueObject;

use MongoDB\BSON\Persistable;

/**
 * @psalm-type Data=array{
 *   _id: string,
 *   companyName: string,
 *   accessToken: Token,
 *   refreshToken: Token,
 *   active?: bool
 * }
 */
final class Company implements Persistable
{
    public function __construct(
        public string $realmId,
        public string $companyName,
        public Token $accessToken,
        public Token $refreshToken,
        public bool $active = true
    ) {
    }

    /**
     * @return Data
     */
    public function bsonSerialize(): array
    {
        return [
            '_id' => $this->realmId,
            'companyName' => $this->companyName,
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'active' => $this->active,
        ];
    }

    /**
     * @param Data $data
     */
    public function bsonUnserialize(array $data): void
    {
        $this->realmId = $data['_id'];
        $this->companyName = $data['companyName'];
        $this->accessToken = $data['accessToken'];
        $this->refreshToken = $data['refreshToken'];

        if (isset($data['active'])) {
            $this->active = $data['active'];
        }
    }
}
