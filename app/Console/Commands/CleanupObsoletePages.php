<?php

namespace App\Console\Commands;

use App\Services\ObsoletePageCleanupService;
use Illuminate\Console\Command;
use Throwable;

class CleanupObsoletePages extends Command
{
    protected $signature = 'pages:cleanup-obsolete {--dry-run : Mostra le Page allowlisted senza modificare dati} {--force : Esegue la cancellazione allowlisted}';

    protected $description = 'Rimuove esclusivamente le Page legacy dichiarate obsolete';

    public function handle(ObsoletePageCleanupService $cleanup): int
    {
        $inventory = $cleanup->inventory();
        $targets = array_values(array_filter($inventory['pages'], fn (array $page): bool => in_array($page['slug'], ObsoletePageCleanupService::DELETE_SLUGS, true)));

        $this->table(
            ['ID', 'Slug', 'Titolo', 'Section', 'FAQ', 'Media', 'Redirect automatici'],
            array_map(fn (array $page): array => [$page['id'], $page['slug'], $page['title'], $page['sections_count'], $page['faqs_count'], count($page['media']), $page['automatic_redirects_count']], $targets),
        );
        $this->line('Totale Page allowlisted trovate: '.count($targets));

        if ($inventory['unexpected_pages'] !== []) {
            $this->error('UNEXPECTED PAGE: il cleanup è bloccato finché i record non sono classificati.');
            $this->table(['ID', 'Internal key', 'Slug', 'Titolo'], array_map(fn (array $page): array => [$page['id'], $page['internal_key'], $page['slug'], $page['title']], $inventory['unexpected_pages']));

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry-run completato: nessun dato è stato modificato.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn('Nessun dato modificato. Usa --force per eseguire il cleanup dopo il dry-run.');

            return self::FAILURE;
        }

        try {
            $result = $cleanup->cleanup();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Cleanup completato: %d Page, %d Section, %d FAQ, %d redirect automatici e %d media rimossi.',
            $result['deleted_count'],
            $result['sections_deleted'],
            $result['faqs_deleted'],
            $result['automatic_redirects_deleted'],
            count($result['media_deleted']),
        ));

        return self::SUCCESS;
    }
}
