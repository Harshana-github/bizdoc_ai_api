<?php

namespace App\Services;

use App\Models\OcrProcess;
use App\Models\OcrResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OcrService
{
    protected $ocrResult;
    protected $ocrProcess;

    public function __construct(OcrResult $ocrResult, OcrProcess $ocrProcess)
    {
        $this->ocrResult = $ocrResult;
        $this->ocrProcess = $ocrProcess;
    }

    public function save(array $validated): OcrResult
    {
        // update
        if (!empty($validated['id'])) {
            $ocr = $this->ocrResult
                ->where('id', $validated['id'])
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $ocr->update([
                'file_path' => $validated['file_path'],
                'data'      => $validated['ocr_data'],
            ]);

            return $ocr;
        }

        // create
        return $this->ocrResult->create([
            'file_path' => $validated['file_path'],
            'data'      => $validated['ocr_data'],
            'user_id'   => Auth::id(),
        ]);
    }

    public function ocrProcessCreate($file, $storedPath, $mime)
    {
        return $this->ocrProcess->create([
            'original_name' => $file->getClientOriginalName(),
            'stored_path'   => $storedPath,
            'mime_type'     => $mime,
            'user_id'       => Auth::id(),
        ]);
    }

    public function processCount(): array
    {
        return [
            'total' => $this->ocrProcess->count(),
            'user'  => $this->ocrProcess
                ->where('user_id', Auth::id())
                ->count(),
        ];
    }

    public function history(int $perPage = 10): LengthAwarePaginator
    {
        return $this->ocrResult
            ->where('user_id', Auth::id())
            ->latest()
            ->paginate($perPage);
    }

    public function findById(int $id): OcrResult
    {
        return $this->ocrResult
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }
}
