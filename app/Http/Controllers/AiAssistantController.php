<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Client;
use Illuminate\Support\Str;

class AiAssistantController extends Controller
{
    public function processVoice(Request $request)
    {
        $request->validate([
            'audio' => 'required|file|mimes:m4a,mp3,wav,ogg,mp4,webm',
        ]);

        $audioFile = $request->file('audio');

        // 1. Transcribe audio using Whisper
        $transcription = $this->transcribeAudio($audioFile);

        if (!$transcription || isset($transcription['error'])) {
            return response()->json(['error' => 'No se pudo procesar el audio'], 500);
        }

        $text = $transcription['text'];

        // 2. Classify intent and extract entities
        $intentData = $this->analyzeIntent($text);

        if (!isset($intentData['intent'])) {
            return response()->json(['error' => 'No se pudo comprender la intención'], 500);
        }

        // 3. Process according to intent
        $responseData = [
            'transcription' => $text,
            'intent' => $intentData['intent'],
        ];

        if ($intentData['intent'] === 'create_order') {
            $parsedItems = [];
            $products = Product::all();

            foreach ($intentData['items'] as $item) {
                $matchedProduct = $this->findBestMatch($item['product_name'], $products, 'name', 45);
                $parsedItems[] = [
                    'product_id' => $matchedProduct ? $matchedProduct->id : null,
                    'original_name' => $item['product_name'],
                    'matched_name' => $matchedProduct ? $matchedProduct->name : null,
                    'quantity' => $item['quantity'] ?? 1,
                    'notes' => $item['notes'] ?? '',
                ];
            }

            // Determine if the client exists
            $clientName = $intentData['client_name'] ?? null;
            $isNewClient = true;
            
            if ($clientName) {
                $clients = Client::select('id', 'name')->get();
                $existingClient = $this->findBestMatch($clientName, $clients, 'name', 70);
                if ($existingClient) {
                    $isNewClient = false;
                    $responseData['client_id'] = $existingClient->id;
                }
            }

            $responseData['items'] = $parsedItems;
            $responseData['client_name'] = $clientName;
            $responseData['is_new_client'] = $isNewClient;
            $responseData['event_date'] = $intentData['event_date'] ?? null;
        }

        return response()->json([
            'data' => $responseData
        ]);
    }

    private function transcribeAudio($file)
    {
        $response = Http::withoutVerifying()
            ->withToken(env('OPENAI_API_KEY'))
            ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => 'whisper-1',
                'language' => 'es'
            ]);

        return $response->json();
    }

    private function analyzeIntent(string $text)
    {
        $today = now()->format('Y-m-d');
        $systemPrompt = "Eres el asistente inteligente del mostrador de una pastelería. Hoy es $today.
Tu trabajo es escuchar la transcripción del cliente y clasificarla en uno de los siguientes intents:
- create_order: Cuando el usuario está dictando un pedido para agendar.
- query_orders: Cuando el usuario pregunta por ventas, facturación o pedidos de un cliente.
- unknown: Si no se entiende o es irrelevante.

Si el intent es 'create_order', extrae los siguientes datos:
- 'client_name': El nombre del cliente.
- 'event_date': La fecha para cuándo es el pedido (formato YYYY-MM-DD).
- 'items': Un array con los productos pedidos.
  Para cada item:
  - 'product_name': Extrae ÚNICAMENTE el nombre principal, base o comercial del producto (ej: 'Torta Chantilly', 'Torta Rogel', 'Alfajores'). NO incluyas bajo ninguna circunstancia detalles de rellenos, sabores adicionales o coberturas largas acá.
  - 'quantity': Cantidad (número).
  - 'notes': Si el usuario especifica rellenos, coberturas o detalles (ej: 'relleno de frutilla'), ponlo aquí.

Responde SIEMPRE en formato JSON estricto.";

        $response = Http::withoutVerifying()
            ->withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $text]
                ]
            ]);

        $content = $response->json()['choices'][0]['message']['content'] ?? '{}';
        return json_decode($content, true);
    }

    /**
     * Motor de búsqueda algorítmica para encontrar la mejor coincidencia.
     */
    private function findBestMatch(string $search, $collection, string $field, int $minPercentage)
    {
        $bestMatch = null;
        $highestPercentage = 0;
        
        $searchNormalized = Str::ascii(Str::lower($search));

        foreach ($collection as $item) {
            $target = Str::ascii(Str::lower($item->{$field}));
            
            if (strlen($searchNormalized) == 0 || strlen($target) == 0) continue;
            
            similar_text($searchNormalized, $target, $percent);
            
            if (str_contains($target, $searchNormalized) || str_contains($searchNormalized, $target)) {
                $percent += 20; 
            }

            if ($percent > $highestPercentage) {
                $highestPercentage = $percent;
                $bestMatch = $item;
            }
        }
        
        return $highestPercentage >= $minPercentage ? $bestMatch : null;
    }
}
