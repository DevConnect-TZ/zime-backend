<?php

namespace App\Services\Payments;

use App\Models\Purchase;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public const SETTING_ACTIVE_GATEWAY = 'active_payment_gateway';

    public const SUPPORTED_GATEWAYS = ['sonicpesa', 'mobilipa'];

    /**
     * @param  array<string, PaymentGateway>  $gateways  keyed by provider name
     */
    public function __construct(private readonly array $gateways) {}

    public function activeProvider(): string
    {
        $provider = (string) Setting::get(self::SETTING_ACTIVE_GATEWAY, 'sonicpesa');

        return isset($this->gateways[$provider]) ? $provider : 'sonicpesa';
    }

    public function setActiveProvider(string $provider): void
    {
        Setting::put(self::SETTING_ACTIVE_GATEWAY, $provider);
    }

    public function gateway(?string $provider = null): PaymentGateway
    {
        $provider ??= $this->activeProvider();

        if (! isset($this->gateways[$provider])) {
            throw new PaymentGatewayException("Unsupported payment provider [{$provider}].");
        }

        return $this->gateways[$provider];
    }

    /**
     * Create a payment order for the authenticated user. The price and item
     * details are always resolved server-side from the catalogue; client-sent
     * amounts are never trusted.
     *
     * @param  array{buyer_name: string, buyer_phone: string, item_id: string, item_type: string}  $input
     */
    public function createOrder(User $user, array $input): Transaction
    {
        $itemType = $input['item_type'] === 'series' ? 'series' : 'single';

        /** @var Video $video */
        $video = Video::query()
            ->where('id', $input['item_id'])
            ->where('type', $itemType)
            ->firstOrFail();

        if ($user->hasUnlocked((string) $video->id, $itemType)) {
            throw new PaymentGatewayException('You already own this item.');
        }

        $gateway = $this->gateway();
        $amount = (int) round((float) $video->price);

        $transaction = Transaction::query()->create([
            'transaction_id' => 'zt_'.Str::lower(Str::random(20)),
            'user_id' => $user->id,
            'provider' => $gateway->provider(),
            'amount' => $video->price,
            'currency' => 'TZS',
            'status' => Transaction::STATUS_PENDING,
            'item_id' => (string) $video->id,
            'item_type' => $itemType,
            'item_title' => $video->title,
            'buyer_name' => $input['buyer_name'],
            'buyer_phone' => $this->normalizePhone($input['buyer_phone']),
            'buyer_email' => $user->email,
        ]);

        $order = $gateway->createOrder([
            'buyer_name' => $transaction->buyer_name,
            'buyer_phone' => $transaction->buyer_phone,
            'buyer_email' => $transaction->buyer_email ?? 'customer@zime.app',
            'amount' => $amount,
            'currency' => 'TZS',
        ]);

        $transaction->forceFill([
            'provider_order_id' => $order['order_id'],
            'provider_reference' => $order['reference'],
            'status' => $order['status'],
            'meta' => ['create' => $order['raw']],
        ])->save();

        if ($order['status'] === Transaction::STATUS_SUCCESS) {
            $this->markPaidAndUnlock($transaction);
        }

        return $transaction;
    }

    /**
     * Poll the provider for a still-pending transaction and unlock on success.
     */
    public function syncStatus(Transaction $transaction): Transaction
    {
        if ($transaction->status !== Transaction::STATUS_PENDING || empty($transaction->provider_order_id)) {
            return $transaction;
        }

        $result = $this->gateway($transaction->provider)->orderStatus($transaction->provider_order_id);

        $meta = $transaction->meta ?? [];
        $meta['status'] = $result['raw'];
        $transaction->meta = $meta;

        if ($result['paid']) {
            $this->markPaidAndUnlock($transaction);
        } elseif (in_array($result['status'], [Transaction::STATUS_FAILED, Transaction::STATUS_CANCELLED], true)) {
            $transaction->forceFill(['status' => $result['status']])->save();
        } else {
            $transaction->save();
        }

        return $transaction->refresh();
    }

    /**
     * Atomically mark a transaction paid and grant the purchase. Idempotent.
     */
    public function markPaidAndUnlock(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            /** @var Transaction $locked */
            $locked = Transaction::query()->lockForUpdate()->find($transaction->id);

            if ($locked->status === Transaction::STATUS_SUCCESS) {
                return;
            }

            $locked->forceFill([
                'status' => Transaction::STATUS_SUCCESS,
                'paid_at' => now(),
            ])->save();

            if ($locked->user_id) {
                Purchase::query()->firstOrCreate(
                    [
                        'user_id' => $locked->user_id,
                        'item_id' => $locked->item_id,
                        'item_type' => $locked->item_type,
                    ],
                    ['transaction_id' => $locked->id],
                );
            }

            $transaction->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function accessGranted(Transaction $transaction): bool
    {
        return $transaction->status === Transaction::STATUS_SUCCESS
            && $transaction->user_id !== null
            && $transaction->user->hasUnlocked($transaction->item_id, $transaction->item_type);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // Tanzanian numbers: 07XXXXXXXX -> 2557XXXXXXXX
        if (str_starts_with($digits, '0')) {
            $digits = '255'.substr($digits, 1);
        } elseif (str_starts_with($digits, '7') && strlen($digits) === 9) {
            $digits = '255'.$digits;
        }

        return $digits;
    }
}
