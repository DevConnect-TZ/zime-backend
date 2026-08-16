<?php

use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Services\Payments\MobiliPaGateway;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\Http;

function configureMobiliPa(string $key = 'mp_live_abc'): void
{
    Setting::setSecret(MobiliPaGateway::SETTING_API_KEY, $key);
    Setting::put(PaymentService::SETTING_ACTIVE_GATEWAY, 'mobilipa');
}

it('lets an admin view the payment gateway settings without exposing secrets', function () {
    Setting::setSecret(MobiliPaGateway::SETTING_API_KEY, 'mp_secret');
    Setting::put(PaymentService::SETTING_ACTIVE_GATEWAY, 'mobilipa');
    $admin = User::factory()->admin()->create();

    $this->getJson('/api/admin/settings', tokenHeader($admin))
        ->assertOk()
        ->assertJsonPath('data.provider', 'mobilipa')
        ->assertJsonPath('data.configured.mobilipa', true)
        ->assertJsonMissingPath('data.mobilipa_api_key');

    $this->getJson('/api/admin/settings', tokenHeader(User::factory()->create()))
        ->assertStatus(403);
});

it('lets an admin set the active gateway and mobilipa api key', function () {
    $admin = User::factory()->admin()->create();

    $this->putJson('/api/admin/settings', [
        'provider' => 'mobilipa',
        'mobilipa_api_key' => 'mp_new_key',
    ], tokenHeader($admin))
        ->assertOk()
        ->assertJsonPath('data.provider', 'mobilipa');

    expect(Setting::getSecret(MobiliPaGateway::SETTING_API_KEY))->toBe('mp_new_key');
    expect(Setting::get(PaymentService::SETTING_ACTIVE_GATEWAY))->toBe('mobilipa');
});

it('keeps the existing mobilipa key when none is provided', function () {
    configureMobiliPa('mp_original');
    $admin = User::factory()->admin()->create();

    $this->putJson('/api/admin/settings', [
        'provider' => 'mobilipa',
        'mobilipa_api_key' => '',
    ], tokenHeader($admin))->assertOk();

    expect(Setting::getSecret(MobiliPaGateway::SETTING_API_KEY))->toBe('mp_original');
});

it('rejects an unsupported gateway', function () {
    $admin = User::factory()->admin()->create();

    $this->putJson('/api/admin/settings', ['provider' => 'paypal'], tokenHeader($admin))
        ->assertStatus(422);
});

it('creates a mobilipa order and polls status via POST /v1/payment/status', function () {
    configureMobiliPa();
    Http::fake([
        'https://api.mobilipa.store/v1/payment/create_order' => Http::response([
            'status' => 'success',
            'data' => [
                'order_id' => 'mp_123',
                'payment_status' => 'PENDING',
            ],
        ]),
        'https://api.mobilipa.store/v1/payment/status' => Http::response([
            'status' => 'success',
            'data' => [
                'order_id' => 'mp_123',
                'payment_status' => 'COMPLETED',
                'transid' => 'txn_999',
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $video = Video::factory()->create(['price' => 4000, 'type' => 'single']);

    $order = $this->postJson('/api/payments/sonicpesa/order', [
        'buyer_name' => 'John',
        'buyer_phone' => '0712345678',
        'item_id' => (string) $video->id,
        'item_type' => 'single',
    ], tokenHeader($user))->assertOk()->json('data.order_id');

    $this->assertDatabaseHas('transactions', [
        'transaction_id' => $order,
        'provider' => 'mobilipa',
        'provider_order_id' => 'mp_123',
        'status' => 'PENDING',
    ]);

    $this->getJson("/api/payments/sonicpesa/orders/{$order}", tokenHeader($user))
        ->assertOk()
        ->assertJsonPath('data.access_granted', true);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.mobilipa.store/v1/payment/status'
            && $request->method() === 'POST';
    });

    $this->assertDatabaseHas('transactions', ['transaction_id' => $order, 'status' => 'SUCCESS']);
    $this->assertDatabaseHas('purchases', ['user_id' => $user->id, 'item_id' => (string) $video->id]);
});

it('fails when mobilipa is active but not configured', function () {
    Setting::put(PaymentService::SETTING_ACTIVE_GATEWAY, 'mobilipa');
    $user = User::factory()->create();
    $video = Video::factory()->create(['price' => 1000, 'type' => 'single']);

    $this->postJson('/api/payments/sonicpesa/order', [
        'buyer_name' => 'John',
        'buyer_phone' => '0712345678',
        'item_id' => (string) $video->id,
        'item_type' => 'single',
    ], tokenHeader($user))->assertStatus(422);
});

it('creates a mobilipa order against the real gateway shape', function () {
    configureMobiliPa();
    Http::fake([
        'https://api.mobilipa.store/*' => Http::response([
            'status' => 'success',
            'data' => ['order_id' => 'mp_x', 'payment_status' => 'PENDING'],
        ]),
    ]);

    $gateway = app(MobiliPaGateway::class);

    $result = $gateway->createOrder([
        'buyer_name' => 'Jane',
        'buyer_phone' => '255700000000',
        'buyer_email' => 'jane@example.com',
        'amount' => 5000,
        'currency' => 'TZS',
    ]);

    expect($result['order_id'])->toBe('mp_x');
    expect($result['status'])->toBe('PENDING');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.mobilipa.store/v1/payment/create_order'
            && $request->header('X-API-KEY')[0] === 'mp_live_abc';
    });
});
