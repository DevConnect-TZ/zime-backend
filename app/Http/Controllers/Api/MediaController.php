<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    /** Folders whose contents may be served through the app. */
    private const SERVABLE_FOLDERS = ['videos', 'thumbnails', 'trailers'];

    /**
     * Stream an uploaded file straight from the public disk instead of relying
     * on the `public/storage` symlink or a baked APP_URL. Symfony's
     * BinaryFileResponse honours the Range header automatically, so browsers can
     * seek within videos (206 Partial Content) the same way a static file server
     * would allow.
     */
    public function show(Request $request, string $path): BinaryFileResponse
    {
        $path = ltrim($path, '/');

        // Reject traversal and anything outside the whitelisted media folders.
        abort_if(str_contains($path, '..'), 404);
        $folder = explode('/', $path)[0] ?? '';
        abort_unless(in_array($folder, self::SERVABLE_FOLDERS, true), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path));
    }

    /**
     * Turn whatever is stored on a video/episode into a URL the browser can
     * actually play. Files we host (whether stored as a bare relative path, a
     * legacy `{APP_URL}/storage/...` URL, or an existing `/api/media/...` URL)
     * are rewritten to hit the app media route on the host that served this
     * request, so playback works no matter what APP_URL or symlink state the
     * production box is in. Genuinely external URLs are passed through untouched.
     */
    public static function playableUrl(Request $request, ?string $stored): ?string
    {
        if (empty($stored)) {
            return null;
        }

        $relative = self::relativePathFor($stored);

        // Not one of our hosted files (e.g. an external CDN link) — leave as is.
        if ($relative === null) {
            return $stored;
        }

        return $request->getSchemeAndHttpHost().'/api/media/'.$relative;
    }

    /**
     * Extract the disk-relative path for a file we host, or null if the value
     * points somewhere external.
     */
    private static function relativePathFor(string $stored): ?string
    {
        foreach (['/api/media/', '/storage/'] as $marker) {
            if (($pos = strpos($stored, $marker)) !== false) {
                return ltrim(substr($stored, $pos + strlen($marker)), '/');
            }
        }

        // A bare relative path such as "videos/uuid.mp4".
        if (! preg_match('#^https?://#i', $stored)) {
            $folder = explode('/', ltrim($stored, '/'))[0] ?? '';
            if (in_array($folder, self::SERVABLE_FOLDERS, true)) {
                return ltrim($stored, '/');
            }
        }

        return null;
    }
}
