<?php

namespace App\Support\Professionals;

use Illuminate\Support\Str;

class ProfessionalAreaOptions
{
    public const ALL = [
        'Angiologia',
        'Allergologia',
        'Analisi cliniche',
        'Cardiologia',
        'Chirurgia maxillo-facciale',
        'Chirurgia plastica',
        'Chirurgia vascolare',
        'Dermatologia',
        'Dietologia',
        'Ecografia',
        'Endocrinologia',
        'Ginecologia',
        'Ostetricia',
        'Medicina estetica',
        'Medicina interna',
        'Neurologia',
        'Nutrizione',
        'Pneumologia',
        'Psicologia clinica',
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
            'psicologo clinico' => 'Psicologia clinica',
            'psicologia clinica' => 'Psicologia clinica',
            'cardiologo' => 'Cardiologia',
            'cardiologia' => 'Cardiologia',
            'ginecologo' => 'Ginecologia',
            'ginecologia' => 'Ginecologia',
            'ostetrico' => 'Ostetricia',
            'ostetrica' => 'Ostetricia',
            'ostetricia' => 'Ostetricia',
            'chirurgo vascolare' => 'Chirurgia vascolare',
            'chirurgia vascolare' => 'Chirurgia vascolare',
            'endocrinologo' => 'Endocrinologia',
            'endocrinologia' => 'Endocrinologia',
            'chirurgo plastico' => 'Chirurgia plastica',
            'chirurgia plastica' => 'Chirurgia plastica',
            'medico estetico' => 'Medicina estetica',
            'medicina estetica' => 'Medicina estetica',
            'urologo' => 'Urologia',
            'urologia' => 'Urologia',
            'nutrizionista' => 'Nutrizione',
            'nutrizione' => 'Nutrizione',
            'dietologo' => 'Dietologia',
            'dietologia' => 'Dietologia',
            'dermatologo' => 'Dermatologia',
            'dermatologia' => 'Dermatologia',
            'pneumologo' => 'Pneumologia',
            'pneumologia' => 'Pneumologia',
            'reumatologo' => 'Reumatologia',
            'reumatologia' => 'Reumatologia',
            'internista' => 'Medicina interna',
            'medicina interna' => 'Medicina interna',
            'allergologo' => 'Allergologia',
            'allergologia' => 'Allergologia',
            'analisi cliniche' => 'Analisi cliniche',
            'analisi clinica' => 'Analisi cliniche',
            'laboratorio analisi' => 'Analisi cliniche',
            'tecnico di laboratorio' => 'Analisi cliniche',
            'ecografista' => 'Ecografia',
            'ecografia' => 'Ecografia',
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
