<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Payments\MobiliPaGateway;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /**
     * Payment gateway configuration for the admin panel. Secrets are never
     * returned; only whether each provider is already configured.
     */
    public function gatewaySettings(PaymentService $payments): JsonResponse
    {
        return response()->json([
            'data' => [
                'provider' => $payments->activeProvider(),
                'supported' => PaymentService::SUPPORTED_GATEWAYS,
                'configured' => [
                    'mobilipa' => filled(Setting::getSecret(MobiliPaGateway::SETTING_API_KEY)),
                    'sonicpesa' => filled((string) Config::get('services.sonicpesa.api_key')),
                ],
            ],
        ]);
    }

    /**
     * Update the active gateway and (optionally) the MobiliPa API key.
     */
    public function updateGatewaySettings(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(PaymentService::SUPPORTED_GATEWAYS)],
            'mobilipa_api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $payments->setActiveProvider($validated['provider']);

        if (filled($validated['mobilipa_api_key'] ?? null)) {
            Setting::setSecret(MobiliPaGateway::SETTING_API_KEY, $validated['mobilipa_api_key']);
        }

        return response()->json([
            'message' => 'Payment settings updated.',
            'data' => [
                'provider' => $validated['provider'],
                'configured' => [
                    'mobilipa' => filled(Setting::getSecret(MobiliPaGateway::SETTING_API_KEY)),
                    'sonicpesa' => filled((string) Config::get('services.sonicpesa.api_key')),
                ],
            ],
        ]);
    }
}
