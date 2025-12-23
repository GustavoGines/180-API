<?php

namespace App\Http\Controllers;

use App\Models\Extra;
use App\Models\Filling;
use App\Models\Product;

class CatalogController extends Controller
{
    /**
     * Retorna el catálogo completo para la app.
     * Incluye Productos, Rellenos y Extras activos.
     */
    public function index()
    {
        // 1. Productos Activos con sus Variantes
        $products = Product::where('is_active', true)
            ->orderBy('name', 'asc') // Alfabético
            ->with(['variants' => function ($q) {
                $q->orderBy('variant_name', 'asc'); // Variantes también ordenadas
            }])
            ->get();

        // 2. Rellenos Activos
        $fillings = Filling::where('is_active', true)
            ->orderBy('name', 'asc') // Alfabético
            ->get();

        // 3. Extras Activos
        $extras = Extra::where('is_active', true)
            ->orderBy('name', 'asc') // Alfabético
            ->get();

        return response()->json([
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'version' => '1.0',
            ],
            'data' => [
                'products' => $products,
                'fillings' => $fillings,
                'extras' => $extras,
            ],
        ]);
    }
}
