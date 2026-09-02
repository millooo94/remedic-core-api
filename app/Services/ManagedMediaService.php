<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
     * @param  array<int, string>  $sourceDirectories
     * @param  array<int, string>  $managedDirectories
     */
    public function copyManagedFile(
        Model $model,
        string $attribute,
        string $sourcePath,
        array $sourceDirectories,
        string $directory,
        array $managedDirectories = [],
    ): Model {
        $sourcePath = $this->normalizedPath($sourcePath);
        if ($sourcePath === null || ! $this->isManagedPath($sourcePath, $sourceDirectories)) {
            throw new InvalidArgumentException('Il file sorgente non Ã¨ gestito dal Core.');
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($sourcePath)) {
            throw new InvalidArgumentException('Il file sorgente non Ã¨ disponibile.');
        }

        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $newPath = trim($directory, '/').'/'.Str::uuid().($extension === '' ? '' : '.'.$extension);
        if (! $disk->copy($sourcePath, $newPath)) {
            throw new InvalidArgumentException('Impossibile copiare il file sorgente.');
        }

        $oldPath = $this->normalizedPath($model->getAttribute($attribute));
        try {
            DB::transaction(function () use ($model, $attribute, $newPath): void {
                $model->forceFill([$attribute => $newPath])->save();
            });
        } catch (Throwable $error) {
            $disk->delete($newPath);
            throw $error;
        }

        $this->deleteManagedFile($oldPath, [...$managedDirectories, $directory]);

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

        if ($this->isManagedPath($path, $managedDirectories)) {
            Storage::disk('public')->delete(ltrim($path, '/'));
        }
    }

    /** @param array<int, string> $managedDirectories */
    private function isManagedPath(string $path, array $managedDirectories): bool
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return false;
        }

        foreach ($managedDirectories as $directory) {
            $prefix = trim($directory, '/').'/';
            if (str_starts_with(ltrim($path, '/'), $prefix)) {
                return true;
            }
        }

        return false;
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
