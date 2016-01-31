#! /usr/bin/env php

<?php

use Symfony\Component\Console\Application;

// Composer
if (!file_exists('vendor/autoload.php')) {
    die('Composer dependency manager is needed: https://getcomposer.org/');
}
require 'vendor/autoload.php';

$app = new Application('WP Bruteforcer', '1.0');

$app->add(new Arall\WPBruteforcer\Commands\Bruteforce());
$app->add(new Arall\WPBruteforcer\Commands\Enumerate());
$app->add(new Arall\WPBruteforcer\Commands\Benchmark());

$app->run();
