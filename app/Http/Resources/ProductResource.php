<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'base_price' => $this->base_price,
            'unit_type' => $this->unit_type,
            'allow_half_dozen' => $this->allow_half_dozen,
            'half_dozen_price' => $this->half_dozen_price,
            'multiplier_adjustment_per_kg' => $this->multiplier_adjustment_per_kg,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
