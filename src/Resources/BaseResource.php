<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Resources;

use Sonnenglas\Yoco\Http\HttpClient;

abstract class BaseResource
{
    public function __construct(protected readonly HttpClient $http)
    {
    }
}
