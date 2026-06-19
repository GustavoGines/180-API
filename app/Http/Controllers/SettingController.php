<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    /**
     * Devuelve todas las configuraciones en formato clave => valor tipado.
     */
    public function index()
    {
        $settings = Cache::rememberForever('app_settings', function () {
            return Setting::all();
        });

        $mapped = [];
        foreach ($settings as $setting) {
            $mapped[$setting->key] = $setting->typed_value;
        }

        return response()->json(['data' => $mapped]);
    }

    /**
     * Actualiza un lote de configuraciones (Solo Admin).
     */
    public function updateBatch(Request $request)
    {
        // Aseguramos que solo un admin pueda cambiar esto
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable',
            'settings.*.type' => 'sometimes|string|in:string,integer,boolean,float,decimal,json',
        ]);

        foreach ($validated['settings'] as $item) {
            // Si el valor es un booleano o array en PHP, lo convertimos a string/json para guardarlo
            $valueToSave = $item['value'];
            if (is_bool($valueToSave)) {
                $valueToSave = $valueToSave ? 'true' : 'false';
            } elseif (is_array($valueToSave)) {
                $valueToSave = json_encode($valueToSave);
            }

            Setting::updateOrCreate(
                ['key' => $item['key']],
                [
                    'value' => (string) $valueToSave,
                    'type' => $item['type'] ?? 'string',
                ]
            );
        }

        Cache::forget('app_settings');

        return response()->json(['message' => 'Configuraciones actualizadas correctamente']);
    }
}
