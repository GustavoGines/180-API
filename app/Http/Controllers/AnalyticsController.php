<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    // ─────────────────────────── Summary ────────────────────────────

    /**
     * GET /api/analytics/summary?year=2025&month=6
     *
     * Devuelve el resumen financiero del mes solicitado:
     *  - ingreso_realizado: suma de `total` de pedidos no cancelados Y pagados.
     *  - ingreso_pendiente: suma de `total` de pedidos activos Y no pagados.
     *  - total_pedidos:     cuenta de pedidos no cancelados en el período.
     *
     * Estrategia de caché:
     *  - Mes en curso  → TTL 5 min  (datos cambian frecuentemente).
     *  - Mes cerrado   → TTL 24 h   (datos inmutables).
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'year'  => 'sometimes|integer|min:2020|max:2099',
            'month' => 'sometimes|integer|min:1|max:12',
        ]);

        $year  = (int) $request->query('year',  now()->year);
        $month = (int) $request->query('month', now()->month);

        // TTL dinámico: corto si es el mes actual, largo si es histórico.
        $isCurrentMonth = ($year === (int) now()->year && $month === (int) now()->month);
        $ttl = $isCurrentMonth
            ? now()->addMinutes(5)
            : now()->addHours(24);

        $cacheKey = "analytics:summary:{$year}:{$month}";

        $data = Cache::remember($cacheKey, $ttl, function () use ($year, $month) {
            return $this->computeSummary($year, $month);
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Ejecuta las consultas SQL optimizadas para el resumen del mes.
     *
     * Se usa DB::select con una sola consulta condicional (FILTER / CASE WHEN)
     * para evitar 3 roundtrips a la base de datos.
     *
     * PostgreSQL soporta la sintaxis FILTER (WHERE ...) que es más legible.
     * Se usa YEAR() / MONTH() sobre `event_date` que está indexado.
     *
     * El filtro por `deleted_at IS NULL` es crítico porque el modelo usa SoftDeletes.
     */
    private function computeSummary(int $year, int $month): array
    {
        // Una sola query con tres agregaciones condicionales.
        // Filtramos primero por (year, month) usando el índice de event_date.
        $result = DB::selectOne("
            SELECT
                COALESCE(SUM(total) FILTER (WHERE status != 'canceled' AND is_paid = true),  0) AS ingreso_realizado,
                COALESCE(SUM(total) FILTER (WHERE status != 'canceled' AND is_paid = false), 0) AS ingreso_pendiente,
                COUNT(*) FILTER (WHERE status != 'canceled')                                   AS total_pedidos
            FROM orders
            WHERE
                EXTRACT(YEAR  FROM event_date) = :year
                AND EXTRACT(MONTH FROM event_date) = :month
                AND deleted_at IS NULL
        ", ['year' => $year, 'month' => $month]);

        return [
            'ingreso_realizado' => (float) ($result->ingreso_realizado ?? 0),
            'ingreso_pendiente' => (float) ($result->ingreso_pendiente ?? 0),
            'total_pedidos'     => (int)   ($result->total_pedidos     ?? 0),
            'year'              => $year,
            'month'             => $month,
        ];
    }

    // ─────────────────────────── Top Products ────────────────────────

    /**
     * GET /api/analytics/top-products?from=2025-01-01&to=2025-12-31&limit=5
     *
     * Devuelve el ranking de productos más vendidos en el rango de fechas.
     * Ordena por cantidad total vendida (desc) y también expone el revenue.
     *
     * Estrategia de caché:
     *  - Si el rango incluye el mes actual → TTL 15 min.
     *  - Si el rango es completamente histórico → TTL 24 h.
     */
    public function topProducts(Request $request): JsonResponse
    {
        $request->validate([
            'from'  => 'sometimes|date_format:Y-m-d',
            'to'    => 'sometimes|date_format:Y-m-d|after_or_equal:from',
            'limit' => 'sometimes|integer|min:1|max:20',
        ]);

        $from  = $request->query('from',  now()->startOfMonth()->toDateString());
        $to    = $request->query('to',    now()->endOfMonth()->toDateString());
        $limit = (int) $request->query('limit', 5);

        // TTL dinámico: ¿el rango incluye el mes actual?
        $rangeIncludesNow = now()->between($from . ' 00:00:00', $to . ' 23:59:59');
        $ttl = $rangeIncludesNow
            ? now()->addMinutes(15)
            : now()->addHours(24);

        $cacheKey = "analytics:top_products:{$from}:{$to}:{$limit}";

        $data = Cache::remember($cacheKey, $ttl, function () use ($from, $to, $limit) {
            return $this->computeTopProducts($from, $to, $limit);
        });

        return response()->json(['data' => ['items' => $data]]);
    }

    /**
     * Consulta optimizada para el Top N de productos.
     *
     * ESTRATEGIA CLAVE: Filtramos el subconjunto de orders PRIMERO (aprovechando
     * el índice en event_date y status), y solo luego hacemos el JOIN con
     * order_items. Esto evita un full scan de order_items.
     *
     * Precio de línea = (base_price + adjustments) * qty
     */
    private function computeTopProducts(string $from, string $to, int $limit): array
    {
        $rows = DB::select("
            SELECT
                oi.name,
                SUM(oi.qty)                                              AS total_qty,
                SUM(oi.qty * (oi.base_price + oi.adjustments))          AS revenue
            FROM orders o
            INNER JOIN order_items oi ON oi.order_id = o.id
            WHERE
                o.event_date BETWEEN :from AND :to
                AND o.status != 'canceled'
                AND o.deleted_at IS NULL
            GROUP BY oi.name
            ORDER BY total_qty DESC
            LIMIT :limit
        ", ['from' => $from, 'to' => $to, 'limit' => $limit]);

        return array_map(fn ($row) => [
            'name'         => $row->name,
            'total_qty'    => (float) $row->total_qty,
            'revenue'      => (float) $row->revenue,
        ], $rows);
    }
}
