<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LandingForm;
use App\Support\ApiResponse;
use App\Support\SafeSvg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A form's logo (and hero image). A small PNG, JPEG or WebP that is checked as a picture — by what it is, not by what it is called —
 * stored under a random name on the private disk and handed out by this controller alone, with headers that stop a browser from
 * treating it as anything but an image. No SVG: it can carry script.
 */
class FormAssetController extends Controller
{
    private const TYPES = [IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_WEBP => ['webp', 'image/webp']];

    public function store(Request $request)
    {
        $this->authorize('create', LandingForm::class);

        $request->validate(['file' => ['required', 'file', 'max:1536']]);
        $file = $request->file('file');

        // A logo may be vector. Only a drawing: anything that could run or fetch something is refused, not cleaned.
        if (SafeSvg::looksLikeSvg($file->getRealPath())) {
            if (! SafeSvg::isSafe((string) file_get_contents($file->getRealPath()))) {
                return response()->json(['success' => false, 'data' => null, 'message' => 'Invalid image.', 'errors' => ['file' => ['invalid_image']]], 422);
            }

            $name = bin2hex(random_bytes(16));
            Storage::disk('local')->putFileAs('form-assets', $file, "{$name}.svg");

            return ApiResponse::created(['url' => "/api/v1/public/form-assets/{$name}.svg", 'width' => null, 'height' => null]);
        }

        $info = @getimagesize($file->getRealPath());

        if ($info === false || ! isset(self::TYPES[$info[2]]) || $info[0] > 4000 || $info[1] > 4000 || $info[0] < 16 || $info[1] < 16) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Invalid image.', 'errors' => ['file' => ['invalid_image']]], 422);
        }

        [$ext] = self::TYPES[$info[2]];
        $name = bin2hex(random_bytes(16));
        Storage::disk('local')->putFileAs('form-assets', $file, "{$name}.{$ext}");

        return ApiResponse::created(['url' => "/api/v1/public/form-assets/{$name}.{$ext}", 'width' => $info[0], 'height' => $info[1]]);
    }

    public function show(string $name)
    {
        $path = "form-assets/{$name}";

        abort_unless(Storage::disk('local')->exists($path), 404);

        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'][pathinfo($name, PATHINFO_EXTENSION)];

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            // Opened directly, an SVG is a document: sandboxed, it can run nothing even if a check ever missed something.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
