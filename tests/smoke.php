<?php

// Run from the command line, outside the public website.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$baseUrl = rtrim($argv[1] ?? 'http://web-server', '/');
$passed = 0;
$failed = 0;

function check($condition, $description)
{
    global $passed, $failed;

    if ($condition) {
        echo "PASS: {$description}\n";
        $passed++;
    } else {
        echo "FAIL: {$description}\n";
        $failed++;
    }
}

function requestPage($url)
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10
        ]
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    $location = '';

    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int) $match[1];
        }

        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }

    return [
        'body' => $body === false ? '' : $body,
        'status' => $status,
        'location' => $location
    ];
}

// Requests deliberately send no login cookies.
$publicPages = [
    'login.php',
    'register.php',
    'adminRegister.php',
    'verify_email.php',
    'reset.php'
];

foreach ($publicPages as $page) {
    $response = requestPage($baseUrl . '/' . $page);

    check(
        $response['status'] === 200,
        "{$page} returns HTTP 200"
    );

    check(
        str_contains($response['body'], 'bootstrap.min.css'),
        "{$page} includes Bootstrap"
    );

    check(
        !preg_match(
            '/Warning:|Fatal error:|Parse error:|Uncaught\s/i',
            $response['body']
        ),
        "{$page} contains no visible PHP errors"
    );
}

$redirectPages = [
    'index.php',
    'dashboard.php',
    'admin.php',
    'setup_2fa.php',
    'verify_2fa.php'
];

foreach ($redirectPages as $page) {
    $response = requestPage($baseUrl . '/' . $page);

    check(
        $response['status'] === 302 &&
        $response['location'] === 'login.php',
        "{$page} redirects an unauthenticated visitor to login"
    );
}

$response = requestPage(
    $baseUrl . '/verify_email.php?token=invalid'
);

check(
    $response['status'] === 200 &&
    str_contains(
        $response['body'],
        'Enter the complete verification token'
    ),
    'Malformed email verification token is rejected'
);

$response = requestPage(
    $baseUrl . '/reset.php?token=invalid'
);

check(
    $response['status'] === 200 &&
    str_contains(
        $response['body'],
        'This reset link is invalid'
    ),
    'Malformed password-reset token is rejected'
);

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);