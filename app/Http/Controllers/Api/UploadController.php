<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /** Folders the client is permitted to write into. */
    private const ALLOWED_FOLDERS = ['thumbnails', 'videos', 'trailers'];

    public function image(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
            'folder' => ['sometimes', 'string'],
        ]);

        return $this->store($request, 'thumbnails');
    }

    public function video(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime,video/x-matroska', 'max:1048576'],
            'folder' => ['sometimes', 'string'],
        ]);

        return $this->store($request, 'videos');
    }

    private function store(Request $request, string $default): JsonResponse
    {
        $folder = $request->input('folder', $default);
        if (! in_array($folder, self::ALLOWED_FOLDERS, true)) {
            $folder = $default;
        }

        $file = $request->file('file');
        $filename = Str::uuid()->toString().'.'.$file->extension();

        $path = $file->storeAs($folder, $filename, 'public');

        return response()->json([
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
        ]);
    }
}
