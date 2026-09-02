<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$GLOBALS['config'] = ['app' => ['base_url' => 'https://test.local']];

require APP_ROOT . '/vendor/autoload.php';
