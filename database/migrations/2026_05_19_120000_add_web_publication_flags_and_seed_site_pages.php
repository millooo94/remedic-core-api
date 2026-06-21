<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specializations') && ! Schema::hasColumn('specializations', 'is_web_active')) {
            Schema::table('specializations', function (Blueprint $table): void {
                $table->boolean('is_web_active')->default(true)->after('is_active');
            });
        }

        if (Schema::hasTable('services') && ! Schema::hasColumn('services', 'is_web_active')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->boolean('is_web_active')->default(true)->after('is_active');
            });
        }

        if (Schema::hasTable('specializations') && Schema::hasColumn('specializations', 'is_web_active')) {
            DB::table('specializations')->update([
                'is_web_active' => DB::raw('is_active'),
            ]);
        }

        if (Schema::hasTable('services') && Schema::hasColumn('services', 'is_web_active')) {
            DB::table('services')->update([
                'is_web_active' => DB::raw('is_active'),
            ]);
        }

        $this->seedSitePages();
    }

    public function down(): void
    {
        $this->deleteSitePages();

        if (Schema::hasTable('services') && Schema::hasColumn('services', 'is_web_active')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->dropColumn('is_web_active');
            });
        }

        if (Schema::hasTable('specializations') && Schema::hasColumn('specializations', 'is_web_active')) {
            Schema::table('specializations', function (Blueprint $table): void {
                $table->dropColumn('is_web_active');
            });
        }
    }

    private function seedSitePages(): void
    {
        if (! Schema::hasTable('pages') || ! Schema::hasTable('sections')) {
            return;
        }

        $now = now();
        $pageModelType = 'App\\Models\\Page';

        $pages = [
            'chi-siamo' => [
                'title' => 'Chi siamo',
                'template' => 'default',
                'excerpt' => 'Remedic e il centro medico polispecialistico di Acireale nato per offrire visite, diagnostica e percorsi di prevenzione con un approccio rapido, accessibile e umano.',
                'intro_text' => 'Un centro polispecialistico ad Acireale nato per offrire visite, diagnostica e percorsi di prevenzione con un approccio rapido, accessibile e umano.',
                'seo_title' => 'Chi siamo | Remedic',
                'seo_description' => 'Scopri l’identita di Remedic, il centro medico polispecialistico di Acireale che coordina visite, diagnostica e prevenzione.',
                'seo_h1' => 'Remedic, il centro medico pensato su di te',
                'is_active' => true,
                'published_at' => $now,
                'sections' => [
                    [
                        'key' => 'hero',
                        'title' => 'Remedic, il centro medico pensato su di te',
                        'subtitle' => 'Centro polispecialistico ad Acireale',
                        'content' => 'Visite, diagnostica e percorsi di prevenzione coordinati in uno spazio medico pensato per essere piu chiaro, accessibile e umano.',
                        'extra_json' => $this->json([
                            'side_note_title' => 'Un unico riferimento',
                            'ctas' => [
                                ['label' => 'Prenota una visita', 'href' => '/contatti'],
                                ['label' => 'Contattaci', 'href' => '/contatti', 'variant' => 'outline'],
                            ],
                        ]),
                    ],
                    [
                        'key' => 'approach_items',
                        'title' => 'Il nostro approccio',
                        'subtitle' => 'Un centro medico che prova a rendere piu semplice il rapporto tra persone, specialisti e prevenzione',
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                [
                                    'title' => 'Persona al centro',
                                    'text' => 'Ogni percorso nasce dall’ascolto e dall’esigenza concreta della persona, non da una proposta standard.',
                                ],
                                [
                                    'title' => 'Ascolto e personalizzazione',
                                    'text' => 'Orientiamo visite, diagnostica e controlli in modo chiaro, costruendo un’esperienza piu semplice e comprensibile.',
                                ],
                                [
                                    'title' => 'Professionalita e coordinamento',
                                    'text' => 'Mettiamo in relazione specialisti e prestazioni per evitare frammentazioni e rendere piu lineare il percorso di cura.',
                                ],
                                [
                                    'title' => 'Prevenzione e continuita',
                                    'text' => 'Crediamo in una medicina che accompagna nel tempo, con controlli utili, follow-up e prevenzione consapevole.',
                                ],
                            ],
                        ]),
                    ],
                    [
                        'key' => 'what_you_find_items',
                        'title' => 'Cosa trovi in Remedic',
                        'subtitle' => 'Aree mediche, percorsi e servizi costruiti per rendere piu lineare il tuo accesso alla salute',
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                ['title' => 'Visite specialistiche', 'text' => 'Aree mediche coordinate per affrontare sintomi, controlli e follow-up.'],
                                ['title' => 'Diagnostica', 'text' => 'Esami e approfondimenti utili per rendere piu rapido il percorso di valutazione.'],
                                ['title' => 'Check-up', 'text' => 'Percorsi di prevenzione pensati per eta, fattori di rischio e obiettivi di salute.'],
                                ['title' => 'Medicina estetica', 'text' => 'Consulenze e trattamenti con un approccio medico, responsabile e personalizzato.'],
                                ['title' => 'Medicina di genere', 'text' => 'Percorsi costruiti sulle esigenze di donne e uomini nelle diverse fasi della vita.'],
                                ['title' => 'Percorsi personalizzati', 'text' => 'Orientamento e combinazioni di visite o controlli pensati sulle esigenze reali della persona.'],
                            ],
                        ]),
                    ],
                    [
                        'key' => 'why_remedic_items',
                        'title' => 'Perche scegliere Remedic',
                        'subtitle' => 'Un centro pensato per offrire piu continuita tra visite, prevenzione e orientamento',
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'Centro polispecialistico ad Acireale.',
                                'Piu specialita in un unico luogo, con un accesso piu semplice.',
                                'Prenotazione e orientamento con un linguaggio chiaro.',
                                'Attenzione concreta alla persona e ai suoi tempi.',
                                'Percorsi coordinati tra visite, diagnostica e prevenzione.',
                            ],
                            'note_title' => 'Il centro',
                            'note_text' => 'Ambulatori, spazi di consulto e un ambiente clinico ordinato per accoglierti con un’esperienza piu chiara e rassicurante.',
                        ]),
                    ],
                    [
                        'key' => 'team_intro',
                        'title' => 'L’equipe Remedic',
                        'subtitle' => 'Un gruppo di specialisti con competenze diverse, pensato per offrire orientamento e continuita di cura.',
                        'content' => null,
                        'extra_json' => $this->json([]),
                    ],
                    [
                        'key' => 'cta',
                        'title' => 'Hai bisogno di una visita o di un percorso personalizzato?',
                        'subtitle' => null,
                        'content' => 'Il team Remedic ti aiuta a trovare la visita, il controllo o il percorso piu adatto alle tue esigenze.',
                        'extra_json' => $this->json([]),
                    ],
                ],
            ],
            'privacy' => [
                'title' => 'Privacy Policy',
                'template' => 'legal',
                'excerpt' => 'Informazioni sul trattamento dei dati personali raccolti tramite il sito e i canali di contatto Remedic.',
                'intro_text' => 'Una versione leggibile e ordinata della policy, progettata per essere completata con i riferimenti legali definitivi.',
                'seo_title' => 'Privacy Policy | Remedic',
                'seo_description' => 'Informazioni sul trattamento dei dati personali relativi ai servizi e ai contatti del sito Remedic.',
                'seo_h1' => 'Privacy Policy',
                'is_active' => true,
                'published_at' => $now,
                'sections' => [
                    [
                        'key' => 'hero',
                        'title' => 'Privacy Policy',
                        'subtitle' => 'Informativa dati personali',
                        'content' => 'Informazioni sul trattamento dei dati personali raccolti tramite il sito e i canali di contatto Remedic.',
                        'extra_json' => $this->json([
                            'side_note_title' => 'Pagina in revisione',
                            'side_note_text' => 'La struttura e pronta per ospitare il testo legale definitivo. I contenuti vanno verificati e completati con il consulente privacy.',
                            'meta_items' => [
                                ['label' => 'Ambito', 'value' => 'Sito e contatti Remedic'],
                                ['label' => 'Aggiornamento', 'value' => 'Da verificare'],
                                ['label' => 'Supporto', 'value' => 'Contatti privacy dedicati'],
                            ],
                        ]),
                    ],
                    [
                        'key' => 'titolare',
                        'title' => 'Titolare del trattamento',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'Il presente testo deve essere verificato e completato con i riferimenti definitivi del titolare del trattamento dei dati personali e con gli eventuali recapiti privacy dedicati.',
                                'Remedic utilizza questa pagina per fornire informazioni sul trattamento dei dati raccolti tramite navigazione, richieste informative, prenotazioni e utilizzo dei servizi presenti sul sito.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'dati-trattati',
                        'title' => 'Tipologie di dati trattati',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'Possono essere trattati dati identificativi e di contatto inseriti volontariamente dall’utente, oltre a dati tecnici legati alla navigazione e all’utilizzo dei servizi digitali del sito.',
                                'Eventuali dati relativi a richieste di prenotazione o orientamento sanitario devono essere trattati secondo procedure e informative specifiche da validare con il consulente privacy.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'finalita',
                        'title' => 'Finalita del trattamento',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'I dati possono essere trattati per rispondere a richieste informative, gestire contatti e prenotazioni, migliorare l’esperienza di navigazione e garantire il corretto funzionamento dei servizi digitali.',
                                'Ulteriori finalita, basi giuridiche e tempi di conservazione devono essere confermati nella versione legale definitiva della policy.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'base-giuridica',
                        'title' => 'Base giuridica',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'La base giuridica del trattamento deve essere definita in relazione alle specifiche attivita svolte: esecuzione di misure precontrattuali, adempimenti normativi, legittimo interesse o consenso quando richiesto.',
                                'Questa sezione e predisposta per una revisione formale con il professionista che segue la conformita privacy del progetto.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'modalita',
                        'title' => 'Modalita del trattamento',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'I dati possono essere trattati con strumenti digitali e organizzativi idonei a garantire sicurezza, riservatezza e controllo degli accessi, nel rispetto della normativa applicabile.',
                                'Le misure tecniche e organizzative specifiche devono essere confermate nel documento definitivo.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'conservazione',
                        'title' => 'Conservazione dei dati',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'I tempi di conservazione devono essere determinati in base alla finalita del trattamento, agli obblighi di legge e alle procedure interne applicabili ai servizi offerti.',
                                'In questa fase la sezione resta come struttura da completare con indicazioni puntuali e validate.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'diritti',
                        'title' => 'Diritti dell’interessato',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'Gli utenti possono esercitare i diritti previsti dalla normativa applicabile, tra cui accesso, rettifica, cancellazione, limitazione, opposizione e, ove previsto, portabilita dei dati.',
                                'Le modalita operative per l’esercizio di tali diritti devono essere esplicitate nella versione finale della policy.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'contatti-privacy',
                        'title' => 'Contatti privacy',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'I recapiti privacy dedicati devono essere confermati e inseriti in modo definitivo prima della pubblicazione finale della policy.',
                                'Fino a quel momento, questa pagina funge da struttura leggibile e coerente con il sito, pronta per la revisione legale conclusiva.',
                            ],
                        ]),
                    ],
                ],
            ],
            'cookie-policy' => [
                'title' => 'Cookie Policy',
                'template' => 'legal',
                'excerpt' => 'Informazioni sull’utilizzo dei cookie e sulle preferenze di consenso del sito Remedic.',
                'intro_text' => 'Una pagina legale piu leggibile, coerente con il sito e pronta per accogliere il testo definitivo.',
                'seo_title' => 'Cookie Policy | Remedic',
                'seo_description' => 'Informazioni sull’utilizzo dei cookie, degli strumenti di tracciamento e sulle preferenze di consenso del sito Remedic.',
                'seo_h1' => 'Cookie Policy',
                'is_active' => true,
                'published_at' => $now,
                'sections' => [
                    [
                        'key' => 'hero',
                        'title' => 'Cookie Policy',
                        'subtitle' => 'Informativa cookie',
                        'content' => 'Informazioni sull’utilizzo dei cookie e sulle preferenze di consenso del sito Remedic.',
                        'extra_json' => $this->json([
                            'side_note_title' => 'Preferenze cookie',
                            'side_note_text' => 'Il sito e predisposto per gestire le preferenze di consenso. La policy finale va completata con il dettaglio tecnico dei cookie effettivamente utilizzati.',
                            'meta_items' => [
                                ['label' => 'Ambito', 'value' => 'Navigazione e preferenze'],
                                ['label' => 'Consenso', 'value' => 'Gestibile dal pannello cookie'],
                                ['label' => 'Verifica', 'value' => 'Da completare'],
                            ],
                        ]),
                    ],
                    [
                        'key' => 'cosa-sono',
                        'title' => 'Cosa sono i cookie',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'I cookie sono piccoli file di testo che permettono al sito di funzionare correttamente, ricordare preferenze o raccogliere informazioni tecniche e statistiche sulla navigazione.',
                                'La classificazione puntuale dei cookie utilizzati deve essere confermata nella versione legale definitiva della policy.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'cookie-tecnici',
                        'title' => 'Cookie tecnici',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'I cookie tecnici supportano funzioni essenziali del sito, come la navigazione, la sicurezza e la corretta erogazione dei contenuti richiesti dall’utente.',
                                'Questa sezione va integrata con l’elenco effettivo dei cookie tecnici presenti nel progetto e con le relative durate.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'cookie-analitici',
                        'title' => 'Cookie analitici',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'Eventuali cookie analitici permettono di comprendere come viene utilizzato il sito e aiutano a migliorare struttura, contenuti e percorsi informativi.',
                                'La policy definitiva dovra specificare se tali strumenti sono configurati in forma aggregata, anonimizzata o soggetti a consenso.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'terze-parti',
                        'title' => 'Cookie di terze parti',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'Componenti esterni o integrazioni di terze parti possono comportare l’utilizzo di cookie o tecnologie analoghe soggette alle rispettive informative.',
                                'I fornitori effettivamente coinvolti e i relativi link informativi vanno verificati e inseriti nella revisione conclusiva.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'preferenze',
                        'title' => 'Gestione preferenze',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'Le preferenze di consenso possono essere gestite tramite il sistema cookie del sito quando attivo, distinguendo tra strumenti necessari e categorie opzionali.',
                                'Questa pagina e predisposta per collegarsi al pannello reale di gestione del consenso gia presente nel progetto.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'aggiornamenti',
                        'title' => 'Aggiornamenti della policy',
                        'subtitle' => null,
                        'content' => null,
                        'extra_json' => $this->json([
                            'items' => [
                                'La cookie policy puo essere aggiornata nel tempo per adeguarsi a modifiche tecniche, normative o organizzative relative al sito e ai servizi connessi.',
                                'La data di ultimo aggiornamento deve essere definita nella versione finale del testo approvato.',
                            ],
                        ]),
                    ],
                    [
                        'key' => 'preferences_cta',
                        'title' => 'Modifica le tue preferenze cookie',
                        'subtitle' => null,
                        'content' => 'Se il sistema di consenso e attivo, puoi riaprire il pannello dedicato e aggiornare in qualsiasi momento le tue preferenze.',
                        'extra_json' => $this->json([]),
                    ],
                ],
            ],
        ];

        foreach ($pages as $slug => $page) {
            DB::table('pages')->updateOrInsert(
                ['slug' => $slug],
                [
                    'title' => $page['title'],
                    'template' => $page['template'],
                    'excerpt' => $page['excerpt'],
                    'intro_text' => $page['intro_text'],
                    'seo_title' => $page['seo_title'],
                    'seo_description' => $page['seo_description'],
                    'seo_h1' => $page['seo_h1'],
                    'is_active' => $page['is_active'],
                    'published_at' => $page['published_at'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            $pageId = DB::table('pages')->where('slug', $slug)->value('id');
            if (! $pageId) {
                continue;
            }

            DB::table('sections')
                ->where('sectionable_type', $pageModelType)
                ->where('sectionable_id', $pageId)
                ->delete();

            foreach ($page['sections'] as $index => $section) {
                DB::table('sections')->insert([
                    'sectionable_type' => $pageModelType,
                    'sectionable_id' => $pageId,
                    'key' => $section['key'],
                    'title' => $section['title'],
                    'subtitle' => $section['subtitle'],
                    'content' => $section['content'],
                    'extra_json' => $section['extra_json'],
                    'sort_order' => $index,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function deleteSitePages(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        $pageIds = DB::table('pages')
            ->whereIn('slug', ['chi-siamo', 'privacy', 'cookie-policy'])
            ->pluck('id')
            ->all();

        if ($pageIds !== [] && Schema::hasTable('sections')) {
            DB::table('sections')
                ->where('sectionable_type', 'App\\Models\\Page')
                ->whereIn('sectionable_id', $pageIds)
                ->delete();
        }

        DB::table('pages')
            ->whereIn('slug', ['chi-siamo', 'privacy', 'cookie-policy'])
            ->delete();
    }

    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
};
