<?php

namespace App\Exceptions\Wallet;

class InsufficientWithdrawableBalanceException extends WalletException
{
    protected string $errorCode = 'INSUFFICIENT_WITHDRAWABLE_BALANCE';
    protected int $statusCode = 422;

    public function __construct(string $message = 'Amount exceeds available withdrawable balance.')
    {
        parent::__construct($message, 'INSUFFICIENT_WITHDRAWABLE_BALANCE', 422);
    }
}
