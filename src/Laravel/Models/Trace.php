<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Nordkit\Wiretap\HttpDirection;

/**
 * @property string $id
 * @property HttpDirection $direction
 * @property string $driver
 * @property string $url
 * @property string $method
 * @property array<string, string>|null $request_headers
 * @property string|null $request_body
 * @property int|null $response_status
 * @property array<string, string>|null $response_headers
 * @property string|null $response_body
 * @property int $duration_ms
 * @property string|null $error_message
 * @property string|null $traceable_type
 * @property string|null $traceable_id
 * @property Carbon|null $created_at
 */
class Trace extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'wiretap_traces';

    /** @var list<string> */
    protected $fillable = [
        'direction',
        'driver',
        'url',
        'method',
        'request_headers',
        'request_body',
        'response_status',
        'response_headers',
        'response_body',
        'duration_ms',
        'error_message',
        'traceable_type',
        'traceable_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => HttpDirection::class,
            'request_headers' => 'array',
            'response_headers' => 'array',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * Get the parent traceable model (polymorphic relation).
     *
     * @return MorphTo<Model, $this>
     */
    public function traceable(): MorphTo
    {
        return $this->morphTo();
    }
}
