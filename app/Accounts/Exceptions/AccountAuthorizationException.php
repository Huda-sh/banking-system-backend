<?php

namespace App\Accounts\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AccountAuthorizationException extends UnauthorizedHttpException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}

