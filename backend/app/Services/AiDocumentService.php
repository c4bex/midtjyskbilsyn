<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;

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
            'approval_status' => 'draft',
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'access_level' => 'employee',
            'is_active' => true,
            'uploaded_by' => $userId,
            'replaces_document_id' => $data['replaces_document_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->processDocument($documentId, $path, $extension);

        return (array) DB::table('ai_documents')->where('id', $documentId)->first();
    }

    public function reprocess(int $documentId): array
    {
        $document = DB::table('ai_documents')->where('id', $documentId)->first();
        if (! $document || ! Storage::disk('local')->exists($document->storage_path)) {
            throw new RuntimeException('Dokumentfilen findes ikke længere.');
        }
        DB::table('ai_document_chunks')->where('document_id', $documentId)->delete();
        DB::table('ai_documents')->where('id', $documentId)->update([
            'status' => 'processing', 'processing_error' => null, 'updated_at' => now(),
        ]);
        $extension = strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION));
        $this->processDocument($documentId, $document->storage_path, $extension);

        return (array) DB::table('ai_documents')->where('id', $documentId)->first();
    }

    private function processDocument(int $documentId, string $path, string $extension): void
    {
        try {
            $extraction = $this->extractPages(Storage::disk('local')->path($path), $extension);
            $chunks = [];
            foreach ($extraction['pages'] as $pageNumber => $text) {
                foreach ($this->chunk($text) as $content) {
                    $chunks[] = [
                        'document_id' => $documentId,
                        'page_number' => $pageNumber,
                        'section_title' => null,
                        'content' => $content,
                        'embedding' => null,
                        'metadata' => json_encode(['source' => $extraction['method']]),
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
                'status' => 'ready', 'processing_error' => null,
                'extraction_method' => $extraction['method'], 'ocr_attempted' => $extraction['ocr_attempted'],
                'content_hash' => hash_file('sha256', Storage::disk('local')->path($path)), 'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            DB::table('ai_documents')->where('id', $documentId)->update([
                'status' => 'error',
                'processing_error' => Str::limit($exception->getMessage(), 1000),
                'ocr_attempted' => $extension === 'pdf',
                'updated_at' => now(),
            ]);
        }
    }

    private function extractPages(string $path, string $extension): array
    {
        if ($extension === 'pdf') {
            $pages = [];
            foreach ((new Parser)->parseFile($path)->getPages() as $index => $page) {
                $pages[$index + 1] = $page->getText();
            }
            if (mb_strlen(implode(' ', $pages)) >= 80) {
                return ['pages' => $pages, 'method' => 'pdf_text', 'ocr_attempted' => false];
            }

            return ['pages' => $this->ocrPdf($path), 'method' => 'ocr', 'ocr_attempted' => true];
        }
        if (in_array($extension, ['txt', 'md', 'csv'], true)) {
            return ['pages' => [1 => (string) file_get_contents($path)], 'method' => 'plain_text', 'ocr_attempted' => false];
        }
        throw new RuntimeException('Filtypen understøttes ikke endnu. Brug PDF eller tekstfil.');
    }

    private function ocrPdf(string $path): array
    {
        if (! $this->commandExists('pdftoppm') || ! $this->commandExists('tesseract')) {
            throw new RuntimeException('PDF-filen ser scannet ud. OCR er klargjort, men Tesseract/Poppler mangler på denne server.');
        }
        $directory = sys_get_temp_dir().'/mb-ocr-'.Str::uuid();
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Kunne ikke oprette en midlertidig OCR-mappe.');
        }
        try {
            $convert = new Process(['pdftoppm', '-png', '-r', '200', $path, $directory.'/page']);
            $convert->setTimeout(180)->mustRun();
            $images = glob($directory.'/page-*.png') ?: [];
            natsort($images);
            $pages = [];
            foreach (array_values($images) as $index => $image) {
                $ocr = new Process(['tesseract', $image, 'stdout', '-l', 'dan+eng', '--psm', '6']);
                $ocr->setTimeout(120)->mustRun();
                $pages[$index + 1] = trim($ocr->getOutput());
            }
            if (trim(implode(' ', $pages)) === '') {
                throw new RuntimeException('OCR kunne ikke finde læsbar tekst i dokumentet.');
            }

            return $pages;
        } finally {
            foreach (glob($directory.'/*') ?: [] as $temporaryFile) {
                @unlink($temporaryFile);
            }
            @rmdir($directory);
        }
    }

    private function commandExists(string $command): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_executable($directory.DIRECTORY_SEPARATOR.$command)) {
                return true;
            }
        }

        return false;
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
