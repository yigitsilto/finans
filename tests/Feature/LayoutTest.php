<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_turkish_layout_and_menu(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('<html lang="tr">', false)
            ->assertSee('Zafir Sigorta')
            ->assertSee('Nakit Girişleri')
            ->assertSee('Yaşlandırma')
            ->assertSee('Denetim Kayıtları');
    }

    public function test_empty_dashboard_shows_only_real_zero_values(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Henüz hesap tanımlı değil')
            ->assertSee('0,00 ₺')
            ->assertDontSeeText('27.000')
            ->assertDontSeeText('1.700');
    }
}
