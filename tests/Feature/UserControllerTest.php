<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Admin Boss',
            'email' => 'admin@example.com',
        ]);
        Sanctum::actingAs($admin, ['*']);
    }

    /** @test */
    public function test_users_index_filtra_por_parametro_query()
    {
        User::factory()->create([
            'role' => 'staff',
            'name' => 'Carlos Rodriguez',
            'email' => 'carlos@example.com',
        ]);

        User::factory()->create([
            'role' => 'staff',
            'name' => 'Ana Gomez',
            'email' => 'ana@example.com',
        ]);

        // Búsqueda por nombre
        $res = $this->getJson('/api/users?query=Carlos');
        $res->assertStatus(200);
        $names = collect($res->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Carlos Rodriguez'));
        $this->assertFalse($names->contains('Ana Gomez'));

        // Búsqueda por email
        $resEmail = $this->getJson('/api/users?query=ana@example.com');
        $resEmail->assertStatus(200);
        $namesEmail = collect($resEmail->json('data'))->pluck('name');
        $this->assertTrue($namesEmail->contains('Ana Gomez'));
        $this->assertFalse($namesEmail->contains('Carlos Rodriguez'));
    }
}
