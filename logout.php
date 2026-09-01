<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (auth_user() !== null) {
    if (!is_post()) {
        http_response_code(405);
        header('Allow: POST');
        exit('Sign out requires a POST request.');
    }
    csrf_verify();
    auth_logout();
}

redirect('login.php');
