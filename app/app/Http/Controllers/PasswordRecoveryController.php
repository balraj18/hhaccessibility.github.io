<?php namespace App\Http\Controllers;

use App\BaseUser;
use App\User;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Redirect;

class PasswordRecoveryController extends Controller {

	public function form(Request $request)
	{
		return view('pages.password_recovery.form');
	}

	public function sendEmail(Request $request)
	{
		$validation_rules = array(
			'email'                 => 'required|email',  
			'g-recaptcha-response'  => 'required|captcha'
		);
		$validator = Validator::make(Input::all(), $validation_rules);
		if ($validator->fails())
		{
			return Redirect::to('password-recovery')->withErrors($validator)->withInput();;
		}
		$email = trim(Input::get('email'));
		// find user with matching email address.
		$matching_user = User::where('email', '=', $email)->first();
		if ( !$matching_user ) {
			return view('pages.password_recovery.unmatched_email');
		}
		
		mail($email, 'Password Recovery', 'Your password has been reset.');
		return view('pages.password_recovery.email_sent');
	}
}