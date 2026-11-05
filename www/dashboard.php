<?php
require 'db.php';

header('Cache-Control: no-store');

if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

// Retrieve profile information without fetching passwords or secrets.
$stmt = $pdo->prepare(
    "SELECT userID, FirstName, LastName, Email, Role,
            AdminRequestStatus, RegDatetime,
            LoginDatetime, LogoutDatetime
     FROM users WHERE userID = ?"
);
$stmt->execute([$_SESSION['userID']]);
$profile = $stmt->fetch();

if (!$profile) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$isAdmin = $profile['Role'] === 'admin';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Dashboard</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
        crossorigin="anonymous">
</head>

<body class="bg-body-tertiary">
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h1 class="h3 text-break">
                            Welcome,
                            <?php echo escapeHtml(
                                $profile['FirstName'] . ' '
                                . $profile['LastName']
                            ); ?>!
                        </h1>

                        <p class="text-body-secondary text-break">
                            <?php echo escapeHtml($profile['Email']); ?>
                        </p>

                        <div class="alert alert-success" role="status">
                            You are signed in.
                            Password and authenticator verification completed.
                        </div>

                        <h2 class="h5 mt-4 mb-3">Account details</h2>

                        <dl class="row mb-0">
                            <dt class="col-sm-4">Account ID</dt>
                            <dd class="col-sm-8">
                                <?php echo (int) $profile['userID']; ?>
                            </dd>

                            <dt class="col-sm-4">Role</dt>
                            <dd class="col-sm-8">
                                <span class="badge <?php
                                    echo $isAdmin
                                        ? 'text-bg-primary'
                                        : 'text-bg-secondary';
                                ?>">
                                    <?php echo $isAdmin
                                        ? 'Administrator' : 'User'; ?>
                                </span>
                            </dd>

                            <dt class="col-sm-4">Admin application</dt>
                            <dd class="col-sm-8">
                                <?php echo escapeHtml(
                                    $profile['AdminRequestStatus'] === 'none'
                                        ? 'Not submitted'
                                        : ucfirst(
                                            $profile['AdminRequestStatus']
                                        )
                                ); ?>
                            </dd>

                            <dt class="col-sm-4">Registered</dt>
                            <dd class="col-sm-8 text-break">
                                <?php echo escapeHtml(
                                    $profile['RegDatetime']
                                ); ?>
                            </dd>

                            <dt class="col-sm-4">Last login</dt>
                            <dd class="col-sm-8 text-break">
                                <?php echo escapeHtml(
                                    $profile['LoginDatetime'] ?? 'Not recorded'
                                ); ?>
                            </dd>

                            <dt class="col-sm-4">Previous logout</dt>
                            <dd class="col-sm-8 text-break">
                                <?php echo escapeHtml(
                                    $profile['LogoutDatetime'] ?? 'Never'
                                ); ?>
                            </dd>
                        </dl>

                        <div class="d-grid gap-2 d-sm-flex mt-4">
                            <?php if ($isAdmin): ?>
                                <a href="admin.php" class="btn btn-primary">
                                    Admin account management
                                </a>
                            <?php endif; ?>

                            <a href="logout.php" class="btn btn-outline-danger">
                                Sign out
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>