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
                $stmt = $conn->prepare("
                    INSERT INTO dbo.MaterialNorms (material_id, construction_type, unit_consumption, waste_factor) 
                    VALUES (:material_id, :category, :unit_consumption, :waste_factor)
                ");
            }
            $stmt->execute([
                ':unit_consumption' => $unitConsumption,
                ':waste_factor' => $wasteFactor,
                ':material_id' => $materialId,
                ':category' => $category
            ]);

            // Оновлення постачальника
            $conn->prepare("
                UPDATE dbo.SupplierMaterials 
                SET supplier_id = :supplier_id 
                WHERE material_id = :material_id
            ")->execute([':supplier_id' => $supplierId, ':material_id' => $materialId]);
        } else {
            // Додавання нового матеріалу
            $stmt = $conn->prepare("
                INSERT INTO dbo.Materials (name, category, unit, base_price, description, specifications) 
                VALUES (:name, :category, :unit, :base_price, :description, :specifications)
            ");
            $stmt->execute([
                ':name' => $name,
                ':category' => $category,
                ':unit' => $unit,
                ':base_price' => $basePrice,
                ':description' => $description,
                ':specifications' => $specifications
            ]);
            $newMaterialId = $conn->lastInsertId();

            // Додавання норми
            $stmt = $conn->prepare("
                INSERT INTO dbo.MaterialNorms (material_id, construction_type, unit_consumption, waste_factor) 
                VALUES (:material_id, :category, :unit_consumption, :waste_factor)
            ");
            $stmt->execute([
                ':material_id' => $newMaterialId,
                ':category' => $category,
                ':unit_consumption' => $unitConsumption,
                ':waste_factor' => $wasteFactor
            ]);

            // Додавання постачальника
            $conn->prepare("
                INSERT INTO dbo.SupplierMaterials (material_id, supplier_id) 
                VALUES (:material_id, :supplier_id)
            ")->execute([':material_id' => $newMaterialId, ':supplier_id' => $supplierId]);
        }
        header("Location: materials.php");
        exit;
    } catch (PDOException $e) {
        die("Помилка збереження: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Матеріали</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <h1>Матеріали</h1>
        <?php if ($isAdmin): ?>
            <button id="addMaterialBtn" class="btn"><i class="fas fa-plus"></i> Додати матеріал</button>
        <?php endif; ?>
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
                        <?php foreach ($materialCategories as $category): ?>
                            <li class="category-item" data-filter-type="category" data-filter-value="<?= htmlspecialchars($category) ?>">
                                <i class="fas fa-circle"></i> <?= htmlspecialchars($category) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            
            <!-- Права колонка: Картки матеріалів -->
            <div class="suppliers-section" id="materials-list">
                <?php if (empty($materialsBySupplier)): ?>
                    <div class="no-results">
                        <i class="fas fa-search"></i>
                        <h3>Матеріалів не знайдено</h3>
                        <p>Спробуйте змінити параметри фільтрів</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($materialsBySupplier as $supplierId => $supplierData): ?>
                        <?php foreach ($supplierData['materials'] as $material): ?>
                        <div class="supplier-card" 
                             data-id="<?= $material['material_id'] ?>" 
                             data-category="<?= htmlspecialchars($material['category'] ?? '') ?>"
                             data-supplier-id="<?= $supplierId ?>">
                            <?php if ($isAdmin): ?>
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
                            <?php endif; ?>
                            
                            <div class="supplier-header">
                                <div>
                                    <h3 class="supplier-name"><?= htmlspecialchars($material['name']) ?></h3>
                                    <?php if (!empty($material['category'])): ?>
                                        <div class="material-category">
                                            Категорія: <?= htmlspecialchars($material['category']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($material['base_price'] > 0): ?>
                                        <div class="material-price">
                                            Ціна: <?= number_format($material['base_price'], 2) ?> грн/<?= htmlspecialchars($material['unit'] ?? 'од.') ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="supplier-info">
                                <?php if (!empty($material['description'])): ?>
                                    <div class="info-item">
                                        <span class="info-label">Опис:</span>
                                        <span class="info-value"><?= htmlspecialchars($material['description']) ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($material['specifications'])): ?>
                                    <div class="info-item">
                                        <span class="info-label">Характеристики:</span>
                                        <span class="info-value"><?= htmlspecialchars($material['specifications']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
                    <input type="number" id="base_price" name="base_price" min="0" step="0.01" required>
                </div>
                
                <div class="modal-input-group">
                    <label for="unit_consumption">Витрата на одиницю (наприклад, кг/м²):</label>
                    <input type="number" id="unit_consumption" name="unit_consumption" min="0" step="0.01">
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
                    <select id="supplier_id" name="supplier_id">
                        <option value="0">-- Оберіть постачальника --</option>
                        <?php foreach ($allSuppliers as $supplier): ?>
                            <option value="<?= $supplier['supplier_id'] ?>">
                                <?= htmlspecialchars($supplier['name']) ?>
                            </option>
                        <?php endforeach; ?>
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
            
            // Кнопка "Додати матеріал"
            document.getElementById('addMaterialBtn').addEventListener('click', function() {
                document.getElementById('modal-title').textContent = 'Додати матеріал';
                document.getElementById('material_id').value = '0';
                document.getElementById('material-form').reset();
                document.getElementById('supplier_id').value = '0';
                document.getElementById('material-modal').style.display = 'flex';
            });
            
            // Кнопки редагування
            document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    document.getElementById('modal-title').textContent = 'Редагувати матеріал';
                    document.getElementById('material_id').value = this.dataset.id;
                    document.getElementById('name').value = this.dataset.name;
                    document.getElementById('category').value = this.dataset.category;
                    document.getElementById('unit').value = this.dataset.unit;
                    document.getElementById('base_price').value = this.dataset.basePrice;
                    document.getElementById('unit_consumption').value = this.dataset.unitConsumption || '';
                    document.getElementById('waste_factor').value = this.dataset.wasteFactor || '';
                    document.getElementById('description').value = this.dataset.description;
                    document.getElementById('specifications').value = this.dataset.specifications;
                    document.getElementById('supplier_id').value = this.dataset.supplierId || '0';
                    document.getElementById('material-modal').style.display = 'flex';
                });
            });
            
            // Кнопки видалення
            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const materialId = this.dataset.id;
                    const materialName = this.dataset.name;
                    
                    document.getElementById('delete-name').textContent = materialName;
                    document.getElementById('confirm-delete').href = `delete-material.php?delete=${materialId}`;
                    document.getElementById('delete-modal').style.display = 'flex';
                });
            });
            
            // Обробник кліку на картку матеріалу
            document.querySelectorAll('.supplier-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    if (e.target.closest('.admin-controls')) return;
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
            
            if (visibleCards === 0 && !noResults) {
                document.getElementById('materials-list').innerHTML = `
                    <div class="no-results">
                        <i class="fas fa-search"></i>
                        <h3>Матеріалів не знайдено</h3>
                        <p>Спробуйте змінити параметри фільтрів</p>
                    </div>
                `;
            } else if (visibleCards > 0 && noResults) {
                noResults.remove();
            }
        }
        
        // Ініціалізація сторінки
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            filterMaterials();
        });
    </script>
</body>
</html>