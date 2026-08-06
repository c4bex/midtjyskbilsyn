<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiAssistantApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::factory()->create();
        DB::table('employees')->insert([
            'user_id' => $this->user->id, 'display_name' => 'AI-testadministrator',
            'role' => 'Teknisk ansvarlig / Ejer', 'active' => true, 'booking_capacity' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        config()->set('services.ai.enabled', false);
        config()->set('services.ai.api_key', null);
    }

    public function test_document_is_stored_privately_and_split_into_searchable_chunks(): void
    {
        $response = $this->actingAs($this->user)->post('/api/ai/documents', [
            'title' => 'Intern vejledning om omsyn', 'category' => 'Vejledning', 'publisher' => 'Midtjysk Bilsyn',
            'file' => UploadedFile::fake()->createWithContent('omsyn.txt', 'Ved omsyn skal køretøjets tidligere synsrapport kontrolleres før afgørelsen.'),
        ])->assertCreated()->assertJsonPath('document.status', 'ready');

        $documentId = $response->json('document.id');
        $this->assertDatabaseHas('ai_document_chunks', ['document_id' => $documentId]);
        Storage::disk('local')->assertExists($response->json('document.storage_path'));
    }

    public function test_question_returns_sources_but_does_not_guess_when_ai_is_disabled(): void
    {
        $this->actingAs($this->user)->post('/api/ai/documents', [
            'title' => 'Vejledning om omsyn', 'category' => 'Vejledning',
            'file' => UploadedFile::fake()->createWithContent('omsyn.txt', 'Ved omsyn skal den tidligere synsrapport kontrolleres.'),
        ])->assertCreated();
        $conversation = $this->actingAs($this->user)->postJson('/api/ai/conversations', [])->assertCreated()->json('conversation.id');

        $this->actingAs($this->user)->postJson("/api/ai/conversations/{$conversation}/messages", [
            'question' => 'Hvad skal kontrolleres ved omsyn?', 'includeBookingContext' => false,
        ])->assertOk()
            ->assertJsonPath('confidence', 'needs_review')
            ->assertJsonFragment(['title' => 'Vejledning om omsyn'])
            ->assertJsonPath('sources.0.page_number', 1);
    }

    public function test_investigation_is_saved_and_arvo_remains_a_disabled_draft(): void
    {
        $investigation = $this->actingAs($this->user)->postJson('/api/ai/investigations', [
            'title' => 'Afklar særlig registrering', 'description' => 'Sagen kræver en manuel vurdering.',
        ])->assertCreated()->json('investigation.id');

        $this->actingAs($this->user)->postJson("/api/ai/investigations/{$investigation}/arvo", [])
            ->assertStatus(202)->assertJsonPath('integration.sent', false)->assertJsonPath('task.status', 'draft');
    }

    public function test_booking_context_is_only_linked_after_explicit_consent(): void
    {
        $customer = DB::table('customers')->insertGetId(['display_name' => 'Privat testkunde', 'customer_type' => 'private', 'created_at' => now(), 'updated_at' => now()]);
        $vehicle = DB::table('vehicles')->insertGetId(['customer_id' => $customer, 'registration_normalized' => 'AB12345', 'created_at' => now(), 'updated_at' => now()]);
        $booking = DB::table('bookings')->insertGetId(['customer_id' => $customer, 'vehicle_id' => $vehicle, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(20), 'inspection_type' => 'Periodisk syn', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
        $conversation = $this->actingAs($this->user)->postJson('/api/ai/conversations', [])->json('conversation.id');

        $this->actingAs($this->user)->postJson("/api/ai/conversations/{$conversation}/messages", ['question' => 'Find vejledning', 'bookingId' => $booking, 'includeBookingContext' => false])->assertOk();
        $this->assertDatabaseHas('ai_conversations', ['id' => $conversation, 'booking_id' => null]);
    }
}
