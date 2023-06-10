<?php

namespace PossiblePromise\QbHealthcare\ValueObject;

use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;

/**
 * @psalm-type Data=array{token: string, expires: UTCDateTime}
 */
class Token implements Persistable
{
    public function __construct(
        public string $token,
        public \DateTime $expires
    )
    {

    }

    public static function fromOauth(string $token, string $expires): self
    {
        $date = \DateTime::createFromFormat('Y/m/d H:i:s', $expires);
        if ($date === false) {
            throw new \InvalidArgumentException('Invalid date given');
        }

        return new self($token, $date);
    }

    /**
     * @return Data
     */
    public function bsonSerialize(): array
    {
        return [
            'token' => $this->token,
            'expires' => new UTCDateTime($this->expires)
        ];
    }

    /**
     * @param Data $data
     */
    public function bsonUnserialize(array $data): void
    {
        $this->token = $data['token'];
        $this->expires = $data['expires']->toDateTime();
    }
}