<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique(); // internal reference exposed to clients
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider')->default('sonicpesa');
            $table->string('provider_order_id')->nullable()->index(); // e.g. sp_xxx from SonicPesa
            $table->string('provider_reference')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 8)->default('TZS');
            $table->string('status')->default('PENDING')->index(); // PENDING | SUCCESS | FAILED | CANCELLED
            $table->string('item_id'); // video/series id being purchased
            $table->string('item_type')->default('single'); // single | series
            $table->string('item_title')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->string('buyer_email')->nullable();
            $table->json('meta')->nullable(); // raw provider payloads
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
