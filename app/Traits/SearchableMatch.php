<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait SearchableMatch
{
    /**
     * Motor de búsqueda algorítmica para encontrar la mejor coincidencia.
     */
    protected function findBestMatch(string $search, $collection, string $field, int $minPercentage)
    {
        $bestMatch = null;
        $highestPercentage = 0;

        $searchNormalized = Str::ascii(Str::lower($search));

        foreach ($collection as $item) {
            $target = Str::ascii(Str::lower($item->{$field}));

            if (strlen($searchNormalized) == 0 || strlen($target) == 0) {
                continue;
            }

            similar_text($searchNormalized, $target, $percent);

            if (str_contains($target, $searchNormalized) || str_contains($searchNormalized, $target)) {
                // Si la palabra buscada está contenida completamente (ej: "lorena" en "lorena caballero")
                // Le damos un bonus enorme para que supere el 75% mínimo.
                $percent += 40;
            }

            if ($percent > $highestPercentage) {
                $highestPercentage = $percent;
                $bestMatch = $item;
            }
        }

        return $highestPercentage >= $minPercentage ? $bestMatch : null;
    }

    /**
     * Devuelve las mejores coincidencias que superen el porcentaje mínimo, ordenadas por puntaje.
     */
    protected function findTopMatches(string $search, $collection, string $field, int $minPercentage)
    {
        $matches = [];
        $searchNormalized = Str::ascii(Str::lower($search));

        foreach ($collection as $item) {
            $target = Str::ascii(Str::lower($item->{$field}));

            if (strlen($searchNormalized) == 0 || strlen($target) == 0) {
                continue;
            }

            similar_text($searchNormalized, $target, $percent);

            if (str_contains($target, $searchNormalized) || str_contains($searchNormalized, $target)) {
                $percent += 40;
            }

            if ($percent >= $minPercentage) {
                $matches[] = [
                    'item' => $item,
                    'score' => $percent
                ];
            }
        }

        // Ordenar de mayor a menor porcentaje
        usort($matches, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $matches;
    }
}
