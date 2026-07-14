<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::query()->with('purchases')->latest()->get()
        );
    }

    public function updateRole(Request $request, User $user): UserResource
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_UPLOADER, User::ROLE_ADMIN])],
        ]);

        $this->guardSelfDemotion($request, $user, $validated['role']);

        $user->forceFill(['role' => $validated['role']])->save();

        return new UserResource($user->load('purchases'));
    }

    public function updateStatus(Request $request, User $user): UserResource
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_BANNED])],
        ]);

        abort_if(
            $request->user()->id === $user->id && $validated['status'] === User::STATUS_BANNED,
            422,
            'You cannot ban your own account.'
        );

        $user->forceFill(['status' => $validated['status']])->save();

        // Banning invalidates any active sessions immediately.
        if ($validated['status'] === User::STATUS_BANNED) {
            $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        return new UserResource($user->load('purchases'));
    }

    /**
     * Manually grant access to a video/series (admin override, no payment).
     */
    public function unlockVideo(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'video_id' => ['required', 'string', 'max:64'],
        ]);

        $video = Video::query()->find($validated['video_id']);
        $itemType = $video?->type ?? 'single';

        Purchase::query()->firstOrCreate([
            'user_id' => $user->id,
            'item_id' => $validated['video_id'],
            'item_type' => $itemType,
        ]);

        return response()->json([
            'message' => 'Access granted.',
            'user' => new UserResource($user->load('purchases')),
        ]);
    }

    private function guardSelfDemotion(Request $request, User $user, string $newRole): void
    {
        abort_if(
            $request->user()->id === $user->id && $newRole !== User::ROLE_ADMIN,
            422,
            'You cannot remove your own admin role.'
        );
    }
}
