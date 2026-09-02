<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServicePricingItem;
use App\Models\ServicePricingItemPresentation;
use App\Models\ServicePricingProfile;
use App\Models\ServicePricingProfilePresentation;
use App\Models\ServiceWebProfile;
use App\Models\SiteIndexPage;
use App\Services\SiteIndexPageInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructuredPricingApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function public_detail_exposes_only_the_published_structured_pricing_projection(): void
    {
        $service = $this->service('laser');
        $woman = ServicePricingProfile::query()->create(['service_id' => $service->id, 'label' => 'Donna', 'is_active' => true, 'sort_order' => 0]);
        $man = ServicePricingProfile::query()->create(['service_id' => $service->id, 'label' => 'Uomo', 'is_active' => true, 'sort_order' => 1]);
        ServicePricingProfilePresentation::query()->create(['service_pricing_profile_id' => $woman->id, 'is_web_enabled' => true]);
        ServicePricingProfilePresentation::query()->create(['service_pricing_profile_id' => $man->id, 'is_web_enabled' => true]);
        $female = ServicePricingItem::query()->create(['service_pricing_profile_id' => $woman->id, 'label' => 'Ascelle', 'kind' => 'zone', 'price_amount' => 50, 'is_active' => true, 'sort_order' => 0]);
        $male = ServicePricingItem::query()->create(['service_pricing_profile_id' => $man->id, 'label' => 'Ascelle', 'kind' => 'zone', 'price_amount' => 60, 'is_active' => true, 'sort_order' => 0]);
        $total = ServicePricingItem::query()->create(['service_pricing_profile_id' => $woman->id, 'label' => 'Total Body', 'kind' => 'package', 'price_amount' => 320, 'is_active' => true, 'sort_order' => 1]);
        foreach ([$female, $male, $total] as $item) {
            ServicePricingItemPresentation::query()->create(['service_pricing_item_id' => $item->id, 'is_web_enabled' => true, 'is_highlighted' => $item->id === $total->id]);
        }

        $this->getJson('/api/v1/public/prestazioni/laser')->assertOk()
            ->assertJsonPath('data.pricing.profiles.0.label', 'Donna')
            ->assertJsonPath('data.pricing.profiles.0.items.0.price', '50.00')
            ->assertJsonPath('data.pricing.profiles.0.items.1.type', 'package')
            ->assertJsonPath('data.pricing.profiles.0.items.1.is_highlighted', true)
            ->assertJsonPath('data.pricing.profiles.1.items.0.price', '60.00')
            ->assertJsonMissingPath('data.pricing.profiles.0.items.0.id');
    }

    #[Test]
    public function pricing_relations_cascade_when_a_profile_is_deleted(): void
    {
        $profile = ServicePricingProfile::query()->create(['service_id' => $this->service('cascade')->id, 'label' => 'Profilo', 'is_active' => true]);
        $item = ServicePricingItem::query()->create(['service_pricing_profile_id' => $profile->id, 'label' => 'Voce', 'kind' => 'variant', 'price_amount' => 10, 'is_active' => true]);
        ServicePricingProfilePresentation::query()->create(['service_pricing_profile_id' => $profile->id]);
        ServicePricingItemPresentation::query()->create(['service_pricing_item_id' => $item->id]);
        $profile->delete();
        $this->assertDatabaseCount('service_pricing_profiles', 0)->assertDatabaseCount('service_pricing_items', 0)->assertDatabaseCount('service_pricing_profile_presentations', 0)->assertDatabaseCount('service_pricing_item_presentations', 0);
    }

    #[Test]
    public function public_indexes_follow_master_classification_and_visibility_gates(): void
    {
        app(SiteIndexPageInitializer::class)->initialize();
        SiteIndexPage::query()->whereIn('internal_key', ['diagnostics_index', 'aesthetic_medicine_index'])
            ->update(['is_active' => true, 'published_at' => now()->subMinute()]);

        $this->service('ecografia', 'diagnostic');
        $this->service('biostimolazione', 'aesthetic_medicine');
        $this->service('cardiologia', 'specialist_visit');
        $inactiveDiagnostic = $this->service('ecografia-inattiva', 'diagnostic');
        $inactiveDiagnostic->update(['is_active' => false]);
        $webDisabledDiagnostic = $this->service('ecografia-web-disabilitata', 'diagnostic');
        ServiceWebProfile::query()->where('service_id', $webDisabledDiagnostic->id)->update(['is_web_enabled' => false]);

        $this->getJson('/api/v1/public/site-indexes/diagnostics_index')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Laser ecografia'])
            ->assertJsonMissing(['name' => 'Laser biostimolazione'])
            ->assertJsonMissing(['name' => 'Laser cardiologia'])
            ->assertJsonMissing(['name' => 'Laser ecografia-inattiva'])
            ->assertJsonMissing(['name' => 'Laser ecografia-web-disabilitata']);
        $this->getJson('/api/v1/public/site-indexes/aesthetic_medicine_index')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Laser biostimolazione'])
            ->assertJsonMissing(['name' => 'Laser ecografia'])
            ->assertJsonMissing(['name' => 'Laser cardiologia']);
    }

    private function service(string $slug, ?string $classification = null): Service
    {
        $service = Service::query()->create(['canonical_name' => 'Laser '.$slug, 'display_name' => 'Laser '.$slug, 'slug' => $slug, 'classification' => $classification, 'is_active' => true]);
        ServiceWebProfile::query()->create(['service_id' => $service->id, 'public_slug' => $slug, 'is_web_enabled' => true]);

        return $service;
    }
}
