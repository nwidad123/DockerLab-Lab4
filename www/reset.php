<?php
require 'db.php';
require 'mailer.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$message = '';
$messageType = 'danger';
$email = '';
$finished = false;
$validToken = false;

if (empty($_SESSION['password_reset_csrf'])) {
    $_SESSION['password_reset_csrf'] = bin2hex(random_bytes(32));
}

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function postText($name)
{
    return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $isPost ? postText('action') : '';

$tokenInput = $isPost
    ? postText('token')
    : ($_GET['token'] ?? '');

$token = is_string($tokenInput) ? trim($tokenInput) : '';
$resetMode = $action === 'change' || (!$isPost && isset($_GET['token']));

if ($isPost) {
    if (!hash_equals(
        $_SESSION['password_reset_csrf'],
        postText('csrf_token')
    )) {
        $message = 'The form expired. Refresh this page and try again.';
    } elseif ($action === 'request') {
        $email = trim(postText('email'));

        if (
            strlen($email) > 255 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $message = 'Enter a valid email address.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'SELECT userID, FirstName, LastName, Email
                     FROM users
                     WHERE Email = ? AND EmailVerified = 1'
                );
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);

                    // Each account has at most one current reset link.
                    $save = $pdo->prepare(
                        'INSERT INTO password_reset_tokens
                         (userID, TokenHash, ExpiresAt)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                         ON DUPLICATE KEY UPDATE
                             TokenHash = ?,
                             ExpiresAt = DATE_ADD(NOW(), INTERVAL 30 MINUTE)'
                    );
                    $save->execute([
                        $user['userID'],
                        $tokenHash,
                        $tokenHash
                    ]);

                    $baseUrl = rtrim(
                        getenv('APP_URL') ?: 'http://localhost:8080',
                        '/'
                    );

                    $link = $baseUrl . '/reset.php?token=' . $rawToken;
                    $safeLink = escapeHtml($link);
                    $safeFirst = escapeHtml($user['FirstName']);

                    $content = "
                        <h3>Hello, {$safeFirst}!</h3>
                        <p>A password reset was requested for your account.</p>
                        <p><a href='{$safeLink}'>Reset your password</a></p>
                        <p>This link expires in 30 minutes and works once.
                        Requesting another link replaces this one.</p>
                        <p>If you did not request this, ignore this email.
                        Your password has not changed.</p>
                    ";

                    try {
                        $sent = sendSystemMail(
                            $user['Email'],
                            $user['FirstName'] . ' ' . $user['LastName'],
                            'Reset Your Password',
                            $content
                        );
                    } catch (\Throwable $e) {
                        $sent = false;
                    }

                    if (!$sent) {
                        error_log('Password reset email delivery failed.');

                        // Remove only the token from this failed request.
                        $remove = $pdo->prepare(
                            'DELETE FROM password_reset_tokens
                             WHERE userID = ? AND TokenHash = ?'
                        );
                        $remove->execute([$user['userID'], $tokenHash]);
                    }
                }

                // Same response whether or not a verified account exists.
                $messageType = 'info';
                $message = 'If this email belongs to a verified account, '
                    . 'a password-reset link will be sent. Check Mailtrap. '
                    . 'If no email arrives, try again later or contact '
                    . 'the site administrator.';
            } catch (\PDOException $e) {
                error_log('Password reset request failed.');
                $message = 'Password reset is temporarily unavailable. '
                    . 'Please try again later.';
            }
        }
    } elseif ($action === 'change') {
        $password = postText('new_password');
        $confirmation = postText('confirm_password');

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $message = 'This reset link is invalid.';
        } elseif (
            strlen($password) < 12 ||
            strlen($password) > 72 ||
            str_contains($password, "\0")
        ) {
            $message = 'Use a password between 12 and 72 bytes '
                . 'without null characters.';
        } elseif ($password !== $confirmation) {
            $message = 'The passwords do not match.';
        } else {
            $newHash = password_hash($password, PASSWORD_BCRYPT);

            try {
                $pdo->beginTransaction();

                // Lock the token so two requests cannot both consume it.
                $stmt = $pdo->prepare(
                    'SELECT userID
                     FROM password_reset_tokens
                     WHERE TokenHash = ? AND ExpiresAt > NOW()
                     FOR UPDATE'
                );
                $stmt->execute([hash('sha256', $token)]);
                $reset = $stmt->fetch();

                if (!$reset) {
                    $pdo->rollBack();
                    $message = 'This reset link is invalid, expired, '
                        . 'or has already been used.';
                } else {
                    $update = $pdo->prepare(
                        'UPDATE users SET Password = ? WHERE userID = ?'
                    );
                    $update->execute([$newHash, $reset['userID']]);

                    $delete = $pdo->prepare(
                        'DELETE FROM password_reset_tokens WHERE userID = ?'
                    );
                    $delete->execute([$reset['userID']]);

                    $pdo->commit();

                    // Clear authentication state in this browser.
                    $_SESSION = [];
                    session_regenerate_id(true);
                    $_SESSION['password_reset_csrf'] =
                        bin2hex(random_bytes(32));

                    $finished = true;
                    $messageType = 'success';
                    $message = 'Your password has been updated. '
                        . 'Sign in with your new password and authenticator.';
                }
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Password reset update failed.');
                $message = 'Your password could not be updated. '
                    . 'Please try again later.';
            }
        }
    } else {
        $message = 'Invalid form submission.';
    }
}

