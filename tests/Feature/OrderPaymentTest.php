<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);

        // Mockear Google Calendar para no necesitar credenciales reales
        $this->mock(\App\Services\GoogleCalendarService::class, function ($mock) {
            $mock->shouldReceive('createFromOrder')->andReturn('mock_event_id');
            $mock->shouldReceive('updateFromOrder')->andReturnNull();
            $mock->shouldReceive('deleteEvent')->andReturnNull();
        });
    }

    /**
     * Crea una orden de prueba con ítems básicos.
     */
    private function createOrder(array $overrides = []): Order
    {
        $client = Client::factory()->create();

        $order = Order::create(array_merge([
            'client_id' => $client->id,
            'event_date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '14:00',
            'end_time' => '16:00',
            'status' => 'confirmed',
            'total' => 10000,
            'deposit' => 0,
            'is_paid' => false,
        ], $overrides));

        $order->items()->create([
            'name' => 'Torta Test',
            'qty' => 1,
            'base_price' => 10000,
        ]);

        return $order;
    }

    // ──────────────────────────────────────────────
    // markAsPaid — PATCH /api/orders/{id}/mark-paid
    // ──────────────────────────────────────────────

    /** @test */
    public function test_mark_as_paid_retorna_order_resource()
    {
        $order = $this->createOrder();

        $response = $this->patchJson("/api/orders/{$order->id}/mark-paid");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'is_paid', 'deposit', 'client', 'items']])
            ->assertJsonPath('data.is_paid', true);
        // Deposit == total (SQLite retorna int, PostgreSQL retorna string decimal)
        $this->assertEquals(10000, $response->json('data.deposit'));
    }

    /** @test */
    public function test_mark_as_paid_actualiza_la_base_de_datos()
    {
        $order = $this->createOrder(['total' => 15000, 'deposit' => 3000]);

        $this->patchJson("/api/orders/{$order->id}/mark-paid");

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'is_paid' => true,
            'deposit' => 15000,
        ]);
    }

    /** @test */
    public function test_mark_as_paid_rechaza_si_ya_esta_pagado()
    {
        // Orden ya pagada: deposit >= total
        $order = $this->createOrder(['total' => 5000, 'deposit' => 5000]);

        $response = $this->patchJson("/api/orders/{$order->id}/mark-paid");

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────
    // markAsUnpaid — PATCH /api/orders/{id}/mark-unpaid
    // ──────────────────────────────────────────────

    /** @test */
    public function test_mark_as_unpaid_retorna_order_resource()
    {
        $order = $this->createOrder(['deposit' => 10000, 'is_paid' => true]);

        $response = $this->patchJson("/api/orders/{$order->id}/mark-unpaid");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'is_paid', 'deposit', 'client', 'items']])
            ->assertJsonPath('data.is_paid', false);
    }

    /** @test */
    public function test_mark_as_unpaid_resetea_deposito_si_era_igual_al_total()
    {
        $order = $this->createOrder(['total' => 8000, 'deposit' => 8000, 'is_paid' => true]);

        $this->patchJson("/api/orders/{$order->id}/mark-unpaid");

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'is_paid' => false,
            'deposit' => 0,
        ]);
    }

    /** @test */
    public function test_mark_as_unpaid_conserva_deposito_parcial()
    {
        // Si tenía un depósito parcial (< total), no se resetea a 0
        $order = $this->createOrder(['total' => 10000, 'deposit' => 3000, 'is_paid' => false]);

        $this->patchJson("/api/orders/{$order->id}/mark-unpaid");

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'is_paid' => false,
            'deposit' => 3000, // Depósito parcial no se toca
        ]);
    }

    // ──────────────────────────────────────────────
    // updateStatus — consistencia con is_fully_paid (B5.5)
    // ──────────────────────────────────────────────

    /** @test */
    public function test_update_status_con_is_fully_paid_setea_is_paid_true()
    {
        $order = $this->createOrder(['total' => 12000, 'deposit' => 0, 'is_paid' => false]);

        $response = $this->patchJson("/api/orders/{$order->id}/status", [
            'is_fully_paid' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_paid', true);
        // Deposit == total (SQLite retorna int, PostgreSQL retorna string decimal)
        $this->assertEquals(12000, $response->json('data.deposit'));

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'is_paid' => true,
            'deposit' => 12000,
        ]);
    }

    /** @test */
    public function test_los_items_en_la_respuesta_no_incluyen_order_id()
    {
        $order = $this->createOrder();

        $response = $this->patchJson("/api/orders/{$order->id}/mark-paid");

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertNotEmpty($items);

        // Verificar que order_id fue eliminado de los ítems (fix B3: OrderItemResource)
        $this->assertArrayNotHasKey('order_id', $items[0]);
    }
}
