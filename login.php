<?php

session_start();
if(empty($_SESSION["auth_csrf"])) $_SESSION["auth_csrf"]=bin2hex(random_bytes(24));

$login_error = "";

/*
|--------------------------------------------------------------------------
| RENDER POSTGRESQL DATABASE
|--------------------------------------------------------------------------
| Render provides DATABASE_URL through Environment Variables.
|--------------------------------------------------------------------------
*/

$database_url = getenv("DATABASE_URL");

if (!$database_url) {
    die("DATABASE_URL is not configured in Render.");
}

try {

    $db = parse_url($database_url);

    if (!$db || !isset($db["host"])) {
        die("Invalid DATABASE_URL.");
    }

    $db_host = $db["host"];
    $db_port = $db["port"] ?? 5432;
    $db_name = isset($db["path"])
        ? ltrim($db["path"], "/")
        : "";

    $db_user = isset($db["user"])
        ? rawurldecode($db["user"])
        : "";

    $db_password = isset($db["pass"])
        ? rawurldecode($db["pass"])
        : "";

    $pdo = new PDO(
        "pgsql:host={$db_host};port={$db_port};dbname={$db_name}",
        $db_user,
        $db_password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | AUTOMATICALLY CREATE USERS TABLE
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id BIGSERIAL PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
        )
    ");

} catch (PDOException $e) {

    die("Unable to connect to database.");

}


