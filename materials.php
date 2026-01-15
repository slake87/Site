<?php
session_start();

// Перевірка автентифікації
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: login.php');
    exit;
}

// Перевірка ролі користувача
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
$database = "form_data";
$error = '';
$success = isset($_GET['success']) ? urldecode($_GET['success']) : '';

try {
    $conn = new PDO(
        "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
        null,
        null
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $error = "Помилка бази даних: " . $e->getMessage();
}

// Отримання унікальних категорій матеріалів
try {
    $stmt = $conn->query("SELECT DISTINCT category FROM dbo.Materials WHERE category IS NOT NULL AND category != ''");
    $materialCategories = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (PDOException $e) {
    $error = "Помилка бази даних: " . $e->getMessage();
}

// Отримання матеріалів
try {
    $stmt = $conn->query("
        SELECT 
            m.material_id, m.name, m.category, m.unit, m.base_price, m.description, m.specifications,
            s.supplier_id, s.name AS supplier_name,
            n.unit_consumption, n.waste_factor
        FROM dbo.Materials m
        LEFT JOIN dbo.SupplierMaterials sm ON m.material_id = sm.material_id
        LEFT JOIN dbo.Suppliers s ON sm.supplier_id = s.supplier_id
        LEFT JOIN dbo.MaterialNorms n ON m.material_id = n.material_id AND n.construction_type = m.category
        ORDER BY s.name, m.name
    ");
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Групування матеріалів за постачальниками
    $materialsBySupplier = [];
    foreach ($materials as $material) {
        $supplierId = $material['supplier_id'] ?? 0;
        $supplierName = $material['supplier_name'] ?? 'Інші матеріали';
        
        if (!isset($materialsBySupplier[$supplierId])) {
            $materialsBySupplier[$supplierId] = [
                'supplier_name' => $supplierName,
                'materials' => []
            ];
        }
        
        $materialsBySupplier[$supplierId]['materials'][] = $material;
    }

    $suppliersStmt = $conn->query("SELECT supplier_id, name FROM dbo.Suppliers ORDER BY name");
    $allSuppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Помилка бази даних: " . $e->getMessage());
}

// Обробка збереження матеріалу
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_material'])) {
    $materialId = (int)$_POST['material_id'];
    $name = trim($_POST['name']);
    $category = trim($_POST['category']);
    $unit = trim($_POST['unit']);
    $basePrice = (float)$_POST['base_price'];
    $description = trim($_POST['description']);
    $specifications = trim($_POST['specifications']);
    $supplierId = (int)$_POST['supplier_id'];
    $unitConsumption = (float)$_POST['unit_consumption'];
    $wasteFactor = (float)$_POST['waste_factor'];

    try {
        // Перевірка обов'язкових полів
        if (empty($name)) {
            throw new Exception("Назва матеріалу є обов'язковою");
        }
        if (empty($category)) {
            throw new Exception("Категорія є обов'язковою");
        }
        if (empty($unit)) {
            throw new Exception("Одиниця виміру є обов'язковою");
        }
        if ($basePrice <= 0) {
            throw new Exception("Ціна повинна бути більше 0");
        }
        if ($supplierId <= 0) {
            throw new Exception("Постачальник є обов'язковим");
        }
        
        // Перевірка на унікальність назви матеріалу
        if ($materialId > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM dbo.Materials 
                WHERE name = :name AND material_id != :material_id
            ");
            $stmt->execute([':name' => $name, ':material_id' => $materialId]);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM dbo.Materials 
                WHERE name = :name
            ");
            $stmt->execute([':name' => $name]);
        }
        
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Матеріал з такою назвою вже існує");
        }

        if ($materialId > 0) {
            // Оновлення існуючого матеріалу
            $stmt = $conn->prepare("
                UPDATE dbo.Materials 
                SET name = :name, category = :category, unit = :unit, base_price = :base_price, 
                    description = :description, specifications = :specifications 
                WHERE material_id = :material_id
            ");
            $stmt->execute([
                ':name' => $name,
                ':category' => $category,
                ':unit' => $unit,
                ':base_price' => $basePrice,
                ':description' => $description,
                ':specifications' => $specifications,
                ':material_id' => $materialId
            ]);

            // Оновлення або вставка норми
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM dbo.MaterialNorms 
                WHERE material_id = :material_id AND construction_type = :category
            ");
            $stmt->execute([':material_id' => $materialId, ':category' => $category]);
            $exists = $stmt->fetchColumn() > 0;

            if ($exists) {
                $stmt = $conn->prepare("
                    UPDATE dbo.MaterialNorms 
                    SET unit_consumption = :unit_consumption, waste_factor = :waste_factor 
                    WHERE material_id = :material_id AND construction_type = :category
                ");
            } else {
                // Генерація нового norm_id
                $stmt = $conn->query("SELECT MAX(norm_id) AS max_id FROM dbo.MaterialNorms");
                $maxId = $stmt->fetchColumn();
                $nextNormId = $maxId + 1;

                $stmt = $conn->prepare("
                    INSERT INTO dbo.MaterialNorms 
                    (norm_id, material_id, construction_type, unit_consumption, waste_factor) 
                    VALUES (:norm_id, :material_id, :category, :unit_consumption, :waste_factor)
                ");
            }
            $stmt->execute([
                ':unit_consumption' => $unitConsumption,
                ':waste_factor' => $wasteFactor,
                ':material_id' => $materialId,
                ':category' => $category
            ]);

            // Перевірити чи існує запис про постачальника
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM dbo.SupplierMaterials 
    WHERE material_id = :material_id
");
$stmt->execute([':material_id' => $materialId]);
$exists = $stmt->fetchColumn() > 0;

if ($exists) {
    // Оновити існуючий запис
    $stmt = $conn->prepare("
        UPDATE dbo.SupplierMaterials 
        SET supplier_id = :supplier_id 
        WHERE material_id = :material_id
    ");
    $stmt->execute([
        ':supplier_id' => $supplierId,
        ':material_id' => $materialId
    ]);
} else {
    // Додати новий запис
    $stmt = $conn->query("SELECT MAX(record_id) AS max_record_id FROM dbo.SupplierMaterials");
    $maxRecordId = $stmt->fetchColumn();
    $nextRecordId = $maxRecordId + 1;

    $stmt = $conn->prepare("
        INSERT INTO dbo.SupplierMaterials 
        (record_id, material_id, supplier_id) 
        VALUES (:record_id, :material_id, :supplier_id)
    ");
    $stmt->execute([
        ':record_id' => $nextRecordId,
        ':material_id' => $materialId,
        ':supplier_id' => $supplierId
    ]);
}
        } else {
            // Генерація нового material_id
            $stmt = $conn->query("SELECT MAX(material_id) AS max_id FROM dbo.Materials");
            $maxId = $stmt->fetchColumn();
            $nextId = $maxId + 1;

            // Додавання нового матеріалу
            $stmt = $conn->prepare("
                INSERT INTO dbo.Materials 
                (material_id, name, category, unit, base_price, description, specifications) 
                VALUES (:material_id, :name, :category, :unit, :base_price, :description, :specifications)
            ");
            $stmt->execute([
                ':material_id' => $nextId,
                ':name' => $name,
                ':category' => $category,
                ':unit' => $unit,
                ':base_price' => $basePrice,
                ':description' => $description,
                ':specifications' => $specifications
            ]);

            // Генерація нового norm_id
            $stmt = $conn->query("SELECT MAX(norm_id) AS max_id FROM dbo.MaterialNorms");
            $maxNormId = $stmt->fetchColumn();
            $nextNormId = $maxNormId + 1;

            // Додавання норми
            $stmt = $conn->prepare("
                INSERT INTO dbo.MaterialNorms 
                (norm_id, material_id, construction_type, unit_consumption, waste_factor) 
                VALUES (:norm_id, :material_id, :category, :unit_consumption, :waste_factor)
            ");
            $stmt->execute([
                ':norm_id' => $nextNormId,
                ':material_id' => $nextId,
                ':category' => $category,
                ':unit_consumption' => $unitConsumption,
                ':waste_factor' => $wasteFactor
            ]);

            // Генерація нового record_id для SupplierMaterials
            $stmt = $conn->query("SELECT MAX(record_id) AS max_record_id FROM dbo.SupplierMaterials");
            $maxRecordId = $stmt->fetchColumn();
            $nextRecordId = $maxRecordId + 1;

            // Додавання постачальника
            $stmt = $conn->prepare("
                INSERT INTO dbo.SupplierMaterials (record_id, material_id, supplier_id) 
                VALUES (:record_id, :material_id, :supplier_id)
            ");
            $stmt->execute([
                ':record_id' => $nextRecordId,
                ':material_id' => $nextId,
                ':supplier_id' => $supplierId
            ]);
            
            $success = "Матеріал успішно додано!";
        }
        header("Location: materials.php?success=" . urlencode($success));
        exit;
    } catch (Exception $e) {
        $error = "Помилка збереження: " . $e->getMessage();
    }
}

// Обробка видалення матеріалу
if ($isAdmin && isset($_GET['delete'])) {
    $materialId = (int)$_GET['delete'];
    
    try {
        $conn->beginTransaction();
        
        // Видалення зв'язків з постачальниками
        $stmt = $conn->prepare("DELETE FROM dbo.SupplierMaterials WHERE material_id = :material_id");
        $stmt->execute([':material_id' => $materialId]);
        
        // Видалення норм
        $stmt = $conn->prepare("DELETE FROM dbo.MaterialNorms WHERE material_id = :material_id");
        $stmt->execute([':material_id' => $materialId]);
        
        // Видалення матеріалу
        $stmt = $conn->prepare("DELETE FROM dbo.Materials WHERE material_id = :material_id");
        $stmt->execute([':material_id' => $materialId]);
        
        $conn->commit();
        $success = "Матеріал успішно видалено!";
        header("Location: materials.php?success=" . urlencode($success));
        exit;
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Помилка видалення: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Матеріали | Будівельний калькулятор</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles/style7.css">
    <style>
        .suppliers-section {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        
        .supplier-card {
            height: auto;
            min-height: 250px;
            display: flex;
            flex-direction: column;
            padding: 10px;
            border: 1px solid #eee;
            border-radius: 10px;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .supplier-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .supplier-info .info-item {
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
        }
        
        .supplier-info .info-label {
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .supplier-info .info-value {
            flex: 1;
            padding-left: 5px;
        }
        
        .filters-section {
            width: 250px;
            padding-right: 20px;
        }
        
        .suppliers-container {
            display: flex;
        }
        
        .admin-controls {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            justify-content: flex-end;
        }
        
        .edit-btn, .delete-btn {
            padding: 5px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            background: none;
            border: none;
            color: #555;
            z-index: 10;
            position: relative;
        }
        
        .edit-btn:hover {
            color: #3498db;
        }
        
        .delete-btn:hover {
            color: #e74c3c;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 10px;
            padding: 25px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            margin-bottom: 20px;
        }
        
        .close-btn {
            position: absolute;
            top: 5px;
            right: 10px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #555;
        }
        
        .modal-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }
        
        .cancel-btn {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .save-btn {
            background: #2ecc71;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .delete-confirm-btn {
            background: #2ecc71;
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            text-decoration: none;
            text-align: center;
            display: inline-block;
        }
        
        .delete-confirm-btn:hover {
            background: #27ae60;
        }
        
        .supplier-form input, 
        .supplier-form textarea,
        .supplier-form select {
            width: 90%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
        }
        
        .supplier-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .add-supplier-btn {
            background-color: #eb7609;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
        }
        
        .add-supplier-btn:hover {
            background-color: #eb7609;
        }
        
        /* Адаптивність */
        @media (max-width: 1200px) {
            .suppliers-section {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 900px) {
            .suppliers-section {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 600px) {
            .suppliers-section {
                grid-template-columns: 1fr;
            }
            
            .suppliers-container {
                flex-direction: column;
            }
            
            .filters-section {
                width: 100%;
                padding-right: 0;
                margin-bottom: 20px;
            }
        }
        
        .material-category {
            font-style: italic;
            color: #666;
            font-size: 0.9em;
            margin-top: 5px;
        }
        
        .material-price {
            font-weight: bold;
            color:rgb(24, 43, 68);
            margin-top: 5px;
        }
        
        .no-results {
            grid-column: 1 / -1;
            text-align: center;
            padding: 40px;
            color: #777;
        }
        
        /* Стилі для хедера */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            background-color: #2c3e50;
            color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .nav-menu {
            display: flex;
        }
        
        .nav-list {
            display: flex;
            list-style: none;
            gap: 20px;
            margin: 0;
            padding: 0;
        }
        
        .nav-link {
            color: #ecf0f1;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .nav-link:hover, .nav-link.active {
            background-color:  #eb7609;
        }
        
        .user-panel {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .profile, .logout-btn {
            background:  #eb7609;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }
        
        .profile:hover, .logout-btn:hover {
            background:  #eb7609;
        }
        
        .username {
            font-weight: bold;
        }
        
        .error-message {
            background-color: #f8d7da;
            color:rgb(195, 20, 37);
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        
        .success-message {
            background-color: #d4edda;
            color:rgb(22, 169, 56);
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
        
        /* Підсвічування картки при переході */
        .supplier-card.highlighted {
            border: 2px solid #3498db;
            box-shadow: 0 0 10px rgba(52, 152, 219, 0.5);
        }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <nav class="nav-menu">
            <ul class="nav-list">
                <li><a href="estimates.php" class="nav-link">Проєкти</a></li>
                <li><a href="materials.php" class="nav-link active">Матеріали</a></li>
                <li><a href="calculations.php" class="nav-link">Розрахунки</a></li>
                <li><a href="suppliers.php" class="nav-link">Постачальники</a></li>
            </ul>
        </nav>
        <div class="user-panel">
            <button class="profile" onclick="window.location.href='profile.php'">Мій профіль</button>
            <span class="username">Привіт, <?= htmlspecialchars($_SESSION['username'] ?? 'Користувач') ?>!</span>
            <button class="logout-btn" onclick="window.location.href='logout.php'">Вихід</button>
        </div>
    </header>

    <main class="dashboard-main">
        <div class="section-header">
            <h2 class="section-title">Будівельні матеріали</h2>
            <?php if ($isAdmin) { ?>
                <button id="addMaterialBtn" class="add-supplier-btn">
                    <i class="fas fa-plus"></i> Додати матеріал
                </button>
            <?php } ?>
        </div>
        
        <?php if ($error) { ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php } ?>
        
        <?php if ($success) { ?>
            <div class="success-message"><?= htmlspecialchars($success) ?></div>
        <?php } ?>
        
        <div class="suppliers-container">
            <!-- Ліва колонка: Фільтри -->
            <div class="filters-section">
                <div class="search-container">
                    <i class="fas fa-search"></i>
                    <input type="text" id="material-search" placeholder="Пошук матеріалів...">
                </div>
                
                <div class="filter-group">
                    <h3><i class="fas fa-filter"></i> Категорія матеріалу</h3>
                    <ul class="category-list" id="category-filter">
                        <li class="category-item active" data-filter-type="category" data-filter-value="all">
                            <i class="fas fa-circle"></i> Всі категорії
                        </li>
                        <?php foreach ($materialCategories as $category) { ?>
                            <li class="category-item" data-filter-type="category" data-filter-value="<?= htmlspecialchars($category) ?>">
                                <i class="fas fa-circle"></i> <?= htmlspecialchars($category) ?>
                            </li>
                        <?php } ?>
                    </ul>
                </div>
            </div>
            
            <!-- Права колонка: Картки матеріалів -->
            <div class="suppliers-section" id="materials-list">
                <?php if (empty($materialsBySupplier)) { ?>
                    <div class="no-results">
                        <i class="fas fa-search"></i>
                        <h3>Матеріалів не знайдено</h3>
                        <p>Спробуйте змінити параметри фільтрів</p>
                    </div>
                <?php } else { ?>
                    <?php foreach ($materialsBySupplier as $supplierId => $supplierData) { ?>
                        <?php foreach ($supplierData['materials'] as $material) { ?>
                        <div class="supplier-card" 
                             data-id="<?= $material['material_id'] ?>" 
                             data-category="<?= htmlspecialchars($material['category'] ?? '') ?>"
                             data-supplier-id="<?= $supplierId ?>">
                            <?php if ($isAdmin) { ?>
                                <div class="admin-controls">
                                    <button class="edit-btn" 
                                        data-id="<?= $material['material_id'] ?>"
                                        data-name="<?= htmlspecialchars($material['name']) ?>"
                                        data-category="<?= htmlspecialchars($material['category'] ?? '') ?>"
                                        data-unit="<?= htmlspecialchars($material['unit'] ?? '') ?>"
                                        data-base-price="<?= $material['base_price'] ?>"
                                        data-description="<?= htmlspecialchars($material['description'] ?? '') ?>"
                                        data-specifications="<?= htmlspecialchars($material['specifications'] ?? '') ?>"
                                        data-supplier-id="<?= $supplierId ?>"
                                        data-unit-consumption="<?= $material['unit_consumption'] ?? '' ?>"
                                        data-waste-factor="<?= $material['waste_factor'] ?? '' ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="delete-btn" 
                                        data-id="<?= $material['material_id'] ?>"
                                        data-name="<?= htmlspecialchars($material['name']) ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            <?php } ?>
                            
                            <div class="supplier-header">
                                <div>
                                    <h3 class="supplier-name"><?= htmlspecialchars($material['name']) ?></h3>
                                    <?php if (!empty($material['category'])) { ?>
                                        <div class="material-category">
                                            Категорія: <?= htmlspecialchars($material['category']) ?>
                                        </div>
                                    <?php } ?>
                                    <?php if ($material['base_price'] > 0) { ?>
                                        <div class="material-price">
                                            Ціна: <?= number_format($material['base_price'], 2) ?> грн/<?= htmlspecialchars($material['unit'] ?? 'од.') ?>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>
                            <div class="supplier-info">
                                <?php if (!empty($material['description'])) { ?>
                                    <div class="info-item">
                                        <span class="info-label">Опис:</span>
                                        <span class="info-value"><?= htmlspecialchars($material['description']) ?></span>
                                    </div>
                                <?php } ?>
                                
                                <?php if (!empty($material['specifications'])) { ?>
                                    <div class="info-item">
                                        <span class="info-label">Характеристики:</span>
                                        <span class="info-value"><?= htmlspecialchars($material['specifications']) ?></span>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                        <?php } ?>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>
    </main>

    <!-- Модальне вікно для додавання/редагування матеріалу -->
    <div class="modal" id="material-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modal-title">Додати матеріал</h3>
                <button class="close-btn" id="close-modal">&times;</button>
            </div>
            <form id="material-form" class="supplier-form" method="post">
                <input type="hidden" name="material_id" id="material_id" value="0">
                
                <div class="modal-input-group">
                    <label for="name">Назва матеріалу:</label>
                    <input type="text" id="name" name="name" required>
                </div>
                
                <div class="modal-input-group">
                    <label for="category">Категорія:</label>
                    <input type="text" id="category" name="category" required>
                </div>
                
                <div class="modal-input-group">
                    <label for="unit">Одиниця виміру:</label>
                    <input type="text" id="unit" name="unit" required>
                </div>
                
                <div class="modal-input-group">
                    <label for="base_price">Базова ціна (грн):</label>
                    <input type="number" id="base_price" name="base_price" min="0.01" step="0.01" required>
                </div>
                
                <div class="modal-input-group">
                    <label for="unit_consumption">Витрата на одиницю (наприклад, кг/м²):</label>
                    <input type="number" id="unit_consumption" name="unit_consumption" min="0" step="0.001">
                </div>
                
                <div class="modal-input-group">
                    <label for="waste_factor">Коефіцієнт відходів (%):</label>
                    <input type="number" id="waste_factor" name="waste_factor" min="0" max="100" step="0.1">
                </div>
                
                <div class="modal-input-group">
                    <label for="description">Опис:</label>
                    <textarea id="description" name="description" rows="3"></textarea>
                </div>
                
                <div class="modal-input-group">
                    <label for="specifications">Характеристики:</label>
                    <textarea id="specifications" name="specifications" rows="3"></textarea>
                </div>
                
                <div class="modal-input-group">
                    <label for="supplier_id">Постачальник:</label>
                    <select id="supplier_id" name="supplier_id" required>
                        <option value="0">-- Оберіть постачальника --</option>
                        <?php foreach ($allSuppliers as $supplier) { ?>
                            <option value="<?= $supplier['supplier_id'] ?>">
                                <?= htmlspecialchars($supplier['name']) ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                
                <div class="modal-buttons">
                    <button type="button" class="cancel-btn" id="cancel-edit">Скасувати</button>
                    <button type="submit" name="save_material" class="save-btn">Зберегти</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Модальне вікно для підтвердження видалення -->
    <div class="modal" id="delete-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Підтвердження видалення</h3>
                <button class="close-btn" id="close-delete-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Ви дійсно бажаєте видалити матеріал "<span id="delete-name"></span>"?</p>
            </div>
            <div class="modal-buttons">
                <button type="button" class="cancel-btn" id="cancel-delete">Скасувати</button>
                <a href="#" class="delete-confirm-btn" id="confirm-delete">Видалити</a>
            </div>
        </div>
    </div>

    <script>
        // Поточні фільтри
        let activeFilters = {
            category: 'all',
            search: ''
        };
        
        // Налаштування обробників подій
        function setupEventListeners() {
            // Обробники фільтрів категорій
            document.querySelectorAll('#category-filter .category-item').forEach(item => {
                item.addEventListener('click', function() {
                    document.querySelectorAll('#category-filter .category-item').forEach(i => {
                        i.classList.remove('active');
                    });
                    this.classList.add('active');
                    activeFilters.category = this.dataset.filterValue;
                    filterMaterials();
                });
            });
            
            // Пошук матеріалів
            document.getElementById('material-search').addEventListener('input', function() {
                activeFilters.search = this.value.toLowerCase();
                filterMaterials();
            });
            
            <?php if ($isAdmin) { ?>
                // Кнопка "Додати матеріал" - тільки для адміна
                document.getElementById('addMaterialBtn').addEventListener('click', function() {
                    document.getElementById('modal-title').textContent = 'Додати матеріал';
                    document.getElementById('material_id').value = '0';
                    document.getElementById('material-form').reset();
                    document.getElementById('supplier_id').value = '0';
                    document.getElementById('material-modal').style.display = 'flex';
                });
                
                // Кнопки редагування - тільки для адміна
                document.querySelectorAll('.edit-btn').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        document.getElementById('modal-title').textContent = 'Редагувати матеріал';
                        document.getElementById('material_id').value = this.dataset.id;
                        document.getElementById('name').value = this.dataset.name;
                        document.getElementById('category').value = this.dataset.category;
                        document.getElementById('unit').value = this.dataset.unit;
                        
                        // Форматування ціни - 2 знаки після коми
                        const basePrice = this.dataset.basePrice;
                        document.getElementById('base_price').value = basePrice ? 
                            parseFloat(basePrice).toFixed(2) : '';
                        
                        // Форматування витрати - 3 знаки після коми
                        const unitConsumption = this.dataset.unitConsumption;
                        document.getElementById('unit_consumption').value = unitConsumption ? 
                            parseFloat(unitConsumption).toFixed(3) : '';
                        
                        // Форматування коефіцієнта - 1 знак після коми
                        const wasteFactor = this.dataset.wasteFactor;
                        document.getElementById('waste_factor').value = wasteFactor ? 
                            parseFloat(wasteFactor).toFixed(1) : '';
                        
                        document.getElementById('description').value = this.dataset.description;
                        document.getElementById('specifications').value = this.dataset.specifications;
                        document.getElementById('supplier_id').value = this.dataset.supplierId || '0';
                        document.getElementById('material-modal').style.display = 'flex';
                    });
                });
                
                // Кнопки видалення - тільки для адміна
                document.querySelectorAll('.delete-btn').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const materialId = this.dataset.id;
                        const materialName = this.dataset.name;
                        
                        document.getElementById('delete-name').textContent = materialName;
                        document.getElementById('confirm-delete').href = `materials.php?delete=${materialId}`;
                        document.getElementById('delete-modal').style.display = 'flex';
                    });
                });
            <?php } ?>
            
            // Обробник кліку на картку матеріалу - для всіх користувачів
            document.querySelectorAll('.supplier-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    // Не обробляємо кліки на адмін-контролах
                    if (e.target.closest('.admin-controls')) {
                        return;
                    }
                    
                    const supplierId = this.dataset.supplierId;
                    if (supplierId && supplierId !== '0') {
                        window.location.href = `suppliers.php?supplier_id=${supplierId}`;
                    }
                });
            });
            
            // Закриття модальних вікон
            document.getElementById('close-modal').addEventListener('click', function() {
                document.getElementById('material-modal').style.display = 'none';
            });
            
            document.getElementById('cancel-edit').addEventListener('click', function() {
                document.getElementById('material-modal').style.display = 'none';
            });
            
            document.getElementById('close-delete-modal').addEventListener('click', function() {
                document.getElementById('delete-modal').style.display = 'none';
            });
            
            document.getElementById('cancel-delete').addEventListener('click', function() {
                document.getElementById('delete-modal').style.display = 'none';
            });
            
            window.addEventListener('click', function(e) {
                if (e.target === document.getElementById('material-modal')) {
                    document.getElementById('material-modal').style.display = 'none';
                }
                if (e.target === document.getElementById('delete-modal')) {
                    document.getElementById('delete-modal').style.display = 'none';
                }
            });
            
            // Перевірка форми перед відправкою
            document.getElementById('material-form').addEventListener('submit', function(e) {
                const name = document.getElementById('name').value.trim();
                const category = document.getElementById('category').value.trim();
                const unit = document.getElementById('unit').value.trim();
                const basePrice = parseFloat(document.getElementById('base_price').value);
                const supplierId = parseInt(document.getElementById('supplier_id').value);
                
                if (!name) {
                    alert("Будь ласка, введіть назву матеріалу");
                    e.preventDefault();
                    return;
                }
                if (!category) {
                    alert("Будь ласка, введіть категорію");
                    e.preventDefault();
                    return;
                }
                if (!unit) {
                    alert("Будь ласка, введіть одиницю виміру");
                    e.preventDefault();
                    return;
                }
                if (isNaN(basePrice) || basePrice <= 0) {
                    alert("Ціна повинна бути додатнім числом");
                    e.preventDefault();
                    return;
                }
                if (isNaN(supplierId) || supplierId <= 0) {
                    alert("Будь ласка, оберіть постачальника");
                    e.preventDefault();
                    return;
                }
            });
        }
        
        // Фільтрація матеріалів
        function filterMaterials() {
            const searchTerm = activeFilters.search;
            const categoryFilter = activeFilters.category;
            
            document.querySelectorAll('.supplier-card').forEach(card => {
                const name = card.querySelector('.supplier-name').textContent.toLowerCase();
                const description = card.querySelector('.supplier-info .info-value')?.textContent.toLowerCase() || '';
                const category = card.dataset.category.toLowerCase();
                
                let visible = true;
                
                if (searchTerm && !name.includes(searchTerm) && !description.includes(searchTerm)) {
                    visible = false;
                }
                
                if (categoryFilter !== 'all' && category !== categoryFilter.toLowerCase()) {
                    visible = false;
                }
                
                card.style.display = visible ? 'block' : 'none';
            });
            
            const visibleCards = document.querySelectorAll('.supplier-card[style="display: block;"]').length;
            const noResults = document.querySelector('.no-results');
            
            if (visibleCards === 0) {
                if (!noResults) {
                    document.getElementById('materials-list').innerHTML = `
                        <div class="no-results">
                            <i class="fas fa-search"></i>
                            <h3>Матеріалів не знайдено</h3>
                            <p>Спробуйте змінити параметри фільтрів</p>
                        </div>
                    `;
                }
            } else if (noResults) {
                noResults.remove();
            }
        }
        
        // Ініціалізація сторінки
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            filterMaterials();
            
            // Підсвітка після видалення
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('success')) {
                // Оновлюємо сторінку через 2 секунди
                setTimeout(() => {
                    window.location.href = 'materials.php';
                }, 2000);
            }
        });
    </script>
</body>
</html>