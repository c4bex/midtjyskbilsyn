<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Parser;

class AiDocumentService
{
    public function store(array $data, UploadedFile $file, ?int $userId): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('ai-documents', Str::uuid().'.'.$extension, 'local');
        if (! $path) {
            throw new RuntimeException('Dokumentet kunne ikke gemmes.');
        }

        $documentId = DB::table('ai_documents')->insertGetId([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'publisher' => $data['publisher'] ?? null,
            'version' => $data['version'] ?? null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
            'status' => 'processing',
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'access_level' => 'employee',
            'is_active' => true,
            'uploaded_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $pages = $this->extractPages(Storage::disk('local')->path($path), $extension);
            $chunks = [];
            foreach ($pages as $pageNumber => $text) {
                foreach ($this->chunk($text) as $content) {
                    $chunks[] = [
                        'document_id' => $documentId,
                        'page_number' => $pageNumber,
                        'section_title' => null,
                        'content' => $content,
                        'embedding' => null,
                        'metadata' => json_encode(['source' => 'local-extraction']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            if ($chunks === []) {
                throw new RuntimeException('Dokumentet indeholder ingen læsbar tekst.');
            }
            DB::table('ai_document_chunks')->insert($chunks);
            DB::table('ai_documents')->where('id', $documentId)->update([
                'status' => 'ready', 'processing_error' => null, 'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            DB::table('ai_documents')->where('id', $documentId)->update([
                'status' => 'error',
                'processing_error' => Str::limit($exception->getMessage(), 1000),
                'updated_at' => now(),
            ]);
        }

        return (array) DB::table('ai_documents')->where('id', $documentId)->first();
    }

    private function extractPages(string $path, string $extension): array
    {
        if ($extension === 'pdf') {
            $pages = [];
            foreach ((new Parser)->parseFile($path)->getPages() as $index => $page) {
                $pages[$index + 1] = $page->getText();
            }

            return $pages;
        }
        if (in_array($extension, ['txt', 'md', 'csv'], true)) {
            return [1 => (string) file_get_contents($path)];
        }
        throw new RuntimeException('Filtypen understøttes ikke endnu. Brug PDF eller tekstfil.');
    }

    private function chunk(string $text, int $size = 1800, int $overlap = 220): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        $result = [];
        $length = mb_strlen($text);
        for ($start = 0; $start < $length; $start += max(1, $size - $overlap)) {
            $part = trim(mb_substr($text, $start, $size));
            if ($part !== '') {
                $result[] = $part;
            }
            if ($start + $size >= $length) {
                break;
            }
        }

        return $result;
    }
}
