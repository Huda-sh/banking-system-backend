<?php

namespace App\Accounts\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AccountNotFoundException extends NotFoundHttpException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}

