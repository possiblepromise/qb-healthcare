#!/usr/bin/env php
<?php

require_once 'vendor/autoload.php';

use MongoDB;
use PossiblePromise\QbHealthcare\Command\ImportServicesCommand;
use PossiblePromise\QbHealthcare\Repository\PayersRepository;
use PossiblePromise\QbHealthcare\Serializer\PayerSerializer;
use Symfony\Component\Console\Application;

$application = new Application('QB Healthcare', '0.1.0');

$client = new MongoDB\Client();
$db = $client->qbHealthcare;
$db->command(['ping' => 1]);

$payersRepository = new PayersRepository($db);

$application
    ->add(new ImportServicesCommand(new PayerSerializer(), $payersRepository))
;

$application->run();
