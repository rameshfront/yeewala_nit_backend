<?php

namespace App\Exceptions\Wallet;

class InsufficientBalanceException extends WalletException
{
    protected string $errorCode = 'INSUFFICIENT_BALANCE';
    protected int $statusCode = 422;

    public function __construct(string $message = 'Insufficient balance in wallet to complete this purchase.')
    {
        parent::__construct($message, 'INSUFFICIENT_BALANCE', 422);
    }
}
