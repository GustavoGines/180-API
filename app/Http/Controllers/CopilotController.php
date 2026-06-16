<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class CopilotController extends Controller
{
    public function process(Request $request)
    {
        $inputMessages = $request->input('messages');
        
        if (!$inputMessages || !is_array($inputMessages)) {
            return response()->json(['error' => 'Se requiere un array de mensajes.'], 400);
        }

        $today = now()->format('Y-m-d');
        
        $messages = [
            ['role' => 'system', 'content' => 'Eres Copiloto 180, el asistente inteligente de una pastelería. Hoy es ' . $today . '. Usa esta fecha como referencia para resolver palabras como "hoy" o "mañana". Tu trabajo es ayudar a los dueños a gestionar el negocio usando las herramientas disponibles. 
IMPORTANTE: Debes responder SIEMPRE con una estructura JSON estricta. El formato debe ser:
{
  "reply": "Texto amigable para el usuario",
  "ui_widget": {
    "type": "order_card", // o null si es solo una charla normal
    "data": {
      "title": "Pedido #123",
      "subtitle": "Cliente: Nombre",
      "total": "$5000"
    }
  }
}
Si la conversación es casual, usa type null.']
        ];
        
        $messages = array_merge($messages, $inputMessages);
        
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_daily_revenue',
                    'description' => 'Obtiene la facturación total de ventas para una fecha específica.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => [
                                'type' => 'string',
                                'description' => 'La fecha exacta en formato YYYY-MM-DD para la cual obtener la facturación.',
                            ]
                        ],
                        'required' => ['date']
                    ]
                ]
            ]
        ];

        // Primera llamada a OpenAI
        $response = $this->callOpenAI($messages, $tools);

        if (isset($response['error'])) {
             Log::error('OpenAI API Error: ' . json_encode($response['error']));
             return response()->json(['error' => 'Error al comunicarse con OpenAI.'], 502);
        }

        $responseMessage = $response['choices'][0]['message'];

        // Revisar si OpenAI quiere llamar a una tool
        if (isset($responseMessage['tool_calls'])) {
            $messages[] = $responseMessage; // Agregar el mensaje de la IA con los tool_calls al historial

            foreach ($responseMessage['tool_calls'] as $toolCall) {
                if ($toolCall['function']['name'] === 'get_daily_revenue') {
                    $args = json_decode($toolCall['function']['arguments'], true);
                    $date = $args['date'] ?? $today;
                    
                    // Ejecutar función real (Query DB)
                    $revenue = Order::whereDate('created_at', $date)->sum('total');

                    // Agregar el resultado al historial como 'tool'
                    $messages[] = [
                        'tool_call_id' => $toolCall['id'],
                        'role' => 'tool',
                        'name' => 'get_daily_revenue',
                        'content' => json_encode(['revenue' => (float)$revenue])
                    ];
                }
            }

            // Segunda llamada a OpenAI para que arme la respuesta amigable con la data
            $finalResponse = $this->callOpenAI($messages, $tools);
            $finalContent = $finalResponse['choices'][0]['message']['content'];
            
            $decodedContent = json_decode($finalContent, true) ?? [];
            
            return response()->json([
                'reply' => $decodedContent['reply'] ?? $finalContent,
                'ui_widget' => $decodedContent['ui_widget'] ?? null,
                'tool_used' => true, 
                'raw_tool_calls' => $responseMessage['tool_calls']
            ]);
        }

        // Si no usó tool, devuelve la respuesta directa
        $decodedContent = json_decode($responseMessage['content'], true) ?? [];
        return response()->json([
            'reply' => $decodedContent['reply'] ?? $responseMessage['content'],
            'ui_widget' => $decodedContent['ui_widget'] ?? null,
            'tool_used' => false
        ]);
    }

    private function callOpenAI(array $messages, array $tools = null)
    {
        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'response_format' => ['type' => 'json_object']
        ];
        
        if ($tools) {
            $payload['tools'] = $tools;
        }

        $response = Http::withoutVerifying()
            ->withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        return $response->json();
    }
}
