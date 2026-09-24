<?php

namespace App\Application\Actions\Exclusions;

use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\DuplicateExclusionException;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventDayNotInEventException;
use App\Domain\Exceptions\ExclusionScheduleAlreadyEndedException;
use App\Domain\Exceptions\StudentNotFoundException;
use App\Domain\Exceptions\WindowNotFoundOnDayException;
use App\Domain\ValueObjects\QrPayload;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class CreateExclusion
{
    /**
     * Spec §5: identify the student by student number (manual entry) or
     * by scanning their QR code — the FormRequest guarantees exactly one
     * of student_number/qr_token is present before this ever runs.
     *
     * @param array{
     *     event_id: int,
     *     scope: string,
     *     event_day_id?: int|null,
     *     window_type?: string|null,
     *     reason: string,
     *     student_number?: string|null,
     *     qr_token?: string|null,
     *     batch_id?: string|null,
     * } $data
     *
     * @throws StudentNotFoundException
     * @throws EventAlreadyEndedException
     * @throws EventDayNotInEventException
     * @throws WindowNotFoundOnDayException
     * @throws ExclusionScheduleAlreadyEndedException
     * @throws DuplicateExclusionException
     */
    public function __invoke(array $data, Student $createdBy): Exclusion
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $student = $this->resolveStudent($data);
            $scope = ExclusionScope::from($data['scope']);

            // Locks the event row so a concurrent "end event" can't slip
            // through between this check and the INSERT (§6a point 2's
            // race-condition note, applied to creation as well as
            // removal).
            $event = EventModel::whereKey($data['event_id'])->lockForUpdate()->first();

            $windowType = isset($data['window_type']) ? WindowType::from($data['window_type']) : null;
            $eventDay = $this->resolveAndValidateTarget($event, $scope, $data['event_day_id'] ?? null, $windowType);

            if (Exclusion::hasActiveDuplicate($student->id, $event->id, [
                'scope' => $scope,
                'event_day_id' => $eventDay?->id,
                'window_type' => $windowType,
            ])) {
                throw new DuplicateExclusionException;
            }

            return Exclusion::create([
                'student_id' => $student->id,
                'event_id' => $event->id,
                'scope' => $scope,
                'event_day_id' => $eventDay?->id,
                'window_type' => $windowType,
                'reason' => $data['reason'],
                'status' => ExclusionStatus::Active,
                'batch_id' => $data['batch_id'] ?? null,
                'created_by' => $createdBy->id,
            ]);
        });
    }

    private function resolveStudent(array $data): Student
    {
        if (! empty($data['qr_token'])) {
            $payload = QrPayload::decrypt($data['qr_token']);
            $student = Student::where('student_number', $payload->studentNumber)->first();
        } else {
            $student = Student::where('student_number', $data['student_number'])->first();
        }

        if (! $student) {
            throw new StudentNotFoundException;
        }

        return $student;
    }

    /**
     * Validates the target scope exists and hasn't ended yet
     * (student-exclusion-feature-plan.md §2 rules 3 & 4), locking the
     * relevant row(s) so this check is atomic at commit time rather than
     * trusting whatever the UI displayed when the form was opened (§6a
     * point 2).
     *
     * @throws EventAlreadyEndedException
     * @throws EventDayNotInEventException
     * @throws WindowNotFoundOnDayException
     * @throws ExclusionScheduleAlreadyEndedException
     */
    private function resolveAndValidateTarget(
        EventModel $event,
        ExclusionScope $scope,
        ?int $eventDayId,
        ?WindowType $windowType,
    ): ?EventDay {
        if ($scope === ExclusionScope::Event) {
            if ($event->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot add an event-level exclusion because this event has already ended.'
                );
            }

            return null;
        }

        // Both Day and Window scope require a real, existing EventDay
        // that belongs to this event — the schedule must exist first.
        $eventDay = EventDay::whereKey($eventDayId)->first();

        if (! $eventDay || $eventDay->event_id !== $event->id) {
            throw new EventDayNotInEventException;
        }

        if ($scope === ExclusionScope::Day) {
            if ($eventDay->hasEnded(forUpdate: true)) {
                throw new ExclusionScheduleAlreadyEndedException(
                    'Cannot add an exclusion for this day because it has already ended.'
                );
            }

            return $eventDay;
        }

        // Window scope.
        if (! $eventDay->hasWindow($windowType)) {
            throw new WindowNotFoundOnDayException;
        }

        if ($eventDay->windowHasEnded($windowType, forUpdate: true)) {
            throw new ExclusionScheduleAlreadyEndedException(
                'Cannot add an exclusion for this window because it has already ended.'
            );
        }

        return $eventDay;
    }
}
