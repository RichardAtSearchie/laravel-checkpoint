<?php

namespace Plank\Checkpoint\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * @property int $id
 * @property string $revisionable_type
 * @property int $revisionable_id
 * @property int $original_revisionable_id
 * @property int|null $previous_revision_id
 * @property int|null $checkpoint_id
 * @property mixed|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent $revisionable
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent $initialRevisionable
 * @property-read \Illuminate\Database\Eloquent\Collection|Revision[] $allRevisions
 * @property-read int|null $all_revisions_count
 * @property-read \Illuminate\Database\Eloquent\Collection|Revision[] $otherRevisions
 * @property-read int|null $other_revisions_count
 * @property-read Revision|null $newest
 * @property-read Revision|null $next
 * @property-read Revision|null $previous
 * @property-read Checkpoint|null $checkpoint
 * @method static Builder|Revision newModelQuery()
 * @method static Builder|Revision newQuery()
 * @method static Builder|Revision query()
 * @method static \Illuminate\Database\Query\Builder|Revision select($columns = ['*'])
 * @method static Builder|Revision whereId($value)
 * @method static Builder|Revision whereType($value)
 * @method static Builder|Revision whereRevisionableId($value)
 * @method static Builder|Revision whereRevisionableType($value)
 * @method static Builder|Revision whereOriginalRevisionableId($value)
 * @method static Builder|Revision wherePreviousRevisionId($value)
 * @method static Builder|Revision whereCheckpointId($value)
 * @method static Builder|Revision whereMetadata($value)
 * @method static Builder|Revision whereCreatedAt($value)
 * @method static Builder|Revision whereUpdatedAt($value)
 * @method static Builder|Revision latestIds($until = null, $since = null)
 * @mixin Model
 */
