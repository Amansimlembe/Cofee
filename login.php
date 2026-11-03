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
function sendSignupOtp(string $to,string $otp):array {
 $key=trim((string)(getenv('BREVO_API_KEY')?:''));
 $from=trim((string)(getenv('OTP_FROM_EMAIL')?:''));
 if($key==='')return [false,'Email verification is not configured. The administrator must set BREVO_API_KEY on Render.'];
 if(!filter_var($from,FILTER_VALIDATE_EMAIL))return [false,'Email sender is not configured. The administrator must set OTP_FROM_EMAIL to a verified sender.'];
 if(!function_exists('curl_init'))return [false,'Email service is unavailable: the PHP cURL extension is missing.'];
 $payload=json_encode(['sender'=>['email'=>$from,'name'=>'Coffee Sales Analysis'],'to'=>[['email'=>$to]],'subject'=>'Your Coffee Sales verification code','textContent'=>"Your verification code is $otp. It expires in 10 minutes. Do not share it."],JSON_THROW_ON_ERROR);
 $ch=curl_init('https://api.brevo.com/v3/smtp/email');
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['api-key: '.$key,'Content-Type: application/json','Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20]);
 $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlError=curl_error($ch);curl_close($ch);
 if($response!==false&&$status>=200&&$status<300)return [true,''];
 $remote=is_string($response)?json_decode($response,true):null;
 $remoteMessage=is_array($remote)?(string)($remote['message']??''):'';
 error_log('Signup OTP delivery failed: HTTP '.$status.'; '.substr($remoteMessage?:$curlError,0,350));
 if($status===401||$status===403)return [false,'Email service authorization failed. Check the Brevo API key and sender verification in Render.'];
 if($status===400)return [false,'Email service rejected the request. Verify OTP_FROM_EMAIL is an approved sender in Brevo.'];
 if($status===429)return [false,'Email delivery limit reached. Please try again later.'];
 return [false,'Email delivery is temporarily unavailable. Please try again later or contact the administrator.'];
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
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>100||mb_strlen($name)<2||mb_strlen($name)>150||strlen($password)<6)throw new RuntimeException('Enter a valid email, name and password of at least 6 characters.');
  $q=$pdo->prepare('SELECT 1 FROM users WHERE lower(username)=?');$q->execute([$email]);if($q->fetchColumn())throw new RuntimeException('This email is already registered. Please log in.');
  $q=$pdo->prepare('SELECT sent_at FROM signup_otps WHERE email=?');$q->execute([$email]);$last=$q->fetchColumn();if($last&&time()-strtotime($last)<60)throw new RuntimeException('Please wait one minute before requesting another code.');
  $otp=(string)random_int(100000,999999);[$sent,$deliveryError]=sendSignupOtp($email,$otp);if(!$sent)throw new RuntimeException($deliveryError);
  $q=$pdo->prepare("INSERT INTO signup_otps(email,display_name,password_hash,otp_hash,expires_at,attempts,sent_at,verified_at) VALUES(?,?,?,?,now()+interval '10 minutes',0,now(),NULL) ON CONFLICT(email) DO UPDATE SET display_name=excluded.display_name,password_hash=excluded.password_hash,otp_hash=excluded.otp_hash,expires_at=excluded.expires_at,attempts=0,sent_at=now(),verified_at=NULL");$q->execute([$email,$name,password_hash($password,PASSWORD_DEFAULT),password_hash($otp,PASSWORD_DEFAULT)]);
  $_SESSION['signup_email']=$email;$_SESSION['signup_step']='otp';$signupStep='otp';$signupMessage='A six-digit code has been sent to your email.';
  }elseif($act==='resend_otp'){
   $email=(string)($_SESSION['signup_email']??'');
   if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Signup session expired. Please start registration again.');
   $q=$pdo->prepare('SELECT sent_at, verified_at FROM signup_otps WHERE email=?');$q->execute([$email]);$r=$q->fetch();
   if(!$r)throw new RuntimeException('Registration not found. Please start again.');
   if($r['verified_at']!==null)throw new RuntimeException('This email has already been verified. Continue registration.');
   $wait=max(0,60-(time()-strtotime($r['sent_at'])));
   if($wait>0)throw new RuntimeException('Please wait '.$wait.' seconds before requesting another code.');
   // Only replace the old OTP after Brevo accepts the new message.
   $otp=(string)random_int(100000,999999);
   [$sent,$deliveryError]=sendSignupOtp($email,$otp);
   if(!$sent)throw new RuntimeException($deliveryError);
   $q=$pdo->prepare("UPDATE signup_otps SET otp_hash=?,expires_at=now()+interval '10 minutes',attempts=0,sent_at=now() WHERE email=? AND verified_at IS NULL");
   $q->execute([password_hash($otp,PASSWORD_DEFAULT),$email]);
   $_SESSION['signup_step']='otp';$signupStep='otp';
   $signupMessage='A new verification code has been sent. Use the latest code; it expires in 10 minutes.';
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

<?php $showSignup = (($_GET['view']??'')==='signup') || ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['signup_action'])) || ($signupStep!=='start' && !$login_error); ?>
<style>
body{background:#f5f3f0;color:#292524}
.login-page{min-height:100vh;background:radial-gradient(circle at 12% 12%,#e8e0d8 0,transparent 38%),linear-gradient(145deg,#f8f7f5,#ece7e1);padding:32px 18px}
.login-container{max-width:460px}
.login-card{border:1px solid #e7e1db;border-radius:18px;padding:38px 38px 30px;box-shadow:0 20px 65px rgba(44,30,24,.10);animation:none}
.login-logo{width:58px;height:58px;font-size:27px;margin-bottom:18px;box-shadow:none;border-radius:15px}
.login-card h1{font-size:24px;letter-spacing:-.6px}
.login-subtitle{margin-bottom:27px;line-height:1.5}
.auth-switch{display:flex;justify-content:center;gap:4px;background:transparent;border:0;padding:0;margin:0 0 22px}
.auth-switch button{border:0;border-radius:8px;padding:10px 15px;font-size:13px;font-weight:600;color:#786b64;background:transparent;cursor:pointer}
.auth-switch button[aria-selected="true"]{color:#3e2723;background:#f4f1ee;box-shadow:none}
.auth-view[hidden]{display:none!important}
.form-group{margin-bottom:17px}
.form-group label{font-size:12px;letter-spacing:.1px}
.form-group input,.form-group select{width:100%;height:46px;border:1px solid #dcd6d0;border-radius:8px;padding:0 13px;font-size:14px;background:white}
.form-group input:focus,.form-group select:focus{outline:none;border-color:#795548;box-shadow:0 0 0 3px rgba(121,85,72,.12)}
.login-button{height:48px;margin-top:6px}
.resend-otp-button{border:0;background:transparent;color:#5d4037;font-size:13px;font-weight:700;cursor:pointer;padding:9px 12px}.resend-otp-button:hover{text-decoration:underline}.resend-otp-button:disabled{opacity:.5;cursor:not-allowed;text-decoration:none}
.auth-note{color:#82766e;font-size:12px;line-height:1.6;text-align:center;margin-top:17px}
.auth-step{font-size:13px;color:#766b64;margin:-9px 0 20px;text-align:center}
.login-footer{margin-top:26px;padding-top:20px;border-top:1px solid #eee9e5;color:#8b817a;font-size:11px;line-height:1.7}
@media(max-width:520px){.login-card{padding:29px 22px 24px}.login-page{padding:16px}}
</style>
<div class="login-page"><div class="login-container"><main class="login-card">
<div class="login-logo" aria-hidden="true">☕</div>
<h1>Coffee Sales Analysis</h1>
<p class="login-subtitle">Sign in to your account</p>
<div class="auth-switch" role="tablist" aria-label="Account access">
<button type="button" id="loginTab" role="tab" aria-controls="loginView" aria-selected="<?= $showSignup?'false':'true' ?>" onclick="showAuth('login')">Sign in</button>
<button type="button" id="signupTab" role="tab" aria-controls="signupView" aria-selected="<?= $showSignup?'true':'false' ?>" onclick="showAuth('signup')">Create account</button>
</div>
<section id="loginView" class="auth-view" role="tabpanel" <?= $showSignup?'hidden':'' ?>>
<?php if($login_error):?><div class="login-error"><?=htmlspecialchars($login_error,ENT_QUOTES,'UTF-8')?></div><?php endif;?>
<form method="post" autocomplete="on">
<div class="form-group"><label for="username">Email address</label><input id="username" name="username" type="text" autocomplete="username" placeholder="name@example.com" required></div>
<div class="form-group"><label for="password">Password</label><div class="password-wrapper"><input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required><button type="button" class="password-toggle" aria-label="Show or hide password" onclick="togglePassword()">👁</button></div></div>
<button class="login-button" type="submit" name="login" id="loginButton"><span class="login-default">Sign in</span><span class="login-loading" aria-hidden="true"><span class="coffee-spinner"></span><span>Signing in...</span></span></button>
</form></section>
<section id="signupView" class="auth-view" role="tabpanel" <?= $showSignup?'':'hidden' ?>>
<h2 style="font-size:18px;color:#3e2723;text-align:center;margin-bottom:14px">Create account</h2>
<p class="auth-step"><?php if($signupStep==='start'):?>Step 1 of 3 · Account details<?php elseif($signupStep==='otp'):?>Step 2 of 3 · Verify your email<?php else:?>Step 3 of 3 · Confirm your country<?php endif;?></p>
<?php if($signupMessage):?><div class="login-error" style="background:#f9f1e9;color:#68452f;border-color:#b18464"><?=htmlspecialchars($signupMessage,ENT_QUOTES,'UTF-8')?></div><?php endif;?>
<?php if($signupStep==='start'):?>
<form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['auth_csrf'],ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="signup_action" value="start">
<div class="form-group"><label for="display_name">Full name</label><input id="display_name" name="display_name" maxlength="150" autocomplete="name" placeholder="Your full name" required></div>
<div class="form-group"><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="email" placeholder="name@example.com" required></div>
<div class="form-group"><label for="new_password">Create password</label><input id="new_password" type="password" name="new_password" minlength="6" autocomplete="new-password" placeholder="At least 6 characters" required></div>
<button class="login-button" type="submit">Continue with email verification</button></form>
<?php elseif($signupStep==='otp'):?>
<form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['auth_csrf'],ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="signup_action" value="otp"><div class="form-group"><label for="otp">Six-digit verification code</label><input id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required></div><button class="login-button" type="submit">Verify email</button></form>
<form method="post" id="resendOtpForm" style="margin-top:12px;text-align:center"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['auth_csrf'],ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="signup_action" value="resend_otp"><button type="submit" id="resendOtpButton" class="resend-otp-button">Resend verification code</button><p id="resendOtpHint" class="auth-note" style="margin-top:5px" aria-live="polite"></p></form>
<script>
(function(){
 const button=document.getElementById('resendOtpButton'),hint=document.getElementById('resendOtpHint');
 const lastSent=<?=json_encode((int)(function()use($pdo){$q=$pdo->prepare('SELECT EXTRACT(EPOCH FROM sent_at)::bigint FROM signup_otps WHERE email=?');$q->execute([(string)($_SESSION['signup_email']??'')]);return $q->fetchColumn()?:0;})())?>;
 function update(){const left=Math.max(0,60-Math.floor(Date.now()/1000-lastSent));button.disabled=left>0;hint.textContent=left>0?'You can request another code in '+left+'s.':'Did not receive the email?';}
 update();const interval=setInterval(()=>{update();if(!button.disabled)clearInterval(interval);},1000);
})();
</script>
<?php else:?>
<form method="post" id="countryForm"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['auth_csrf'],ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="signup_action" value="country"><div class="form-group"><label for="country">Country where you are located</label><select id="country" name="country" required><option value="">Select your country</option><?php foreach($countries as $iso=>$prefix):?><option value="<?=htmlspecialchars($iso,ENT_QUOTES,'UTF-8')?>"><?=htmlspecialchars(['TZ'=>'Tanzania','KE'=>'Kenya','UG'=>'Uganda','RW'=>'Rwanda','BI'=>'Burundi','ZA'=>'South Africa'][$iso],ENT_QUOTES,'UTF-8')?> (<?=htmlspecialchars($prefix,ENT_QUOTES,'UTF-8')?>)</option><?php endforeach;?></select></div><input type="hidden" name="lat" id="signupLat"><input type="hidden" name="lon" id="signupLon"><p class="auth-note" style="margin:0 0 15px">We will request location permission to check your selected country.</p><button class="login-button" type="submit">Verify location and finish</button></form>
<script>document.getElementById('countryForm').addEventListener('submit',function(e){if(this.dataset.ready==='1')return;e.preventDefault();const form=this;if(!navigator.geolocation){alert('Location is not supported by this browser.');return;}navigator.geolocation.getCurrentPosition(p=>{document.getElementById('signupLat').value=p.coords.latitude;document.getElementById('signupLon').value=p.coords.longitude;form.dataset.ready='1';form.requestSubmit();},err=>alert('Location access failed: '+err.message),{enableHighAccuracy:true,timeout:15000,maximumAge:0});});</script>
<?php endif;?>

</section>

</main></div></div>
<script>
function showAuth(view){const signup=view==='signup';document.getElementById('loginView').hidden=signup;document.getElementById('signupView').hidden=!signup;document.getElementById('loginTab').setAttribute('aria-selected',String(!signup));document.getElementById('signupTab').setAttribute('aria-selected',String(signup));history.replaceState(null,'',signup?'?view=signup':'login.php');}
</script>
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
