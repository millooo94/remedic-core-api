<?php

namespace App\Support\Pages;

use App\Models\Page;

/** Closed contracts shared by the three legal documents. */
final class LegalDocumentRegistry
{
    public const PRIVACY = 'privacy';

    public const COOKIE = 'cookie-policy';

    public const TERMS = 'terms_of_service';

    public const HERO_KEY = 'legal_hero';

    /** @var array<string, array<string, list<string>>> */
    private const FIXED_PLACEHOLDER_TARGETS = [
        self::PRIVACY => ['controller_contacts' => ['owner_email', 'owner_phone']],
        self::TERMS => ['contacts' => ['owner_email', 'owner_phone']],
    ];

    /** @var array<string, list<array{key: string, title: string}>> */
    private const SECTIONS = [
        self::PRIVACY => [
            ['key' => 'legal_hero', 'title' => 'Privacy Policy'], ['key' => 'scope', 'title' => 'Ambito dell’informativa'], ['key' => 'controller_contacts', 'title' => 'Titolare del trattamento e contatti'], ['key' => 'personal_data', 'title' => 'Dati personali trattati'], ['key' => 'data_collection', 'title' => 'Come raccogliamo i dati'], ['key' => 'purposes_legal_bases', 'title' => 'Finalità e basi giuridiche'], ['key' => 'mandatory_optional', 'title' => 'Conferimento obbligatorio e facoltativo'], ['key' => 'processing_security', 'title' => 'Modalità del trattamento e sicurezza'], ['key' => 'retention', 'title' => 'Tempi di conservazione'], ['key' => 'recipients_processors', 'title' => 'Destinatari e responsabili del trattamento'], ['key' => 'automated_decisions', 'title' => 'Decisioni automatizzate'], ['key' => 'data_subject_rights', 'title' => 'I tuoi diritti'], ['key' => 'minors', 'title' => 'Dati dei minori'], ['key' => 'policy_updates', 'title' => 'Aggiornamenti dell’informativa'],
        ],
        self::COOKIE => [
            ['key' => 'legal_hero', 'title' => 'Cookie Policy'], ['key' => 'cookies_technologies', 'title' => 'Cookie e tecnologie analoghe'], ['key' => 'strictly_necessary', 'title' => 'Strumenti strettamente necessari'], ['key' => 'authentication_session', 'title' => 'Autenticazione e sessione'], ['key' => 'analytics', 'title' => 'Misurazione e Web Analytics'], ['key' => 'consent_categories', 'title' => 'Categorie disponibili nel pannello'], ['key' => 'first_third_party', 'title' => 'Prima parte e terze parti'], ['key' => 'tool_duration', 'title' => 'Durata degli strumenti'], ['key' => 'manage_preferences', 'title' => 'Come gestire o revocare le preferenze'], ['key' => 'policy_updates', 'title' => 'Aggiornamenti della Cookie Policy'],
        ],
        self::TERMS => [
            ['key' => 'legal_hero', 'title' => 'Termini di servizio'], ['key' => 'scope', 'title' => 'Ambito di applicazione'], ['key' => 'health_information_emergencies', 'title' => 'Informazioni sanitarie e urgenze'], ['key' => 'professionals_services', 'title' => 'Professionisti e prestazioni'], ['key' => 'requests_bookings', 'title' => 'Richieste e prenotazioni'], ['key' => 'account_reserved_area', 'title' => 'Account e area riservata'], ['key' => 'medical_reports_documents', 'title' => 'Referti e documenti sanitari'], ['key' => 'service_communications_newsletter', 'title' => 'Comunicazioni di servizio e newsletter'], ['key' => 'proper_use', 'title' => 'Uso corretto dei servizi'], ['key' => 'service_availability_changes', 'title' => 'Disponibilità e modifiche del servizio'], ['key' => 'intellectual_property', 'title' => 'Contenuti e proprietà intellettuale'], ['key' => 'third_party_links', 'title' => 'Link e servizi di terzi'], ['key' => 'liability', 'title' => 'Responsabilità'], ['key' => 'privacy_cookies', 'title' => 'Privacy e cookie'], ['key' => 'terms_updates', 'title' => 'Modifiche ai termini'], ['key' => 'contacts', 'title' => 'Contatti'],
        ],
    ];

    public static function isLegal(string $internalKey): bool
    {
        return array_key_exists($internalKey, self::SECTIONS);
    }

    /** @return list<string>|null */
    public static function fixedPlaceholderTargets(string $internalKey, string $sectionKey): ?array
    {
        return self::FIXED_PLACEHOLDER_TARGETS[$internalKey][$sectionKey] ?? null;
    }

    /** @return list<string> */
    public static function sectionKeys(string $internalKey): array
    {
        return array_column(self::SECTIONS[$internalKey] ?? [], 'key');
    }

    /** @return array{key:string,title:string}|null */
    public static function section(string $internalKey, string $key): ?array
    {
        foreach (self::SECTIONS[$internalKey] ?? [] as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return null;
    }

    /** @return array<string, array{label:string,summary:string,editor:string,default_sort_order:int,capabilities:list<string>}> */
    public static function definitions(string $internalKey): array
    {
        $definitions = [];
        foreach (self::SECTIONS[$internalKey] ?? [] as $order => $section) {
            $definitions[$section['key']] = [
                'label' => $section['title'],
                'summary' => $section['key'] === self::HERO_KEY ? 'Identità e introduzione del documento. La data è aggiornata automaticamente.' : 'Contenuto legale strutturato.',
                'editor' => $section['key'] === self::HERO_KEY ? 'legal-hero' : 'legal-content',
                'default_sort_order' => $order,
                'capabilities' => $section['key'] === self::HERO_KEY ? ['edit'] : ['edit', 'toggle', 'reorder'],
            ];
        }

        return $definitions;
    }

    /** @return list<array{key:string,title:string,content:?string,extra_json:array<string,mixed>,sort_order:int,is_active:bool}> */
    public static function missingDefaults(Page $page): array
    {
        $existing = $page->sections()->pluck('key')->all();

        return array_values(array_filter(array_map(function (array $section, int $order) use ($page): array {
            $hero = $section['key'] === self::HERO_KEY;

            return ['key' => $section['key'], 'title' => $section['title'], 'content' => $hero ? self::heroDescription((string) $page->internal_key) : null, 'extra_json' => $hero ? ['eyebrow' => 'INFORMAZIONI LEGALI', 'blocks' => []] : ['blocks' => []], 'sort_order' => $order, 'is_active' => true];
        }, self::SECTIONS[(string) $page->internal_key] ?? [], array_keys(self::SECTIONS[(string) $page->internal_key] ?? [])), fn (array $section): bool => ! in_array($section['key'], $existing, true)));
    }

    public static function heroDescription(string $key): string
    {
        return match ($key) {
            self::PRIVACY => 'Come trattiamo e proteggiamo i dati personali di utenti e pazienti nei servizi digitali Remedic.',
            self::COOKIE => 'Quali tecnologie usa il sito Remedic, per quali finalità e come puoi gestire le tue preferenze.',
            default => 'Le regole per utilizzare il sito, l’area riservata e i servizi digitali Remedic in modo sicuro e consapevole.',
        };
    }
}
