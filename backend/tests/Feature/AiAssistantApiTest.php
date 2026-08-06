<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_web_search_requires_explicit_choice_and_is_limited_to_official_domains(): void
    {
        config()->set('services.ai.enabled', true);
        config()->set('services.ai.api_key', 'test-key');
        config()->set('services.ai.web_search_enabled', true);
        config()->set('services.ai.web_allowed_domains', ['retsinformation.dk', 'fstyr.dk']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_test_web',
                'output' => [
                    ['type' => 'web_search_call', 'action' => ['sources' => [[
                        'url' => 'https://www.retsinformation.dk/eli/lta/2026/123',
                        'title' => 'Bekendtgørelse om syn af køretøjer',
                    ]]]],
                    ['type' => 'message', 'content' => [[
                        'type' => 'output_text', 'text' => 'Kort svar med officiel kilde.',
                        'annotations' => [[
                            'type' => 'url_citation',
                            'url' => 'https://www.retsinformation.dk/eli/lta/2026/123',
                            'title' => 'Bekendtgørelse om syn af køretøjer',
                        ]],
                    ]]],
                ],
            ]),
        ]);
        $conversation = $this->actingAs($this->user)->postJson('/api/ai/conversations', [])->assertCreated()->json('conversation.id');

        $this->actingAs($this->user)->postJson("/api/ai/conversations/{$conversation}/messages", [
            'question' => 'Hvad siger den gældende bekendtgørelse?',
            'useWebSearch' => true,
        ])->assertOk()
            ->assertJsonPath('confidence', 'source_grounded')
            ->assertJsonPath('sources.0.kind', 'web')
            ->assertJsonPath('sources.0.domain', 'www.retsinformation.dk');

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.com/v1/responses'
                && data_get($payload, 'tools.0.type') === 'web_search'
                && data_get($payload, 'tools.0.filters.allowed_domains') === ['retsinformation.dk', 'fstyr.dk']
                && data_get($payload, 'tool_choice') === 'required';
        });
        $this->assertDatabaseHas('ai_web_sources', [
            'domain' => 'www.retsinformation.dk',
            'source_type' => 'official_web',
        ]);
    }

    public function test_normal_question_never_searches_the_web_implicitly(): void
    {
        config()->set('services.ai.enabled', true);
        config()->set('services.ai.api_key', 'test-key');
        config()->set('services.ai.web_search_enabled', true);
        config()->set('services.ai.web_allowed_domains', ['retsinformation.dk']);
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'resp_no_web', 'output_text' => 'Svar uden netsøgning.'])]);
        $conversation = $this->actingAs($this->user)->postJson('/api/ai/conversations', [])->assertCreated()->json('conversation.id');

        $this->actingAs($this->user)->postJson("/api/ai/conversations/{$conversation}/messages", [
            'question' => 'Hvad findes i vores dokumenter?',
        ])->assertOk();

        Http::assertSent(fn ($request) => ! array_key_exists('tools', $request->data()));
        $this->assertDatabaseCount('ai_web_sources', 0);
    }
}
