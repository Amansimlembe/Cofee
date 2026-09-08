<?php

session_start();

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

    $db_user = $db["user"] ?? "";
    $db_password = $db["pass"] ?? "";

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


/*
|--------------------------------------------------------------------------
| CREATE DEFAULT ADMIN ACCOUNT
|--------------------------------------------------------------------------
*/

$admin_username = "aman@coffee.com";
$admin_password = "Ammy@123";

$check_admin = $pdo->prepare("
    SELECT id
    FROM users
    WHERE username = :username
    LIMIT 1
");

$check_admin->execute([
    ":username" => $admin_username
]);

if (!$check_admin->fetch()) {

    $hashed_password = password_hash(
        $admin_password,
        PASSWORD_DEFAULT
    );

    $create_admin = $pdo->prepare("
        INSERT INTO users (username, password)
        VALUES (:username, :password)
    ");

    $create_admin->execute([
        ":username" => $admin_username,
        ":password" => $hashed_password
    ]);
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
                    class="login-button">

                    Login to System

                </button>


            </form>


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
