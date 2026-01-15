<?php
session_start();

// Перевірка автентифікації та прав адміна
if (!isset($_SESSION['authenticated']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
$database = "form_data";

if (isset($_GET['delete'])) {
    $supplierId = (int)$_GET['delete'];
    
    try {
        $conn = new PDO(
            "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
            null,
            null
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Починаємо транзакцію
        $conn->beginTransaction();
        
        // 1. Видаляємо зв'язки з матеріалами
        $stmt = $conn->prepare("DELETE FROM dbo.SupplierMaterials WHERE supplier_id = :supplier_id");
        $stmt->bindParam(':supplier_id', $supplierId, PDO::PARAM_INT);
        $stmt->execute();
        
        // 2. Видаляємо самого постачальника
        $stmt = $conn->prepare("DELETE FROM dbo.Suppliers WHERE supplier_id = :supplier_id");
        $stmt->bindParam(':supplier_id', $supplierId, PDO::PARAM_INT);
        $stmt->execute();
        
        // Підтверджуємо транзакцію
        $conn->commit();
        
        header('Location: suppliers.php?success=' . urlencode('Постачальника успішно видалено!'));
        exit;
        
    } catch (PDOException $e) {
        // Відкат транзакції при помилці
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        header('Location: suppliers.php?error=' . urlencode('Помилка бази даних: ' . $e->getMessage()));
        exit;
    }
}

header('Location: suppliers.php');