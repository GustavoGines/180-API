<?php

namespace App\Http\Controllers;

use App\Services\AiBrainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiAssistantController extends Controller
{
    protected AiBrainService $brainService;

    public function __construct(AiBrainService $brainService)
    {
        $this->brainService = $brainService;
    }

    public function processVoice(Request $request)
    {
        $request->validate([
            'audio' => 'required|file|mimes:m4a,mp3,wav,ogg,mp4,webm',
        ]);

        try {
            $audioFile = $request->file('audio');

            // 1. Transcribe audio using Whisper
            $transcription = $this->transcribeAudio($audioFile);

            if (! $transcription || isset($transcription['error'])) {
                return response()->json(['error' => 'Audio inválido o demasiado corto. Intenta de nuevo.'], 400);
            }

            $text = $transcription['text'] ?? '';
            if (empty($text)) {
                return response()->json([
                    'error' => 'No se detectó voz en el audio. Intenta hablar un poco más.'
                ], 400);
            }

            // 2. LLamar a OpenAI usando el AiBrainService para extraer el intent
            $messages = [
                ['role' => 'system', 'content' => $this->brainService->getSystemPrompt(true)],
                ['role' => 'user', 'content' => $text],
            ];

            $response = $this->brainService->callOpenAI($messages, $this->brainService->getTools(), false);

            if (isset($response['error'])) {
                return response()->json(['error' => 'No se pudo comprender la intención (Error API OpenAI)'], 500);
            }

            $message = $response['choices'][0]['message'];

            if (! isset($message['tool_calls']) || empty($message['tool_calls'])) {
                return response()->json([
                    'data' => [
                        'transcription' => $text,
                        'intent' => 'unknown',
                    ],
                ]);
            }

            $toolCall = $message['tool_calls'][0];
            $toolName = $toolCall['function']['name'];
            $args = json_decode($toolCall['function']['arguments'], true);

            $responseData = [
                'transcription' => $text,
                'intent' => $toolName,
            ];

            if ($toolName === 'create_order') {
                $parsedData = $this->brainService->parseOrderArguments($args['items']);

                $clientName = $args['client_name'] ?? null;
                $isNewClient = true;
                $clientId = null;
                $suggestedClients = [];

                if ($clientName) {
                    $matchedClients = $this->brainService->matchClients($clientName, 50);

                    if (count($matchedClients) === 1 && $matchedClients[0]['score'] >= 70) {
                        // Única coincidencia y aceptable
                        $isNewClient = false;
                        $clientId = $matchedClients[0]['item']->id;
                    } elseif (count($matchedClients) > 0) {
                        // Varias coincidencias o una sola con puntaje bajo
                        // Comprobamos si la primera coincidencia tiene un puntaje casi perfecto y la segunda es baja
                        if ($matchedClients[0]['score'] >= 85 && (!isset($matchedClients[1]) || $matchedClients[1]['score'] < 65)) {
                            $isNewClient = false;
                            $clientId = $matchedClients[0]['item']->id;
                        } else {
                            $isNewClient = true;
                            // Enviar solo el id, nombre y teléfono de los mejores 5
                            $suggestedClients = array_map(function ($match) {
                                return [
                                    'id' => $match['item']->id,
                                    'name' => $match['item']->name,
                                    'phone' => $match['item']->phone,
                                ];
                            }, array_slice($matchedClients, 0, 5));
                        }
                    }
                }

                $responseData['items'] = $parsedData['parsedItems'];
                $responseData['client_name'] = $clientName;
                $responseData['client_id'] = $clientId;
                $responseData['is_new_client'] = $isNewClient;
                $responseData['suggested_clients'] = $suggestedClients;
                $responseData['event_date'] = $args['event_date'] ?? null;
                $responseData['start_time'] = $args['start_time'] ?? null; // BUG-V02: exponer horario a Flutter
                $responseData['total'] = $parsedData['total'];
            }

            return response()->json([
                'data' => $responseData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error interno al procesar el audio',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    private function transcribeAudio($file)
    {
        $clients = \App\Models\Client::pluck('name')->implode(', ');

        $response = Http::withoutVerifying()
            ->withToken(env('OPENAI_API_KEY'))
            ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => 'whisper-1',
                'language' => 'es',
                'prompt' => 'Nombres de clientes frecuentes que pueden aparecer en este audio: ' . $clients,
            ]);

        return $response->json();
    }
}
