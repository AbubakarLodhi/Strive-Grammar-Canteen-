<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionStabilityTest extends TestCase
{
    public function test_root_redirects_to_merchant_login(): void
    {
        $this->get('/')->assertRedirect('/merchant/login');
    }

    public function test_assign_images_dev_route_is_not_exposed(): void
    {
        $this->get('/assign-images')->assertNotFound();
    }

    public function test_asset_preview_redirects_guests_to_login(): void
    {
        $response = $this->get('/assets/preview/00000000-0000-0000-0000-000000000000');

        $response->assertRedirect(route('filament.merchant.auth.login'));
    }
}
