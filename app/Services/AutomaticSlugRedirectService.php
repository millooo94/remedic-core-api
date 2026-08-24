<?php

namespace App\Services;

use App\Models\Redirect;

class AutomaticSlugRedirectService
{
    public function sync(string $sourceType, int $sourceId, string $previousPath, string $currentPath): void
    {
        $oldPath = Redirect::normalizePathValue($previousPath);
        $newPath = Redirect::normalizePathValue($currentPath);

        if ($oldPath === $newPath) {
            return;
        }

        Redirect::query()->automatic()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('from_path', $newPath)
            ->update(['is_active' => false]);

        Redirect::query()->automatic()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('to_path', $oldPath)
            ->update(['to_path' => $newPath, 'http_code' => 301, 'is_active' => true]);

        Redirect::query()->updateOrCreate(
            ['from_path' => $oldPath],
            [
                'to_path' => $newPath,
                'http_code' => 301,
                'is_active' => true,
                'is_automatic' => true,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]
        );
    }
}
