<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Promotion;
use App\Services\ManagedMediaService;
use App\Services\SitePopupInitializer;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class SitePopupMediaController extends Controller
{
    public function __construct(private readonly ManagedMediaService $media, private readonly SitePopupInitializer $initializer) {}

    public function store(Request $request)
    {
        $request->validate(['file' => ['required', 'image', 'max:51200']]);
        $popup = $this->initializer->initialize();
        $this->media->replace($popup, 'image_path', $request->file('file'), 'site-popup/image');

        return $this->imageResponse($popup->refresh()->image_path, $request);
    }

    public function destroy(Request $request)
    {
        $popup = $this->initializer->initialize();
        $this->media->delete($popup, 'image_path', ['site-popup/image']);

        return $this->imageResponse($popup->refresh()->image_path, $request);
    }

    public function copySourceImage(Request $request)
    {
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['promotion', 'event'])],
            'source_id' => ['required', 'integer'],
        ]);
        $source = $data['source_type'] === 'promotion'
            ? Promotion::query()->findOrFail($data['source_id'])
            : Event::query()->findOrFail($data['source_id']);
        $sourceDirectory = $data['source_type'] === 'promotion'
            ? "promotions/{$source->id}/images"
            : "events/{$source->id}/images";
        $popup = $this->initializer->initialize();

        try {
            if ($source->image_path === null) {
                $this->media->delete($popup, 'image_path', ['site-popup/image']);
            } else {
                $this->media->copyManagedFile($popup, 'image_path', $source->image_path, [$sourceDirectory], 'site-popup/image');
            }
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return $this->imageResponse($popup->refresh()->image_path, $request);
    }

    private function imageResponse(?string $imagePath, Request $request)
    {
        return response()->json(['data' => [
            'image_path' => $imagePath,
            'image_url' => PublicMediaUrl::fromPublicDisk($imagePath, $request),
        ]]);
    }
}
