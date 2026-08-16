<?php

namespace App\Services\Payments;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MobiliPa mobile-money gateway.
 *
 * @see https://mobilipa.store
 * Endpoints: POST /v1/payment/create_order, POST /v1/payment/status.
 * Auth via the X-API-KEY header. The API key is configured by the admin
 * through the settings UI and stored (encrypted) in the settings table.
 */
class MobiliPaGateway implements PaymentGateway
{
    public const SETTING_API_KEY = 'mobilipa_api_key';

    /** Provider statuses that mean the money has been collected. */
    private const PAID_STATUSES = ['COMPLETED', 'SUCCESS', 'PAID'];

    private const FAILED_STATUSES = ['FAILED', 'CANCELLED', 'REJECTED', 'EXPIRED'];

    public function __construct(private readonly string $baseUrl) {}

    public function provider(): string
    {
        return 'mobilipa';
    }

    public function createOrder(array $payload): array
    {
        $response = $this->request('POST', '/v1/payment/create_order', [
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
        $response = $this->request('POST', '/v1/payment/status', [
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

    /**
     * MobiliPa transactions are reconciled by polling POST /v1/payment/status
     * rather than signed webhooks.
     */
    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        return false;
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
        $apiKey = Setting::getSecret(self::SETTING_API_KEY);

        if (empty($apiKey)) {
            throw new PaymentGatewayException('MobiliPa credentials are not configured.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout(30)
                ->acceptJson()
                ->withHeaders(['X-API-KEY' => $apiKey])
                ->send($method, $path, ['json' => $body]);
        } catch (\Throwable $e) {
            Log::warning('MobiliPa request failed', ['path' => $path, 'error' => $e->getMessage()]);

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
