<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExtraResource;
use App\Http\Resources\FillingResource;
use App\Http\Resources\ProductResource;
use App\Models\Extra;
use App\Models\Filling;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class CatalogController extends Controller
{
    /**
     * Clave centralizada de caché del catálogo.
     * AdminCatalogController la usa para invalidar en cada mutación.
     */
    public const CACHE_KEY = 'catalog_v1';

    /**
     * TTL del caché en segundos (1 hora).
     */
    private const CACHE_TTL = 3600;

    public function index()
    {
        $data = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            // 1. Productos activos con sus variantes
            $products = Product::where('is_active', true)
                ->orderBy('name', 'asc')
                ->with(['variants' => fn ($q) => $q->orderBy('variant_name', 'asc')])
                ->get();

            // 2. Rellenos activos
            $fillings = Filling::where('is_active', true)
                ->orderBy('name', 'asc')
                ->get();

            // 3. Extras activos
            $extras = Extra::where('is_active', true)
                ->orderBy('name', 'asc')
                ->get();

            return [
                'products' => ProductResource::collection($products),
                'fillings' => FillingResource::collection($fillings),
                'extras'   => ExtraResource::collection($extras),
            ];
        });

        return response()->json([
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'version'   => '1.0',
            ],
            'data' => $data,
        ]);
    }
}