// Check links before displaying the new-password form.
if ($resetMode && !$finished) {
    try {
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $check = $pdo->prepare(
                'SELECT userID FROM password_reset_tokens
                 WHERE TokenHash = ? AND ExpiresAt > NOW()'
            );
            $check->execute([hash('sha256', $token)]);
            $validToken = (bool) $check->fetch();
        }

        if (!$validToken && $message === '') {
            $message = 'This reset link is invalid, expired, '
                . 'or has already been used.';
        }
    } catch (\PDOException $e) {
        error_log('Password reset token lookup failed.');
        $message = 'Password reset is temporarily unavailable. '
            . 'Please try again later.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password</title>

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
                        <h1 class="h3 mb-3">Reset your password</h1>

                        <?php if ($message !== ''): ?>
                            <div
                                class="alert alert-<?php echo escapeHtml($messageType); ?>"
                                role="alert">
                                <?php echo escapeHtml($message); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($finished): ?>
                            <a class="btn btn-primary w-100" href="login.php">
                                Continue to sign in
                            </a>

                        <?php elseif ($resetMode && $validToken): ?>
                            <p class="text-secondary">
                                Choose a new password.
                                Your existing authenticator will still be required.
                            </p>

                            <form method="POST" action="reset.php">
                                <input type="hidden" name="action" value="change">
                                <input
                                    type="hidden"
                                    name="token"
                                    value="<?php echo escapeHtml($token); ?>">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?php echo escapeHtml(
                                        $_SESSION['password_reset_csrf']
                                    ); ?>">

                                <div class="mb-3">
                                    <label for="new_password" class="form-label">
                                        New password
                                    </label>
                                    <input
                                        class="form-control"
                                        id="new_password"
                                        name="new_password"
                                        type="password"
                                        minlength="12"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        aria-describedby="passwordHelp"
                                        required>
                                    <div id="passwordHelp" class="form-text">
                                        Use 12 to 72 ASCII characters.
                                        Non-ASCII characters may use more than
                                        one byte; the limit is 72 bytes.
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="confirm_password" class="form-label">
                                        Confirm new password
                                    </label>
                                    <input
                                        class="form-control"
                                        id="confirm_password"
                                        name="confirm_password"
                                        type="password"
                                        minlength="12"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        required>
                                </div>

                                <button class="btn btn-primary w-100" type="submit">
                                    Update password
                                </button>
                            </form>

                        <?php elseif ($resetMode): ?>
                            <a class="btn btn-primary w-100" href="reset.php">
                                Request a new reset link
                            </a>

                        <?php else: ?>
                            <p class="text-secondary">
                                Enter your verified email address to request
                                a password-reset link.
                            </p>

                            <form method="POST" action="reset.php">
                                <input type="hidden" name="action" value="request">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?php echo escapeHtml(
                                        $_SESSION['password_reset_csrf']
                                    ); ?>">

                                <div class="mb-4">
                                    <label for="email" class="form-label">
                                        Email address
                                    </label>
                                    <input
                                        class="form-control"
                                        id="email"
                                        name="email"
                                        type="email"
                                        maxlength="255"
                                        autocomplete="email"
                                        value="<?php echo escapeHtml($email); ?>"
                                        required>
                                </div>

                                <button class="btn btn-primary w-100" type="submit">
                                    Send reset link
                                </button>
                            </form>
                        <?php endif; ?>

                        <div class="mt-4">
                            <a href="login.php">Return to sign in</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>