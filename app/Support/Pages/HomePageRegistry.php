<?php

namespace App\Support\Pages;

use App\Models\Page;

final class HomePageRegistry
{
    public const INTERNAL_KEY = 'home';

    public const KEYS = ['hero', 'center_intro', 'medical_areas', 'professionals', 'diagnostics', 'checkups', 'aesthetic_medicine', 'health_pills', 'conventions', 'faq', 'contact', 'newsletter'];

    public const MEDIA_SLOTS = ['hero_video', 'hero_poster', 'center_intro', 'diagnostics_feature', 'checkups_feature', 'aesthetic_feature'];

    /** @return array<string,array{label:string,summary:string,editor:string,default_sort_order:int,capabilities:list<string>}> */
    public static function definitions(): array
    {
        return collect(self::KEYS)->mapWithKeys(fn (string $key, int $order) => [$key => [
            'label' => match ($key) {
                'hero' => 'Hero', 'center_intro' => 'Introduzione centro', 'medical_areas' => 'Aree mediche', 'professionals' => 'Professionisti', 'diagnostics' => 'Diagnostica', 'checkups' => 'Check-up', 'aesthetic_medicine' => 'Medicina estetica', 'health_pills' => 'Pillole di salute', 'conventions' => 'Convenzioni', 'faq' => 'FAQ', 'contact' => 'Contatti', default => 'Newsletter'
            },
            'summary' => 'Sezione editoriale Homepage con dati dei domini derivati in sola lettura.',
            'editor' => 'home-'.$key,
            'default_sort_order' => $order,
            'capabilities' => ['edit', 'toggle', 'reorder'],
        ]])->all();
    }

