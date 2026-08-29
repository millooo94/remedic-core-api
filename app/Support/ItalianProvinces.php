<?php

namespace App\Support;

final class ItalianProvinces
{
    /** @var array<string, string> */
    private const ITEMS = [
        'AG' => 'Agrigento', 'AL' => 'Alessandria', 'AN' => 'Ancona', 'AO' => 'Aosta', 'AP' => 'Ascoli Piceno', 'AQ' => "L'Aquila", 'AR' => 'Arezzo', 'AT' => 'Asti', 'AV' => 'Avellino',
        'BA' => 'Bari', 'BG' => 'Bergamo', 'BI' => 'Biella', 'BL' => 'Belluno', 'BN' => 'Benevento', 'BO' => 'Bologna', 'BR' => 'Brindisi', 'BS' => 'Brescia', 'BT' => 'Barletta-Andria-Trani', 'BZ' => 'Bolzano',
        'CA' => 'Cagliari', 'CB' => 'Campobasso', 'CE' => 'Caserta', 'CH' => 'Chieti', 'CL' => 'Caltanissetta', 'CN' => 'Cuneo', 'CO' => 'Como', 'CR' => 'Cremona', 'CS' => 'Cosenza', 'CT' => 'Catania', 'CZ' => 'Catanzaro',
        'EN' => 'Enna', 'FC' => 'Forlì-Cesena', 'FE' => 'Ferrara', 'FG' => 'Foggia', 'FI' => 'Firenze', 'FM' => 'Fermo', 'FR' => 'Frosinone',
        'GE' => 'Genova', 'GO' => 'Gorizia', 'GR' => 'Grosseto',
        'IM' => 'Imperia', 'IS' => 'Isernia',
        'KR' => 'Crotone',
        'LC' => 'Lecco', 'LE' => 'Lecce', 'LI' => 'Livorno', 'LO' => 'Lodi', 'LT' => 'Latina', 'LU' => 'Lucca',
        'MB' => 'Monza e della Brianza', 'MC' => 'Macerata', 'ME' => 'Messina', 'MI' => 'Milano', 'MN' => 'Mantova', 'MO' => 'Modena', 'MS' => 'Massa-Carrara', 'MT' => 'Matera',
        'NA' => 'Napoli', 'NO' => 'Novara', 'NU' => 'Nuoro',
        'OR' => 'Oristano',
        'PA' => 'Palermo', 'PC' => 'Piacenza', 'PD' => 'Padova', 'PE' => 'Pescara', 'PG' => 'Perugia', 'PI' => 'Pisa', 'PN' => 'Pordenone', 'PO' => 'Prato', 'PR' => 'Parma', 'PT' => 'Pistoia', 'PU' => 'Pesaro e Urbino', 'PV' => 'Pavia', 'PZ' => 'Potenza',
        'RA' => 'Ravenna', 'RC' => 'Reggio Calabria', 'RE' => 'Reggio Emilia', 'RG' => 'Ragusa', 'RI' => 'Rieti', 'RM' => 'Roma', 'RN' => 'Rimini', 'RO' => 'Rovigo',
        'SA' => 'Salerno', 'SI' => 'Siena', 'SO' => 'Sondrio', 'SP' => 'La Spezia', 'SR' => 'Siracusa', 'SS' => 'Sassari', 'SU' => 'Sud Sardegna', 'SV' => 'Savona',
        'TA' => 'Taranto', 'TE' => 'Teramo', 'TN' => 'Trento', 'TO' => 'Torino', 'TP' => 'Trapani', 'TR' => 'Terni', 'TS' => 'Trieste', 'TV' => 'Treviso',
        'UD' => 'Udine',
        'VA' => 'Varese', 'VB' => 'Verbano-Cusio-Ossola', 'VC' => 'Vercelli', 'VE' => 'Venezia', 'VI' => 'Vicenza', 'VR' => 'Verona', 'VT' => 'Viterbo', 'VV' => 'Vibo Valentia',
    ];

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::ITEMS;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::ITEMS);
    }

    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $code = strtoupper($value);
        if (array_key_exists($code, self::ITEMS)) {
            return $code;
        }

        foreach (self::ITEMS as $provinceCode => $name) {
            if (mb_strtolower($name) === mb_strtolower($value)) {
                return $provinceCode;
            }
        }

        return null;
    }
}
