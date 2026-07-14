<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Transaction::query()->with('user')->latest();

        // Admins see the full ledger; everyone else sees only their own.
        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        return TransactionResource::collection($query->limit(500)->get());
    }
}