    /** @return array<string,mixed> */
    public static function defaults(string $key): array
    {
        $defaults = match ($key) {
            'hero' => ['title' => 'Il centro medico pensato su di te.', 'highlight_text' => 'te.', 'body' => 'Specialisti, prevenzione e diagnosi in un percorso costruito intorno a te.', 'search_placeholder' => 'Cerca una specialità, un esame o un professionista', 'primary_cta_label' => 'Prenota una visita', 'primary_cta_target' => 'booking', 'secondary_cta_label' => 'Scopri le aree mediche', 'secondary_cta_target' => 'medical_areas_index'],
            'center_intro' => ['eyebrow' => 'CHI SIAMO', 'title' => 'Un centro, un unico percorso di cura', 'body' => 'Remedic riunisce competenze specialistiche, diagnostica e prevenzione in un unico centro, con l’obiettivo di rendere il percorso di cura più semplice, coordinato e vicino alla persona.', 'items' => [['semantic_key' => 'multidisciplinary_center', 'title' => 'Centro multidisciplinare', 'description' => 'Specialità diverse convivono nello stesso centro e possono dialogare quando il percorso lo richiede.'], ['semantic_key' => 'coordinated_skills', 'title' => 'Competenze coordinate', 'description' => 'Professionisti, diagnostica e servizi lavorano all’interno di un modello condiviso.'], ['semantic_key' => 'person_centered_paths', 'title' => 'Percorsi costruiti intorno alla persona', 'description' => 'Dalla prima visita agli eventuali approfondimenti, ogni passaggio deve essere chiaro e comprensibile.']], 'team_badge_title' => 'Un’équipe coordinata', 'team_badge_body' => 'Specialisti che collaborano su di te', 'quote' => 'Un unico percorso, molti specialisti che collaborano sulla stessa persona.', 'cta_label' => 'Scopri Remedic', 'cta_target' => 'center'],
            'medical_areas' => self::collection('AREE MEDICHE', 'Tutte le specialità, coordinate in un unico centro', 'Esplora le aree mediche di Remedic: ogni specialità dialoga con le altre per costruire un percorso di cura continuo e su misura.', 'Scopri tutte le aree mediche'),
            'professionals' => self::collection('I NOSTRI PROFESSIONISTI', 'Un’équipe multidisciplinare, un metodo condiviso', 'Decine di specialisti che si confrontano, si conoscono e condividono lo stesso approccio. Scorri per conoscere alcuni dei medici che ogni giorno si prendono cura dei nostri pazienti.', 'Conosci tutta l’équipe'),
            'diagnostics' => [...self::collection('DIAGNOSTICA', 'Un poliambulatorio diagnostico, non un singolo esame', 'Un ventaglio ampio di prestazioni specialistiche, eseguite con strumentazione moderna e refertate dai nostri medici. Scorri per esplorare alcuni degli esami disponibili.', 'Scopri tutti gli esami'), 'feature_title' => 'Strumentazione di ultima generazione', 'feature_note' => 'Referti integrati nel percorso'],
            'checkups' => ['eyebrow' => 'CHECK-UP E PREVENZIONE', 'title' => 'Percorsi di prevenzione costruiti su di te', 'body' => 'Non semplici pacchetti, ma percorsi coordinati tra più specialisti e costruiti intorno alla tua storia.', 'cta_label' => 'Scopri tutti i check-up', 'custom_eyebrow' => 'NON TROVI IL PERCORSO ADATTO?', 'custom_title' => 'Costruiamo un check-up personalizzato intorno alle tue esigenze.', 'custom_body' => 'Possiamo aiutarti a individuare un percorso di prevenzione più adatto alla tua situazione.', 'custom_cta_label' => 'Richiedi un check-up personalizzato'],
            'aesthetic_medicine' => [...self::collection('MEDICINA ESTETICA', 'Estetica medica, con rigore clinico', 'Trattamenti sempre affidati a medici specialisti, con valutazioni personalizzate e risultati naturali, misurati nel tempo.', 'Scopri tutti i trattamenti'), 'feature_badge' => 'Approccio naturale', 'feature_title' => 'Naturalezza, prima di tutto', 'feature_body' => 'Un approccio misurato, pensato per valorizzare senza stravolgere i tratti del viso.', 'feature_footer_note' => 'Ogni trattamento è preceduto da una valutazione medica'],
            'health_pills' => ['eyebrow' => 'PILLOLE DI SALUTE', 'title' => 'Il magazine dei nostri specialisti', 'body' => 'Approfondimenti, consigli e chiarimenti firmati dai medici di Remedic, per prenderti cura di te ogni giorno con informazione affidabile.', 'cta_label' => 'Leggi tutti gli articoli', 'selection_mode' => 'automatic', 'featured_blog_post_id' => null, 'secondary_blog_post_ids' => []],
            'conventions' => ['eyebrow' => 'CONVENZIONI E ASSICURAZIONI', 'title' => 'Ci prendiamo cura anche della parte burocratica', 'body' => 'Remedic collabora con i principali fondi sanitari, compagnie assicurative e circuiti convenzionati. Verifichiamo la tua copertura e gestiamo la pratica al posto tuo, così puoi concentrarti solo sulla tua salute.', 'cta_label' => 'Scopri tutte le convenzioni', 'cta_target' => 'conventions_network', 'items' => [['semantic_key' => 'direct_booking', 'title' => 'Prenotazione diretta', 'description' => 'Fissiamo la visita con gli enti convenzionati, senza passaggi inutili.'], ['semantic_key' => 'reimbursement_management', 'title' => 'Gestione dei rimborsi', 'description' => 'Ti assistiamo nella pratica e nella documentazione necessaria.'], ['semantic_key' => 'no_advance_payment', 'title' => 'Nessuna anticipazione', 'description' => 'Dove previsto dalla polizza, non anticipi nulla di tasca tua.']], 'selection_mode' => 'automatic', 'partner_ids' => [], 'other_networks_title' => 'Altri network', 'other_networks_body' => 'Fasi, Previmedical, Blue Assistance, MyAssistance e altri circuiti convenzionati.', 'footnote' => 'Non trovi il tuo fondo o la tua convenzione? Contattaci per verificarne le modalità.'],
            'faq' => ['eyebrow' => 'DOMANDE FREQUENTI', 'title' => 'Le risposte alle domande più comuni', 'body' => "Tutto quello che c'è da sapere prima della tua visita. Non trovi quello che cerchi?", 'support_title' => 'Il nostro team è a tua disposizione', 'support_body' => 'Scrivici o chiamaci: ti aiutiamo a trovare la risposta giusta.', 'cta_label' => 'Contatta il centro', 'cta_target' => 'contact'],
            'contact' => ['eyebrow' => 'CONTATTI', 'title' => 'Un centro accogliente, facile da raggiungere', 'body' => 'Nel cuore della città, con orari ampi e spazi pensati per accoglierti. Trovi qui tutte le informazioni pratiche per la tua visita.', 'primary_cta_label' => 'Prenota ora', 'primary_cta_target' => 'booking', 'secondary_cta_label' => 'Indicazioni', 'secondary_cta_target' => 'map'],
            'newsletter' => ['eyebrow' => 'NEWSLETTER', 'title' => 'Iscriviti e resta aggiornato sulla tua salute', 'benefits' => ['Una email al mese', 'Contenuti dei nostri medici', 'Niente spam'], 'email_placeholder' => 'nome@esempio.it', 'submit_label' => 'Iscriviti', 'submit_target' => 'newsletter_subscription', 'privacy_text' => 'Iscrivendoti richiedi di ricevere la newsletter Remedic. Leggi la Privacy Policy.'],
            default => [],
        };

        if (in_array($key, ['hero', 'contact'], true)) {
            $defaults += ['primary_cta_external_url' => null, 'primary_cta_whatsapp_message' => null, 'secondary_cta_external_url' => null, 'secondary_cta_whatsapp_message' => null];
        }
        if (in_array($key, ['center_intro', 'conventions', 'faq'], true)) {
            $defaults += ['cta_external_url' => null, 'cta_whatsapp_message' => null];
        }
        if ($key === 'newsletter') {
            $defaults += ['submit_external_url' => null, 'submit_whatsapp_message' => null];
        }

        return $defaults;
    }

    /** @return array<string,mixed> */
    private static function collection(string $eyebrow, string $title, string $body, string $cta): array
    {
        return compact('eyebrow', 'title', 'body') + ['cta_label' => $cta, 'max_items' => 6];
    }

    /** @return list<array<string,mixed>> */
    public static function missingDefaults(Page $page): array
    {
        $existing = $page->sections()->pluck('key')->all();

        return collect(self::KEYS)->reject(fn (string $key) => in_array($key, $existing, true))->map(fn (string $key, int $order) => ['key' => $key, 'title' => self::definitions()[$key]['label'], 'content' => null, 'extra_json' => self::defaults($key), 'sort_order' => $order, 'is_active' => true])->values()->all();
    }

    public static function supportsMedia(string $key, string $slot): bool
    {
        return in_array($slot, self::MEDIA_SLOTS, true) && match ($slot) {
            'hero_video', 'hero_poster' => $key === 'hero', default => $key === $slot
        };
    }
}
