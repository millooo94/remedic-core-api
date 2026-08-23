<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ManagedMediaService
{
    /**
     * @param  array<int, string>  $managedDirectories
     */
    public function replace(
        Model $model,
        string $attribute,
        UploadedFile $file,
        string $directory,
        array $managedDirectories = [],
    ): Model {
        $oldPath = $this->normalizedPath($model->getAttribute($attribute));
        $newPath = $file->store($directory, 'public');

        try {
            DB::transaction(function () use ($model, $attribute, $newPath): void {
                $model->forceFill([$attribute => $newPath])->save();
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete($newPath);
            throw $error;
        }

        $this->deleteManagedFile($oldPath, [...$managedDirectories, $directory]);

        return $model->refresh();
    }

    /**
     * @param  array<int, string>  $managedDirectories
     */
    public function delete(Model $model, string $attribute, array $managedDirectories): Model
    {
        $oldPath = $this->normalizedPath($model->getAttribute($attribute));

        if ($oldPath === null) {
            return $model;
        }

        DB::transaction(function () use ($model, $attribute): void {
            $model->forceFill([$attribute => null])->save();
        });

        $this->deleteManagedFile($oldPath, $managedDirectories);

        return $model->refresh();
    }

    /**
     * @param  array<int, string>  $managedDirectories
     */
    public function deleteManagedFile(?string $path, array $managedDirectories): void
    {
        if ($path === null || filter_var($path, FILTER_VALIDATE_URL)) {
            return;
        }

        foreach ($managedDirectories as $directory) {
            $prefix = trim($directory, '/').'/';
            if (str_starts_with(ltrim($path, '/'), $prefix)) {
                Storage::disk('public')->delete(ltrim($path, '/'));

                return;
            }
        }
    }

    private function normalizedPath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $normalized = trim($path);

        return $normalized === '' ? null : $normalized;
    }
}