class Revision extends MorphPivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'revisions';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The name of the "updated at" column.
     *
     * @var string
     */
    const UPDATED_AT = 'updated_at';

    /**
     * The name of the "checkpoint id" column.
     *
     * @var string
     */
    const CHECKPOINT_ID = 'checkpoint_id';

    /**
     * The name of the "timeline id" column.
     *
     * @var string
     */
    const TIMELINE_ID = 'timeline_id';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'id',
        'metadata'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'metadata' => 'array'
    ];

    protected static function boot()
    {
        static::deleting(function (self $revision) {
            $next = $revision->next;
            // if newer revision exists, point its previous_revision_id to the previous revision of this item
            if ($next !== null) {
                $next->previous_revision_id = $revision->previous_revision_id;
                $next->save();
            } elseif ($revision->previous_revision_id !== null) {
                // if no newer revision exists, update the latest flag on the previous revision
                $revision->previous()->update(['latest' => true]);
            }

        });
        parent::boot();
    }

    /**
     * Get the name of the "checkpoint id" column.
     *
     * @return string
     */
    public function getCheckpointKeyName()
    {
        return static::CHECKPOINT_ID;
    }

    /**
     * Get the name of the "timeline id" column.
     *
     * @return string
     */
    public function getTimelineKeyName()
    {
        return static::TIMELINE_ID;
    }

    /**
     * Retrieve the revisioned model associated with this entry
     *
     * @return MorphTo
     */
    public function revisionable(): MorphTo
    {
        return $this->morphTo('revisionable')->withoutGlobalScopes();
    }

    /**
     * Retrieve the original model in this sequence
     *
     * @return MorphTo
     */
    public function initialRevisionable(): MorphTo
    {
        return $this->morphTo(
            'revisionable',
            'revisionable_type',
            'original_revisionable_id'
        )->withoutGlobalScopes();
    }

    /**
     * Retrieve the original model in this sequence
     *
     * @return MorphTo
     */
    public function originalRevisionable(): MorphTo
    {
        return $this->initialRevisionable();
    }

    /**
     * Return the associated timeline to this revision - should match the on checkpoint, if set
     *
     * @return BelongsTo
     */
    public function timeline(): BelongsTo
    {
        return $this->belongsTo(get_class(app(Timeline::class)), $this->getTimelineKeyName());
    }

    /**
     * Return the associated checkpoint/release to this revision
     *
     * @return BelongsTo
     */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(get_class(app(Checkpoint::class)), $this->getCheckpointKeyName());
    }

    /**
     * Return the revision made right before this one
     *
     * @return BelongsTo
     */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(static::class, 'previous_revision_id', $this->getKeyName());
    }

    /**
     * Return the revision that follows this one
     *
     * @return HasOne
     */
    public function next(): HasOne
    {
        return $this->hasOne(static::class, 'previous_revision_id', $this->getKeyName());
    }

    /**
     * Return latest revision
     *
     * @return HasOne
     */
    public function newest(): HasOne
    {
        return $this->hasOne(static::class, 'revisionable_type', 'revisionable_type')
            ->where('original_revisionable_id', $this->original_revisionable_id)
            ->where('latest', true)->latest();
    }

    /**
     * Returns true if this is the most current revision for an item
     *
     * @return bool
     */
    public function isLatest(): bool
    {
        return $this->next()->doesntExist();
    }

    /**
     * Returns true if this is the first revision for an item
     *
     * @return bool
     */
    public function isNew()
    {
        return $this->previous_revision_id === null;
    }

    /**
     * Returns true if item is new for the given bulletin
     *
     * @param  Checkpoint  $moment
     * @return bool
     */
    public function isNewAt(Checkpoint $moment): bool
    {
        if ($this->checkpoint()->exists()) {
            return $this->isNew() && $moment->is($this->checkpoint);
        }

        return $this->isNew();
    }

    /**
     * Returns true if this is revision is updated at the given bulletin
     *
     * @param  Checkpoint  $moment
     * @return bool
     */
    public function isUpdatedAt(Checkpoint $moment): bool
    {
        return !$this->isNew() && ($this->checkpoint()->doesntExist() || $moment->is($this->checkpoint));
    }

    /**
     * Return all the revisions that share the same item
     *
     * @return HasMany
     */
    public function allRevisions(): HasMany
    {
        return $this->hasMany(static::class, 'revisionable_type', 'revisionable_type')
            ->where('original_revisionable_id', $this->original_revisionable_id);
    }

    /**
     * Return all the revisions sibling that share the same item except the current one
     *
     * @return HasMany
     */
    public function otherRevisions(): HasMany
    {
        return $this->allRevisions()->whereKeyNot($this->getKey());
    }

    /**
     * Retrieve the latest revision ids within the boundary window given valid checkpoints, carbon or datetime strings
     *
     * @param  Builder  $q
     * @param  Checkpoint|\Illuminate\Support\Carbon|string|null  $until  valid checkpoint, carbon or datetime string
     * @param  Checkpoint|\Illuminate\Support\Carbon|string|null  $since  valid checkpoint, carbon or datetime string
     * @return Builder
     */
    public function scopeLatestIds(Builder $q, $until = null, $since = null)
    {
        $q->withoutGlobalScopes()->selectRaw("max({$this->getKeyName()})")
            ->groupBy(['original_revisionable_id', 'revisionable_type'])->orderByDesc('previous_revision_id');

        $checkpoint = app(Checkpoint::class);
        $keyname = $checkpoint->getKeyName();

        if ($until instanceof $checkpoint) {
            // where in given checkpoint or one of the previous ones
            $q->whereIn($this->getCheckpointKeyName(), $checkpoint->olderThanEquals($until)->select($keyname));
        } elseif ($until !== null) {
            $q->where($this->getQualifiedCreatedAtColumn(), '<=', $until);
        }

        if ($since instanceof $checkpoint) {
            // where in one of the newer checkpoints than given
            $q->whereIn($this->getCheckpointKeyName(), $checkpoint->newerThan($since)->select($keyname));
        } elseif ($since !== null) {
            $q->where($this->getQualifiedCreatedAtColumn(), '>', $since);
        }

        return $q;
    }

    /**
     * @param  Builder  $q
     * @param  Model|string|null  $type
     * @return Builder
     */
    public function scopeWhereType(Builder $q, $type = null)
    {
        if (is_string($type)) {
            $q->where('revisionable_type', $type);
        } elseif ($type instanceof Model) {
            if (str_contains(get_class($type), 'Models')) {
                $q->where('revisionable_type', str_replace("\Models", "", get_class($type)));
            } else {
                $q->where('revisionable_type', get_class($type));
            }
        }
        return $q;
    }
}
