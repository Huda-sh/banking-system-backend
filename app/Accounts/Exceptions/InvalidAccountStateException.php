<?php

namespace App\Accounts\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class InvalidAccountStateException extends UnprocessableEntityHttpException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}

