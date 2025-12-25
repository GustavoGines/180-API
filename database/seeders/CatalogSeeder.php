<?php

namespace Database\Seeders;

use App\Models\Extra;
use App\Models\Filling;
use App\Models\Product;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Fillings
        $fillings = [
            ['name' => 'Dulce de leche', 'price_per_kg' => 0, 'is_free' => true],
            ['name' => 'Dulce de leche con merenguitos', 'price_per_kg' => 0, 'is_free' => true],
            ['name' => 'Crema Chantilly', 'price_per_kg' => 0, 'is_free' => true],
            ['name' => 'Crema Cookie', 'price_per_kg' => 0, 'is_free' => true],
            ['name' => 'Crema Moka, con café', 'price_per_kg' => 0, 'is_free' => true],
            ['name' => 'Mousse de chocolate', 'price_per_kg' => 2000, 'is_free' => false],
            ['name' => 'Mousse de frutilla', 'price_per_kg' => 2000, 'is_free' => false],
        ];

        foreach ($fillings as $f) {
            Filling::create($f);
        }

        // 2. Extras
        $extras = [
            ['name' => 'Nueces', 'price' => 2000, 'price_type' => 'per_kg'],
            ['name' => 'Oreos', 'price' => 1000, 'price_type' => 'per_kg'],
            ['name' => 'Chips de chocolate', 'price' => 1000, 'price_type' => 'per_kg'],
            ['name' => 'Cerezas', 'price' => 1500, 'price_type' => 'per_kg'],
            ['name' => 'Mani Tostado', 'price' => 1000, 'price_type' => 'per_kg'],
            ['name' => 'Bon o Bon', 'price' => 600, 'price_type' => 'per_unit'],
            ['name' => 'Alfajor Tatín triple (blanco)', 'price' => 1000, 'price_type' => 'per_unit'],
            ['name' => 'Alfajor Tatín triple (negro)', 'price' => 1000, 'price_type' => 'per_unit'],
            ['name' => 'Turrón Arcor', 'price' => 500, 'price_type' => 'per_unit'],
            ['name' => 'Obleas Opera', 'price' => 1000, 'price_type' => 'per_unit'],
            ['name' => 'Lámina Comestible', 'price' => 2500, 'price_type' => 'per_unit'],
            ['name' => 'Papel Fotográfico', 'price' => 1500, 'price_type' => 'per_unit'],
        ];

        foreach ($extras as $e) {
            Extra::create($e);
        }

        // 3. Products

        // Helper
        $createProduct = function ($data, $variants = []) {
            $p = Product::create($data);
            if (! empty($variants)) {
                $p->variants()->createMany($variants);
            }
        };

        // --- BOXES ---
        $createProduct([
            'name' => 'BOX DULCE Personalizado (Armar)', 'category' => 'box', 'unit_type' => 'unit', 'base_price' => 0,
        ]);
        $createProduct([
            'name' => 'BOX DULCE: Tartas Frutales (Solo Duraznos)', 'category' => 'box', 'unit_type' => 'unit', 'base_price' => 13350,
        ]);
        $createProduct([
            'name' => 'BOX DULCE: Tartas Frutales (Frutillas y Duraznos)', 'category' => 'box', 'unit_type' => 'unit', 'base_price' => 16350,
        ]);
        $createProduct([
            'name' => 'BOX DULCE: Romántico (Torta Corazones/Te Amo)', 'category' => 'box', 'unit_type' => 'unit', 'base_price' => 18700,
        ]);
        $createProduct([
            'name' => 'BOX DULCE: Drip Cake Temático (Choc. Azules)', 'category' => 'box', 'unit_type' => 'unit', 'base_price' => 19000,
        ]);
        $createProduct([
            'name' => 'BOX DULCE: Drip Cake (Oreo/Rosado) + Jugo', 'category' => 'box', 'unit_type' => 'unit', 'base_price' => 21800,
        ]);
        $createProduct([
            'name' => 'BOX DULCE: Cumpleañero (Torta/Taza)', 'category' => 'box', 'unit_type' => 'unit', 'base_price' => 21800,
        ]);

        // --- TORTAS (and small cakes) ---
        $createProduct([
            'name' => 'Micro Torta (Base)', 'category' => 'torta', 'unit_type' => 'kg', 'base_price' => 4500,
        ]);
        $createProduct([
            'name' => 'Mini Torta Personalizada (Base)', 'category' => 'torta', 'unit_type' => 'kg', 'base_price' => 8500,
        ]);
        $createProduct([
            'name' => 'Torta Base (1 kg)', 'category' => 'torta', 'unit_type' => 'kg', 'base_price' => 15500,
        ]);
        $createProduct([
            'name' => 'Torta Decorada con Crema Chantilly', 'category' => 'torta', 'unit_type' => 'kg', 'base_price' => 15500,
        ]);
        $createProduct([
            'name' => 'Torta con Galletitas/Chocolates/Cerezas', 'category' => 'torta', 'unit_type' => 'kg', 'base_price' => 18500,
        ]);
        $createProduct([
            'name' => 'Torta con Ganache (Negro/Blanco)', 'category' => 'torta', 'unit_type' => 'kg', 'base_price' => 19000,
        ]);
        $createProduct([
            'name' => 'Torta Cubierta de Fondant', 'category' => 'torta', 'unit_type' => 'kg', 'base_price' => 20000,
        ]);

        // --- MESA DULCE ---
        // Bizcochuelos
        $createProduct([
            'name' => 'Bizcochuelo Vainilla', 'category' => 'mesaDulce', 'unit_type' => 'unit', 'base_price' => 0,
        ], [
            ['variant_name' => 'size20cm', 'price' => 4000],
            ['variant_name' => 'size24cm', 'price' => 5800],
        ]);
        $createProduct([
            'name' => 'Bizcochuelo Chocolate', 'category' => 'mesaDulce', 'unit_type' => 'unit', 'base_price' => 0,
        ], [
            ['variant_name' => 'size20cm', 'price' => 4500],
            ['variant_name' => 'size24cm', 'price' => 6500],
        ]);

        // Alfajores
        $createProduct([
            'name' => 'Alfajores de Maicena Común', 'category' => 'mesaDulce', 'unit_type' => 'dozen',
            'base_price' => 6000, 'allow_half_dozen' => true, 'half_dozen_price' => 3000,
        ]);
        $createProduct([
            'name' => 'Alfajores de Maicena de Colores', 'category' => 'mesaDulce', 'unit_type' => 'dozen',
            'base_price' => 7000, 'allow_half_dozen' => true, 'half_dozen_price' => 3500,
        ]);
        $createProduct([
            'name' => 'Alfajores Bañados', 'category' => 'mesaDulce', 'unit_type' => 'dozen',
            'base_price' => 8000, 'allow_half_dozen' => true, 'half_dozen_price' => 4000,
        ]);

        // Brownies
        $createProduct([
            'name' => 'Brownies 5x5cm', 'category' => 'mesaDulce', 'unit_type' => 'dozen',
            'base_price' => 14200, 'allow_half_dozen' => true, 'half_dozen_price' => 7100,
        ]);
        $createProduct([
            'name' => 'Brownies 6x6cm', 'category' => 'mesaDulce', 'unit_type' => 'dozen',
            'base_price' => 20400, 'allow_half_dozen' => true, 'half_dozen_price' => 10200,
        ]);
        $createProduct([
            'name' => 'Brownie Redondo', 'category' => 'mesaDulce', 'unit_type' => 'unit', 'base_price' => 0,
        ], [
            ['variant_name' => 'size18cm', 'price' => 12000],
            ['variant_name' => 'size24cm', 'price' => 21200],
        ]);

        // Others
        $createProduct([
            'name' => 'Postres en Vasitos (Surtidos)', 'category' => 'mesaDulce', 'unit_type' => 'dozen',
            'base_price' => 17000, 'allow_half_dozen' => true, 'half_dozen_price' => 8500,
        ]);
        $createProduct([
            'name' => 'Cupcakes Personalizados', 'category' => 'mesaDulce', 'unit_type' => 'dozen',
            'base_price' => 12000, 'allow_half_dozen' => true, 'half_dozen_price' => 6000,
        ]);
        $createProduct([
            'name' => 'Chocooreos', 'category' => 'mesaDulce', 'unit_type' => 'dozen',
            'base_price' => 12000, 'allow_half_dozen' => true, 'half_dozen_price' => 6000,
        ]);
        $createProduct([
            'name' => 'Galletitas Decoradas Fondant', 'category' => 'mesaDulce', 'unit_type' => 'dozen',
            'base_price' => 18000, 'allow_half_dozen' => true, 'half_dozen_price' => 9000,
        ]);

        // Tartas
        $createProduct([
            'name' => 'Tarta con Durazno', 'category' => 'mesaDulce', 'unit_type' => 'unit', 'base_price' => 0,
        ], [
            ['variant_name' => 'size12cm', 'price' => 3500],
            ['variant_name' => 'size18cm', 'price' => 8000],
            ['variant_name' => 'size24cm', 'price' => 14000],
        ]);
        $createProduct([
            'name' => 'Tarta con Durazno y Frutillas', 'category' => 'mesaDulce', 'unit_type' => 'unit', 'base_price' => 0,
        ], [
            ['variant_name' => 'size12cm', 'price' => 4500],
            ['variant_name' => 'size18cm', 'price' => 10000],
            ['variant_name' => 'size24cm', 'price' => 18000],
        ]);
        $createProduct([
            'name' => 'Tarta con Frutillas', 'category' => 'mesaDulce', 'unit_type' => 'unit', 'base_price' => 0,
        ], [
            ['variant_name' => 'size12cm', 'price' => 6000],
            ['variant_name' => 'size18cm', 'price' => 13500],
            ['variant_name' => 'size24cm', 'price' => 24000],
        ]);
        $createProduct([
            'name' => 'Tarta Toffi', 'category' => 'mesaDulce', 'unit_type' => 'unit', 'base_price' => 0,
        ], [
            ['variant_name' => 'size12cm', 'price' => 3500],
            ['variant_name' => 'size18cm', 'price' => 8000],
            ['variant_name' => 'size24cm', 'price' => 14000],
        ]);

        // Pastafrola & Frolitas
        $createProduct([
            'name' => 'Pastafrola', 'category' => 'mesaDulce', 'unit_type' => 'unit', 'base_price' => 0,
        ], [
            ['variant_name' => 'size12cm', 'price' => 2500],
            ['variant_name' => 'size18cm', 'price' => 5600],
            ['variant_name' => 'size24cm', 'price' => 10000],
        ]);
        $createProduct([
            'name' => 'Frolitas (10cm)', 'category' => 'mesaDulce', 'unit_type' => 'dozen',
            'base_price' => 20400, 'allow_half_dozen' => true, 'half_dozen_price' => 10200,
        ]);

        // Bandejas
        $createProduct([
            'name' => 'Bandeja 25x25 cm', 'category' => 'mesaDulce', 'unit_type' => 'unit', 'base_price' => 1800,
        ]);
        $createProduct([
            'name' => 'Bandeja 30x30 cm', 'category' => 'mesaDulce', 'unit_type' => 'unit', 'base_price' => 2000,
        ]);
    }
}
