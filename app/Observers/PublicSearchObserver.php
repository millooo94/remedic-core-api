<?php

namespace App\Observers;

use App\Models\BlogPost;
use App\Models\CheckupWebProfile;
use App\Models\Page;
use App\Models\ProfessionalPublicProfile;
use App\Models\ServiceWebProfile;
use App\Models\SiteIndexPage;
use App\Models\SpecializationWebProfile;
use App\Services\PublicSearchIndexer;
use Illuminate\Database\Eloquent\Model;

class PublicSearchObserver
{
    public function saved(Model $model): void
    {
        app(PublicSearchIndexer::class)->reindexFrom($model);
    }

    public function deleted(Model $model): void
    {
        $indexer = app(PublicSearchIndexer::class);
        if (in_array($model::class, [Page::class, SiteIndexPage::class, SpecializationWebProfile::class, ServiceWebProfile::class, ProfessionalPublicProfile::class, CheckupWebProfile::class, BlogPost::class], true)) {
            $indexer->remove($model);

            return;
        }

        $indexer->reindexFrom($model);
    }
}
