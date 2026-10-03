<?php

namespace App\Http\Controllers\App\Resources;

use App\Domain\Resources\ArchiveResource;
use App\Domain\Resources\PublishResource;
use App\Domain\Resources\ResourceAudience;
use App\Domain\Resources\ResourceFiles;
use App\Domain\Resources\ResourceType;
use App\Domain\Resources\SaveResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Resources\SaveResourceRequest;
use App\Models\Resource;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Create, edit, publish and archive (resources.manage). Thin: validate, call one domain action, redirect. */
final class ManageResourceController extends Controller
{
    public function create(): View
    {
        return $this->form(null);
    }

    public function store(SaveResourceRequest $request, SaveResource $save): RedirectResponse
    {
        $resource = $save($request->resourceInput(), $request->file('file'));

        return redirect()->route('app.resources.show', ['resource' => $resource])
            ->with('success', 'The resource was saved as a draft. Publish it when it is ready.');
    }

    public function edit(Resource $resource): View
    {
        return $this->form($resource);
    }

    public function update(SaveResourceRequest $request, Resource $resource, SaveResource $save): RedirectResponse
    {
        $save($request->resourceInput(), $request->file('file'), $resource, $request->boolean('remove_file'));

        return redirect()->route('app.resources.show', ['resource' => $resource])->with('success', 'The resource was saved.');
    }

    public function publish(Resource $resource, PublishResource $publish): RedirectResponse
    {
        $publish($resource);

        return redirect()->route('app.resources.show', ['resource' => $resource])->with('success', 'The resource is published.');
    }

    public function archive(Resource $resource, ArchiveResource $archive): RedirectResponse
    {
        $archive($resource);

        return redirect()->route('app.resources.show', ['resource' => $resource])->with('success', 'The resource was archived.');
    }

    private function form(?Resource $resource): View
    {
        return view('app.resources.form', [
            'resource' => $resource,
            'types' => collect(ResourceType::cases())->mapWithKeys(fn (ResourceType $t) => [$t->value => $t->label()])->all(),
            'audiences' => collect(ResourceAudience::cases())->mapWithKeys(fn (ResourceAudience $a) => [$a->value => $a->label()])->all(),
            'maxMegabytes' => ResourceFiles::MAX_BYTES / 1_048_576,
        ]);
    }
}
