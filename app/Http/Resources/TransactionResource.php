<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'user_id' => $this->user_id,
            'user_email' => $this->whenLoaded('user', fn () => $this->user?->email, $this->buyer_email),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'item_id' => $this->item_id,
            'item_type' => $this->item_type,
            'item_title' => $this->item_title,
            'provider' => $this->provider,
            'reference' => $this->provider_reference,
            'created_at' => $this->created_at,
            'paid_at' => $this->paid_at,
        ];
    }
}
