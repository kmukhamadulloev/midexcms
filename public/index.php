<?php

declare(strict_types=1);

use PlainCMS\Core\App;

$rootPath = dirname(__DIR__);

require_once $rootPath . '/app/Core/autoload.php';

(new App($rootPath))->run();
