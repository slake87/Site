<?php
session_start();

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header(header: 'Location: login.php');
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['delete_estimate_error'] = "Невірний ідентифікатор кошторису";
    header(header: 'Location: estimates.php');
    exit;
}

$estimateId = (int)$_GET['id'];

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
    
    // Починаємо транзакцію
    $conn->beginTransaction();
    
    // 1. Знаходимо calc_id для цього кошторису
    $stmt = $conn->prepare(query: "SELECT calc_id FROM dbo.Estimates WHERE estimate_id = :estimate_id");
    $stmt->bindParam(param: ':estimate_id', var: $estimateId, type: PDO::PARAM_INT);
    $stmt->execute();
    $estimate = $stmt->fetch(mode: PDO::FETCH_ASSOC);
    
    if (!$estimate) {
        throw new Exception(message: "Кошторис не знайдено");
    }
    
    $calcId = $estimate['calc_id'];
    
    // 2. Видаляємо кошторис
    $stmt = $conn->prepare(query: "DELETE FROM dbo.Estimates WHERE estimate_id = :estimate_id");
    $stmt->bindParam(param: ':estimate_id', var: $estimateId, type: PDO::PARAM_INT);
    $stmt->execute();
    
    // 3. Видаляємо пов'язаний розрахунок
    $stmt = $conn->prepare(query: "DELETE FROM dbo.Calculations WHERE calc_id = :calc_id");
    $stmt->bindParam(param: ':calc_id', var: $calcId, type: PDO::PARAM_INT);
    $stmt->execute();
    
    // Підтверджуємо транзакцію
    $conn->commit();
    
    $_SESSION['delete_estimate_success'] = true;
    
} catch (Exception $e) {
    // Відкат транзакції при помилці
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    $_SESSION['delete_estimate_error'] = "Помилка при видаленні: " . $e->getMessage();
}

header(header: 'Location: estimates.php');
exit;
