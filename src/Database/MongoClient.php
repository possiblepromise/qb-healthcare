<?php

declare(strict_types=1);

namespace PossiblePromise\QbHealthcare\Database;

use MongoDB;

final class MongoClient
{
    private MongoDB\Database $database;

    public function __construct()
    {
        $client = new MongoDB\Client();
        $this->database = $client->qbHealthcare;
        $this->database->command(['ping' => 1]);
    }

    public function getDatabase(): MongoDB\Database
    {
        return $this->database;
    }
}
