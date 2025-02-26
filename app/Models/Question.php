<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model {
    protected $guarded = [ ];
    public function user() {
        return $this->belongsTo(User::class);
    }

    public static function getSortedQuestions() {
        return self::query()
            ->leftJoin('question_votes', 'questions.id', '=', 'question_votes.question_id')
            ->selectRaw('questions.*, coalesce(sum(question_votes.count), 0) as votes')
            ->orderBy('votes', 'desc')
            ->groupBy('questions.id')
            ->limit(1000)
            ->get();
    }
}
