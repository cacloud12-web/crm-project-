<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Http\Resources\DemoCalendarEventResource;
use App\Http\Resources\DemoProviderResource;
use App\Models\DemoSchedule;
use App\Services\Demo\DemoAvailabilityService;
use App\Services\Demo\DemoCalendarService;
use App\Services\Demo\DemoProviderService;
use App\Services\Demo\DemoScheduleCalendarService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DemoCalendarController extends Controller
{
    public function __construct(
        private readonly DemoCalendarService $calendarService,
        private readonly DemoAvailabilityService $availabilityService,
        private readonly DemoScheduleCalendarService $scheduleService,
        private readonly DemoProviderService $providerService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->calendarService->summary($request->user(), $request->query()),
            'Demo calendar summary loaded',
        );
    }

    public function events(Request $request): JsonResponse
    {
        $items = $this->calendarService->events($request->user(), $request->query());

        return ApiResponse::success(
            DemoCalendarEventResource::collection($items),
            'Demo calendar events loaded',
        );
    }

    public function availableSlots(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->calendarService->availableSlots($request->user(), $request->query()),
            'Available demo slots loaded',
        );
    }

    public function providers(): JsonResponse
    {
        return ApiResponse::success(
            $this->calendarService->providers(),
            'Demo providers loaded',
        );
    }

    public function checkConflict(Request $request): JsonResponse
    {
        try {
            $result = $this->availabilityService->checkConflict(
                $request->all(),
                $request->integer('ignore_schedule_id') ?: null,
            );

            return ApiResponse::success($result, $result['available'] ? 'Slot available' : 'Slot conflict detected');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function schedule(Request $request): JsonResponse
    {
        try {
            $result = $this->scheduleService->schedule($request->all(), $request->user());

            return ApiResponse::success([
                'demo_schedule' => new DemoCalendarEventResource($this->serializeSchedule($result['demo_schedule'])),
                'follow_up' => $result['follow_up'] ?? null,
            ], 'Demo scheduled');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function reschedule(Request $request, DemoSchedule $demoSchedule): JsonResponse
    {
        try {
            $schedule = $this->scheduleService->reschedule($demoSchedule, $request->all(), $request->user());

            return ApiResponse::success(
                new DemoCalendarEventResource($this->serializeSchedule($schedule)),
                'Demo rescheduled',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function cancel(Request $request, DemoSchedule $demoSchedule): JsonResponse
    {
        try {
            $schedule = $this->scheduleService->cancel($demoSchedule, $request->input('reason'), $request->user());

            return ApiResponse::success(
                new DemoCalendarEventResource($this->serializeSchedule($schedule)),
                'Demo cancelled',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function complete(DemoSchedule $demoSchedule): JsonResponse
    {
        try {
            $schedule = $this->scheduleService->markCompleted($demoSchedule, request()->user());

            return ApiResponse::success(
                new DemoCalendarEventResource($this->serializeSchedule($schedule)),
                'Demo marked completed',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function missed(DemoSchedule $demoSchedule): JsonResponse
    {
        try {
            $schedule = $this->scheduleService->markMissed($demoSchedule, request()->user());

            return ApiResponse::success(
                new DemoCalendarEventResource($this->serializeSchedule($schedule)),
                'Demo marked missed',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function providerSettings(): JsonResponse
    {
        return ApiResponse::success(
            DemoProviderResource::collection($this->providerService->list(true)),
            'Demo provider settings loaded',
        );
    }

    public function updateProvider(Request $request, int $providerId): JsonResponse
    {
        $provider = $this->providerService->update(
            $this->providerService->find($providerId),
            $request->all(),
        );

        if ($request->has('leaves')) {
            $provider = $this->providerService->syncLeaves($provider, $request->input('leaves', []));
        }

        return ApiResponse::success(new DemoProviderResource($provider), 'Demo provider updated');
    }

    public function createProvider(Request $request): JsonResponse
    {
        $provider = $this->providerService->create($request->all());
        if ($request->has('leaves')) {
            $provider = $this->providerService->syncLeaves($provider, $request->input('leaves', []));
        }

        return ApiResponse::success(new DemoProviderResource($provider), 'Demo provider created', 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSchedule(DemoSchedule $schedule): array
    {
        $end = $schedule->demo_end_at ?? $schedule->demo_at?->copy()->addHour();

        return [
            'id' => $schedule->id,
            'ca_id' => $schedule->ca_id,
            'followup_id' => $schedule->followup_id,
            'demo_provider_id' => $schedule->demo_provider_id,
            'demo_provider_name' => $schedule->demo_provider_name ?: $schedule->provider?->name,
            'employee_id' => $schedule->employee_id,
            'employee_name' => $schedule->employee?->name,
            'firm_name' => $schedule->firm_name ?: $schedule->lead?->firm_name,
            'ca_name' => $schedule->customer_name ?: $schedule->lead?->ca_name,
            'team_size' => $schedule->team_size ?: $schedule->lead?->team_size,
            'status' => $schedule->status,
            'status_label' => ucfirst(str_replace('_', ' ', $schedule->status)),
            'meeting_link' => $schedule->meeting_link,
            'notes' => $schedule->notes,
            'demo_at' => $schedule->demo_at?->toIso8601String(),
            'demo_end_at' => $end?->toIso8601String(),
            'time_label' => $schedule->demo_at?->format('g:i A'),
            'date_label' => $schedule->demo_at?->format('d M Y'),
        ];
    }
}
