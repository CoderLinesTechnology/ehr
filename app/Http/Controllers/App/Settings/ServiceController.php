<?php

namespace App\Http\Controllers\App\Settings;

use App\Domain\Clients\ClientFormOptions;
use App\Domain\Organization\ChangeServiceStatus;
use App\Domain\Organization\SaveService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ServiceRequest;
use App\Models\Service;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Settings → Services. Prices are typed as decimals, stored as integer minor units in the service's own currency. */
final class ServiceController extends Controller
{
    public function index(): View
    {
        return view('app.settings.services.index', [
            'services' => Service::query()->orderByDesc('is_active')->orderBy('sort')->orderBy('name')->limit(300)->get(),
            'currency' => tenant()->organizationOrFail()->currency,
        ]);
    }

    public function create(ClientFormOptions $options): View
    {
        return view('app.settings.services.form', $this->formData($options, null));
    }

    public function store(ServiceRequest $request, SaveService $save): RedirectResponse
    {
        $service = $save($request->details());

        return redirect()->route('app.settings.services.index')->with('success', "{$service->name} was added.");
    }

    public function edit(Service $service, ClientFormOptions $options): View
    {
        return view('app.settings.services.form', $this->formData($options, $service));
    }

    public function update(ServiceRequest $request, Service $service, SaveService $save): RedirectResponse
    {
        $save($request->details(), $service);

        return redirect()->route('app.settings.services.index')->with('success', "{$service->name} was saved.");
    }

    public function status(Request $request, Service $service, ChangeServiceStatus $change): RedirectResponse
    {
        $active = $request->boolean('active');
        $change($service, $active);

        return redirect()->route('app.settings.services.index')
            ->with('success', $active ? "{$service->name} is available again." : "{$service->name} was switched off.");
    }

    /** @return array<string, mixed> */
    private function formData(ClientFormOptions $options, ?Service $service): array
    {
        $currency = $service?->currency ?? tenant()->organizationOrFail()->currency;
        $decimal = static fn (?int $minor): ?string => $minor === null ? null : Money::toDecimalString($minor, $currency);

        return [
            'service' => $service,
            'currency' => $currency,
            'providers' => $options->clinicians(),
            'locations' => $options->locations(),
            'prices' => $service === null ? [] : [
                'price' => $decimal($service->price_minor),
                'late_cancellation_fee' => $decimal($service->late_cancellation_fee_minor),
                'no_show_fee' => $decimal($service->no_show_fee_minor),
            ],
            'providerIds' => $service?->providers()->pluck('organization_memberships.id')->all() ?? [],
            'locationIds' => $service?->locations()->pluck('locations.id')->all() ?? [],
        ];
    }
}
