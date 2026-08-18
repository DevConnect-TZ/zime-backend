<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /**
     * Payment gateway configuration for the admin panel. Secrets are never
     * returned; only whether the provider is already configured.
     */
    public function gatewaySettings(PaymentService $payments): JsonResponse
    {
        return response()->json([
            'data' => [
                'provider' => $payments->activeProvider(),
                'supported' => PaymentService::SUPPORTED_GATEWAYS,
                'configured' => [
                    'sonicpesa' => filled((string) Config::get('services.sonicpesa.api_key')),
                ],
            ],
        ]);
    }

    /**
     * Update the active payment gateway.
     */
    public function updateGatewaySettings(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(PaymentService::SUPPORTED_GATEWAYS)],
        ]);

        $payments->setActiveProvider($validated['provider']);

        return response()->json([
            'message' => 'Payment settings updated.',
            'data' => [
                'provider' => $validated['provider'],
                'configured' => [
                    'sonicpesa' => filled((string) Config::get('services.sonicpesa.api_key')),
                ],
            ],
        ]);
    }
}
