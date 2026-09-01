<?php

namespace App\Exceptions\Wallet;

class InsufficientEarningsException extends WalletException
{
    protected string $errorCode = 'INSUFFICIENT_EARNINGS';
    protected int $statusCode = 422;

    public function __construct(string $message = 'Approval amount exceeds the user pending earnings balance.')
    {
        parent::__construct($message, 'INSUFFICIENT_EARNINGS', 422);
    }
}
