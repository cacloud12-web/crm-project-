<?php

namespace App\Services\Leads;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;

class LeadOwnershipService
{
  public function __construct(
    private readonly RbacService $rbacService,
  ) {}

  public function canEdit(?User $user, CaMaster $lead): bool
  {
    if (! $user) {
      return false;
    }

    if ($this->rbacService->roleKey($user) !== 'employee') {
      return true;
    }

    $employeeId = Employee::query()->where('user_id', $user->id)->value('employee_id')
      ?: Employee::query()->where('email_id', $user->email)->value('employee_id');

    if (! $employeeId) {
      return false;
    }

    return LeadAssignmentEngine::query()
      ->where('ca_id', $lead->ca_id)
      ->where('employee_id', $employeeId)
      ->where('status', 'Active')
      ->exists();
  }

  public function assertCanEdit(?User $user, CaMaster $lead): void
  {
    if ($this->canEdit($user, $lead)) {
      return;
    }

    if ($this->rbacService->roleKey($user) !== 'employee') {
      throw new AuthorizationException('You do not have permission to edit this lead.');
    }

    $assignee = LeadAssignmentEngine::query()
      ->with('employee')
      ->where('ca_id', $lead->ca_id)
      ->where('status', 'Active')
      ->first();

    $name = $assignee?->employee?->name ?? 'another employee';

    throw new AuthorizationException("This lead is assigned to {$name} and cannot be edited by you.");
  }

  public function isReadOnlyForUser(?User $user, CaMaster $lead): bool
  {
    return $user && $this->rbacService->roleKey($user) === 'employee' && ! $this->canEdit($user, $lead);
  }
}
