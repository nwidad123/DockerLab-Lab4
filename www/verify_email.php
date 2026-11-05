<?php
require 'db.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$message = '';
$messageType = 'danger';
$verified = false;
$token = '';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$submitted = $isPost || isset($_GET['token']);

$input = $isPost
    ? ($_POST['token'] ?? '')
    : ($_GET['token'] ?? '');

if (is_string($input)) {
    $token = trim($input);
}

if ($submitted) {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        $message = 'Enter the complete verification token from your email.';
    } else {
        try {
            // Verify and consume the token together.
            // Role and administrator application status remain unchanged.
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET EmailVerified = 1,
                     EmailVerificationToken = NULL
                 WHERE EmailVerificationToken = ?
                   AND EmailVerified = 0"
            );

            $stmt->execute([$token]);

            if ($stmt->rowCount() > 0) {
                $verified = true;
                $messageType = 'success';
                $message = 'Your email is verified. You can now sign in. '
                    . 'Administrator applications still require approval.';
                $token = '';
            } else {
                $message = 'This verification token is invalid or has already '
                    . 'been used. If you already verified your email, '
                    . 'return to sign in.';
            }
        } catch (\PDOException $e) {
            error_log('Email verification database operation failed.');
            $message = 'Email verification is temporarily unavailable. '
                . 'Please try again later.';
        }
    }
}

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Email</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
        crossorigin="anonymous">
</head>

<body class="bg-light">
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-7 col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h1 class="h3 mb-3">Verify your email</h1>

                        <?php if ($message !== ''): ?>
                            <div
                                class="alert alert-<?php echo escapeHtml($messageType); ?>"
                                role="alert">
                                <?php echo escapeHtml($message); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($verified): ?>
                            <a href="login.php" class="btn btn-primary w-100">
                                Continue to sign in
                            </a>
                        <?php else: ?>
                            <p class="text-secondary">
                                Open the verification link in your email,
                                or paste the token below.
                            </p>

                            <form method="POST" action="verify_email.php">
                                <div class="mb-4">
                                    <label for="token" class="form-label">
                                        Verification token
                                    </label>

                                    <input
                                        class="form-control"
                                        id="token"
                                        name="token"
                                        type="text"
                                        minlength="32"
                                        maxlength="32"
                                        pattern="[a-f0-9]{32}"
                                        autocomplete="off"
                                        autocapitalize="none"
                                        spellcheck="false"
                                        aria-describedby="tokenHelp"
                                        value="<?php echo escapeHtml($token); ?>"
                                        required>

                                    <div id="tokenHelp" class="form-text">
                                        Copy all 32 characters from your email.
                                    </div>
                                </div>

                                <button
                                    class="btn btn-primary w-100"
                                    type="submit">
                                    Verify email
                                </button>
                            </form>

                            <div class="mt-4">
                                <a href="login.php">Return to sign in</a>
                            </div>

                            <div class="mt-2">
                                <a href="register.php">Create an account</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>