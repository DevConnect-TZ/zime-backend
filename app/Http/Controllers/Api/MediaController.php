<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    /** Folders whose contents may be served through the app. */
    private const SERVABLE_FOLDERS = ['videos', 'thumbnails', 'trailers'];

    /** Folders that require a logged-in session to view. */
    private const PROTECTED_FOLDERS = ['videos', 'trailers'];

    public function __construct(private readonly TokenService $tokens) {}

    /**
     * Stream an uploaded file straight from the public disk instead of relying
     * on the `public/storage` symlink or a baked APP_URL. Symfony's
     * BinaryFileResponse honours the Range header automatically, so browsers can
     * seek within videos (206 Partial Content) the same way a static file server
     * would allow.
     *
     * Trailers and full videos are gated behind a login; thumbnails stay public
     * so the browse pages render for anonymous visitors.
     */
    public function show(Request $request, string $path): BinaryFileResponse
    {
        $path = ltrim($path, '/');

        // Reject traversal and anything outside the whitelisted media folders.
        abort_if(str_contains($path, '..'), 404);
        $folder = explode('/', $path)[0] ?? '';
        abort_unless(in_array($folder, self::SERVABLE_FOLDERS, true), 404);

        if (in_array($folder, self::PROTECTED_FOLDERS, true)) {
            $token = (string) ($request->query('token') ?: $request->bearerToken());
            abort_if($token === '' || ! $this->tokens->parseAccessToken($token), 401, 'Login required to view this media.');
        }

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
    public static function playableUrl(Request $request, ?string $stored, ?string $token = null): ?string
    {
        if (empty($stored)) {
            return null;
        }

        $relative = self::relativePathFor($stored);

        // Not one of our hosted files (e.g. an external CDN link) — leave as is.
        if ($relative === null) {
            return $stored;
        }

        $url = $request->getSchemeAndHttpHost().'/api/media/'.$relative;

        // Gated media is played through a plain <video> tag, which cannot attach
        // auth headers, so the short-lived access token is carried as a query
        // parameter that the media route validates.
        if ($token !== null) {
            $url .= '?token='.urlencode($token);
        }

        return $url;
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
