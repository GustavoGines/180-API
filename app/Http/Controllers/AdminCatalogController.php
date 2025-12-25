<?php

namespace App\Http\Controllers;

use App\Models\Extra;
use App\Models\Filling;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AdminCatalogController extends Controller
{
    // --- PRODUCTS ---

    public function storeProduct(Request $request)
    {
        Gate::authorize('admin'); // Ensure user is admin

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string',
            'description' => 'nullable|string',
            'base_price' => 'required|numeric',
            'unit_type' => 'required|string',
            'allow_half_dozen' => 'boolean',
            'half_dozen_price' => 'nullable|numeric',
            'multiplier_adjustment_per_kg' => 'nullable|numeric',
            'variants' => 'array',
            'variants.*.variant_name' => 'required|string',
            'variants.*.price' => 'required|numeric',
        ]);

        return DB::transaction(function () use ($validated) {
            $productData = collect($validated)->except('variants')->toArray();
            $product = Product::create($productData);

            if (! empty($validated['variants'])) {
                $product->variants()->createMany($validated['variants']);
            }

            return response()->json(['message' => 'Product created', 'data' => $product->load('variants')], 201);
        });
    }

    public function updateProduct(Request $request, $id)
    {
        Gate::authorize('admin');

        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string',
            'description' => 'nullable|string',
            'base_price' => 'required|numeric',
            'unit_type' => 'required|string',
            'allow_half_dozen' => 'boolean',
            'half_dozen_price' => 'nullable|numeric',
            'multiplier_adjustment_per_kg' => 'nullable|numeric',
            'variants' => 'array',
            'variants.*.id' => 'nullable|integer', // ID exists if updating
            'variants.*.variant_name' => 'required|string',
            'variants.*.price' => 'required|numeric',
        ]);

        DB::transaction(function () use ($product, $validated) {
            $productData = collect($validated)->except('variants')->toArray();
            $product->update($productData);

            // Sync variants: Delete missing, update existing, create new
            if (isset($validated['variants'])) {
                // 1. Get IDs of variants kept in the request
                $keptIds = collect($validated['variants'])
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                // 2. Delete variants not in the kept list
                $product->variants()->whereNotIn('id', $keptIds)->delete();

                // 3. Update or Create
                foreach ($validated['variants'] as $vData) {
                    if (isset($vData['id'])) {
                        $product->variants()->where('id', $vData['id'])->update([
                            'variant_name' => $vData['variant_name'],
                            'price' => $vData['price'],
                        ]);
                    } else {
                        $product->variants()->create([
                            'variant_name' => $vData['variant_name'],
                            'price' => $vData['price'],
                        ]);
                    }
                }
            } else {
                // If variants key is missing (explicitly sent as empty or not sent in a way we want to clear?),
                // typically we expect it to be an array. If empty array, clear all.
                // Assuming specific "variants" key is present to update them.
                $product->variants()->delete();
            }
        });

        return response()->json(['message' => 'Product updated', 'data' => $product->load('variants')]);
    }

    public function destroyProduct($id)
    {
        Gate::authorize('admin');
        $product = Product::findOrFail($id);
        $product->delete(); // This assumes cascading deletes or soft deletes implementation if needed.
        // For hard delete with relationships, ensure migration has onDelete('cascade')

        return response()->json(['message' => 'Product deleted']);
    }

    // --- FILLINGS ---

    public function storeFilling(Request $request)
    {
        Gate::authorize('admin');
        $validated = $request->validate([
            'name' => 'required|string',
            'price_per_kg' => 'required|numeric',
            'is_free' => 'boolean',
        ]);

        $filling = Filling::create($validated);

        return response()->json(['message' => 'Filling created', 'data' => $filling], 201);
    }

    public function updateFilling(Request $request, $id)
    {
        Gate::authorize('admin');
        $filling = Filling::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string',
            'price_per_kg' => 'required|numeric',
            'is_free' => 'boolean',
        ]);
        $filling->update($validated);

        return response()->json(['message' => 'Filling updated', 'data' => $filling]);
    }

    public function destroyFilling($id)
    {
        Gate::authorize('admin');
        Filling::findOrFail($id)->delete();

        return response()->json(['message' => 'Filling deleted']);
    }

    // --- EXTRAS ---
    public function storeExtra(Request $request)
    {
        Gate::authorize('admin');
        $validated = $request->validate([
            'name' => 'required|string',
            'price' => 'required|numeric',
            'price_type' => 'required|in:per_unit,per_kg',
        ]);
        $extra = Extra::create($validated);

        return response()->json(['message' => 'Extra created', 'data' => $extra], 201);
    }

    public function updateExtra(Request $request, $id)
    {
        Gate::authorize('admin');
        $extra = Extra::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string',
            'price' => 'required|numeric',
            'price_type' => 'required|in:per_unit,per_kg',
        ]);
        $extra->update($validated);

        return response()->json(['message' => 'Extra updated', 'data' => $extra]);
    }

    public function destroyExtra($id)
    {
        Gate::authorize('admin');
        Extra::findOrFail($id)->delete();

        return response()->json(['message' => 'Extra deleted']);
    }
}
