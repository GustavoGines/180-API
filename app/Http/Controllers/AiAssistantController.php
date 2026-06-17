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

                if ($clientName) {
                    $existingClient = $this->brainService->matchClient($clientName, 70);
                    if ($existingClient) {
                        $isNewClient = false;
                        $clientId = $existingClient->id;
                    }
                }

                $responseData['items'] = $parsedData['parsedItems'];
                $responseData['client_name'] = $clientName;
                $responseData['client_id'] = $clientId;
                $responseData['is_new_client'] = $isNewClient;
                $responseData['event_date'] = $args['event_date'] ?? null;
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
        $response = Http::withoutVerifying()
            ->withToken(env('OPENAI_API_KEY'))
            ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => 'whisper-1',
                'language' => 'es',
            ]);

        return $response->json();
    }
}
