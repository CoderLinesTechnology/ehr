<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The single writer of audit_logs. Call it from domain actions, inside the
 * same transaction as the change it describes, so the trail can never claim
 * something that was rolled back (or miss something that committed).
 */
final class AuditLogger
{
    /** Keys whose values never reach the audit trail. */
    private const REDACT = '/(password|secret|token|recovery|two_factor|api[_-]?key|credential|signature_data)/i';

    private ?string $requestId = null;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ?Request $request = null,
    ) {}

    /**
     * @param  string  $action  dot-namespaced verb: "appointment.cancelled"
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?string $summary = null,
        ?AuditContext $context = null,
        ?string $organizationId = null,
    ): AuditLog {
        $user = Auth::user();
        $context ??= $this->resolveContext();

        $log = new AuditLog;
        $log->forceFill([
            'occurred_at' => now(),
            'context' => $context->value,
            'action' => $action,
            'actor_user_id' => $user?->getAuthIdentifier(),
            'actor_label' => $user?->name,
            'organization_id' => $organizationId ?? $this->organizationIdFor($subject, $context),
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'summary' => $summary !== null ? self::cleanText($summary, 297) : null,
            'before' => $before !== null ? $this->redact($before) : null,
            'after' => $after !== null ? $this->redact($after) : null,
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
            'ip' => $this->request?->ip(),
            'user_agent' => $this->request ? self::cleanText((string) $this->request->userAgent(), 497) : null,
            'request_id' => $this->requestId(),
        ])->save();

        return $log;
    }

    /**
     * Record an update using the model's dirty attributes. Call BEFORE save().
     *
     * @param  list<string>  $ignore
     */
    public function recordChanges(string $action, Model $model, array $ignore = ['updated_at'], array $metadata = [], ?string $summary = null): ?AuditLog
    {
        $dirty = array_diff_key($model->getDirty(), array_flip($ignore));

        if ($dirty === []) {
            return null;
        }

        $before = [];
        foreach (array_keys($dirty) as $key) {
            $before[$key] = $model->getRawOriginal($key);
        }

        return $this->record($action, $model, $before, $dirty, $metadata, $summary);
    }

    private function resolveContext(): AuditContext
    {
        if ($this->tenant->has()) {
            return AuditContext::Organization;
        }

        $route = $this->request?->route();
        if ($route && str_starts_with((string) $route->getName(), 'platform.')) {
            return AuditContext::Platform;
        }

        return app()->runningInConsole() ? AuditContext::System : AuditContext::Public;
    }

    private function organizationIdFor(?Model $subject, AuditContext $context): ?string
    {
        if ($subject !== null) {
            $attributes = $subject->getAttributes();
            if (array_key_exists('organization_id', $attributes)) {
                return $attributes['organization_id'];
            }
            if ($subject->getMorphClass() === 'organization') {
                return $subject->getKey();
            }
        }

        return $context === AuditContext::Organization ? $this->tenant->id() : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::REDACT, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif ($value instanceof \BackedEnum) {
                $data[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format(DATE_ATOM);
            } elseif (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $data[$key] = mb_scrub($value, 'UTF-8');
            }
        }

        return $data;
    }

    private function requestId(): string
    {
        if ($this->requestId !== null) {
            return $this->requestId;
        }

        $header = $this->request?->headers->get('X-Request-Id');

        return $this->requestId = ($header !== null && Str::isUuid($header)) ? $header : (string) Str::uuid7();
    }

    /**
     * Attacker-controlled text (user agents, attempted emails) may be invalid
     * UTF-8 or carry control characters; PostgreSQL rejects the former, which
     * would make the audit insert — and the action it records — fail.
     */
    private static function cleanText(string $value, int $limit): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', mb_scrub($value, 'UTF-8'));

        return Str::limit($value, $limit);
    }

    /** Morph alias for a model class (for queries over audit_logs). */
    public static function aliasFor(string $class): string
    {
        return array_search($class, Relation::morphMap(), true) ?: $class;
    }
}
