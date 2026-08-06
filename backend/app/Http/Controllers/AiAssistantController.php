<?php

namespace App\Http\Controllers;

use App\Services\AiAssistantService;
use App\Services\AiDocumentService;
use App\Services\ArvoTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiAssistantController extends Controller
{
    public function bootstrap(): JsonResponse
    {
        return response()->json([
            'status' => [
                'aiEnabled' => (bool) config('services.ai.enabled') && (bool) config('services.ai.api_key'),
                'arvoEnabled' => (bool) config('services.arvo.enabled'),
                'model' => config('services.ai.model'),
            ],
            'conversations' => $this->conversationQuery()->limit(30)->get(),
            'documents' => DB::table('ai_documents')->where('is_active', true)->orderByDesc('updated_at')->limit(100)->get(),
            'investigations' => DB::table('investigations')->orderByDesc('updated_at')->limit(30)->get(),
        ]);
    }

    public function createConversation(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:160']]);
        $id = DB::table('ai_conversations')->insertGetId([
            'title' => $data['title'] ?? 'Ny samtale', 'created_by' => Auth::id(),
            'is_shared' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json($this->conversation($id), 201);
    }

    public function showConversation(int $conversation): JsonResponse
    {
        $this->assertConversationAccess($conversation);

        return response()->json($this->conversation($conversation));
    }

    public function ask(Request $request, int $conversation, AiAssistantService $assistant): JsonResponse
    {
        $this->assertConversationAccess($conversation);
        $data = $request->validate([
            'question' => ['required', 'string', 'max:8000'],
            'includeBookingContext' => ['sometimes', 'boolean'],
            'bookingId' => ['nullable', 'integer', 'exists:bookings,id'],
        ]);
        DB::table('ai_messages')->insert([
            'conversation_id' => $conversation, 'role' => 'user', 'content' => $data['question'],
            'confidence' => 'not_assessed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $context = [];
        if (($data['includeBookingContext'] ?? false) && ! empty($data['bookingId'])) {
            $booking = DB::table('bookings as b')->leftJoin('vehicles as v', 'v.id', '=', 'b.vehicle_id')
                ->leftJoin('customers as c', 'c.id', '=', 'b.customer_id')
                ->where('b.id', $data['bookingId'])->select('b.id', 'b.starts_at', 'b.inspection_type', 'c.customer_type', 'v.registration_normalized')->first();
            if ($booking) {
                $context = (array) $booking;
            }
            DB::table('ai_conversations')->where('id', $conversation)->update([
                'booking_id' => $data['bookingId'], 'vehicle_id' => DB::table('bookings')->where('id', $data['bookingId'])->value('vehicle_id'), 'updated_at' => now(),
            ]);
        }

        try {
            $answer = $assistant->answer($data['question'], $context);
        } catch (\Throwable $exception) {
            report($exception);
            $answer = [
                'content' => 'AI-assistenten er midlertidigt utilgængelig. Dokumenter og booking kan fortsat bruges normalt.',
                'confidence' => 'unavailable', 'model' => null,
                'provider_metadata' => ['error' => 'provider_unavailable'], 'sources' => [],
            ];
        }
        $messageId = DB::table('ai_messages')->insertGetId([
            'conversation_id' => $conversation, 'role' => 'assistant', 'content' => $answer['content'],
            'model' => $answer['model'], 'confidence' => $answer['confidence'],
            'provider_metadata' => json_encode($answer['provider_metadata']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($answer['sources'] as $source) {
            DB::table('ai_message_sources')->insert([
                'message_id' => $messageId, 'document_id' => $source['document_id'], 'chunk_id' => $source['chunk_id'],
                'page_number' => $source['page_number'], 'quotation' => Str::limit($source['content'], 700),
                'relevance_score' => $source['score'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (DB::table('ai_messages')->where('conversation_id', $conversation)->count() === 2) {
            DB::table('ai_conversations')->where('id', $conversation)->update(['title' => Str::limit($data['question'], 100), 'updated_at' => now()]);
        } else {
            DB::table('ai_conversations')->where('id', $conversation)->update(['updated_at' => now()]);
        }

        return response()->json($this->message($messageId));
    }

    public function documents(): JsonResponse
    {
        return response()->json(['documents' => DB::table('ai_documents')->where('is_active', true)->orderByDesc('updated_at')->get()]);
    }

    public function uploadDocument(Request $request, AiDocumentService $documents): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['required', 'string', 'max:80'], 'publisher' => ['nullable', 'string', 'max:180'],
            'version' => ['nullable', 'string', 'max:80'], 'valid_from' => ['nullable', 'date'], 'valid_to' => ['nullable', 'date'],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,txt,md,csv'],
        ]);

        return response()->json(['document' => $documents->store($data, $request->file('file'), Auth::id())], 201);
    }

    public function downloadDocument(int $document): StreamedResponse
    {
        $row = DB::table('ai_documents')->where('id', $document)->where('is_active', true)->first();
        abort_unless($row && Storage::disk('local')->exists($row->storage_path), 404);

        return Storage::disk('local')->download($row->storage_path, $row->original_filename);
    }

    public function investigations(Request $request): JsonResponse
    {
        $query = DB::table('investigations')->orderByDesc('updated_at');
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%')->orWhere('reference_number', 'like', '%'.$request->string('search').'%'));
        }

        return response()->json(['investigations' => $query->limit(100)->get()]);
    }

    public function createInvestigation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:5000'],
            'conversationId' => ['nullable', 'integer', 'exists:ai_conversations,id'], 'bookingId' => ['nullable', 'integer', 'exists:bookings,id'],
            'followUpDate' => ['nullable', 'date'],
        ]);
        $reference = 'UND-'.now()->format('Ym').'-'.str_pad((string) (DB::table('investigations')->count() + 1), 4, '0', STR_PAD_LEFT);
        $id = DB::table('investigations')->insertGetId([
            'reference_number' => $reference, 'title' => $data['title'], 'description' => $data['description'], 'status' => 'Ny',
            'conversation_id' => $data['conversationId'] ?? null, 'booking_id' => $data['bookingId'] ?? null,
            'created_by' => Auth::id(), 'follow_up_date' => $data['followUpDate'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['investigation' => DB::table('investigations')->where('id', $id)->first()], 201);
    }

    public function sendToArvo(Request $request, int $investigation, ArvoTaskService $arvo): JsonResponse
    {
        $row = DB::table('investigations')->where('id', $investigation)->first();
        abort_unless($row, 404);
        $data = $request->validate(['title' => ['nullable', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000']]);
        $taskId = DB::table('investigation_tasks')->insertGetId([
            'investigation_id' => $investigation, 'title' => $data['title'] ?? $row->title,
            'description' => $data['description'] ?? $row->description, 'status' => 'draft', 'priority' => 'normal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = $arvo->send(['id' => $taskId, 'investigation' => (array) $row]);

        return response()->json(['task' => DB::table('investigation_tasks')->where('id', $taskId)->first(), 'integration' => $result], 202);
    }

    private function conversationQuery()
    {
        $query = DB::table('ai_conversations')->orderByDesc('updated_at');
        if (Auth::check()) {
            $query->where(fn ($q) => $q->where('created_by', Auth::id())->orWhere('is_shared', true));
        }

        return $query;
    }

    private function assertConversationAccess(int $id): void
    {
        abort_unless($this->conversationQuery()->where('id', $id)->exists(), 404);
    }

    private function conversation(int $id): array
    {
        $conversation = DB::table('ai_conversations')->where('id', $id)->first();
        abort_unless($conversation, 404);

        return ['conversation' => $conversation, 'messages' => DB::table('ai_messages')->where('conversation_id', $id)->orderBy('created_at')->get()->map(fn ($m) => $this->message($m->id))];
    }

    private function message(int $id): array
    {
        $message = DB::table('ai_messages')->where('id', $id)->first();

        return ['id' => $message->id, 'role' => $message->role, 'content' => $message->content, 'confidence' => $message->confidence,
            'model' => $message->model, 'created_at' => $message->created_at,
            'sources' => DB::table('ai_message_sources as s')->join('ai_documents as d', 'd.id', '=', 's.document_id')
                ->where('s.message_id', $id)->select('s.document_id', 'd.title', 'd.category', 's.page_number', 's.quotation', 's.relevance_score')->get()];
    }
}
