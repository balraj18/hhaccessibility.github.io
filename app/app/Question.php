<?php

namespace App;
use Eloquent;
use DB;

class Question extends Eloquent
{
    protected $fillable = [
        'question_html',
    ];
	
	protected $table = 'question';
	
	public function getAccessibilityRating($location_id, $ratingSystem)
	{
		$answers = $this->answers()
			->join('user_answer as a2', 'a2.answered_by_user_id', '=', 'user_answer.answered_by_user_id')
			->where('a2.location_id', '=', $location_id)
			->whereRaw('a2.question_id = user_answer.question_id')
			->where('user_answer.location_id', '=', $location_id)
			->groupBy(['user_answer.answered_by_user_id', 'a2.when_submitted'])
			->havingRaw('max(user_answer.when_submitted) = a2.when_submitted')
			->get([DB::raw('avg(a2.answer_value) as answer_value')]);
		$sum = 0;
		$totalCount = 0;
		foreach ($answers as $answer)
		{
			$individualRating = intval($answer->answer_value);
			
			// count N/A the same as yes(1).
			if ($individualRating === 2)
				$individualRating = 1;
			
			$sum = $sum + $individualRating;
			$totalCount = $totalCount + 1;
		}
		if ($totalCount === 0)
			return 0;
		else
			return $sum * 100 / $totalCount;
	}

	public function answers()
	{
        return $this->hasMany('App\UserAnswer');		
	}
}
