<?php

namespace App\Http\Controllers;

use App\Models\Extra;
use App\Models\Filling;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Http\Resources\ProductResource;
use App\Http\Resources\FillingResource;
use App\Http\Resources\ExtraResource;

class AdminCatalogController extends Controller
{
    // --- PRODUCTS ---

    public function storeProduct(Request $request)
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'name'                          => 'required|string|max:255',
            'category'                      => 'required|string',
            'description'                   => 'nullable|string',
            'base_price'                    => 'required|numeric',
            'unit_type'                     => 'required|string',
            'allow_half_dozen'              => 'boolean',
            'half_dozen_price'              => 'nullable|numeric',
            'multiplier_adjustment_per_kg'  => 'nullable|numeric',
            'variants'                      => 'array',
            'variants.*.variant_name'       => 'required|string',
            'variants.*.price'              => 'required|numeric',
        ]);

        $result = DB::transaction(function () use ($validated) {
            $productData = collect($validated)->except('variants')->toArray();
            $product = Product::create($productData);

            if (! empty($validated['variants'])) {
                $product->variants()->createMany($validated['variants']);
            }

            return response()->json(['message' => 'Product created', 'data' => new ProductResource($product->load('variants'))], 201);
        });

        Cache::forget(CatalogController::CACHE_KEY);

        return $result;
    }

    public function updateProduct(Request $request, $id)
    {
        Gate::authorize('admin');

        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'name'                          => 'required|string|max:255',
            'category'                      => 'required|string',
            'description'                   => 'nullable|string',
            'base_price'                    => 'required|numeric',
            'unit_type'                     => 'required|string',
            'allow_half_dozen'              => 'boolean',
            'half_dozen_price'              => 'nullable|numeric',
            'multiplier_adjustment_per_kg'  => 'nullable|numeric',
            'variants'                      => 'array',
            'variants.*.id'                 => 'nullable|integer',
            'variants.*.variant_name'       => 'required|string',
            'variants.*.price'              => 'required|numeric',
        ]);

        DB::transaction(function () use ($product, $validated) {
            $productData = collect($validated)->except('variants')->toArray();
            $product->update($productData);

            // Sync variants solo si el campo viene explícitamente en el request
            if (isset($validated['variants'])) {
                // 1. IDs de variantes que se conservan
                $keptIds = collect($validated['variants'])
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                // 2. Borrar las que no están en la lista
                $product->variants()->whereNotIn('id', $keptIds)->delete();

                // 3. Actualizar o crear
                foreach ($validated['variants'] as $vData) {
                    if (isset($vData['id'])) {
                        $product->variants()->where('id', $vData['id'])->update([
                            'variant_name' => $vData['variant_name'],
                            'price'        => $vData['price'],
                        ]);
                    } else {
                        $product->variants()->create([
                            'variant_name' => $vData['variant_name'],
                            'price'        => $vData['price'],
                        ]);
                    }
                }
            } // Si 'variants' no viene en el request, no se tocan las variantes existentes.
        });

        Cache::forget(CatalogController::CACHE_KEY);

        return response()->json(['message' => 'Product updated', 'data' => new ProductResource($product->load('variants'))]);
    }

    public function destroyProduct($id)
    {
        Gate::authorize('admin');

        Product::findOrFail($id)->delete();

        Cache::forget(CatalogController::CACHE_KEY);

        return response()->json(['message' => 'Product deleted']);
    }

    // --- FILLINGS ---

    public function storeFilling(Request $request)
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'name'          => 'required|string',
            'price_per_kg'  => 'required|numeric',
            'is_free'       => 'boolean',
        ]);

        $filling = Filling::create($validated);

        Cache::forget(CatalogController::CACHE_KEY);

        return response()->json(['message' => 'Filling created', 'data' => new FillingResource($filling)], 201);
    }

    public function updateFilling(Request $request, $id)
    {
        Gate::authorize('admin');

        $filling = Filling::findOrFail($id);

        $validated = $request->validate([
            'name'          => 'required|string',
            'price_per_kg'  => 'required|numeric',
            'is_free'       => 'boolean',
        ]);

        $filling->update($validated);

        Cache::forget(CatalogController::CACHE_KEY);

        return response()->json(['message' => 'Filling updated', 'data' => new FillingResource($filling)]);
    }

    public function destroyFilling($id)
    {
        Gate::authorize('admin');

        Filling::findOrFail($id)->delete();

        Cache::forget(CatalogController::CACHE_KEY);

        return response()->json(['message' => 'Filling deleted']);
    }

    // --- EXTRAS ---

    public function storeExtra(Request $request)
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'name'       => 'required|string',
            'price'      => 'required|numeric',
            'price_type' => 'required|in:per_unit,per_kg',
        ]);

        $extra = Extra::create($validated);

        Cache::forget(CatalogController::CACHE_KEY);

        return response()->json(['message' => 'Extra created', 'data' => new ExtraResource($extra)], 201);
    }

    public function updateExtra(Request $request, $id)
    {
        Gate::authorize('admin');

        $extra = Extra::findOrFail($id);

        $validated = $request->validate([
            'name'       => 'required|string',
            'price'      => 'required|numeric',
            'price_type' => 'required|in:per_unit,per_kg',
        ]);

        $extra->update($validated);

        Cache::forget(CatalogController::CACHE_KEY);

        return response()->json(['message' => 'Extra updated', 'data' => new ExtraResource($extra)]);
    }

    public function destroyExtra($id)
    {
        Gate::authorize('admin');

        Extra::findOrFail($id)->delete();

        Cache::forget(CatalogController::CACHE_KEY);

        return response()->json(['message' => 'Extra deleted']);
    }
}
