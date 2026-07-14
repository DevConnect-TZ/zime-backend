<?php

namespace App\Services\Payments;

interface PaymentGateway
{
    public function provider(): string;

    /**
     * Initiate a mobile-money charge. Returns normalized order data.
     *
     * @param  array{buyer_name: string, buyer_phone: string, buyer_email: string, amount: int|float, currency: string}  $payload
     * @return array{order_id: string, reference: ?string, status: string, raw: array<string, mixed>}
     *
     * @throws PaymentGatewayException
     */
    public function createOrder(array $payload): array;

    /**
     * Fetch the current status of an order.
     *
     * @return array{status: string, paid: bool, raw: array<string, mixed>}
     *
     * @throws PaymentGatewayException
     */
    public function orderStatus(string $orderId): array;

    /**
     * Verify an inbound webhook against the raw request body.
     */
    public function verifyWebhook(string $rawBody, ?string $signature): bool;
}
