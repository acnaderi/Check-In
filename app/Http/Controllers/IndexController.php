<?php namespace App\Http\Controllers;

use Auth, Config, Request, Validator, Hash, Mail, View;
use App\User;
use App\Audit;
use App\Announcement;
use App\Setting;
use Illuminate\Routing\Controller;

class IndexController extends Controller {

    public function __construct() {
        $announcements = Announcement::where("hidden", "!=", 0)->orderBy("created_at", "DESC")->get();
        View::share('announcements', $announcements);
    }
    public function get_index()
    {
        //Is the member logged in?
        if (Auth::check())
        {
            return redirect()->route('lacheckin');
        }
        //They are not, let's redirect to the login page
        return redirect()->route('login');
    }

    public function get_login(Request $request)
    {

        $provider = new League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => 'la-manager',    // The client ID assigned to you by the provider
            'clientSecret'            =>  env('APP_OAUTH_KEY', 'SomeRandomKey'),   // The client password assigned to you by the provider
            'redirectUri'             =>  route('oauth'),
            'urlAuthorize'            => 'http://okpy.org/oauth/authorize',
            'urlAccessToken'          => 'http://okpy.org/oauth/token',
            'urlResourceOwnerDetails' => 'http://okpy.org/oauth/resource'
        ]);

        // Fetch our data from the request
        $code = Request::input('code');

        // Is the code provided?
        if (!is_null($code)) {
            // This is a callback request from OK
            $accessToken = $provider->getAccessToken('authorization_code', [
                'code' => $code
            ]);
            //Send a request to fetch user info
            $result = json_decode($okService->request('https://okpy.org/api/v3/user/?access_token=' . $accessToken), true);
            dd($result);
        }
        else {
            $authorizationUrl = $provider->getAuthorizationUrl();
            return redirect((string) $authorizationUrl);
        }
    }

    public function get_reset()
    {
        return view('reset');
    }

    public function post_reset()
    {
        $email = Request::input("inputEmail");
        $user = User::where("email", "=", $email)->first();
        if (empty($user))
            return redirect()->route("reset")->with("message", "There is no user with that email address");
        //Alright valid user let's generate a code for them
        $hash = md5($email . time());
        $user->reset = $hash;
        $user->save();
        $url = route("tokenauth", $hash);
        //Send the email
        $data = ['name' => $user->name, 'email' => $user->email, 'url' => $url];
        Mail::send('emails.reset', $data, function($message) use ($data)
        {
            $message->to($data["email"], $data["name"])->subject('CS61A - LAM Password Reset');
        });
        return redirect()->route("login")->with("message", "A one time log in link has been sent to your email. If you have any other questions please post on Piazza.");
    }

    public function get_tokenauth($token)
    {
        $user = User::where("reset", "=", $token)->first();
        if (empty($user))
            return redirect()->route("login")->with("message", "That token is either invalid or already used.");
        //Sign the user in
        Auth::loginUsingId($user->id);
        Audit::log("One time token login");
        //Delete the reset token
        $user->reset = "";
        $user->save();
        return redirect()->route("laaccount")->with("message", "Logged in successfully! Please update your password and information here.");
    }

    public function post_login()
    {
        $email = Request::input("inputEmail");
        $password = Request::input("inputPassword");
        $remember = (Request::has('inputRemember')) ? true: false;
        //Start our validator
        $validator = Validator::make([
            "email" => $email,
            "password" => $password,
        ], [
            "email" => "required|exists:users,email",
            "password" => "required",
        ], [
            "email.required" => "Please enter your email address.",
            "email.exists" => "There does not appear to be an account with that email address. Please try again or register an account.",
            "password.required" => "Please enter your password.",
        ]);
        if ($validator->fails())
        {
            //Darn, back to the login screen it is
            return redirect()->route("login")->withInput(Request::except("inputPassword"))->withErrors($validator);
        }
        //Alright all good, lets pull our user model
        $user = User::where("email", "=", $email)->first();
        //Let's try to log them in
        if (Auth::attempt(['email' => $email, 'password' => $password], $remember))
        {
            //Log the sign-in as an audit
            Audit::log("Logged in");

            //Great they are logged in. Let's redirect them to the appropriate page
            if ($user->is_gsi()) {
                return redirect()->route("taconsole");
            }
            return redirect()->route("lacheckin");
        }
        else
        {
            //Add an error to the message back
            $validator->errors()->add('password', 'Invalid password.');
            //Yikes back to the login screen we go
            return redirect()->route("login")->withInput(Request::except("inputPassword"))->withErrors($validator);
        }

    }

    public function get_logout()
    {
        Audit::log("Logged out");
        //Log the user out
        Auth::logout();
        //Redirect back to the index with a message
        return redirect()->route("login")->with("message", "You have been successfully logged out.");
    }

    public function get_registration()
    {
        //Return our registration view
        return view('registration');
    }

    public function post_registration()
    {
        //Get all of our form input
        $input = Request::all();
        //Start our validator
        $validator = Validator::make([
            "name" => $input["inputName"],
            "email" => $input["inputEmail"],
            "password" => $input["inputPassword"],
        ], [
            "name" => "required",
            "email" => "required|email|unique:users,email",
            "password" => "required|min:8",
        ], [
            "name.required" => "Please enter your full first and last name.",
            "email.required" => "Please enter your email.",
            "email.email" => "That does not appear to be a valid email address.",
            "password.required" => "You must enter a password.",
            "password.min" => "Your password must be at least 8 characters",
        ]);

        if ($validator->fails())
        {
            //We have issues. Redirect back to the form with all of the input except the password and include the error messages
            return redirect()->back()->withErrors($validator->errors())->withInput(Request::except('inputPassword'));
        }
        //Alright all of the validation is complete. Now to create our new user
        $user = new User;
        $user->name = $input["inputName"];
        $user->email = $input["inputEmail"];
        //Hash our password
        $hashedpasswd = Hash::make($input["inputPassword"]);
        $user->password = $hashedpasswd;
        //Save our model to the database
        $user->save();

        //Log the user in

        Auth::loginUsingId($user->id);
        //Create an audit log entry for this action
        Audit::log("Account created");

        //Redirect them to the checkin page with the following message
        return redirect()->route("lacheckin")->with("message", "Thanks " . $user->name . ", your account was successfully created. You can now check in to your lab sections using your credentials.");
    }

    public function get_information() {
        $informationContent = Setting::getValue("information_content");
        return view("information")->with(array("informationContent" => $informationContent));
    }

}
