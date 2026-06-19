<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\AiBrainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CopilotController extends Controller
{
    protected AiBrainService $brainService;

    public function __construct(AiBrainService $brainService)
    {
        $this->brainService = $brainService;
    }

    public function process(Request $request)
    {
        $inputMessages = $request->input('messages');

        if (! $inputMessages || ! is_array($inputMessages)) {
            return response()->json(['error' => 'Se requiere un array de mensajes.'], 400);
        }

        $messages = [
            ['role' => 'system', 'content' => $this->brainService->getSystemPrompt(false)],
        ];

        $messages = array_merge($messages, $inputMessages);
        $tools = $this->brainService->getTools();

        // Primera llamada a OpenAI
        $response = $this->brainService->callOpenAI($messages, $tools, true);

        if (isset($response['error'])) {
            Log::error('OpenAI API Error: '.json_encode($response['error']));

            return response()->json(['error' => 'Error al comunicarse con OpenAI.'], 502);
        }

        $responseMessage = $response['choices'][0]['message'];

        // Revisar si OpenAI quiere llamar a una tool
        if (isset($responseMessage['tool_calls'])) {
            $messages[] = $responseMessage; // Agregar el mensaje de la IA con los tool_calls al historial

            foreach ($responseMessage['tool_calls'] as $toolCall) {
                $toolName = $toolCall['function']['name'];
                $args = json_decode($toolCall['function']['arguments'], true);

                $toolResponse = [];

                try {
                    if ($toolName === 'create_client') {
                        $clientName = trim($args['name']);
                        $existingClient = Client::where(DB::raw('lower(name)'), strtolower($clientName))->first();

                        if ($existingClient) {
                            if (! empty($args['phone'])) {
                                $existingClient->phone = $args['phone'];
                                $existingClient->save();
                            }
                            $client = $existingClient;
                        } else {
                            $client = Client::create([
                                'name' => $clientName,
                                'phone' => $args['phone'] ?? null,
                            ]);
                        }
                        $toolResponse = ['success' => true, 'client' => $client];
                    } elseif ($toolName === 'search_client') {
                        $clientName = trim($args['name']);
                        $matchedClient = $this->brainService->matchClient($clientName, 75);

                        if ($matchedClient) {
                            $clientData = Client::select('id', 'name', 'phone')->find($matchedClient->id);
                            $toolResponse = ['success' => true, 'client' => $clientData];
                        } else {
                            $toolResponse = ['success' => false, 'message' => "No se encontró ningún cliente llamado '$clientName' en la base de datos."];
                        }
                    } elseif ($toolName === 'search_orders_by_client') {
                        $clientName = trim($args['client_name']);
                        $matchedClient = $this->brainService->matchClient($clientName, 75);

                        if ($matchedClient) {
                            $orders = Order::with(['client', 'items'])
                                ->where('client_id', $matchedClient->id)
                                ->orderBy('event_date', 'desc')
                                ->limit(5)
                                ->get();
                            $toolResponse = ['success' => true, 'orders' => $orders];
                        } else {
                            $toolResponse = ['success' => false, 'message' => "No se encontraron pedidos porque el cliente '$clientName' no existe."];
                        }
                    } elseif ($toolName === 'create_order') {
                        $clientName = $args['client_name'];
                        $matchedClient = $this->brainService->matchClient($clientName, 85);

                        $clientId = null;
                        if ($matchedClient) {
                            $clientId = $matchedClient->id;
                        } else {
                            $newClient = Client::create(['name' => $clientName]);
                            $clientId = $newClient->id;
                        }

                        // Parseamos usando AiBrainService centralizado
                        $parsedData = $this->brainService->parseOrderArguments($args['items']);
                        $total = $parsedData['total'];
                        $orderItemsData = $parsedData['parsedItems'];

                        DB::beginTransaction();
                        try {
                            $order = Order::create([
                                'client_id' => $clientId,
                                'event_date' => $args['event_date'],
                                'start_time' => $args['start_time'] ?? null,
                                'status' => 'pending',
                                'total' => $total,
                                'deposit' => 0,
                                'delivery_cost' => 0,
                                'notes' => 'Creado por IA',
                                'is_paid' => false,
                            ]);

                            foreach ($orderItemsData as $itemData) {
                                OrderItem::create([
                                    'order_id' => $order->id,
                                    'name' => $itemData['name'],
                                    'qty' => $itemData['qty'],
                                    'base_price' => $itemData['base_price'],
                                    'customization_notes' => $itemData['customization_notes'],
                                    'customization_json' => $itemData['customization_json'],
                                ]);
                            }
                            DB::commit();
                            $toolResponse = ['success' => true, 'order' => $order->load('client', 'items')];
                        } catch (\Exception $e) {
                            DB::rollBack();
                            throw $e;
                        }
                    } elseif ($toolName === 'update_order') {
                        $clientName = $args['client_name'];
                        $matchedClient = $this->brainService->matchClient($clientName, 85);

                        if (! $matchedClient) {
                            throw new \Exception('No se encontraron órdenes activas para ese cliente (el cliente no existe).');
                        }

                        $pendingOrders = Order::where('client_id', $matchedClient->id)
                            ->whereIn('status', ['pending', 'confirmed', 'ready'])
                            ->get();

                        if ($pendingOrders->count() === 0) {
                            throw new \Exception("No se encontraron órdenes activas para el cliente {$matchedClient->name}.");
                        }

                        if ($pendingOrders->count() > 1) {
                            throw new \Exception("Hay más de un pedido pendiente para el cliente {$matchedClient->name}. Por favor consúltale al usuario la fecha del pedido que desea editar o especifica más detalles.");
                        }

                        $order = $pendingOrders->first();

                        DB::beginTransaction();
                        try {
                            if ($args['action'] === 'change_date' && isset($args['new_event_date'])) {
                                $order->event_date = $args['new_event_date'];
                                $order->save();
                            }

                            if ($args['action'] === 'add_items' && isset($args['items_to_add'])) {

                                // Usar el AiBrainService para parsear los items a agregar
                                $parsedData = $this->brainService->parseOrderArguments($args['items_to_add']);

                                foreach ($parsedData['parsedItems'] as $itemData) {
                                    OrderItem::create([
                                        'order_id' => $order->id,
                                        'name' => $itemData['name'],
                                        'qty' => $itemData['qty'],
                                        'base_price' => $itemData['base_price'],
                                        'customization_notes' => $itemData['customization_notes'],
                                        'customization_json' => $itemData['customization_json'],
                                    ]);
                                }

                                // Recalcular total
                                $newTotal = OrderItem::where('order_id', $order->id)
                                    ->get()
                                    ->sum(function ($i) {
                                        return $i->base_price * $i->qty;
                                    });

                                $order->total = $newTotal;
                                $order->save();
                            }
                            DB::commit();
                            $toolResponse = ['success' => true, 'order' => $order->load('client', 'items')];
                        } catch (\Exception $e) {
                            DB::rollBack();
                            throw $e;
                        }
                    } elseif ($toolName === 'register_payment') {
                        $clientName = $args['client_name'];
                        $amount = (float) $args['amount'];
                        $paymentMethod = $args['payment_method'] ?? 'no especificado';
                        $matchedClient = $this->brainService->matchClient($clientName, 85);

                        if (! $matchedClient) {
                            throw new \Exception('No se encontraron órdenes activas para ese cliente (el cliente no existe).');
                        }

                        $pendingOrders = Order::where('client_id', $matchedClient->id)
                            ->whereIn('status', ['pending', 'confirmed', 'ready'])
                            ->get();

                        if ($pendingOrders->count() === 0) {
                            throw new \Exception("No se encontraron órdenes activas para el cliente {$matchedClient->name}.");
                        }

                        if ($pendingOrders->count() > 1) {
                            throw new \Exception("Hay más de un pedido pendiente para el cliente {$matchedClient->name}. Por favor consúltale al usuario a qué pedido desea aplicar el pago.");
                        }

                        $order = $pendingOrders->first();

                        if ($order->is_paid) {
                            throw new \Exception("El pedido del cliente {$matchedClient->name} ya se encuentra pagado en su totalidad.");
                        }

                        DB::beginTransaction();
                        try {
                            $order->deposit += $amount;
                            
                            if ($order->deposit >= $order->total) {
                                $order->deposit = $order->total;
                                $order->is_paid = true;
                            }

                            $noteAddition = "[Seña $" . number_format($amount, 2, ',', '.') . " en " . ucfirst($paymentMethod) . "]";
                            $order->notes = empty($order->notes) ? $noteAddition : $order->notes . "\n" . $noteAddition;

                            $order->save();
                            
                            DB::commit();
                            $toolResponse = ['success' => true, 'order' => $order->load('client', 'items')];
                        } catch (\Exception $e) {
                            DB::rollBack();
                            throw $e;
                        }
                    } elseif ($toolName === 'get_orders_by_date') {
                        $orders = Order::with('client', 'items')
                            ->whereDate('event_date', $args['date'])
                            ->limit(10)
                            ->get();
                        $toolResponse = ['success' => true, 'orders' => $orders];
                    } elseif ($toolName === 'search_orders') {
                        $query = Order::with('client', 'items')
                            ->whereBetween('event_date', [$args['start_date'], $args['end_date']]);

                        if (isset($args['is_paid'])) {
                            $query->where('is_paid', $args['is_paid']);
                        }

                        $totalCount = $query->count();
                        $totalSum = $query->sum('total');

                        // Limitamos a los primeros 5 para no saturar la pantalla ni el contexto de OpenAI
                        $orders = $query->orderBy('event_date', 'desc')->limit(5)->get();

                        $toolResponse = [
                            'success' => true,
                            'total_count' => $totalCount,
                            'total_revenue' => (float) $totalSum,
                            'orders' => $orders,
                            'message' => 'Se devuelven los 5 más recientes. Menciona el total_count y total_revenue. MUY IMPORTANTE: En el "reply", DEBES preguntar obligatoriamente: "¿Deseas afinar la búsqueda por cliente o por fecha?". DEBES usar el ui_widget "order_list" para mostrar estos 5 pedidos. NO escribas la lista de pedidos en el "reply" de texto.',
                        ];
                    } elseif ($toolName === 'get_production_summary') {
                        $summary = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
                            ->whereBetween('orders.event_date', [$args['start_date'], $args['end_date']])
                            ->whereNull('orders.deleted_at')
                            ->select('order_items.name', DB::raw('SUM(order_items.qty) as total_quantity'))
                            ->groupBy('order_items.name')
                            ->get();
                        $toolResponse = ['success' => true, 'summary' => $summary];
                    } elseif ($toolName === 'get_revenue_by_period') {
                        $revenue = Order::whereBetween('event_date', [$args['start_date'], $args['end_date']])
                            ->sum('total');
                        $toolResponse = ['success' => true, 'revenue' => (float) $revenue];
                    } elseif ($toolName === 'navigate_to_calendar') {
                        $toolResponse = ['success' => true, 'date' => $args['date']];
                    }
                } catch (\Exception $e) {
                    Log::error("Copilot Tool Error ($toolName): ".$e->getMessage());
                    $toolResponse = ['success' => false, 'error' => $e->getMessage()];
                }

                // Agregar el resultado al historial como 'tool'
                $messages[] = [
                    'tool_call_id' => $toolCall['id'],
                    'role' => 'tool',
                    'name' => $toolName,
                    'content' => json_encode($toolResponse),
                ];
            }

            // Segunda llamada a OpenAI para que arme la respuesta amigable con la data
            $finalResponse = $this->brainService->callOpenAI($messages, null, true);

            if (isset($finalResponse['error'])) {
                Log::error('OpenAI API Error (2nd call): '.json_encode($finalResponse['error']));

                return response()->json(['error' => 'Error al comunicarse con OpenAI.'], 502);
            }

            $finalContent = $finalResponse['choices'][0]['message']['content'] ?? '{"reply": "Lo siento, no pude formular una respuesta con esa información."}';
            $cleanContent = preg_replace('/```(?:json)?\s*|\s*```/', '', trim($finalContent));

            $decodedContent = json_decode($cleanContent, true) ?? [];

            return response()->json([
                'reply' => $decodedContent['reply'] ?? $finalContent,
                'ui_widget' => $decodedContent['ui_widget'] ?? null,
                'tool_used' => true,
                'raw_tool_calls' => $responseMessage['tool_calls'],
            ]);
        }

        // Si no usó tool, devuelve la respuesta directa
        $rawContent = $responseMessage['content'];
        $cleanContent = preg_replace('/```(?:json)?\s*|\s*```/', '', trim($rawContent));
        $decodedContent = json_decode($cleanContent, true) ?? [];

        return response()->json([
            'reply' => $decodedContent['reply'] ?? $rawContent,
            'ui_widget' => $decodedContent['ui_widget'] ?? null,
            'tool_used' => false,
        ]);
    }
}
