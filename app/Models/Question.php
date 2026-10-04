<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    /** @use HasFactory<\Database\Factories\QuestionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Questions still in the queue (not archived by clearing a topic).
     *
     * @param  Builder<Question>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('questions.archived_at');
    }

    public static function getSortedQuestions($limit = 50)
    {
        return self::query()
            ->active()
            ->leftJoin('question_votes', 'questions.id', '=', 'question_votes.question_id')
            ->selectRaw('questions.*, coalesce(sum(question_votes.count), 0) as votes')
            ->orderBy('votes', 'desc')
            ->groupBy('questions.id')
            ->limit($limit)
            ->with('user')
            ->get();
    }

    public static function getRecentQuestions($limit = 50)
    {
        return self::query()
            ->active()
            ->leftJoin('question_votes', 'questions.id', '=', 'question_votes.question_id')
            ->selectRaw('questions.*, coalesce(sum(question_votes.count), 0) as votes')
            ->orderBy('id', 'desc')
            ->groupBy('questions.id')
            ->limit($limit)
            ->with('user')
            ->get();
    }

    public function voteCount(): int
    {
        return (int) QuestionVote::where('question_id', $this->id)->sum('count');
    }
}
