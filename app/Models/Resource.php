<?php

namespace App\Models;

use App\Domain\Resources\ResourceAudience;
use App\Domain\Resources\ResourceStatus;
use App\Domain\Resources\ResourceType;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A guide, form, document, video or FAQ the organization publishes. Status, featured flag, publication
 * time, the stored file and its size are written by the Resources domain actions only (not mass-assignable).
 */
#[Fillable(['type', 'title', 'summary', 'body', 'external_url', 'reading_minutes', 'audience'])]
class Resource extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'type' => ResourceType::class,
            'audience' => ResourceAudience::class,
            'status' => ResourceStatus::class,
            'is_featured' => 'boolean',
            'reading_minutes' => 'integer',
            'file_size_bytes' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === ResourceStatus::Published;
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null && $this->file_path !== '';
    }

    /** "PDF" when a PDF is attached, else the type ("Form", "Video"...). */
    public function formatLabel(): string
    {
        return $this->hasFile() ? 'PDF' : $this->type->label();
    }

    /** "PDF • 5 min read" (comp 09 featured cards). */
    public function metaLine(): string
    {
        $parts = [$this->formatLabel()];
        if ($this->reading_minutes !== null) {
            $parts[] = $this->type->minutesLabel($this->reading_minutes);
        }

        return implode(' • ', $parts);
    }
}
