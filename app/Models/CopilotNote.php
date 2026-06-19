<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CopilotNote extends Model {
    protected $fillable = ['user_id', 'content', 'ui_widget', 'source_context'];
    protected $casts = ['ui_widget' => 'array'];
    public function user() {
        return $this->belongsTo(User::class);
    }
}
