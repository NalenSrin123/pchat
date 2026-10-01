<?php

namespace Tests\Feature;

use Tests\TestCase;

class SeoTest extends TestCase
{
    public function test_public_pages_and_sitemap_are_available(): void
    {
        config()->set('seo.site_url', 'https://chat.example.com');

        foreach (['/', '/about', '/features', '/privacy', '/terms'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('https://chat.example.com/features', false)
            ->assertDontSee('/messenger', false)
            ->assertDontSee('/admin', false);
    }

    public function test_private_messenger_route_remains_authenticated(): void
    {
        $this->get('/messenger')->assertRedirect('/login');
    }

    public function test_login_document_is_noindex(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="robots" content="noindex, nofollow"', false);
    }
}
