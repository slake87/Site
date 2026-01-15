<?php
session_start();

// Знищуємо всі дані сесії
$_SESSION = array();

// Видаляємо куки сесії
if (ini_get(option: "session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(name: session_name(), value: '', expires_or_options: time() - 42000,
        path: $params["path"], domain: $params["domain"],
        secure: $params["secure"], httponly: $params["httponly"]
    );
}

// Знищуємо сесію
session_destroy();

// Перенаправляємо на сторінку входу
header(header: 'Location: login.php');
exit;
?>