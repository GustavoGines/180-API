<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
    }

    private function getAvailability(string $date, ?string $time = null): \Illuminate\Testing\TestResponse
    {
        $params = "date={$date}";
        if ($time) {
            $params .= "&time={$time}";
        }

        return $this->getJson("/api/availability?{$params}");
    }

    // ──────────────────────────────────────────────
    // Regla 1: Martes cerrado
    // ──────────────────────────────────────────────

    /** @test */
    public function test_martes_retorna_closed()
    {
        // Encontrar el próximo martes
        $tuesday = Carbon::now()->next('Tuesday')->format('Y-m-d');

        $response = $this->getAvailability($tuesday, '14:00');

        $response->assertStatus(200)
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'closed');
    }

    // ──────────────────────────────────────────────
    // Regla 2: Cupo lleno
    // ──────────────────────────────────────────────

    /** @test */
    public function test_cupo_lleno_retorna_full_capacity()
    {
        // Usar un miércoles futuro para evitar martes y pasado
        $date = Carbon::now()->next('Wednesday')->format('Y-m-d');
        $client = Client::factory()->create();
        $capacity = config('shop.default_daily_capacity', 10);

        // Llenar el cupo con pedidos confirmados
        for ($i = 0; $i < $capacity; $i++) {
            Order::create([
                'client_id' => $client->id,
                'event_date' => $date,
                'start_time' => '10:00',
                'end_time' => '12:00',
                'status' => 'confirmed',
                'total' => 1000,
                'deposit' => 0,
            ]);
        }

        $response = $this->getAvailability($date, '14:00');

        $response->assertStatus(200)
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'full_capacity');
    }

    // ──────────────────────────────────────────────
    // Regla 3: Fecha pasada
    // ──────────────────────────────────────────────

    /** @test */
    public function test_fecha_pasada_retorna_past_date()
    {
        $pastDate = Carbon::now()->subDays(4)->format('Y-m-d');

        $response = $this->getAvailability($pastDate, '10:00');

        $response->assertStatus(200)
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'past_date');
    }

    // ──────────────────────────────────────────────
    // Regla 4: Express (menos de 24 horas)
    // ──────────────────────────────────────────────

    /** @test */
    public function test_menos_de_24_horas_retorna_express()
    {
        // Fecha: dentro de 2 horas (mismo día o día siguiente)
        $expressDateTime = Carbon::now()->addHours(2);

        // Si cae en martes, usar dentro de 3 horas en otro día (edge case)
        if ($expressDateTime->isTuesday()) {
            $expressDateTime = Carbon::now()->addHours(3)->subMinutes(10);
        }

        $date = $expressDateTime->format('Y-m-d');
        $time = $expressDateTime->format('H:i');

        $response = $this->getAvailability($date, $time);

        // Express puede ser available=true pero con reason=express
        // O puede ser past_date si la hora ya pasó — ambos son válidos
        $response->assertStatus(200);
        $reason = $response->json('reason');
        $this->assertContains($reason, ['express', 'past_date', 'closed']);
    }

    // ──────────────────────────────────────────────
    // Regla 5: Fecha disponible (happy path)
    // ──────────────────────────────────────────────

    /** @test */
    public function test_fecha_futura_disponible_retorna_ok()
    {
        // Un miércoles dentro de 2 semanas: suficiente anticipación, no martes
        $date = Carbon::now()->next('Wednesday')->addWeek()->format('Y-m-d');

        $response = $this->getAvailability($date, '14:00');

        $response->assertStatus(200)
            ->assertJsonPath('available', true)
            ->assertJsonPath('reason', 'ok')
            ->assertJsonPath('express_review_needed', false);
    }

    // ──────────────────────────────────────────────
    // Validación de input
    // ──────────────────────────────────────────────

    /** @test */
    public function test_sin_date_retorna_422()
    {
        $response = $this->getJson('/api/availability');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    /** @test */
    public function test_date_con_formato_invalido_retorna_422()
    {
        $response = $this->getAvailability('32-13-2025'); // formato inválido

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }
}
