<?php

namespace App\Http\Controllers\App\Scheduling;

use App\Domain\Shared\DomainException;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Runs a domain action and, when a business rule refuses it, goes back to the form with the
 * rule's safe message under the field the FORM calls it (the domain's names differ: the form has
 * a date and a time where the domain has a start instant).
 */
trait MapsDomainErrors
{
    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @param  array<string, string>  $fields  domain field => form field
     * @return T
     */
    protected function attempt(Closure $action, array $fields = []): mixed
    {
        try {
            return $action();
        } catch (DomainException $e) {
            $field = $fields[$e->field()] ?? $e->field() ?? 'domain';

            throw new HttpResponseException(
                back()->withInput(request()->except(['password', '_token']))
                    ->withErrors([$field => $e->userMessage()])
                    ->with('error', $e->userMessage()),
            );
        }
    }
}
