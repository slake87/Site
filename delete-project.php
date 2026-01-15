<?php
session_start();

// Перевірка автентифікації
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header(header: 'Location: login.php');
    exit;
}

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($projectId > 0) {
    // Підключення до бази даних
    $serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
    $database = "form_data";
    
    try {
        $conn = new PDO(
            dsn: "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
            username: null,
            password: null
        );
        $conn->setAttribute(attribute: PDO::ATTR_ERRMODE, value: PDO::ERRMODE_EXCEPTION);
        
        // Видалення проєкту з перевіркою власника
        $stmt = $conn->prepare(query: "
            DELETE FROM dbo.Projects 
            WHERE project_id = :project_id 
            AND user_id = :user_id
        ");
        $stmt->bindParam(param: ':project_id', var: $projectId, type: PDO::PARAM_INT);
        $stmt->bindParam(param: ':user_id', var: $_SESSION['user_id'], type: PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['delete_success'] = true;
        } else {
            $_SESSION['delete_error'] = "Проєкт не знайдено або у вас немає прав для його видалення";
        }
    } catch (PDOException $e) {
        $_SESSION['delete_error'] = "Помилка бази даних: " . $e->getMessage();
    }
} else {
    $_SESSION['delete_error'] = "Невірний ідентифікатор проєкту";
}

header(header: 'Location: estimates.php');
exit;
?>