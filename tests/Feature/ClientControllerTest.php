<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
    }

    // ──────────────────────────────────────────────
    // GET /api/clients — búsqueda y listado
    // ──────────────────────────────────────────────

    /** @test */
    public function test_index_retorna_todos_los_clientes_sin_query()
    {
        Client::factory()->count(3)->create();

        $response = $this->getJson('/api/clients');

        $response->assertStatus(200);
        // Paginado: la clave es 'data'
        $response->assertJsonStructure(['data']);
        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
    }

    /** @test */
    public function test_busqueda_por_nombre_retorna_coincidencias()
    {
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('unaccent() es exclusivo de PostgreSQL.');
        }

        Client::factory()->create(['name' => 'Maria Perez']);
        Client::factory()->create(['name' => 'Juan Lopez']);

        $response = $this->getJson('/api/clients?query=Maria');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Maria Perez', $data[0]['name']);
    }

    /** @test */
    public function test_busqueda_sin_resultados_retorna_array_vacio()
    {
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('unaccent() es exclusivo de PostgreSQL.');
        }

        Client::factory()->create(['name' => 'Ana Gonzalez']);

        $response = $this->getJson('/api/clients?query=zzz_inexistente');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    /** @test */
    public function test_index_respeta_per_page_maximo_de_200()
    {
        // per_page=500 debe ser recortado a 200 (el máximo configurado)
        Client::factory()->count(5)->create();

        $response = $this->getJson('/api/clients?per_page=500');

        $response->assertStatus(200);
        // Verificamos que el per_page en el meta de paginación no supera 200
        $perPage = $response->json('meta.per_page') ?? $response->json('per_page');
        $this->assertLessThanOrEqual(200, $perPage);
    }

    // ──────────────────────────────────────────────
    // POST /api/clients — creación
    // ──────────────────────────────────────────────

    /** @test */
    public function test_store_crea_cliente_correctamente()
    {
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('unaccent() es exclusivo de PostgreSQL.');
        }

        $payload = [
            'name' => 'Nuevo Cliente Test',
            'phone' => '+5491112345678',
            'email' => 'nuevo@test.com',
        ];

        $response = $this->postJson('/api/clients', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Nuevo Cliente Test')
            ->assertJsonPath('data.ig_handle', null); // campo expuesto ahora

        $this->assertDatabaseHas('clients', ['name' => 'Nuevo Cliente Test']);
    }

    /** @test */
    public function test_store_rechaza_nombre_duplicado_con_422()
    {
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('unaccent() es exclusivo de PostgreSQL.');
        }

        Client::factory()->create(['name' => 'Cliente Duplicado']);

        $response = $this->postJson('/api/clients', [
            'name' => 'Cliente Duplicado',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_store_rechaza_nombre_duplicado_en_papelera_con_409()
    {
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('unaccent() es exclusivo de PostgreSQL.');
        }

        $client = Client::factory()->create(['name' => 'Cliente Borrado']);
        $client->delete();

        $response = $this->postJson('/api/clients', [
            'name' => 'Cliente Borrado',
        ]);

        $response->assertStatus(409);
    }

    // ──────────────────────────────────────────────
    // GET /api/clients/{id} — detalle
    // ──────────────────────────────────────────────

    /** @test */
    public function test_show_incluye_ig_handle_y_whatsapp_url()
    {
        $client = Client::factory()->create([
            'name' => 'Test IG',
            'ig_handle' => 'test.ig',
            'phone' => '+5493704123456',
        ]);

        $response = $this->getJson("/api/clients/{$client->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.ig_handle', 'test.ig')
            ->assertJsonPath('data.whatsapp_url', 'https://wa.me/5493704123456')
            ->assertJsonMissing(['address']); // campo eliminado en B2
    }
}
