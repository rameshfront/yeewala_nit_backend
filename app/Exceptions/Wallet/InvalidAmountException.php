<?php

namespace App\Exceptions\Wallet;

class InvalidAmountException extends WalletException
{
    protected string $errorCode = 'INVALID_AMOUNT';
    protected int $statusCode = 422;

    public function __construct(string $message = 'Amount must be greater than zero.')
    {
        parent::__construct($message, 'INVALID_AMOUNT', 422);
    }
}
