<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentService;
use App\Services\Payments\SonicPesaGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    /**
     * Initiate a SonicPesa mobile-money order for the authenticated buyer.
     * The amount and item details are resolved server-side.
     */
    public function createOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'buyer_name' => ['required', 'string', 'max:120'],
            'buyer_phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\s-]{9,20}$/'],
            'item_id' => ['required', 'string', 'max:64'],
            'item_type' => ['required', Rule::in(['single', 'series'])],
        ]);

        try {
            $transaction = $this->payments->createOrder($request->user(), $validated);
        } catch (PaymentGatewayException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'order_id' => $transaction->transaction_id,
                'status' => $transaction->status,
                'access_granted' => $this->payments->accessGranted($transaction),
            ],
        ]);
    }

    /**
     * Poll the status of an order. Called on an interval by the client.
     */
    public function orderStatus(Request $request, string $orderId): JsonResponse
    {
        $transaction = Transaction::query()
            ->where('transaction_id', $orderId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        try {
            $transaction = $this->payments->syncStatus($transaction);
        } catch (PaymentGatewayException $e) {
            // Surface a soft error but keep the client polling.
            Log::info('Order status sync failed', ['order' => $orderId, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'data' => [
                'order_id' => $transaction->transaction_id,
                'status' => $transaction->status,
                'access_granted' => $this->payments->accessGranted($transaction),
            ],
        ]);
    }

    public function gateway(): JsonResponse
    {
        return response()->json([
            'data' => [
                'provider' => $this->payments->activeProvider(),
                'supported' => PaymentService::SUPPORTED_GATEWAYS,
            ],
        ]);
    }

    public function updateGateway(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(PaymentService::SUPPORTED_GATEWAYS)],
        ]);

        $this->payments->setActiveProvider($validated['provider']);

        return response()->json([
            'message' => 'Payment gateway updated.',
            'data' => ['provider' => $validated['provider']],
        ]);
    }

    /**
     * Inbound SonicPesa webhook. Signature-verified, then reconciles the
     * matching transaction. Always returns 200 to avoid provider retries once
     * accepted, except on signature failure.
     */
    public function webhook(Request $request, SonicPesaGateway $gateway): JsonResponse
    {
        $raw = $request->getContent();
        $signature = $request->header('X-SonicPesa-Signature');

        if (! $gateway->verifyWebhook($raw, $signature)) {
            Log::warning('Rejected SonicPesa webhook: bad signature');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        $orderId = $payload['order_id'] ?? ($payload['data']['order_id'] ?? null);

        if (! $orderId) {
            return response()->json(['message' => 'Missing order id.'], 422);
        }

        $transaction = Transaction::query()->where('provider_order_id', $orderId)->first();

        if (! $transaction) {
            Log::info('Webhook for unknown order', ['order_id' => $orderId]);

            return response()->json(['message' => 'Order not tracked.']);
        }

        $status = $gateway->normalizeStatus(
            (string) ($payload['payment_status'] ?? $payload['status'] ?? 'PENDING')
        );

        $meta = $transaction->meta ?? [];
        $meta['webhook'] = $payload;
        $transaction->meta = $meta;

        if ($status === Transaction::STATUS_SUCCESS) {
            $this->payments->markPaidAndUnlock($transaction);
        } else {
            if (in_array($status, [Transaction::STATUS_FAILED, Transaction::STATUS_CANCELLED], true)) {
                $transaction->status = $status;
            }
            $transaction->save();
        }

        return response()->json(['message' => 'ok']);
    }
}
