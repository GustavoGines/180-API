<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Order;

class OrderImageService
{
    /**
     * Procesa los placeholders en los items, sube imágenes nuevas y retorna los items actualizados.
     *
     * @param array $items
     * @param array $files Archivos del request
     * @return array Items con URLs actualizadas
     */
    public function processPlaceholders(array $items, array $files): array
    {
        foreach ($items as &$item) {
            if (isset($item['customization_json']['photo_urls']) && is_array($item['customization_json']['photo_urls'])) {
                $newUrls = [];
                foreach ($item['customization_json']['photo_urls'] as $url) {
                    if (str_starts_with($url, 'placeholder_') && isset($files[$url])) {
                        $file = $files[$url];
                        $path = $file->store('order-photos', 's3'); // Sube a R2
                        $newUrls[] = Storage::disk('s3')->url($path); // Obtiene URL de R2
                    } elseif (! str_starts_with($url, 'placeholder_')) {
                        $newUrls[] = $url;
                    }
                }
                $item['customization_json']['photo_urls'] = $newUrls;
            }
        }
        return $items;
    }

    /**
     * Extrae todas las URLs de fotos de una lista de items o datos de items.
     *
     * @param iterable $items
     * @return array
     */
    public function getPhotoUrls(iterable $items): array
    {
        $urls = [];
        foreach ($items as $item) {
            // Manejar tanto array como objeto (Model)
            $customizationData = is_array($item) 
                ? ($item['customization_json'] ?? []) 
                : ($item->customization_json ?? []);

            if (isset($customizationData['photo_urls']) && is_array($customizationData['photo_urls'])) {
                $urls = array_merge($urls, $customizationData['photo_urls']);
            }
        }
        return array_unique($urls);
    }

    /**
     * Elimina las imágenes huérfanas que ya no están en la orden actualizada.
     *
     * @param array $oldUrls
     * @param array $newUrls
     * @param int $orderId Para logging
     * @return void
     */
    public function deleteOrphanedPhotos(array $oldUrls, array $newUrls, int $orderId): void
    {
        $urlsToDelete = array_diff($oldUrls, $newUrls);
        $this->deleteFromStorage($urlsToDelete, "Update Order {$orderId}");
    }

    /**
     * Elimina todas las imágenes asociadas a una orden.
     *
     * @param Order $order
     * @return void
     */
    public function deleteAllPhotosForOrder(Order $order): void
    {
        $order->loadMissing('items');
        $photoUrls = $this->getPhotoUrls($order->items);
        $this->deleteFromStorage($photoUrls, "Destroy Order {$order->id}");
    }

    /**
     * Lógica interna para borrar archivos del disco S3/R2 dada una lista de URLs.
     *
     * @param array $urls
     * @param string $contextLog
     * @return void
     */
    private function deleteFromStorage(array $urls, string $contextLog): void
    {
        if (empty($urls)) {
            return;
        }

        $r2BaseUrl = rtrim(Storage::disk('s3')->url(''), '/');
        $pathsToDelete = [];

        foreach ($urls as $url) {
            if ($url && str_starts_with((string) $url, $r2BaseUrl)) {
                $path = ltrim(substr((string) $url, strlen($r2BaseUrl)), '/');
                if (! empty($path)) {
                    $pathsToDelete[] = $path;
                }
            } else {
                 // Log opcional para URLs externas ignoradas
                // Log::warning("[{$contextLog}] URL externa o no reconocida ignorada: ".$url);
            }
        }

        if (! empty($pathsToDelete)) {
            Log::info("[{$contextLog}] Borrando archivos de R2: " . implode(', ', $pathsToDelete));
            try {
                Storage::disk('s3')->delete($pathsToDelete);
            } catch (\Exception $e) {
                Log::error("[{$contextLog}] Error borrando de R2: " . $e->getMessage());
            }
        }
    }
}
