<?php

declare(strict_types=1);

namespace App\Http\Routing;

/** What {@see Router::match()} found out about a request. */
enum RouteOutcome
{
    case Matched;
    case NotFound;
    case MethodNotAllowed;
}
