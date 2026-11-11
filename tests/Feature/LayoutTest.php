<?php

namespace Tests\Feature;

use Tests\TestCase;

class LayoutTest extends TestCase
{
    public function test_dashboard_renders_turkish_layout_and_menu(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="tr">', false)
            ->assertSee('Zafir Sigorta')
            ->assertSee('Nakit Girişleri')
            ->assertSee('Yaşlandırma')
            ->assertSee('Denetim Kayıtları');
    }

    public function test_dashboard_shows_no_fake_financial_figures(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Henüz finansal veri yok')
            ->assertDontSee('₺')
            ->assertDontSee(' TL');
    }
}
