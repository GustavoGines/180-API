<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase; // Usamos esto para no borrar tu BD local, solo hace rollback al final

    protected function setUp(): void
    {
        parent::setUp();

        // Autenticar como un usuario que puede gestionar pedidos
        // Forzamos que sea admin para pasar el Gate 'manage-orders'
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['manage-orders']);

        // Mockear GoogleCalendarService para evitar errores de credenciales en CI
        $this->mock(\App\Services\GoogleCalendarService::class, function ($mock) {
            $mock->shouldReceive('createFromOrder')->andReturn('mock_event_id');
            $mock->shouldReceive('updateFromOrder')->andReturnNull();
            $mock->shouldReceive('deleteEvent')->andReturnNull();
        });
    }

    /** @test */
    public function test_can_create_order()
    {
        // 1. Preparar datos
        $client = Client::first() ?? Client::factory()->create();

        $payload = [
            'client_id' => $client->id,
            'event_date' => now()->addDays(5)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '16:00',
            'status' => 'confirmed',
            'deposit' => 1000,
            'items' => [
                [
                    'name' => 'Test Torta',
                    'qty' => 1,
                    'base_price' => 5000,
                    'adjustments' => 0,
                ],
            ],
        ];

        // 2. Ejecutar Request
        $response = $this->postJson('/api/orders', $payload);

        // 3. Verificar Respuesta
        $response->assertStatus(201)
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('total', 5000); // 1 * 5000

        // Verificar que se guardó en BD
        $this->assertDatabaseHas('orders', [
            'client_id' => $client->id,
            'total' => 5000,
            'deposit' => 1000,
        ]);
    }

    /** @test */
    public function test_cannot_create_order_with_invalid_dates()
    {
        $client = Client::first() ?? Client::factory()->create();

        $payload = [
            'client_id' => $client->id,
            'event_date' => '2025-10-10',
            'start_time' => '15:00',
            'end_time' => '14:00', // Hora fin antes que inicio
            'items' => [
                ['name' => 'Item', 'qty' => 1, 'base_price' => 100],
            ],
        ];

        $response = $this->postJson('/api/orders', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_time']);
    }

    /** @test */
    public function test_cannot_create_order_with_excessive_deposit()
    {
        $client = Client::first() ?? Client::factory()->create();

        $payload = [
            'client_id' => $client->id,
            'event_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'deposit' => 10000, // Total será 1000
            'items' => [
                ['name' => 'Item', 'qty' => 1, 'base_price' => 1000],
            ],
        ];

        $response = $this->postJson('/api/orders', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['deposit']);
    }

    /** @test */
    public function test_can_update_order()
    {
        // 1. Crear orden inicial
        $client = Client::first() ?? Client::factory()->create();
        $order = \App\Models\Order::create([
            'client_id' => $client->id,
            'event_date' => now()->addDays(5)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '16:00',
            'status' => 'confirmed',
            'total' => 5000,
            'deposit' => 0,
        ]);

        $order->items()->create([
            'name' => 'Old Item',
            'qty' => 1,
            'base_price' => 5000,
        ]);

        // 2. Payload de actualización (PUT requiere todo el objeto según controller)
        $payload = [
            'client_id' => $client->id,
            'event_date' => now()->addDays(6)->format('Y-m-d'), // Cambio fecha
            'start_time' => '15:00', // Cambio hora
            'end_time' => '17:00',
            'status' => 'ready', // Cambio status
            'items' => [
                [
                    'name' => 'Updated Item',
                    'qty' => 2,
                    'base_price' => 3000, // 2*3000 = 6000
                    'adjustments' => 0,
                ],
            ],
        ];

        // 3. Ejecutar PUT
        $response = $this->putJson("/api/orders/{$order->id}", $payload);

        // 4. Verificar
        $response->assertStatus(200)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('total', 6000);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'ready',
            'total' => 6000,
            'event_date' => now()->addDays(6)->format('Y-m-d').' 00:00:00',
        ]);
    }
}
