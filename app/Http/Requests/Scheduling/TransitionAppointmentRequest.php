<?php

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A status change. Who may do it depends on the TARGET: cancelling and marking no-show need
 * `appointments.cancel`, every other change `appointments.edit` (AppointmentPolicy).
 */
final class TransitionAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $to = AppointmentStatus::tryFrom((string) $this->input('status'));
        if ($to === null) {
            return Gate::allows('update', $this->route('appointment'));
        }

        return Gate::allows($this->cancelling($to) ? 'cancel' : 'update', $this->route('appointment'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
            'cancellation_kind' => ['nullable', Rule::enum(CancellationKind::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function status(): AppointmentStatus
    {
        return AppointmentStatus::from((string) $this->input('status'));
    }

    public function cancelling(AppointmentStatus $to): bool
    {
        return in_array($to, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true);
    }
}
