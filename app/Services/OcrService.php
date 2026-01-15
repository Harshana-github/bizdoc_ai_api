<?php

namespace App\Services;

use App\Models\OcrResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OcrService
{
    protected $ocrResult;

    public function __construct(OcrResult $ocrResult)
    {
        $this->ocrResult = $ocrResult;
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
