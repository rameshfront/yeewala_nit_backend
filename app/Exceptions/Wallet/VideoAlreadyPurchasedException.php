<?php

namespace App\Exceptions\Wallet;

class VideoAlreadyPurchasedException extends WalletException
{
    protected string $errorCode = 'ALREADY_PURCHASED';
    protected int $statusCode = 422;

    public function __construct(int $videoId)
    {
        parent::__construct("Video #{$videoId} has already been purchased.", 'ALREADY_PURCHASED', 422);
    }
}
