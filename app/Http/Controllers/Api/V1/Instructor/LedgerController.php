<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Enums\LedgerEntryType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LedgerEntryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Every signed movement behind the instructor's balance, newest first.
 */
class LedgerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['type' => ['sometimes', Rule::enum(LedgerEntryType::class)]]);

        $entries = $request->user()->ledgerEntries()
            ->when(isset($validated['type']), fn ($query) => $query->where('type', $validated['type']))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return LedgerEntryResource::collection($entries);
    }
}
