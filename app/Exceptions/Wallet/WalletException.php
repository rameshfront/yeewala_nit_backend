<?php

namespace App\Exceptions\Wallet;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class WalletException extends Exception
{
    protected string $errorCode = 'WALLET_ERROR';
    protected int $statusCode = 422;

    public function __construct(string $message = '', string $errorCode = '', int $statusCode = 422, ?Exception $previous = null)
    {
        if (!empty($errorCode)) {
            $this->errorCode = $errorCode;
        }
        $this->statusCode = $statusCode;
        parent::__construct($message, $this->statusCode, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Render the exception into an HTTP JSON response for the frontend.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => null,
            'errors' => [
                [
                    'code' => $this->errorCode,
                    'message' => $this->getMessage(),
                ]
            ],
        ], $this->statusCode);
    }
}
