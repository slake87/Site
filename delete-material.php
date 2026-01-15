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
    $materialId = (int)$_GET['delete'];
    
    try {
        $conn = new PDO(
            "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
            null,
            null
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Починаємо транзакцію
        $conn->beginTransaction();

        // 1. Видаляємо пов'язані записи в ProjectMaterials
        $stmt = $conn->prepare("DELETE FROM dbo.ProjectMaterials WHERE material_id = :material_id");
        $stmt->bindParam(':material_id', $materialId, PDO::PARAM_INT);
        $stmt->execute();

        // 2. Видаляємо зв'язки з постачальниками
        $stmt = $conn->prepare("DELETE FROM dbo.SupplierMaterials WHERE material_id = :material_id");
        $stmt->bindParam(':material_id', $materialId, PDO::PARAM_INT);
        $stmt->execute();
        
        // 3. Видаляємо сам матеріал з таблиці Materials
        $stmt = $conn->prepare("DELETE FROM dbo.Materials WHERE material_id = :material_id");
        $stmt->bindParam(':material_id', $materialId, PDO::PARAM_INT);
        $stmt->execute();
        
        // Підтверджуємо транзакцію
        $conn->commit();
        
        header('Location: materials.php?success=' . urlencode('Матеріал успішно видалено!'));
        exit;
        
    } catch (PDOException $e) {
        // Відкат транзакції при помилці
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        header('Location: materials.php?error=' . urlencode('Помилка бази даних: ' . $e->getMessage()));
        exit;
    }
}

header('Location: materials.php');