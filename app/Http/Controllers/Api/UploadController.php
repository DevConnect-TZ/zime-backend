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

    /** Real MIME types accepted for video sources. */
    private const ALLOWED_VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska'];

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
            'file' => ['required', 'file', 'mimetypes:'.implode(',', self::ALLOWED_VIDEO_MIMES), 'max:1048576'],
            'folder' => ['sometimes', 'string'],
        ]);

        return $this->store($request, 'videos');
    }

    /**
     * Receive one slice of a large video upload. The client splits the file into
     * ordered chunks and posts them one at a time so each request stays small
     * enough to slip under PHP, web-server, and proxy body-size limits. Chunks
     * are buffered on the private disk and assembled once the final one arrives.
     */
    public function videoChunk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chunk' => ['required', 'file', 'max:12288'],
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9\-]{8,64}$/'],
            'chunk_index' => ['required', 'integer', 'min:0', 'max:19999'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:20000'],
            'extension' => ['required', 'string', 'regex:/^[A-Za-z0-9]{1,10}$/'],
            'folder' => ['sometimes', 'string'],
        ]);

        $index = (int) $validated['chunk_index'];
        $total = (int) $validated['total_chunks'];

        if ($index >= $total) {
            return response()->json(['message' => 'Chunk index out of range.'], 422);
        }

        $chunkDir = 'chunks/'.$validated['upload_id'];
        $request->file('chunk')->storeAs($chunkDir, sprintf('%06d', $index), 'local');

        // More chunks still to come — acknowledge and wait for the rest.
        if ($index + 1 < $total) {
            return response()->json(['status' => 'received', 'chunk_index' => $index]);
        }

        return $this->assembleChunks($request, $chunkDir, $total, $validated['extension']);
    }

    /**
     * Stitch the buffered chunks back into a single file, validate it, and move
     * it onto the public disk. Streams are copied piece by piece so a multi-GB
     * file never has to sit in memory.
     */
    private function assembleChunks(Request $request, string $chunkDir, int $total, string $extension): JsonResponse
    {
        $local = Storage::disk('local');
        $folder = $this->resolveFolder($request->input('folder'), 'videos');
        $assembledPath = $local->path($chunkDir.'/assembled');

        $out = fopen($assembledPath, 'wb');

        for ($i = 0; $i < $total; $i++) {
            $partRelative = $chunkDir.'/'.sprintf('%06d', $i);

            if (! $local->exists($partRelative)) {
                fclose($out);
                $local->deleteDirectory($chunkDir);

                return response()->json(['message' => "Upload incomplete: chunk {$i} is missing. Please retry."], 422);
            }

            $in = fopen($local->path($partRelative), 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }

        fclose($out);

        if (! in_array(mime_content_type($assembledPath), self::ALLOWED_VIDEO_MIMES, true)) {
            $local->deleteDirectory($chunkDir);

            return response()->json(['message' => 'The assembled file is not a supported video format.'], 422);
        }

        $targetPath = $folder.'/'.Str::uuid()->toString().'.'.$extension;
        $stream = fopen($assembledPath, 'rb');
        Storage::disk('public')->put($targetPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $local->deleteDirectory($chunkDir);

        return response()->json([
            'url' => Storage::disk('public')->url($targetPath),
            'path' => $targetPath,
        ]);
    }

    private function store(Request $request, string $default): JsonResponse
    {
        $folder = $this->resolveFolder($request->input('folder'), $default);

        $file = $request->file('file');
        $filename = Str::uuid()->toString().'.'.$file->extension();

        $path = $file->storeAs($folder, $filename, 'public');

        return response()->json([
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
        ]);
    }

    private function resolveFolder(?string $folder, string $default): string
    {
        return in_array($folder, self::ALLOWED_FOLDERS, true) ? $folder : $default;
    }
}
