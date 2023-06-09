#!/usr/bin/env php
<?php

require_once 'vendor/autoload.php';

use PossiblePromise\QbHealthcare\Application;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Dotenv\Dotenv;

error_reporting(E_ALL & ~E_DEPRECATED);

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

$container = new ContainerBuilder();
$loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/config'));
$loader->load('services.yaml');

$container->registerForAutoconfiguration(\Symfony\Component\Console\Command\Command::class)
    ->addTag('console.command')
;

$container->compile(true);

$application = $container->get(Application::class);
assert($application instanceof Application);

$application->run();
