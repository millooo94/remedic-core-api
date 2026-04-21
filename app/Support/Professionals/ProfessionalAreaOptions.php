<?php

namespace App\Support\Professionals;

use Illuminate\Support\Str;

class ProfessionalAreaOptions
{
    public const ALL = [
        'Angiologia',
        'Cardiologia',
        'Chirurgia maxillo-facciale',
        'Chirurgia Plastica',
        'Chirurgia Vascolare',
        'Dermatologia',
        'Dietologia',
        'Endocrinologia',
        'Ginecologia',
        'Medicina Estetica',
        'Medicina Interna',
        'Neurologia',
        'Nutrizione',
        'Psicologia Clinica',
        'Reumatologia',
        'Senologia',
        'Tecnico sanitario',
        'Urologia',
    ];

    public static function values(): array
    {
        return self::ALL;
    }

    public static function normalize(?string $value): ?string
    {
        $normalized = Str::of((string) $value)
            ->replace(['â€™', '`'], "'")
            ->replace(["\u{2019}", "\u{2018}"], "'")
            ->squish()
            ->lower()
            ->value();

        if ($normalized === '') {
            return null;
        }

        $map = [
            'neurologo' => 'Neurologia',
            'neurologia' => 'Neurologia',
            'senologo' => 'Senologia',
            'senologia' => 'Senologia',
            'psicologo clinico' => 'Psicologia Clinica',
            'psicologia clinica' => 'Psicologia Clinica',
            'cardiologo' => 'Cardiologia',
            'cardiologia' => 'Cardiologia',
            'ginecologo' => 'Ginecologia',
            'ginecologia' => 'Ginecologia',
            'chirurgo vascolare' => 'Chirurgia Vascolare',
            'chirurgia vascolare' => 'Chirurgia Vascolare',
            'endocrinologo' => 'Endocrinologia',
            'endocrinologia' => 'Endocrinologia',
            'chirurgo plastico' => 'Chirurgia Plastica',
            'chirurgia plastica' => 'Chirurgia Plastica',
            'medico estetico' => 'Medicina Estetica',
            'medicina estetica' => 'Medicina Estetica',
            'urologo' => 'Urologia',
            'urologia' => 'Urologia',
            'nutrizionista' => 'Nutrizione',
            'nutrizione' => 'Nutrizione',
            'dietologo' => 'Dietologia',
            'dietologia' => 'Dietologia',
            'dermatologo' => 'Dermatologia',
            'dermatologia' => 'Dermatologia',
            'reumatologo' => 'Reumatologia',
            'reumatologia' => 'Reumatologia',
            'internista' => 'Medicina Interna',
            'medicina interna' => 'Medicina Interna',
            'angiologo' => 'Angiologia',
            'angiologia' => 'Angiologia',
            'maxillo-facciale' => 'Chirurgia maxillo-facciale',
            'chirurgia maxillo facciale' => 'Chirurgia maxillo-facciale',
            'chirurgia maxillo-facciale' => 'Chirurgia maxillo-facciale',
            'tecnico sanitario' => 'Tecnico sanitario',
        ];

        return $map[$normalized] ?? Str::headline($normalized);
    }
}
