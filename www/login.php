<?php
require 'db.php';

header('Cache-Control: no-store');

$message = "";
$email = "";

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = is_string($_POST['email'] ?? null)
        ? trim($_POST['email']) : '';

    $pass = is_string($_POST['password'] ?? null)
        ? $_POST['password'] : '';

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['login_csrf'], $token)
    ) {
        $message = "The form expired. Refresh the page and try again.";
    } elseif ($email === '' || $pass === '') {
        $message = "Enter your email address and password.";
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT userID, Password, EmailVerified, TotpSecret
                 FROM users WHERE Email = ?"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($pass, $user['Password'])) {
                $message = "Invalid email or password. Please try again.";
            } elseif ((int) $user['EmailVerified'] !== 1) {
                $message = "Verify your email before signing in. "
                    . "Use the verification link or token from your email.";
            } else {
                session_regenerate_id(true);

                // Password verification begins a pending login.
                // Full access is granted only after TOTP verification.
                unset(
                    $_SESSION['userID'],
                    $_SESSION['FirstName'],
                    $_SESSION['2fa_pending_userID'],
                    $_SESSION['totp_setup_secret'],
                    $_SESSION['totp_setup_userID']
                );

                $_SESSION['2fa_pending_userID'] = $user['userID'];
                $_SESSION['login_csrf'] = bin2hex(random_bytes(32));

                if (empty($user['TotpSecret'])) {
                    header("Location: setup_2fa.php");
                } else {
                    header("Location: verify_2fa.php");
                }

                exit;
            }
        } catch (\PDOException $e) {
            error_log("Login database query failed.");
            $message = "Sign-in is temporarily unavailable. Try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In</title>

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
                        <h1 class="h3 mb-2">Sign in</h1>
                        <p class="text-body-secondary mb-4">
                            Enter your account details, then verify
                            with your authenticator.
                        </p>

                        <?php if ($message !== ''): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php echo htmlspecialchars(
                                    $message,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token"
                                   value="<?php echo htmlspecialchars(
                                       $_SESSION['login_csrf'],
                                       ENT_QUOTES,
                                       'UTF-8'
                                   ); ?>">

                            <div class="mb-3">
                                <label for="email" class="form-label">
                                    Email address
                                </label>
                                <input
                                    id="email"
                                    class="form-control"
                                    type="email"
                                    name="email"
                                    maxlength="255"
                                    autocomplete="username"
                                    value="<?php echo htmlspecialchars(
                                        $email,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>"
                                    required>
                            </div>

                            <div class="mb-4">
                                <label for="password" class="form-label">
                                    Password
                                </label>

                                <div class="input-group">
                                    <input
                                        id="password"
                                        class="form-control"
                                        type="password"
                                        name="password"
                                        autocomplete="current-password"
                                        required>

                                    <button
                                        id="togglePassword"
                                        class="btn btn-outline-secondary"
                                        type="button"
                                        aria-controls="password"
                                        aria-label="Show password"
                                        aria-pressed="false">
                                        Show
                                    </button>
                                </div>
                            </div>

                            <button type="submit"
                                    class="btn btn-primary w-100">
                                Continue to authenticator
                            </button>
                        </form>

                        <nav class="d-flex flex-column gap-2 mt-4"
                             aria-label="Account options">
                            <a href="register.php">Create an account</a>
                            <a href="reset.php">Reset password</a>
                            <a href="verify_email.php">Verify email</a>
                            <a href="adminRegister.php">
                                Apply for an administrator account
                            </a>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        const passwordInput = document.getElementById('password');
        const toggleButton = document.getElementById('togglePassword');

        toggleButton.addEventListener('click', function () {
            const showPassword = passwordInput.type === 'password';

            passwordInput.type = showPassword ? 'text' : 'password';
            toggleButton.textContent = showPassword ? 'Hide' : 'Show';
            toggleButton.setAttribute(
                'aria-label',
                showPassword ? 'Hide password' : 'Show password'
            );
            toggleButton.setAttribute(
                'aria-pressed',
                String(showPassword)
            );
        });
    </script>
</body>
</html>