use App\Http\Resources\ExtraResource;
use App\Http\Resources\FillingResource;
use App\Http\Resources\ProductResource;

// ...

    public function index()
    {
        // 1. Productos Activos con sus Variantes
        $products = Product::where('is_active', true)
            ->orderBy('name', 'asc')
            ->with(['variants' => function ($q) {
                $q->orderBy('variant_name', 'asc');
            }])
            ->get();

        // 2. Rellenos Activos
        $fillings = Filling::where('is_active', true)
            ->orderBy('name', 'asc')
            ->get();

        // 3. Extras Activos
        $extras = Extra::where('is_active', true)
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'version' => '1.0',
            ],
            // ⚠️ IMPORTANTE: 'data' sigue siendo el objeto raíz que contiene las listas.
            // Pero cada lista ahora usa su Resource::collection()
            'data' => [
                'products' => ProductResource::collection($products)->resolve(),
                'fillings' => FillingResource::collection($fillings)->resolve(),
                'extras' => ExtraResource::collection($extras)->resolve(),
            ],
        ]);
    }