/* Default hard-coded accounts removed: signup and administrator provisioning only. */
$pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS display_name VARCHAR(150), ADD COLUMN IF NOT EXISTS country_code VARCHAR(2), ADD COLUMN IF NOT EXISTS chat_number VARCHAR(24), ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMPTZ");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS users_chat_number_unique ON users(chat_number) WHERE chat_number IS NOT NULL");
$pdo->exec("CREATE TABLE IF NOT EXISTS signup_otps (email VARCHAR(255) PRIMARY KEY, display_name VARCHAR(150) NOT NULL, password_hash VARCHAR(255) NOT NULL, otp_hash VARCHAR(255) NOT NULL, expires_at TIMESTAMPTZ NOT NULL, attempts INT NOT NULL DEFAULT 0, sent_at TIMESTAMPTZ NOT NULL DEFAULT now(), verified_at TIMESTAMPTZ)");
function sendSignupOtp(string $to,string $otp):bool {
 $key=getenv('BREVO_API_KEY')?:''; $from=getenv('OTP_FROM_EMAIL')?:'';
 if(!$key||!filter_var($from,FILTER_VALIDATE_EMAIL)||!function_exists('curl_init'))return false;
 $payload=json_encode(['sender'=>['email'=>$from,'name'=>'Coffee Sales Analysis'],'to'=>[['email'=>$to]],'subject'=>'Your account verification code','textContent'=>"Your verification code is $otp. It expires in 10 minutes. Do not share it."]);
 $ch=curl_init('https://api.brevo.com/v3/smtp/email');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['api-key: '.$key,'Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12]);curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return $code>=200&&$code<300;
}
function verifiedCountry(float $lat,float $lon):?string {
 if(!function_exists('curl_init'))return null;
 $url='https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=3&addressdetails=1&lat='.rawurlencode((string)$lat).'&lon='.rawurlencode((string)$lon);
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_USERAGENT=>'CoffeeSalesSignup/1.0 ('.(getenv('OTP_FROM_EMAIL')?:'admin@example.com').')']);$json=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($code!==200||!is_string($json))return null;return strtoupper((string)(json_decode($json,true)['address']['country_code']??''))?:null;
}
$countries=['TZ'=>'+255','KE'=>'+254','UG'=>'+256','RW'=>'+250','BI'=>'+257','ZA'=>'+27'];
$signupStep=$_SESSION['signup_step']??'start';$signupMessage='';
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['signup_action'])){
 try {
 if(!hash_equals($_SESSION['auth_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Security token expired. Reload and try again.');
 $act=(string)$_POST['signup_action'];
 if($act==='start'){
  $email=strtolower(trim((string)($_POST['email']??'')));$name=trim((string)($_POST['display_name']??''));$password=(string)($_POST['new_password']??'');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>100||mb_strlen($name)<2||mb_strlen($name)>150||strlen($password)<12)throw new RuntimeException('Enter a valid email, name and password of at least 12 characters.');
  $q=$pdo->prepare('SELECT 1 FROM users WHERE lower(username)=?');$q->execute([$email]);if($q->fetchColumn())throw new RuntimeException('This email is already registered. Please log in.');
  $q=$pdo->prepare('SELECT sent_at FROM signup_otps WHERE email=?');$q->execute([$email]);$last=$q->fetchColumn();if($last&&time()-strtotime($last)<60)throw new RuntimeException('Please wait one minute before requesting another code.');
  $otp=(string)random_int(100000,999999);if(!sendSignupOtp($email,$otp))throw new RuntimeException('Could not send email. Check email service configuration or try later.');
  $q=$pdo->prepare("INSERT INTO signup_otps(email,display_name,password_hash,otp_hash,expires_at,attempts,sent_at,verified_at) VALUES(?,?,?,?,now()+interval '10 minutes',0,now(),NULL) ON CONFLICT(email) DO UPDATE SET display_name=excluded.display_name,password_hash=excluded.password_hash,otp_hash=excluded.otp_hash,expires_at=excluded.expires_at,attempts=0,sent_at=now(),verified_at=NULL");$q->execute([$email,$name,password_hash($password,PASSWORD_DEFAULT),password_hash($otp,PASSWORD_DEFAULT)]);
  $_SESSION['signup_email']=$email;$_SESSION['signup_step']='otp';$signupStep='otp';$signupMessage='A six-digit code has been sent to your email.';
 }elseif($act==='otp'){
  $email=(string)($_SESSION['signup_email']??'');$code=trim((string)($_POST['otp']??''));$q=$pdo->prepare('SELECT * FROM signup_otps WHERE email=?');$q->execute([$email]);$r=$q->fetch();
  if(!$r||strtotime($r['expires_at'])<time()||(int)$r['attempts']>=5)throw new RuntimeException('Code expired or too many attempts. Restart signup.');
  $pdo->prepare('UPDATE signup_otps SET attempts=attempts+1 WHERE email=?')->execute([$email]);
  if(!preg_match('/^[0-9]{6}$/',$code)||!password_verify($code,$r['otp_hash']))throw new RuntimeException('Incorrect verification code.');
  $pdo->prepare('UPDATE signup_otps SET verified_at=now() WHERE email=?')->execute([$email]);$_SESSION['signup_step']='country';$signupStep='country';$signupMessage='Email verified. Select your country and allow location access.';
 }elseif($act==='country'){
  $email=(string)($_SESSION['signup_email']??'');$country=(string)($_POST['country']??'');$lat=filter_var($_POST['lat']??null,FILTER_VALIDATE_FLOAT);$lon=filter_var($_POST['lon']??null,FILTER_VALIDATE_FLOAT);
  if(!isset($countries[$country])||$lat===false||$lon===false||abs($lat)>90||abs($lon)>180)throw new RuntimeException('Select a supported country and allow location access.');
  $q=$pdo->prepare("SELECT * FROM signup_otps WHERE email=? AND verified_at IS NOT NULL AND verified_at>now()-interval '30 minutes'");$q->execute([$email]);$r=$q->fetch();if(!$r)throw new RuntimeException('Verification expired. Restart signup.');
  $actual=verifiedCountry((float)$lat,(float)$lon);if(!$actual)throw new RuntimeException('Location verification service unavailable. Please try again.');if($actual!==$country)throw new RuntimeException('Your detected country does not match your selection.');
  $pdo->beginTransaction();try{
   $id=null;$number=null;
   for($i=0;$i<150;$i++){
    // App-only virtual contact identifier, NOT a real telephone number.
    $first=(string)random_int(1,9);$suffix=$i<100?str_repeat((string)random_int(0,9),4).str_pad((string)random_int(0,9999),4,'0',STR_PAD_LEFT):str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT);
    $candidate=$countries[$country].$first.$suffix;
    $q=$pdo->prepare('INSERT INTO users(username,password,display_name,country_code,chat_number,email_verified_at) VALUES(?,?,?,?,?,now()) ON CONFLICT DO NOTHING RETURNING id');$q->execute([$email,$r['password_hash'],$r['display_name'],$country,$candidate]);$id=$q->fetchColumn();if($id){$number=$candidate;break;}
   }
   if(!$id)throw new RuntimeException('Could not allocate a unique chat number.');
   $pdo->prepare('DELETE FROM signup_otps WHERE email=?')->execute([$email]);$pdo->commit();session_regenerate_id(true);$_SESSION['logged_in']=true;$_SESSION['user_id']=(int)$id;$_SESSION['username']=$email;unset($_SESSION['signup_step'],$_SESSION['signup_email']);header('Location: index.php');exit;
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
 }else{throw new RuntimeException('Unknown signup action.');}
 }catch(Throwable $e){$signupMessage=$e->getMessage();}
}
/*
|--------------------------------------------------------------------------
| LOGIN PROCESS
|--------------------------------------------------------------------------
*/

if (isset($_POST["login"])) {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    $stmt = $pdo->prepare("
        SELECT id, username, password
        FROM users
        WHERE username = :username
        LIMIT 1
    ");

    $stmt->execute([
        ":username" => $username
    ]);

    $user = $stmt->fetch();

    if (
        $user &&
        password_verify($password, $user["password"])
    ) {

        session_regenerate_id(true);

        $_SESSION["logged_in"] = true;
        $_SESSION["user_id"] = $user["id"];
        $_SESSION["username"] = $user["username"];

        header("Location: index.php");
        exit;

    } else {

        $login_error = "Invalid username or password.";

    }
}


/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/

if (isset($_GET["logout"])) {

    session_unset();
    session_destroy();

    header("Location: login.php");
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>
Coffee Sales Data Analysis System
</title>


<style>
/* =========================================================
   GLOBAL
========================================================= */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f5f6f8;

    color: #333;

    overflow-x: hidden;
}


/* =========================================================
   LOGIN PAGE
========================================================= */

.login-page {

    min-height: 100vh;

    display: flex;

    align-items: center;

    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #2b1b18,
            #4e342e,
            #795548
        );

    padding: 20px;

}


/* LOGIN CONTAINER */

.login-container {

    width: 100%;

    max-width: 430px;

}


/* LOGIN CARD */

.login-card {

    background: white;

    border-radius: 18px;

    padding: 42px 38px;

    box-shadow:
        0 20px 60px rgba(0,0,0,0.25);

    animation: loginAppear 0.5s ease;

}


/* ANIMATION */

@keyframes loginAppear {

    from {

        opacity: 0;

        transform:
            translateY(20px)
            scale(0.98);

    }

    to {

        opacity: 1;

        transform:
            translateY(0)
            scale(1);

    }

}


/* LOGIN LOGO */

.login-logo {

    width: 75px;

    height: 75px;

    margin: 0 auto 20px;

    border-radius: 50%;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #3e2723;

    color: white;

    font-size: 38px;

    box-shadow:
        0 8px 20px rgba(62,39,35,0.25);

}


/* LOGIN TITLE */

.login-card h1 {

    text-align: center;

    color: #3e2723;

    font-size: 25px;

    margin-bottom: 7px;

}


/* LOGIN SUBTITLE */

.login-subtitle {

    text-align: center;

    color: #777;

    font-size: 13px;

    margin-bottom: 30px;

}


/* FORM GROUP */

.form-group {

    margin-bottom: 20px;

}


/* LABEL */

.form-group label {

    display: block;

    margin-bottom: 8px;

    font-size: 13px;

    font-weight: bold;

    color: #4e342e;

}


/* INPUT */

.form-group input {

    width: 100%;

    height: 48px;

    border: 1px solid #ddd;

    border-radius: 8px;

    padding: 0 14px;

    font-size: 14px;

    outline: none;

    transition: 0.2s ease;

}


/* INPUT FOCUS */

.form-group input:focus {

    border-color: #795548;

    box-shadow:
        0 0 0 3px rgba(121,85,72,0.10);

}


/* PASSWORD WRAPPER */

.password-wrapper {

    position: relative;

}


/* PASSWORD INPUT */

.password-wrapper input {

    padding-right: 45px;

}


/* SHOW PASSWORD */

.password-toggle {

    position: absolute;

    right: 12px;

    top: 50%;

    transform: translateY(-50%);

    border: none;

    background: transparent;

    cursor: pointer;

    font-size: 18px;

    color: #777;

}


/* LOGIN ERROR */

.login-error {

    background: #ffebee;

    color: #c62828;

    border-left: 4px solid #c62828;

    padding: 12px;

    border-radius: 6px;

    font-size: 13px;

    margin-bottom: 18px;

}


/* LOGIN BUTTON */

.login-button {

    width: 100%;

    height: 49px;

    border: none;

    border-radius: 8px;

    background:
        linear-gradient(
            135deg,
            #3e2723,
            #6d4c41
        );

    color: white;

    font-size: 14px;

    font-weight: bold;

    cursor: pointer;

    transition: 0.2s ease;

}


/* BUTTON HOVER */

.login-button:hover {

    transform: translateY(-1px);

    box-shadow:
        0 7px 18px rgba(62,39,35,0.25);

}


/* LOGIN LOADING STATE */

.login-button {
    position: relative;
    overflow: hidden;
}

.login-button.loading {
    cursor: wait;
    opacity: 0.92;
    pointer-events: none;
}

.login-button .login-loading {
    display: none;
    align-items: center;
    justify-content: center;
    gap: 9px;
}

.login-button.loading .login-default {
    display: none;
}

.login-button.loading .login-loading {
    display: inline-flex;
}

.coffee-spinner {
    width: 17px;
    height: 17px;
    border: 2px solid rgba(255,255,255,0.35);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: coffeeSpin 0.75s linear infinite;
}

@keyframes coffeeSpin {
    to {
        transform: rotate(360deg);
    }
}

/* LOGIN FOOTER */

.login-footer {

    text-align: center;

    margin-top: 25px;

    font-size: 11px;

    color: #999;

}


</style>

</head>
<!-- =========================================================
     LOGIN PAGE
========================================================= -->

<div class="login-page">

    <div class="login-container">

        <div class="login-card">


            <div class="login-logo">
                ☕
            </div>


            <h1>
                Coffee Sales Analysis
            </h1>


            <p class="login-subtitle">
                Tanzania Coffee Market Management System
            </p>


            <?php if ($login_error): ?>

                <div class="login-error">
                    <?= htmlspecialchars($login_error) ?>
                </div>

            <?php endif; ?>


            <form method="POST">


                <div class="form-group">

                    <label for="username">
                        Username
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Enter username"
                        required
                        autofocus
                    >

                </div>


                <div class="form-group">

                    <label for="password">
                        Password
                    </label>


                    <div class="password-wrapper">

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter password"
                            required
                        >


                        <button
                            type="button"
                            class="password-toggle"
                            onclick="togglePassword()">

                            👁

                        </button>

                    </div>

                </div>


                <button
                    type="submit"
                    name="login"
                    class="login-button"
                    id="loginButton">

                    <span class="login-default">
                        Login
                    </span>

                    <span class="login-loading" aria-hidden="true">
                        <span class="coffee-spinner"></span>
                        <span>Logging in...</span>
                    </span>

                </button>


            </form>


            <div style="margin-top:24px;text-align:center;border-top:1px solid #e6dfdc;padding-top:22px">
              <strong style="display:block;color:#3e2723;font-size:17px;margin-bottom:10px">New to Coffee Sales Analysis?</strong>
              <a href="#signupPanel" style="display:block;background:#f4ebe7;color:#3e2723;padding:14px 18px;border:2px solid #795548;border-radius:9px;font-size:16px;font-weight:700;text-decoration:none">Sign Up — Create Account</a>
            </div>
            <div id="signupPanel" style="margin-top:18px;border-top:1px solid #ddd;padding-top:18px">
              <h2 style="font-size:18px;margin-bottom:12px">Sign Up — <?=htmlspecialchars(ucfirst($signupStep))?></h2>
              <?php if($signupMessage):?><p style="padding:10px;background:#fff4e8;margin-bottom:12px"><?=htmlspecialchars($signupMessage,ENT_QUOTES,'UTF-8')?></p><?php endif;?>
              <?php if($signupStep==='start'):?>
              <form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['auth_csrf'])?>"><input type="hidden" name="signup_action" value="start">
                <div class="form-group"><label>Full name</label><input name="display_name" maxlength="150" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                <div class="form-group"><label>Password (12+ characters)</label><input type="password" name="new_password" minlength="12" required></div>
                <button class="login-button" type="submit">Send email OTP</button></form>
              <?php elseif($signupStep==='otp'):?>
              <form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['auth_csrf'])?>"><input type="hidden" name="signup_action" value="otp"><div class="form-group"><label>Six-digit email code</label><input name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></div><button class="login-button">Verify email</button></form>
              <?php else:?>
              <form method="post" id="countryForm"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['auth_csrf'])?>"><input type="hidden" name="signup_action" value="country"><div class="form-group"><label>Country you are currently in</label><select name="country" required style="width:100%;padding:12px"><option value="">Select country</option><?php foreach($countries as $iso=>$prefix):?><option value="<?=htmlspecialchars($iso)?>"><?=htmlspecialchars(['TZ'=>'Tanzania','KE'=>'Kenya','UG'=>'Uganda','RW'=>'Rwanda','BI'=>'Burundi','ZA'=>'South Africa'][$iso])?> (<?=htmlspecialchars($prefix)?>)</option><?php endforeach;?></select></div><input type="hidden" name="lat" id="signupLat"><input type="hidden" name="lon" id="signupLon"><p style="font-size:12px;margin-bottom:12px">Your browser will request permission to share your location. A country match is required.</p><button class="login-button" id="countrySubmit">Verify location & create account</button></form>
              <script>document.getElementById('countryForm').addEventListener('submit',function(e){if(this.dataset.ready==='1')return;e.preventDefault();const form=this;if(!navigator.geolocation){alert('Location is not supported.');return;}navigator.geolocation.getCurrentPosition(p=>{document.getElementById('signupLat').value=p.coords.latitude;document.getElementById('signupLon').value=p.coords.longitude;form.dataset.ready='1';form.requestSubmit();},err=>alert('Location permission or GPS failed: '+err.message),{enableHighAccuracy:true,timeout:15000,maximumAge:0});});</script>
              <?php endif;?>
              <p style="font-size:11px;color:#777;margin-top:12px">Generated numbers identify users inside Messages only. They are not SIM/mobile telephone numbers.</p>
            </div>
            <div class="login-footer">

                Coffee Sales Data Analysis System
                <br>
                Tanzania Coffee Market

            </div>


        </div>

    </div>

</div>
<script>
/* =========================================================
   LOGIN LOADING STATE
========================================================= */

document.addEventListener("DOMContentLoaded", function () {

    const loginForm = document.querySelector('form[method="POST"]');
    const loginButton = document.getElementById("loginButton");

    if (!loginForm || !loginButton) {
        return;
    }

    loginForm.addEventListener("submit", function (event) {

        if (!loginForm.checkValidity()) {
            return;
        }

        loginButton.classList.add("loading");
        loginButton.setAttribute("aria-busy", "true");

        /*
         * Keep the normal form submission intact.
         * The loading state is displayed immediately while PHP processes login.
         */
    });
});

/* =========================================================
   PASSWORD SHOW / HIDE
========================================================= */

function togglePassword() {

    const password =
        document.getElementById("password");


    if (password.type === "password") {

        password.type = "text";

    } else {

        password.type = "password";

    }

}

</script>
</body>
</html>
