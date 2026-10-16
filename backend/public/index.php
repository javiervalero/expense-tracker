<?php

declare(strict_types=1);

// Front controller under php-fpm: all the work, and the fail-closed 500 for anything that goes wrong, is in
// App\Api\FrontController (tested).
require __DIR__ . '/../vendor/autoload.php';

use App\Api\FrontController;
use App\Http\ResponseEmitter;

FrontController::serve($_SERVER, getenv(), ResponseEmitter::native());
