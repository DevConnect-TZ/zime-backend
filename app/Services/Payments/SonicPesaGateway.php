<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SonicPesa mobile-money gateway.
 *
 * @see https://sonicpesa.com/docs
 * Endpoints: POST /payment/create_order, POST /payment/order_status.
 * Auth via X-API-KEY + X-API-SECRET headers. Webhooks are signed with
 * hash_hmac('sha256', rawBody, apiSecret) delivered in X-SonicPesa-Signature.
 */
class SonicPesaGateway implements PaymentGateway
{
    /** Provider statuses that mean the money has been collected. */
    private const PAID_STATUSES = ['SUCCESS', 'COMPLETED', 'PAID'];

    private const FAILED_STATUSES = ['FAILED', 'CANCELLED', 'EXPIRED', 'REJECTED'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly ?string $apiSecret,
        private readonly ?string $webhookSecret,
    ) {
    }

    public function provider(): string
    {
        return 'sonicpesa';
    }

    public function createOrder(array $payload): array
    {
        $response = $this->request('POST', '/payment/create_order', [
            'buyer_email' => $payload['buyer_email'],
            'buyer_name' => $payload['buyer_name'],
            'buyer_phone' => $payload['buyer_phone'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
        ]);

        $data = (array) ($response['data'] ?? []);
        $orderId = $data['order_id'] ?? null;

        if (($response['status'] ?? null) !== 'success' || empty($orderId)) {
            throw new PaymentGatewayException(
                (string) ($response['message'] ?? 'Failed to create payment order.')
            );
        }

        return [
            'order_id' => (string) $orderId,
            'reference' => isset($data['reference']) ? (string) $data['reference'] : null,
            'status' => $this->normalizeStatus($data['payment_status'] ?? $data['status'] ?? 'PENDING'),
            'raw' => $response,
        ];
    }

    public function orderStatus(string $orderId): array
    {
        $response = $this->request('POST', '/payment/order_status', [
            'order_id' => $orderId,
        ]);

        $data = (array) ($response['data'] ?? []);
        $status = $this->normalizeStatus(
            $data['payment_status'] ?? $data['status'] ?? 'PENDING'
        );

        return [
            'status' => $status,
            'paid' => $status === 'SUCCESS',
            'raw' => $response,
        ];
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        if (empty($signature) || empty($this->webhookSecret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * Map raw provider status strings onto the internal status vocabulary.
     */
    public function normalizeStatus(string $status): string
    {
        $upper = strtoupper(trim($status));

        if (in_array($upper, self::PAID_STATUSES, true)) {
            return 'SUCCESS';
        }

        if (in_array($upper, self::FAILED_STATUSES, true)) {
            return $upper === 'CANCELLED' ? 'CANCELLED' : 'FAILED';
        }

        return 'PENDING';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function request(string $method, string $path, array $body): array
    {
        if (empty($this->apiKey) || empty($this->apiSecret)) {
            throw new PaymentGatewayException('SonicPesa credentials are not configured.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout(30)
                ->acceptJson()
                ->withHeaders([
                    'X-API-KEY' => $this->apiKey,
                    'X-API-SECRET' => $this->apiSecret,
                ])
                ->send($method, $path, ['json' => $body]);
        } catch (\Throwable $e) {
            Log::warning('SonicPesa request failed', ['path' => $path, 'error' => $e->getMessage()]);

            throw new PaymentGatewayException('Payment provider is unreachable.');
        }

        if ($response->serverError()) {
            throw new PaymentGatewayException('Payment provider returned an error.');
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }
}
