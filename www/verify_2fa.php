<?php
require 'db.php';
require 'GoogleAuthenticator.php';

// Check if the user has completed password authentication
if (!isset($_SESSION['2fa_pending_userID'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['2fa_pending_userID'];
$message = "";

// Retrieve the user's authenticator information
$stmt = $pdo->prepare(
    "SELECT FirstName, TotpSecret
     FROM users WHERE userID = ?"
);

$stmt->execute([$userId]);
$user = $stmt->fetch();

// Redirect users who have not completed authenticator setup
if (!$user || empty($user['TotpSecret'])) {
    header("Location: setup_2fa.php");
    exit;
}

$ga = new PHPGangsta_GoogleAuthenticator();

// Process the submitted authenticator code
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $userPin = trim($_POST['pin'] ?? '');

    // Verify the six-digit code using the current time
    $checkResult = preg_match('/^[0-9]{6}$/', $userPin)
        && $ga->verifyCode(
            $user['TotpSecret'],
            $userPin,
            1
        );

    if ($checkResult) {

        // Update the user's login status in MySQL
        $update = $pdo->prepare(
            "UPDATE users
             SET LoginDatetime = NOW(),
                 Status = 1
             WHERE userID = ?"
        );

        $update->execute([$userId]);

        // Complete the authenticated session
        session_regenerate_id(true);

        $_SESSION['userID'] = $userId;
        $_SESSION['FirstName'] = $user['FirstName'];

        unset(
            $_SESSION['2fa_pending_userID'],
            $_SESSION['totp_setup_secret'],
            $_SESSION['totp_setup_userID']
        );

        header("Location: dashboard.php");
        exit;

    } else {

        $message = "<p style='color:red;'>
            Access Denied: Invalid or expired authenticator code.
            Check your device time and try again.
            </p>";

    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Authenticator</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
        crossorigin="anonymous">
</head>

<body class="bg-body-tertiary">
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-7 col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h1 class="h3 mb-3">Verify your authenticator</h1>

                        <p class="text-body-secondary">
                            Open your authenticator app and enter
                            the current six-digit code for this account.
                        </p>

                        <?php if ($message !== ''): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php echo htmlspecialchars(
                                    trim(strip_tags($message)),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-4">
                                <label for="pin" class="form-label">
                                    Authenticator code
                                </label>
                                <input
                                    id="pin"
                                    class="form-control form-control-lg text-center"
                                    type="text"
                                    name="pin"
                                    minlength="6"
                                    maxlength="6"
                                    pattern="[0-9]{6}"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    placeholder="000000"
                                    aria-describedby="codeHelp"
                                    required>

                                <div id="codeHelp" class="form-text">
                                    If the code changes before you submit,
                                    use the new code.
                                </div>
                            </div>

                            <button type="submit"
                                    class="btn btn-primary w-100">
                                Verify and sign in
                            </button>
                        </form>

                        <p class="mt-4 mb-0">
                            <a href="login.php">Return to sign in</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>