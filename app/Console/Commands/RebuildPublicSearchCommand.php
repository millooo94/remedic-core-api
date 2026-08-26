<?php

namespace App\Console\Commands;

use App\Services\PublicSearchIndexer;
use Illuminate\Console\Command;

class RebuildPublicSearchCommand extends Command
{
    protected $signature = 'search:rebuild';

    protected $description = 'Rebuild the derived locale-aware public search index.';

    public function handle(PublicSearchIndexer $indexer): int
    {
        $this->info('Rebuilt '.$indexer->rebuild().' public search documents.');

        return self::SUCCESS;
    }
}
