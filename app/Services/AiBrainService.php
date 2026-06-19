<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Extra;
use App\Models\Filling;
use App\Models\Product;
use App\Traits\SearchableMatch;
use Illuminate\Support\Facades\Http;

class AiBrainService
{
    use SearchableMatch;

    /**
     * Devuelve el System Prompt unificado con el catálogo en tiempo real.
     */
    public function getSystemPrompt(bool $isVoiceAssistant = false): string
    {
        $todayStr = now()->translatedFormat('l d \d\e F \d\e Y');
        $todayDate = now()->format('Y-m-d');

        $validProducts = Product::pluck('name')->implode(', ');
        $validFillings = Filling::pluck('name')->implode(', ');
        $validExtras = Extra::pluck('name')->implode(', ');

        $context = "Eres el asistente inteligente de una pastelería. La fecha y día exacto de hoy es $todayStr (Formato YYYY-MM-DD: $todayDate). Usa esta fecha como ancla estricta para calcular matemáticamente cualquier fecha relativa que pida el usuario (ej: 'este sábado', 'el próximo fin de semana', 'mañana').\n";
        $context .= "CATÁLOGO DE PRODUCTOS VÁLIDOS: $validProducts\n";
        $context .= "RELLENOS VÁLIDOS: $validFillings\n";
        $context .= "EXTRAS VÁLIDOS: $validExtras\n";
        $context .= "CRÍTICO: Si vas a usar 'create_order' o 'update_order', estás OBLIGADO a usar EXACTAMENTE los nombres de productos, rellenos y extras listados arriba. No inventes productos.\n";
        $context .= "CRÍTICO (CANTIDADES Y PESOS):\n";
        $context .= "1. Para tortas: Si el usuario pide un peso (ej. 'Torta de 2kg'), envía el atributo 'weight_kg'.\n";
        $context .= "2. Para mesa dulce: Si el usuario pide unidades (ej. '12 cupcakes'), envía 'quantity: 12' e 'is_unit_sale: true'. Si pide docenas (ej. '1 docena'), envía 'quantity: 1' e 'is_unit_sale: false'.\n";

        if (! $isVoiceAssistant) {
            $context .= "4. Para enlaces: NUNCA uses formato Markdown para los enlaces (ej. NO uses [texto](url)). Escribe la URL desnuda, o simplemente dile al usuario que use el botón en la tarjeta de contacto.\n";
            $context .= "IMPORTANTE: Debes responder SIEMPRE con una estructura JSON estricta. El formato debe ser:\n";
            $context .= "{\n  \"reply\": \"Texto amigable para el usuario\",\n  \"ui_widget\": {\n    \"type\": \"order_card\", // o null si es solo charla\n    \"data\": { ... }\n  }\n}\n";
            $context .= "REGLAS PARA ui_widget (type y data):\n";
            $context .= "- Si usaste create_client o search_client: devuelve type 'client_card' con data: {'name': '...', 'phone': '...'}\n";
            $context .= "- Si usaste create_order, update_order o register_payment: devuelve type 'order_card' con data: {'title': 'Pedido para [Nombre Cliente]', 'subtitle': 'Resumen muy detallado de los productos, incluyendo cantidad, peso, rellenos y extras', 'total': '$ [Monto total]', 'order_id': [ID Numérico del pedido], 'event_date': '[Fecha del pedido YYYY-MM-DD]'}\n";
            $context .= "- Si usaste get_orders_by_date, search_orders o search_orders_by_client: devuelve type 'order_list' con data: {'orders': [{'id': 123, 'client_name': '...', 'event_date': '...', 'status': '...'}]}\n";
            $context .= "- Si usaste get_production_summary: devuelve type 'production_list' con data: {'summary': [...]}\n";
            $context .= "- Si usaste get_revenue_by_period: devuelve type 'revenue_card' con data: {'period': 'Facturación', 'revenue': 123456}\n";
            $context .= "- Si usaste navigate_to_calendar: devuelve type 'navigate_calendar' con data: {'date': 'YYYY-MM-DD'}. CRÍTICO: la fecha en 'data.date' SIEMPRE debe ser un día completo en formato YYYY-MM-DD (ej: '2026-07-01'). Si el usuario pide ir a un mes (ej: 'julio', 'agosto 2026'), usa el primer día de ese mes (ej: '2026-07-01'). NUNCA envíes solo 'YYYY-MM'.\n";
            $context .= "Si la conversación es casual, usa type null.\n";
            $context .= "Si el usuario dice 'Juan dejó 5000 de seña', o registra un pago/seña de un cliente, debes usar la herramienta 'register_payment'.\n";
            $context .= "ERES UNA IA CON CONTROL TOTAL SOBRE LA INTERFAZ. Si el usuario pide ir a una fecha, saltar a un día, o ver el calendario, TIENES QUE usar 'navigate_to_calendar'. NUNCA digas que no puedes hacerlo.";
        } else {
            // Instrucciones extra específicas para el modo Extracción pura (Voz)
            $context .= "Tu tarea es ÚNICAMENTE extraer la información del texto provisto por el usuario y llamar a la herramienta adecuada ('create_order'). No debes generar ninguna respuesta amigable, solo usar Tool Calling.";
        }

        return $context;
    }

