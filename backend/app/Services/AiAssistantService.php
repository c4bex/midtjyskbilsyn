<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AiAssistantService
{
    public function answer(string $question, array $context = []): array
    {
        $sources = $this->findSources($question);
        if (! config('services.ai.enabled') || ! config('services.ai.api_key')) {
            return [
                'content' => $sources === []
                    ? "Kort svar\n\nJeg kan ikke finde et entydigt svar i de tilgængelige dokumenter. AI-modellen er ikke aktiveret endnu, så spørgsmålet kan ikke vurderes sikkert.\n\nKilder\n\nIngen relevante kilder fundet."
                    : "Kort svar\n\nJeg fandt relevante afsnit i dokumentbiblioteket, men AI-modellen er ikke aktiveret endnu. Åbn kilderne nedenfor og vurder dem manuelt; jeg vil ikke gætte på svaret.\n\nKilder\n\n".$this->sourceList($sources),
                'confidence' => 'needs_review',
                'model' => null,
                'provider_metadata' => ['enabled' => false],
                'sources' => $sources,
            ];
        }

        $prompt = $this->systemPrompt($sources, $context);
        $response = Http::withToken(config('services.ai.api_key'))
            ->acceptJson()
            ->timeout((int) config('services.ai.timeout', 45))
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.ai.model', 'gpt-5.6-sol'),
                'instructions' => $prompt,
                'input' => $question,
            ])->throw()->json();

        $content = $response['output_text'] ?? $this->outputText($response['output'] ?? []);
        if (! is_string($content) || trim($content) === '') {
            $content = 'Jeg kan ikke finde et entydigt svar i de tilgængelige dokumenter.';
        }

        return [
            'content' => $content,
            'confidence' => $sources === [] ? 'low' : 'source_grounded',
            'model' => (string) config('services.ai.model', 'gpt-5.6-sol'),
            'provider_metadata' => ['response_id' => $response['id'] ?? null],
            'sources' => $sources,
        ];
    }

    private function findSources(string $question): array
    {
        $stop = ['eller', 'ikke', 'skal', 'kan', 'med', 'det', 'den', 'der', 'som', 'til', 'for', 'fra', 'hvad', 'hvordan', 'hvor', 'har', 'jeg', 'vil', 'om', 'og', 'en', 'et', 'på', 'i', 'af'];
        $terms = array_values(array_unique(array_filter(
            preg_split('/[^\pL\pN]+/u', mb_strtolower($question)) ?: [],
            fn ($term) => mb_strlen($term) >= 3 && ! in_array($term, $stop, true)
        )));
        if ($terms === []) {
            return [];
        }

        $rows = DB::table('ai_document_chunks as c')
            ->join('ai_documents as d', 'd.id', '=', 'c.document_id')
            ->where('d.status', 'ready')->where('d.is_active', true)
            ->select('c.id as chunk_id', 'c.document_id', 'c.page_number', 'c.content', 'd.title', 'd.category', 'd.version')
            ->orderByDesc('d.updated_at')->limit(600)->get();
        $ranked = [];
        foreach ($rows as $row) {
            $haystack = mb_strtolower($row->title.' '.$row->content);
            $score = 0;
            foreach ($terms as $term) {
                $score += substr_count($haystack, $term) * (str_contains(mb_strtolower($row->title), $term) ? 3 : 1);
            }
            if ($score > 0) {
                $ranked[] = [
                    'chunk_id' => $row->chunk_id, 'document_id' => $row->document_id,
                    'title' => $row->title, 'category' => $row->category, 'version' => $row->version,
                    'page_number' => $row->page_number, 'content' => $row->content, 'score' => min(1, $score / 12),
                ];
            }
        }
        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($ranked, 0, 6);
    }

    private function systemPrompt(array $sources, array $context): string
    {
        $sourceText = collect($sources)->map(fn ($source, $index) => sprintf(
            "[Kilde %d] %s, side %s\n%s", $index + 1, $source['title'], $source['page_number'] ?? 'ukendt', $source['content']
        ))->implode("\n\n");
        $contextText = $context === [] ? 'Ingen bookingoplysninger er delt.' : json_encode($context, JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Du er en intern dansk fagassistent for en bilsynsvirksomhed. Svar udelukkende ud fra de medsendte kilder. Gæt aldrig. Hvis kilderne ikke giver et entydigt svar, skriv tydeligt: "Jeg kan ikke finde et entydigt svar i de tilgængelige dokumenter." Skeln mellem bindende regel, vejledning og intern fortolkning. Brug ikke personoplysninger ud over den udtrykkeligt delte bookingkontekst.

Svar med overskrifterne: Kort svar, Regelgrundlag, Praktisk vurdering, Dokumentation, Kilder. Henvis løbende med [Kilde N].

Bookingkontekst: {$contextText}

Kilder:
{$sourceText}
PROMPT;
    }

    private function sourceList(array $sources): string
    {
        return collect($sources)->map(fn ($s, $i) => sprintf('[Kilde %d] %s, side %s', $i + 1, $s['title'], $s['page_number'] ?? 'ukendt'))->implode("\n");
    }

    private function outputText(array $output): string
    {
        $parts = [];
        foreach ($output as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (isset($content['text'])) {
                    $parts[] = $content['text'];
                }
            }
        }

        return implode("\n", $parts);
    }
}
