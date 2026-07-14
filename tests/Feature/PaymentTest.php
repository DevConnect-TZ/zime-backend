<?php

use App\Models\Transaction;
use App\Models\User;
use App\Models\Video;
use App\Services\Payments\SonicPesaGateway;

function fakeGateway(Closure $configure): void
{
    test()->mock(SonicPesaGateway::class, function ($mock) use ($configure) {
        $mock->shouldReceive('provider')->andReturn('sonicpesa');
        $mock->shouldReceive('normalizeStatus')->andReturnUsing(fn ($s) => strtoupper($s));
        $configure($mock);
    });
}

it('creates a pending order using the catalogue price, not the client amount', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['price' => 5000, 'type' => 'single']);

    fakeGateway(function ($mock) {
        $mock->shouldReceive('createOrder')
            ->once()
            ->withArgs(fn ($payload) => $payload['amount'] === 5000) // server-enforced price
            ->andReturn(['order_id' => 'sp_123', 'reference' => 'REF1', 'status' => 'PENDING', 'raw' => []]);
    });

    $response = $this->postJson('/api/payments/sonicpesa/order', [
        'buyer_name' => 'John',
        'buyer_phone' => '0712345678',
        'item_id' => (string) $video->id,
        'item_type' => 'single',
    ], tokenHeader($user))->assertOk();

    $orderId = $response->json('data.order_id');
    expect($orderId)->not->toBeNull();
    $this->assertDatabaseHas('transactions', [
        'transaction_id' => $orderId,
        'status' => 'PENDING',
        'provider_order_id' => 'sp_123',
        'amount' => 5000.00,
        'buyer_phone' => '255712345678', // normalized
    ]);
});

it('normalizes the phone and grants access when polling returns success', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['price' => 3000, 'type' => 'single']);

    fakeGateway(function ($mock) {
        $mock->shouldReceive('createOrder')->andReturn(
            ['order_id' => 'sp_poll', 'reference' => 'R', 'status' => 'PENDING', 'raw' => []]
        );
        $mock->shouldReceive('orderStatus')->with('sp_poll')->andReturn(
            ['status' => 'SUCCESS', 'paid' => true, 'raw' => ['payment_status' => 'SUCCESS']]
        );
    });

    $order = $this->postJson('/api/payments/sonicpesa/order', [
        'buyer_name' => 'Jane',
        'buyer_phone' => '255700000000',
        'item_id' => (string) $video->id,
        'item_type' => 'single',
    ], tokenHeader($user))->json('data.order_id');

    $this->getJson("/api/payments/sonicpesa/orders/{$order}", tokenHeader($user))
        ->assertOk()
        ->assertJsonPath('data.access_granted', true);

    $this->assertDatabaseHas('purchases', [
        'user_id' => $user->id,
        'item_id' => (string) $video->id,
        'item_type' => 'single',
    ]);
    $this->assertDatabaseHas('transactions', ['transaction_id' => $order, 'status' => 'SUCCESS']);
});

it('does not grant access while payment is pending', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['type' => 'single']);

    fakeGateway(function ($mock) {
        $mock->shouldReceive('createOrder')->andReturn(
            ['order_id' => 'sp_wait', 'reference' => 'R', 'status' => 'PENDING', 'raw' => []]
        );
        $mock->shouldReceive('orderStatus')->andReturn(
            ['status' => 'PENDING', 'paid' => false, 'raw' => []]
        );
    });

    $order = $this->postJson('/api/payments/sonicpesa/order', [
        'buyer_name' => 'Jane', 'buyer_phone' => '0712345678',
        'item_id' => (string) $video->id, 'item_type' => 'single',
    ], tokenHeader($user))->json('data.order_id');

    $this->getJson("/api/payments/sonicpesa/orders/{$order}", tokenHeader($user))
        ->assertOk()
        ->assertJsonPath('data.access_granted', false);

    $this->assertDatabaseMissing('purchases', ['user_id' => $user->id]);
});

it('prevents polling another users order', function () {
    [$owner, $stranger] = User::factory()->count(2)->create();
    $txn = Transaction::create([
        'transaction_id' => 'zt_secret', 'user_id' => $owner->id, 'provider' => 'sonicpesa',
        'amount' => 1000, 'currency' => 'TZS', 'status' => 'PENDING',
        'item_id' => '1', 'item_type' => 'single',
    ]);

    $this->getJson("/api/payments/sonicpesa/orders/{$txn->transaction_id}", tokenHeader($stranger))
        ->assertStatus(404);
});

it('unlocks the item when a signed webhook reports success', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['type' => 'single']);
    $txn = Transaction::create([
        'transaction_id' => 'zt_wh', 'user_id' => $user->id, 'provider' => 'sonicpesa',
        'provider_order_id' => 'sp_webhook', 'amount' => 1000, 'currency' => 'TZS',
        'status' => 'PENDING', 'item_id' => (string) $video->id, 'item_type' => 'single',
    ]);

    config()->set('services.sonicpesa.webhook_secret', 'whsec');
    $payload = json_encode(['event' => 'payment.success', 'order_id' => 'sp_webhook', 'payment_status' => 'COMPLETED']);
    $signature = hash_hmac('sha256', $payload, 'whsec');

    $this->call('POST', '/api/payments/webhook/sonicpesa', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-SonicPesa-Signature' => $signature,
    ], $payload)->assertOk();

    $this->assertDatabaseHas('transactions', ['id' => $txn->id, 'status' => 'SUCCESS']);
    $this->assertDatabaseHas('purchases', ['user_id' => $user->id, 'item_id' => (string) $video->id]);
});

it('rejects a webhook with a bad signature', function () {
    config()->set('services.sonicpesa.webhook_secret', 'whsec');
    $payload = json_encode(['order_id' => 'sp_x', 'payment_status' => 'SUCCESS']);

    $this->call('POST', '/api/payments/webhook/sonicpesa', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-SonicPesa-Signature' => 'wrong',
    ], $payload)->assertStatus(401);
});
