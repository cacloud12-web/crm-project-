<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Services\Leads\DuplicateAttemptService;
use App\Services\Leads\DuplicateLeadDetectionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadDuplicateCheckController extends Controller
{
    public function __construct(
        private readonly DuplicateLeadDetectionService $duplicateLeadDetection,
        private readonly DuplicateAttemptService $duplicateAttemptService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
            'exclude_ca_id' => ['nullable', 'integer', 'min:1'],
            'field_name' => ['nullable', 'string', 'max:32'],
            'browser' => ['nullable', 'string', 'max:255'],
        ]);

        $excludeCaId = isset($validated['exclude_ca_id']) ? (int) $validated['exclude_ca_id'] : null;
        $fieldName = $validated['field_name'] ?? 'mobile_no';
        $browser = $validated['browser'] ?? null;

        $duplicate = $this->duplicateLeadDetection->checkMobile(
            $validated['mobile'],
            $excludeCaId,
        );

        if ($duplicate) {
            $attempt = $this->duplicateAttemptService->logDuplicate(
                $duplicate,
                $validated['mobile'],
                $request->user(),
                $excludeCaId,
                $fieldName,
                $browser,
            );

            return ApiResponse::error('Duplicate Number Found', 409, [
                'duplicate' => array_merge($duplicate, [
                    'attempt_id' => $attempt->id,
                    'title' => 'Duplicate Number Found',
                ]),
            ]);
        }

        $similar = $this->duplicateAttemptService->checkSimilar($validated['mobile'], $excludeCaId);
        $potentialAttemptId = null;

        if ($similar) {
            $attempt = $this->duplicateAttemptService->logPotentialDuplicate(
                $similar,
                $validated['mobile'],
                $request->user(),
                $excludeCaId,
                $fieldName,
                $browser,
            );
            $potentialAttemptId = $attempt->id;
        }

        return ApiResponse::success([
            'duplicate' => false,
            'normalized_mobile' => $this->duplicateLeadDetection->normalize($validated['mobile']),
            'potential_duplicate' => $similar,
            'potential_attempt_id' => $potentialAttemptId,
        ]);
    }
}
