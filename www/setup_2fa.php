<?php
require 'db.php';
require 'GoogleAuthenticator.php';

if (!isset($_SESSION['2fa_pending_userID'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['2fa_pending_userID'];
$ga = new PHPGangsta_GoogleAuthenticator();
$message = "";

// Retrieve the user's account information
$stmt = $pdo->prepare(
    "SELECT FirstName, Email, TotpSecret
     FROM users WHERE userID = ?"
);
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: login.php");
    exit;
}

// Already registered an authenticator
if (!empty($user['TotpSecret'])) {
    header("Location: verify_2fa.php");
    exit;
}

// Generate a temporary secret for device setup
if (
    empty($_SESSION['totp_setup_secret']) ||
    ($_SESSION['totp_setup_userID'] ?? null) != $userId
) {
    $_SESSION['totp_setup_secret'] = $ga->createSecret();
    $_SESSION['totp_setup_userID'] = $userId;
}

$secretKey = $_SESSION['totp_setup_secret'];

// Create the authenticator QR code URI
$appName = "DockerLab-3Tier";

$qrCodeDataUrl = "otpauth://totp/"
    . rawurlencode($appName) . ":"
    . rawurlencode($user['Email'])
    . "?secret=" . rawurlencode($secretKey)
    . "&issuer=" . rawurlencode($appName);

// Verify the code from the authenticator app
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codeAttempt = trim($_POST['code'] ?? '');

    $checkResult = preg_match('/^[0-9]{6}$/', $codeAttempt)
        && $ga->verifyCode($secretKey, $codeAttempt, 1);

    if ($checkResult) {

        // Save the secret after successful verification
        $update = $pdo->prepare(
            "UPDATE users
             SET TotpSecret = ?,
                 LoginDatetime = NOW(),
                 Status = 1
             WHERE userID = ?"
        );

        $update->execute([$secretKey, $userId]);

        // Complete the login session
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
            Verification Failed: The code does not match.
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
    <title>Set Up Authenticator</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
        crossorigin="anonymous">
    <script src="qrcode.min.js"></script>
</head>

<body class="bg-body-tertiary">
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-7 col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h1 class="h3 mb-3">Set up your authenticator</h1>

                        <p class="text-body-secondary">
                            Add an account in Google Authenticator,
                            Microsoft Authenticator, or another TOTP app.
                            Scan the QR code below.
                        </p>

                        <p class="small text-break">
                            Account:
                            <strong>
                                <?php echo htmlspecialchars(
                                    $user['Email'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </strong>
                        </p>

                        <div class="d-flex justify-content-center
                                    border rounded bg-white p-2 my-4">
                            <div id="qrcode"
                                 role="img"
                                 aria-label="Authenticator enrollment QR code">
                            </div>
                        </div>

                        <details class="mb-4">
                            <summary>Cannot scan? Enter the key manually</summary>
                            <p class="mt-2 mb-1">
                                Select a time-based account in your app.
                            </p>
                            <code class="text-break">
                                <?php echo htmlspecialchars(
                                    $secretKey,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </code>
                        </details>

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
                                <label for="code" class="form-label">
                                    Enter the six-digit code from your app
                                </label>
                                <input
                                    id="code"
                                    class="form-control form-control-lg text-center"
                                    type="text"
                                    name="code"
                                    minlength="6"
                                    maxlength="6"
                                    pattern="[0-9]{6}"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    placeholder="000000"
                                    required>
                            </div>

                            <button type="submit"
                                    class="btn btn-primary w-100">
                                Complete setup and sign in
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

    <script>
        new QRCode(document.getElementById("qrcode"), {
            text: <?php echo json_encode(
                $qrCodeDataUrl,
                JSON_HEX_TAG | JSON_HEX_AMP |
                JSON_HEX_APOS | JSON_HEX_QUOT
            ); ?>,
            width: 200,
            height: 200
        });
    </script>
</body>
</html>