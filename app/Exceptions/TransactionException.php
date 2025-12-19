<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Response;

class TransactionException extends Exception
{
    /**
     * Report the exception.
     */
    public function report(): void
    {
        // Could be reported to an error tracking service
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render($request): Response
    {
        return response()->json([
            'error' => 'Transaction Error',
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'transaction_id' => $request->route('transaction_id') ?? null
        ], 422);
    }

    /**
     * Get the detailed error information.
     */
    public function getDetails(): array
    {
        return [
            'class' => get_class($this),
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString()
        ];
    }
}
