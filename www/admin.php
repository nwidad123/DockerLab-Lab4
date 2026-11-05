<?php
require 'db.php';

header('Cache-Control: no-store');

if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit;
}

$currentUserId = (int) $_SESSION['userID'];

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function denyAccess()
{
    http_response_code(403);
    echo '<h1>Access denied</h1>';
    echo '<p>This page is only available to administrators.</p>';
    echo '<p><a href="dashboard.php">Return to dashboard</a></p>';
    exit;
}

// Check the database on every request.
$roleCheck = $pdo->prepare(
    "SELECT Role FROM users WHERE userID = ?"
);
$roleCheck->execute([$currentUserId]);

if ($roleCheck->fetchColumn() !== 'admin') {
    denyAccess();
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['admin_message'] ?? '';
unset($_SESSION['admin_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $targetId = filter_var(
        $_POST['user_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['admin_csrf'], $token)
    ) {
        http_response_code(403);
        exit('Invalid form token. Reload the admin page and try again.');
    }

    if (
        !$targetId ||
        $targetId < 1 ||
        !in_array($action, ['approve', 'reject', 'revoke'], true)
    ) {
        $message = "Invalid request.";
    } elseif ($targetId === $currentUserId) {
        $message = "You cannot change your own administrator access.";
    } else {
        try {
            $pdo->beginTransaction();

            // Serialize role changes and recheck the acting admin.
            // Locking all users is adequate for this small lab.
            $lockedUsers = $pdo->query(
                "SELECT userID, Role, AdminRequestStatus, EmailVerified
                 FROM users ORDER BY userID FOR UPDATE"
            )->fetchAll();

            $actor = null;
            $target = null;

            foreach ($lockedUsers as $account) {
                if ((int) $account['userID'] === $currentUserId) {
                    $actor = $account;
                }

                if ((int) $account['userID'] === $targetId) {
                    $target = $account;
                }
            }

            if (!$actor || $actor['Role'] !== 'admin') {
                $pdo->rollBack();
                denyAccess();
            }

            $newRole = null;
            $newStatus = null;

            if (!$target) {
                $message = "Account not found.";
            } elseif (
                $action === 'approve' &&
                $target['Role'] === 'user' &&
                $target['AdminRequestStatus'] === 'pending' &&
                (int) $target['EmailVerified'] === 1
            ) {
                $newRole = 'admin';
                $newStatus = 'approved';
                $message = "Admin application approved.";
            } elseif (
                $action === 'reject' &&
                $target['Role'] === 'user' &&
                $target['AdminRequestStatus'] === 'pending'
            ) {
                $newRole = 'user';
                $newStatus = 'rejected';
                $message = "Admin application rejected.";
            } elseif (
                $action === 'revoke' &&
                $target['Role'] === 'admin'
            ) {
                $newRole = 'user';
                $newStatus = 'revoked';
                $message = "Administrator access revoked.";
            } else {
                $message = "This action is not available for that account. "
                    . "Approval requires a pending application "
                    . "and a verified email.";
            }

            if ($newRole !== null) {
                $update = $pdo->prepare(
                    "UPDATE users
                     SET Role = ?, AdminRequestStatus = ?
                     WHERE userID = ?"
                );
                $update->execute([$newRole, $newStatus, $targetId]);
            }

            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Admin role update failed.");
            $message = "The change could not be saved. Please try again.";
        }
    }

    // Redirect so refreshing does not repeat an update.
    $_SESSION['admin_message'] = $message;
    header("Location: admin.php", true, 303);
    exit;
}

$users = $pdo->query(
    "SELECT userID, FirstName, LastName, Email, EmailVerified,
            Role, AdminRequestStatus, LoginDatetime, LogoutDatetime
     FROM users ORDER BY RegDatetime DESC, userID DESC"
)->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Account Management</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
        crossorigin="anonymous">
</head>

<body class="bg-light">
    <main class="container-fluid px-3 px-lg-5 py-5">
        <div class="card shadow-sm">
            <div class="card-body p-3 p-lg-4">
                <h1 class="h3 mb-3">Admin account management</h1>

                <nav class="d-flex flex-wrap gap-2 mb-4"
                     aria-label="Admin navigation">
                    <a class="btn btn-outline-primary" href="dashboard.php">
                        Dashboard
                    </a>
                    <a class="btn btn-outline-secondary" href="admin.php">
                        Refresh
                    </a>
                    <a class="btn btn-outline-primary" href="adminRegister.php">
                        Admin application
                    </a>
                </nav>

                <p class="text-secondary">
                    Applicants must verify their email before approval.
                    Approved administrators must still sign in with their
                    password and authenticator.
                </p>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-info" role="status">
                        <?php echo escapeHtml($message); ?>
                    </div>
                <?php endif; ?>

                <div class="table-responsive"
                     role="region"
                     aria-label="Account management table"
                     tabindex="0">
                    <table class="table table-striped table-hover align-middle">
                        <caption>
                            Registered accounts and administrator applications.
                            Scroll horizontally on smaller screens to see all columns.
                        </caption>

                        <thead class="table-light">
                            <tr class="text-nowrap">
                                <th scope="col">ID</th>
                                <th scope="col">Name</th>
                                <th scope="col">Email</th>
                                <th scope="col">Email verified</th>
                                <th scope="col">Role</th>
                                <th scope="col">Application</th>
                                <th scope="col">Last login</th>
                                <th scope="col">Last logout</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($users as $row): ?>
                                <tr>
                                    <th scope="row">
                                        <?php echo (int) $row['userID']; ?>
                                    </th>

                                    <td class="text-nowrap">
                                        <?php echo escapeHtml(
                                            $row['FirstName'] . ' ' . $row['LastName']
                                        ); ?>
                                    </td>

                                    <td>
                                        <?php echo escapeHtml($row['Email']); ?>
                                    </td>

                                    <td>
                                        <?php if ((int) $row['EmailVerified'] === 1): ?>
                                            <span class="badge text-bg-success">Yes</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($row['Role'] === 'admin'): ?>
                                            <span class="badge text-bg-primary">
                                                Administrator
                                            </span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">
                                                User
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php
                                        $status = $row['AdminRequestStatus'];
                                        $badgeClass = match ($status) {
                                            'pending' => 'text-bg-warning',
                                            'approved' => 'text-bg-success',
                                            'rejected', 'revoked' => 'text-bg-danger',
                                            default => 'text-bg-secondary'
                                        };
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?>">
                                            <?php echo escapeHtml(
                                                $status === 'none'
                                                    ? 'Not submitted'
                                                    : ucfirst($status)
                                            ); ?>
                                        </span>
                                    </td>

                                    <td class="text-nowrap">
                                        <?php echo escapeHtml(
                                            $row['LoginDatetime'] ?? 'Never'
                                        ); ?>
                                    </td>

                                    <td class="text-nowrap">
                                        <?php echo escapeHtml(
                                            $row['LogoutDatetime'] ?? 'Never'
                                        ); ?>
                                    </td>

                                    <td>
                                        <?php if (
                                            (int) $row['userID'] === $currentUserId
                                        ): ?>
                                            <span class="text-secondary text-nowrap">
                                                Your account
                                            </span>

                                        <?php elseif (
                                            $row['Role'] === 'admin' ||
                                            $row['AdminRequestStatus'] === 'pending'
                                        ): ?>
                                            <form method="POST" action="admin.php"
                                                  class="d-flex flex-wrap gap-2">
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?php echo escapeHtml(
                                                        $_SESSION['admin_csrf']
                                                    ); ?>">

                                                <input
                                                    type="hidden"
                                                    name="user_id"
                                                    value="<?php echo (int) $row['userID']; ?>">

                                                <?php if ($row['Role'] === 'admin'): ?>
                                                    <button
                                                        class="btn btn-outline-danger btn-sm text-nowrap"
                                                        type="submit"
                                                        name="action"
                                                        value="revoke">
                                                        Revoke admin
                                                    </button>
                                                <?php else: ?>
                                                    <?php if (
                                                        (int) $row['EmailVerified'] === 1
                                                    ): ?>
                                                        <button
                                                            class="btn btn-success btn-sm"
                                                            type="submit"
                                                            name="action"
                                                            value="approve">
                                                            Approve
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="small text-secondary">
                                                            Awaiting email verification
                                                        </span>
                                                    <?php endif; ?>

                                                    <button
                                                        class="btn btn-outline-danger btn-sm"
                                                        type="submit"
                                                        name="action"
                                                        value="reject">
                                                        Reject
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-secondary text-nowrap">
                                                No pending action
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>
</html>