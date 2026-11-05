<?php
require 'db.php';
require 'mailer.php';

header('Cache-Control: no-store');

$message = '';
$messageType = 'danger';
$first = '';
$last = '';
$email = '';

if (empty($_SESSION['registration_csrf'])) {
    $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));
}

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function postText($name)
{
    return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim(postText('firstname'));
    $last = trim(postText('lastname'));
    $email = trim(postText('email'));
    $pass = postText('password');
    $csrf = postText('csrf_token');
    $created = false;

    if (!hash_equals($_SESSION['registration_csrf'], $csrf)) {
        $message = 'The form expired. Refresh this page and try again.';
    } elseif ($first === '' || $last === '' || $email === '' || $pass === '') {
        $message = 'All fields are required.';
    } elseif (
        strlen($first) > 100 ||
        strlen($last) > 100 ||
        strlen($email) > 255
    ) {
        $message = 'A name or email address is too long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
    } elseif (
        strlen($pass) < 12 ||
        strlen($pass) > 72 ||
        str_contains($pass, "\0")
    ) {
        $message = 'Use a password between 12 and 72 bytes without null characters.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT userID FROM users WHERE Email = ?'
            );
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $message = 'This email is already registered. Return to sign in.';
            } else {
                $hashedPass = password_hash($pass, PASSWORD_BCRYPT);
                $verificationToken = bin2hex(random_bytes(16));

                // Regular registration always creates an ordinary user.
                $insert = $pdo->prepare(
                    "INSERT INTO users
                    (FirstName, LastName, Email, Password, Status,
                     EmailVerified, EmailVerificationToken,
                     RegDatetime, Role, AdminRequestStatus)
                    VALUES (?, ?, ?, ?, 0, 0, ?, NOW(), 'user', 'none')"
                );

                $insert->execute([
                    $first,
                    $last,
                    $email,
                    $hashedPass,
                    $verificationToken
                ]);

                $created = true;
            }
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $message = 'This email is already registered.';
            } else {
                error_log('Registration database operation failed.');
                $message = 'Registration could not be completed. Try again later.';
            }
        }
    }

    if ($created) {
        $baseUrl = rtrim(
            getenv('APP_URL') ?: 'http://localhost:8080',
            '/'
        );

        $verifyLink = $baseUrl . '/verify_email.php?token='
            . rawurlencode($verificationToken);

        $safeFirst = escapeHtml($first);
        $safeLink = escapeHtml($verifyLink);

        $emailContent = "
            <h3>Welcome, {$safeFirst}!</h3>
            <p>Please verify your email address to continue:</p>
            <p><a href='{$safeLink}'>Verify email address</a></p>
            <p>Verification token: {$verificationToken}</p>
        ";

        try {
            $sent = sendSystemMail(
                $email,
                "{$first} {$last}",
                'Verify Your Email Address',
                $emailContent
            );
        } catch (\Throwable $e) {
            error_log('Registration email delivery failed.');
            $sent = false;
        }

        if ($sent) {
            $messageType = 'success';
            $message = 'Account created! Check your Mailtrap inbox '
                . 'and verify your email before signing in.';
        } else {
            $messageType = 'warning';
            $message = 'Your account was created, but the verification email '
                . 'could not be sent. Contact the site administrator for help.';
        }

        $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));
        $first = '';
        $last = '';
        $email = '';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Account</title>

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
                        <h1 class="h3 mb-3">Create an account</h1>

                        <p class="text-secondary">
                            Register, verify your email, then set up your
                            authenticator when you first sign in.
                        </p>

                        <?php if ($message !== ''): ?>
                            <div
                                class="alert alert-<?php echo escapeHtml($messageType); ?>"
                                role="alert">
                                <?php echo escapeHtml($message); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?php echo escapeHtml(
                                    $_SESSION['registration_csrf']
                                ); ?>">

                            <div class="mb-3">
                                <label for="firstname" class="form-label">
                                    First name
                                </label>
                                <input
                                    class="form-control"
                                    id="firstname"
                                    name="firstname"
                                    type="text"
                                    maxlength="100"
                                    autocomplete="given-name"
                                    value="<?php echo escapeHtml($first); ?>"
                                    required>
                            </div>

                            <div class="mb-3">
                                <label for="lastname" class="form-label">
                                    Last name
                                </label>
                                <input
                                    class="form-control"
                                    id="lastname"
                                    name="lastname"
                                    type="text"
                                    maxlength="100"
                                    autocomplete="family-name"
                                    value="<?php echo escapeHtml($last); ?>"
                                    required>
                            </div>

                            <div class="mb-3">
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

                            <div class="mb-4">
                                <label for="password" class="form-label">
                                    Password
                                </label>

                                <div class="input-group">
                                    <input
                                        class="form-control"
                                        id="password"
                                        name="password"
                                        type="password"
                                        minlength="12"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        aria-describedby="passwordHelp"
                                        required>

                                    <button
                                        class="btn btn-outline-secondary"
                                        id="togglePassword"
                                        type="button"
                                        aria-controls="password"
                                        aria-pressed="false">
                                        Show
                                    </button>
                                </div>

                                <div id="passwordHelp" class="form-text">
                                    Use 12 to 72 ASCII characters.
                                    Non-ASCII characters may use more than one
                                    byte; the server limit is 72 bytes.
                                </div>
                            </div>

                            <button class="btn btn-primary w-100" type="submit">
                                Create account
                            </button>
                        </form>

                        <div class="mt-4">
                            <a href="login.php">Return to sign in</a>
                        </div>
                        <div class="mt-2">
                            <a href="verify_email.php">Verify email</a>
                        </div>
                        <div class="mt-2">
                            <a href="adminRegister.php">
                                Apply for an administrator account
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        const password = document.getElementById('password');
        const toggle = document.getElementById('togglePassword');

        toggle.addEventListener('click', function () {
            const show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.textContent = show ? 'Hide' : 'Show';
            toggle.setAttribute('aria-pressed', String(show));
        });
    </script>
</body>
</html>