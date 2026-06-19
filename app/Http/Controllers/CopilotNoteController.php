<?php
namespace App\Http\Controllers;
use App\Models\CopilotNote;
use Illuminate\Http\Request;

class CopilotNoteController extends Controller {
    public function index(Request $request) {
        $notes = CopilotNote::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();
        return response()->json($notes);
    }

    public function store(Request $request) {
        $request->validate([
            'content'        => 'required|string',
            'ui_widget'      => 'nullable|array',
            'source_context' => 'nullable|string|max:150',
        ]);
        $note = CopilotNote::create([
            'user_id'        => $request->user()->id,
            'content'        => $request->content,
            'ui_widget'      => $request->ui_widget,
            'source_context' => $request->source_context,
        ]);
        return response()->json($note, 201);
    }

    public function destroy(Request $request, CopilotNote $copilotNote) {
        if ($copilotNote->user_id !== $request->user()->id) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        $copilotNote->delete();
        return response()->json(['message' => 'Nota eliminada.']);
    }
}
