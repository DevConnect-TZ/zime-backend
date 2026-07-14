<?php
use App\Models\User;
use App\Models\Video;
use App\Services\Payments\SonicPesaGateway;

/**
 * Locks the exact JSON shapes the React client reads, so a backend refactor
 * cannot silently break the frontend.
 */
it('serves the shapes the react client expects', function () {
    $user = User::factory()->create();
    $series = Video::factory()->series()->create();
    $series->episodes()->create(['title'=>'E1','season'=>1,'episode'=>1,'video_url'=>'https://cdn/e1.mp4']);

    // Home.js: Array.isArray(v) -> must be a bare array, and VideoCard reads these keys.
    $list = $this->getJson('/api/videos')->assertOk();
    expect($list->json())->toBeArray();
    $list->assertJsonStructure([['id','title','thumbnail','genre','rating','price','type']]);

    // Series.js reads seasons + episodes[]
    $this->getJson("/api/videos/{$series->id}")
        ->assertOk()
        ->assertJsonStructure(['id','title','price','trailer_url','seasons','episodes'=>[['id','title','season','episode','duration']]]);

    // Payment.js reads res.data.order_id then polls res.data.access_granted
    $this->mock(SonicPesaGateway::class, function ($m) {
        $m->shouldReceive('provider')->andReturn('sonicpesa');
        $m->shouldReceive('createOrder')->andReturn(['order_id'=>'sp_1','reference'=>'R','status'=>'PENDING','raw'=>[]]);
        $m->shouldReceive('orderStatus')->andReturn(['status'=>'SUCCESS','paid'=>true,'raw'=>[]]);
    });

    $order = $this->postJson('/api/payments/sonicpesa/order', [
        'buyer_name'=>'A','buyer_phone'=>'0712345678',
        'item_id'=>(string)$series->id,'item_type'=>'series',
    ], tokenHeader($user))->assertOk()->assertJsonStructure(['data'=>['order_id']])->json('data.order_id');

    $this->getJson("/api/payments/sonicpesa/orders/{$order}", tokenHeader($user))
        ->assertOk()
        ->assertJsonPath('data.access_granted', true);

    // After unlock: AuthContext refreshUser() -> user.unlocked_series drives Series.js
    $this->postJson('/api/auth/session', ['id_token'=>'x']); // not used; assert via /me
    $me = $this->getJson('/api/auth/me', tokenHeader($user->fresh()))->assertOk();
    expect($me->json('unlocked_series'))->toContain((string) $series->id);

    // Series.js can now play episodes (video_url present once purchased)
    $this->getJson("/api/videos/{$series->id}", tokenHeader($user->fresh()))
        ->assertJsonPath('purchased', true)
        ->assertJsonPath('episodes.0.video_url', 'https://cdn/e1.mp4');
});

it('serves Watch.js the gated stream shape', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['video_link'=>'https://cdn/m.mp4']);

    // Watch.js maps a 403 "Purchase required..." to the purchase prompt.
    $denied = $this->postJson("/api/videos/{$video->id}/stream", [], tokenHeader($user))->assertStatus(403);
    expect($denied->json('message'))->toMatch('/purchase required/i');

    App\Models\Purchase::create(['user_id'=>$user->id,'item_id'=>(string)$video->id,'item_type'=>'single']);
    $this->postJson("/api/videos/{$video->id}/stream", [], tokenHeader($user->fresh()))
        ->assertOk()
        ->assertJsonStructure(['data'=>['url']]);
});
