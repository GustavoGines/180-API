<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeviceController extends Controller
{
    /**
     * POST /api/devices/register
     * Guarda o actualiza el token FCM del usuario autenticado.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'fcm_token' => ['required', 'string'],
            'platform'  => ['nullable', 'in:android,ios'],
        ]);

        $device = Device::updateOrCreate(
            [
                'user_id'   => $request->user()->id,
                'fcm_token' => $validated['fcm_token'],
            ],
            [
                'platform'     => $validated['platform'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        return response()->json($device, Response::HTTP_CREATED);
    }
}

