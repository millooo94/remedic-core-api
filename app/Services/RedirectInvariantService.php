<?php

namespace App\Services;

use App\Models\Redirect;
use Illuminate\Validation\ValidationException;

class RedirectInvariantService
{
    public function assertValid(string $source, string $destination, bool $active = true, ?int $ignoreId = null): void
    {
        if (! $active) {
            return;
        }

        $source = Redirect::normalizePathValue($source);
        $destination = Redirect::normalizeTargetValue($destination);
        if ($source === $destination) {
            $this->fail('from_path', 'Un redirect non può puntare a sé stesso.');
        }
        if (Redirect::isExternalTarget($destination)) {
            return;
        }

        $rules = Redirect::query()->active()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get(['from_path', 'to_path']);
        $bySource = $rules->mapWithKeys(fn (Redirect $redirect): array => [$redirect->from_path => $redirect->to_path]);

        if ($rules->contains(fn (Redirect $redirect): bool => $redirect->to_path === $source)) {
            $this->fail('from_path', 'La sorgente creerebbe una catena di redirect.');
        }

        $cursor = $destination;
        for ($hop = 0; $hop < 32; $hop++) {
            if ($cursor === $source) {
                $this->fail('to_path', 'La destinazione creerebbe un loop di redirect.');
            }
            if (! isset($bySource[$cursor])) {
                return;
            }
            $this->fail('to_path', 'La destinazione è già una sorgente redirect: scegli la destinazione canonica finale.');
        }

        $this->fail('to_path', 'La catena redirect supera il limite di sicurezza.');
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
