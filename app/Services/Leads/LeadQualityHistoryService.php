<?php

namespace App\Services\Leads;

use App\Models\CaMaster;
use App\Models\LeadQualityHistory;
use App\Models\User;
use App\Services\Rbac\EmployeeDataScopeService;

class LeadQualityHistoryService
{
  public const EVENT_WRONG_NUMBER = 'wrong_number';
  public const EVENT_INVALID_MOBILE = 'invalid_mobile';
  public const EVENT_DUPLICATE_ATTEMPT = 'duplicate_attempt';
  public const EVENT_VERIFIED = 'verified';
  public const EVENT_MOBILE_VERIFIED = 'mobile_verified';
  public const EVENT_SMS_FAILED = 'sms_failed';
  public const EVENT_WHATSAPP_FAILED = 'whatsapp_failed';
  public const EVENT_EMAIL_FAILED = 'email_failed';

  public function __construct(
    private readonly EmployeeDataScopeService $employeeDataScope,
    private readonly EmployeeProductivityService $productivityService,
  ) {}

  /**
   * @param  array<string, mixed>  $metadata
   */
  public function record(
    CaMaster $lead,
    string $eventType,
    ?string $reason = null,
    ?int $employeeId = null,
    array $metadata = [],
  ): LeadQualityHistory {
    $history = LeadQualityHistory::query()->create([
      'ca_id' => $lead->ca_id,
      'employee_id' => $employeeId,
      'event_type' => $eventType,
      'reason' => $reason,
      'metadata' => $metadata === [] ? null : $metadata,
      'recorded_at' => now(),
    ]);

    if ($employeeId) {
      $this->productivityService->refreshDailySnapshot($employeeId);
    }

    return $history;
  }

  public function markWrongNumber(CaMaster $lead, string $reason, ?User $actor = null): void
  {
    if ($lead->is_wrong_number) {
      return;
    }

    $employeeId = $this->employeeDataScope->resolveEmployeeId($actor ?? auth()->user());

    $lead->update([
      'is_wrong_number' => true,
      'wrong_number_reason' => $reason,
      'status' => in_array($lead->status, ['Wrong Number', 'Number Missing'], true) ? $lead->status : 'Wrong Number',
    ]);

    $this->record($lead->fresh(), self::EVENT_WRONG_NUMBER, $reason, $employeeId);
  }

  public function markVerified(CaMaster $lead, ?User $actor = null): void
  {
    if ($lead->is_verified) {
      return;
    }

    $employeeId = $this->employeeDataScope->resolveEmployeeId($actor ?? auth()->user());

    $lead->update([
      'is_verified' => true,
      'verified_by' => $employeeId,
    ]);

    $this->record($lead->fresh(), self::EVENT_VERIFIED, 'Lead verified', $employeeId);
  }

  public function recordCommunicationFailure(
    CaMaster $lead,
    string $channel,
    string $reason,
    ?int $employeeId = null,
  ): void {
    $event = match ($channel) {
      'sms' => self::EVENT_SMS_FAILED,
      'whatsapp' => self::EVENT_WHATSAPP_FAILED,
      'email' => self::EVENT_EMAIL_FAILED,
      default => $channel.'_failed',
    };

    if ($this->isInvalidNumberFailure($reason)) {
      $this->markWrongNumber($lead, $reason);
    }

    $this->record($lead, $event, $reason, $employeeId);
  }

  public function isInvalidNumberFailure(string $reason): bool
  {
    $needles = config('crm_duplicates.invalid_number_failure_patterns', []);

    foreach ($needles as $needle) {
      if (stripos($reason, $needle) !== false) {
        return true;
      }
    }

    return false;
  }
}
