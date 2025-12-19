<?php

namespace App\Accounts\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AccountTransitionException extends UnprocessableEntityHttpException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}