    /**
     * Devuelve el array de Tools (funciones) de OpenAI
     */
    public function getTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_client',
                    'description' => 'Busca a un cliente por nombre para verificar si existe en la base de datos y obtener sus datos. OBLIGATORIO usar esto en lugar de create_client cuando te preguntan si un cliente existe.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                        ],
                        'required' => ['name'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_orders_by_client',
                    'description' => 'Busca todos los pedidos asociados a un cliente.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'client_name' => ['type' => 'string'],
                        ],
                        'required' => ['client_name'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_orders',
                    'description' => 'Busca pedidos filtrando por rango de fechas y estado de pago (pagado/no pagado). Útil para preguntas como "¿Hay pedidos pagados este año?", "¿Cuántos faltan pagar?", etc.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string', 'description' => 'Fecha inicio YYYY-MM-DD'],
                            'end_date' => ['type' => 'string', 'description' => 'Fecha fin YYYY-MM-DD'],
                            'is_paid' => ['type' => 'boolean', 'description' => 'Opcional. True para buscar solo pagados, false para solo no pagados. Omítelo si quieres ambos.'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_client',
                    'description' => 'Registra un nuevo cliente en la base de datos. NUNCA lo uses solo para buscar o consultar si existe.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'phone' => ['type' => 'string'],
                        ],
                        'required' => ['name'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_order',
                    'description' => 'Agrega un pedido nuevo a la base de datos.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'client_name' => ['type' => 'string', 'description' => 'Nombre del cliente. Para buscarlo o crearlo automáticamente.'],
                            'event_date' => ['type' => 'string', 'description' => 'Fecha de entrega YYYY-MM-DD.'],
                            'start_time' => ['type' => 'string', 'description' => 'Horario de retiro o entrega en formato HH:MM (ej. 15:30). Omitir si no se especifica.'],
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'product_name' => ['type' => 'string', 'description' => 'Nombre EXACTO del producto tal cual aparece en el CATÁLOGO DE PRODUCTOS VÁLIDOS. NUNCA lo acortes. Ej: Si el cliente dice "torta", debes inferir cuál del catálogo es la más adecuada ("Torta Decorada con Crema Chantilly", "Torta con Ganache", etc). NUNCA usar solo "Torta".'],
                                        'quantity' => ['type' => 'number'],
                                        'fillings' => [
                                            'type' => 'array',
                                            'description' => 'Array de strings SOLO con los rellenos o sabores. OBLIGATORIO separar cada relleno como un elemento distinto en el array. (Ej incorrecto: ["Dulce de leche y crema"]. Ej correcto: ["Dulce de Leche", "Crema"]).',
                                            'items' => ['type' => 'string'],
                                        ],
                                        'extras' => [
                                            'type' => 'array',
                                            'description' => 'Array de strings SOLO con los nombres de extras adicionales (ej: ["Oreos", "Nueces"]).',
                                            'items' => ['type' => 'string'],
                                        ],
                                        'weight_kg' => ['type' => 'number', 'description' => 'Peso en kg si el producto es una torta.'],
                                        'is_unit_sale' => ['type' => 'boolean', 'description' => 'True si la cantidad de un producto de mesa dulce está en unidades.'],
                                        'notes' => ['type' => 'string', 'description' => 'Decoración visual, colores o dedicatorias. OBLIGATORIO: NO incluyas aquí los rellenos, kilos, extras ni unidades.'],
                                    ],
                                    'required' => ['product_name', 'quantity'],
                                ],
                            ],
                        ],
                        'required' => ['client_name', 'event_date', 'items'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_order',
                    'description' => 'Edita un pedido activo (pendiente, confirmado o listo) existente.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'client_name' => ['type' => 'string', 'description' => 'Nombre del cliente para buscar el pedido pendiente.'],
                            'action' => ['type' => 'string', 'enum' => ['add_items', 'change_date'], 'description' => 'La acción a realizar: agregar items al pedido o cambiar la fecha de entrega.'],
                            'new_event_date' => ['type' => 'string', 'description' => 'Si action es change_date, la nueva fecha de entrega YYYY-MM-DD.'],
                            'items_to_add' => [
                                'type' => 'array',
                                'description' => 'Si action es add_items, los productos a agregar.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'product_name' => ['type' => 'string', 'description' => 'Nombre EXACTO del producto tal cual aparece en el CATÁLOGO. NUNCA lo acortes. NUNCA usar solo "Torta".'],
                                        'quantity' => ['type' => 'number'],
                                        'fillings' => [
                                            'type' => 'array',
                                            'description' => 'Array de strings SOLO con los rellenos o sabores. OBLIGATORIO separar cada relleno como un elemento distinto en el array.',
                                            'items' => ['type' => 'string'],
                                        ],
                                        'extras' => [
                                            'type' => 'array',
                                            'description' => 'Array de strings SOLO con ingredientes extras o decoración (ej: nueces, oreos).',
                                            'items' => ['type' => 'string'],
                                        ],
                                        'weight_kg' => ['type' => 'number', 'description' => 'Peso en kg si el producto es una torta.'],
                                        'is_unit_sale' => ['type' => 'boolean', 'description' => 'True si la cantidad de un producto de mesa dulce está en unidades.'],
                                        'notes' => ['type' => 'string', 'description' => 'Decoración visual, colores o dedicatorias. OBLIGATORIO: NO incluyas aquí los rellenos, kilos, extras ni unidades.'],
                                    ],
                                    'required' => ['product_name', 'quantity'],
                                ],
                            ],
                        ],
                        'required' => ['client_name', 'action'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'register_payment',
                    'description' => 'Registra un pago parcial (seña) o total para un pedido activo (pendiente, confirmado o listo) de un cliente.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'client_name' => ['type' => 'string', 'description' => 'Nombre del cliente para buscar su pedido pendiente.'],
                            'amount' => ['type' => 'number', 'description' => 'El monto abonado por el cliente.'],
                            'payment_method' => ['type' => 'string', 'description' => 'Método de pago (ej. efectivo, transferencia, tarjeta). Opcional.'],
                        ],
                        'required' => ['client_name', 'amount'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_orders_by_date',
                    'description' => 'Obtiene la lista de pedidos para un día específico.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'Formato YYYY-MM-DD'],
                        ],
                        'required' => ['date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_production_summary',
                    'description' => 'Obtiene un resumen de cantidades de productos a producir en un rango de fechas.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string', 'description' => 'Formato YYYY-MM-DD'],
                            'end_date' => ['type' => 'string', 'description' => 'Formato YYYY-MM-DD'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_revenue_by_period',
                    'description' => 'Calcula la facturación total de ventas para un rango de fechas (día, semana, mes, etc.).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string', 'description' => 'Fecha inicio en formato YYYY-MM-DD.'],
                            'end_date' => ['type' => 'string', 'description' => 'Fecha fin en formato YYYY-MM-DD.'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'navigate_to_calendar',
                    'description' => 'ESTRICTAMENTE OBLIGATORIO: Usa esta función inmediatamente si el usuario pide ir a una fecha, saltar a un día, o ver el calendario. Tú sí tienes permiso y capacidad para navegar la interfaz, no digas que no puedes.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'Fecha en formato YYYY-MM-DD.'],
                        ],
                        'required' => ['date'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Mapea un nombre de cliente al cliente existente o devuelve null
     */
    public function matchClient(string $clientName, int $threshold = 70): ?Client
    {
        $clients = Client::select('id', 'name')->get();

        return $this->findBestMatch($clientName, $clients, 'name', $threshold);
    }

    /**
     * Busca posibles clientes similares y devuelve un array con las mejores coincidencias y sus scores
     */
    public function matchClients(string $clientName, int $threshold = 50): array
    {
        $clients = Client::select('id', 'name', 'phone')->get();
        return $this->findTopMatches($clientName, $clients, 'name', $threshold);
    }

    /**
     * Extrae, empareja con la BD y calcula precios de una lista de ítems de OpenAI
     * Devuelve el total acumulado y los datos parseados de cada ítem.
     */
    public function parseOrderArguments(array $items): array
    {
        $total = 0;
        $products = Product::all();
        $allFillings = Filling::all();
        $allExtras = Extra::all();

        $parsedItems = [];

        foreach ($items as $item) {
            $matchedProduct = $this->findBestMatch($item['product_name'], $products, 'name', 55);

            if (! $matchedProduct) {
                throw new \Exception("El producto '{$item['product_name']}' no existe en el catálogo. Revisa el catálogo e intenta con el nombre correcto.");
            }

            $basePrice = $matchedProduct->base_price;
            $quantity = $item['quantity'] ?? 1;

            $selectedFillings = [];
            $selectedExtraFillings = [];
            if (isset($item['fillings']) && is_array($item['fillings'])) {
                foreach ($item['fillings'] as $fName) {
                    $matchedFilling = $this->findBestMatch($fName, $allFillings, 'name', 50);
                    if ($matchedFilling) {
                        if ($matchedFilling->is_free) {
                            $selectedFillings[] = $matchedFilling->name;
                        } else {
                            $selectedExtraFillings[] = [
                                'name' => $matchedFilling->name,
                                'price' => (float) $matchedFilling->price_per_kg,
                            ];
                        }
                    }
                }
            }

            $selectedExtrasKg = [];
            $selectedExtrasUnit = [];
            if (isset($item['extras']) && is_array($item['extras'])) {
                foreach ($item['extras'] as $eName) {
                    $matchedExtra = $this->findBestMatch($eName, $allExtras, 'name', 50);
                    if ($matchedExtra) {
                        if ($matchedExtra->price_type === 'per_kg') {
                            $selectedExtrasKg[] = [
                                'name' => $matchedExtra->name,
                                'price' => (float) $matchedExtra->price,
                            ];
                        } else {
                            $selectedExtrasUnit[] = [
                                'name' => $matchedExtra->name,
                                'quantity' => 1,
                                'price' => (float) $matchedExtra->price,
                            ];
                        }
                    }
                }
            }

            $customizationJson = [];
            if ($matchedProduct->category) {
                $customizationJson['product_category'] = $matchedProduct->category;
            }
            $effectiveBasePrice = $basePrice;

            if (isset($item['weight_kg']) && is_numeric($item['weight_kg'])) {
                $customizationJson['weight_kg'] = (float) $item['weight_kg'];
                $effectiveBasePrice = $basePrice * $customizationJson['weight_kg'];

                foreach ($selectedExtraFillings as $extra) {
                    $effectiveBasePrice += ($extra['price'] * $customizationJson['weight_kg']);
                }
                foreach ($selectedExtrasKg as $extra) {
                    $effectiveBasePrice += ($extra['price'] * $customizationJson['weight_kg']);
                }
                foreach ($selectedExtrasUnit as $extra) {
                    $effectiveBasePrice += ($extra['price'] * $extra['quantity']);
                }
            } elseif (isset($item['is_unit_sale']) && $item['is_unit_sale'] === true) {
                $customizationJson['is_unit_sale'] = true;
                $effectiveBasePrice = ($basePrice / 12);
                foreach ($selectedExtrasUnit as $extra) {
                    $effectiveBasePrice += ($extra['price'] * $extra['quantity']);
                }
            } else {
                foreach ($selectedExtraFillings as $extra) {
                    $effectiveBasePrice += $extra['price'];
                }
                foreach ($selectedExtrasUnit as $extra) {
                    $effectiveBasePrice += ($extra['price'] * $extra['quantity']);
                }
            }

            $itemTotal = $effectiveBasePrice * $quantity;
            $total += $itemTotal;

            if (! empty($selectedFillings)) {
                $customizationJson['selected_fillings'] = $selectedFillings;
            }
            if (! empty($selectedExtraFillings)) {
                $customizationJson['selected_extra_fillings'] = $selectedExtraFillings;
            }
            if (! empty($selectedExtrasKg)) {
                $customizationJson['selected_extras_kg'] = $selectedExtrasKg;
            }
            if (! empty($selectedExtrasUnit)) {
                $customizationJson['selected_extras_unit'] = $selectedExtrasUnit;
            }

            // --- Alias planos para el Flutter Voice Assistant (BUG-V01, V03, V04) ---
            // Exponemos los campos clave en el primer nivel del DTO para que Flutter
            // pueda leerlos directamente. customization_json se mantiene intacto
            // para la persistencia del Copiloto y la DB.
            $allFillingsFlat = array_merge(
                $selectedFillings,
                array_column($selectedExtraFillings, 'name')
            );
            $allExtrasFlat = array_merge(
                array_column($selectedExtrasKg, 'name'),
                array_column($selectedExtrasUnit, 'name')
            );

            $parsedItems[] = [
                'product_id'          => $matchedProduct->id,
                'name'                => $matchedProduct->name,
                'original_name'       => $item['product_name'], // Útil para frontend Voice
                'qty'                 => $quantity,
                'quantity'            => $quantity,              // Alias plano para Flutter (BUG-V03)
                'weight_kg'           => $customizationJson['weight_kg'] ?? null, // Alias plano (BUG-V04)
                'fillings'            => $allFillingsFlat,       // Alias plano: rellenos gratis + con costo (BUG-V01)
                'extras'              => $allExtrasFlat,         // Alias plano: extras por kg + por unidad (BUG-V01)
                'base_price'          => $effectiveBasePrice,
                'customization_notes' => $item['notes'] ?? null,
                'customization_json'  => empty($customizationJson) ? null : $customizationJson,
            ];
        }

        return [
            'total' => $total,
            'parsedItems' => $parsedItems,
        ];
    }

    /**
     * Utilidad para llamar a OpenAI
     */
    public function callOpenAI(array $messages, ?array $tools = null, bool $forceJson = false)
    {
        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
        ];

        if ($forceJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        if ($tools) {
            $payload['tools'] = $tools;
        }

        $response = Http::withoutVerifying()
            ->withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        return $response->json();
    }
}
