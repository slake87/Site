<?php
session_start();

// Перевірка автентифікації
if (!isset($_SESSION['authenticated'])) {
    header(header: 'Location: login.php');
    exit;
}

$serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
$database = "form_data";
$error = isset($_GET['error']) ? urldecode(string: $_GET['error']) : '';
$success = isset($_GET['success']) ? urldecode(string: $_GET['success']) : '';
$suppliers = [];
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

// Отримання ID постачальника для фільтрації
$filterSupplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;

try {
    $conn = new PDO(
        dsn: "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
        username: null,
        password: null
    );
    $conn->setAttribute(attribute: PDO::ATTR_ERRMODE, value: PDO::ERRMODE_EXCEPTION);

    // Обробка додавання/редагування постачальника
    if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
        $name = $_POST['name'] ?? '';
        $contact = $_POST['contact'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address = $_POST['address'] ?? '';
        $rating = (float)($_POST['rating'] ?? 0);
        
        if ($rating < 0) $rating = 0;
        if ($rating > 5) $rating = 5;
        
        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE dbo.Suppliers 
                SET name = :name, contact_person = :contact, email = :email, 
                    phone = :phone, address = :address, rating = :rating 
                WHERE supplier_id = :id
            ");
            $stmt->bindParam(param: ':id', var: $id, type: PDO::PARAM_INT);
        } else {
            $stmt = $conn->query(query: "SELECT MAX(supplier_id) AS max_id FROM dbo.Suppliers");
            $maxId = $stmt->fetch(mode: PDO::FETCH_ASSOC)['max_id'] ?? 0;
            $nextId = $maxId + 1;
            
            $stmt = $conn->prepare("
                INSERT INTO dbo.Suppliers 
                (supplier_id, name, contact_person, email, phone, address, rating) 
                VALUES (:id, :name, :contact, :email, :phone, :address, :rating)
            ");
            $stmt->bindParam(param: ':id', var: $nextId, type: PDO::PARAM_INT);
        }
        
        $stmt->bindParam(param: ':name', var: $name);
        $stmt->bindParam(param: ':contact', var: $contact);
        $stmt->bindParam(param: ':email', var: $email);
        $stmt->bindParam(param: ':phone', var: $phone);
        $stmt->bindParam(param: ':address', var: $address);
        $stmt->bindParam(param: ':rating', var: $rating);
        
        if ($stmt->execute()) {
            $success = $id > 0 
                ? "Дані постачальника оновлено!" 
                : "Постачальника успішно додано!";
            header(header: "Location: suppliers.php?success=" . urlencode(string: $success));
            exit;
        } else {
            $error = "Помилка при збереженні: " . implode(separator: " ", array: $stmt->errorInfo());
        }
    }

    // Отримання списку постачальників
    $stmt = $conn->query(query: "SELECT * FROM dbo.Suppliers");
    $suppliers = $stmt->fetchAll(mode: PDO::FETCH_ASSOC);

    // Якщо заданий ID постачальника для фільтрації
    if ($filterSupplierId > 0) {
        // Знаходимо постачальника та переміщуємо його на початок масиву
        $selectedSupplier = null;
        foreach ($suppliers as $key => $supplier) {
            if ($supplier['supplier_id'] == $filterSupplierId) {
                $selectedSupplier = $supplier;
                unset($suppliers[$key]);
                break;
            }
        }
        if ($selectedSupplier) {
            array_unshift($suppliers, $selectedSupplier);
        }
    }

} catch (PDOException $e) {
    $error = "Помилка бази даних: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Постачальники | Будівельний калькулятор</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="styles/style7.css">
    <style>
        /* Стилі для адмін-контролів */
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
        }
        .edit-btn:hover {
            color: #3498db;
        }
        .delete-btn:hover {
            color: #e74c3c;
        }
        
        /* Стилі для модальних вікон */
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
        
        /* Стилі для форми */
        .supplier-form input, .supplier-form textarea {
            width: 100%;
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
        
        /* Стилі для карток постачальників */
        .suppliers-section {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        .supplier-card {
            height: auto;
            min-height: 300px;
            display: flex;
            flex-direction: column;
            padding: 15px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .supplier-card.highlighted {
            transform: scale(1.02);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            border: 2px solid #3498db;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(52, 152, 219, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(52, 152, 219, 0); }
            100% { box-shadow: 0 0 0 0 rgba(52, 152, 219, 0); }
        }
        .supplier-info .info-item {
            margin-bottom: 10px;
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
        .contact-button {
            margin-top: auto;
            width: 90%;
            padding: 12px;
            text-align: center;
            background:rgb(2, 51, 71);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
        }
        .contact-button:hover {
            background:rgb(11, 179, 151);
        }
        
        /* Стилі для фільтрів */
        .filters-section {
            width: 250px;
            padding-right: 20px;
        }
        .suppliers-container {
            display: flex;
        }
        
        /* Стилі для рейтингу */
        .rating-stars {
            display: flex;
            margin-bottom: 5px;
        }
        .rating-star {
            color: #ddd;
            margin-right: 2px;
        }
        .rating-star.active {
            color: #FFD700;
        }
        .rating-value {
            font-weight: bold;
        }
        
        /* Оновлені стилі для модальних вікон */
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
        
        /* Адаптивність */
        @media (max-width: 1200px) {
            .suppliers-section {
                grid-template-columns: repeat(2, 1fr);
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
    </style>
</head>
<body>
    <header class="dashboard-header">
        <nav class="nav-menu">
            <ul class="nav-list">
                <li><a href="estimates.php" class="nav-link">Проєкти</a></li>
                <li><a href="materials.php" class="nav-link">Матеріали</a></li>
                <li><a href="calculations.php" class="nav-link">Розрахунки</a></li>
                <li><a href="suppliers.php" class="nav-link active">Постачальники</a></li>
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
            <h2 class="section-title">Наші постачальники</h2>
            <?php if ($isAdmin): ?>
                <button class="add-supplier-btn" id="open-add-modal">
                    <i class="fas fa-plus"></i> Додати постачальника
                </button>
            <?php endif; ?>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <div class="suppliers-container">
            <!-- Ліва колонка: Фільтри -->
            <div class="filters-section">
                <div class="search-container">
                    <i class="fas fa-search"></i>
                    <input type="text" id="supplier-search" placeholder="Пошук постачальників...">
                </div>
                
                <div class="filter-group">
                    <h3><i class="fas fa-star"></i> Рейтинг</h3>
                    <ul class="category-list" id="rating-filter">
                        <li class="category-item active" data-filter-type="rating" data-filter-value="0">
                            <i class="fas fa-circle"></i> Будь-який рейтинг
                        </li>
                        <li class="category-item" data-filter-type="rating" data-filter-value="4">
                            <i class="fas fa-star"></i> Від 4 зірок
                        </li>
                        <li class="category-item" data-filter-type="rating" data-filter-value="3">
                            <i class="fas fa-star"></i> Від 3 зірок
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Права колонка: Картки постачальників -->
            <div class="suppliers-section" id="suppliers-list">
                <?php if (empty($suppliers)): ?>
                    <div class="no-results">
                        <i class="fas fa-search"></i>
                        <h3>Постачальників не знайдено</h3>
                        <p>Спробуйте змінити параметри фільтрів</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($suppliers as $supplier): ?>
                    <div class="supplier-card <?= ($filterSupplierId == $supplier['supplier_id']) ? 'highlighted' : '' ?>" 
                         data-id="<?= $supplier['supplier_id'] ?>" 
                         data-rating="<?= $supplier['rating'] ?>"
                         id="supplier-<?= $supplier['supplier_id'] ?>">
                        <?php if ($isAdmin): ?>
                            <div class="admin-controls">
                                <button class="edit-btn" 
                                    data-id="<?= $supplier['supplier_id'] ?>" 
                                    data-name="<?= htmlspecialchars(string: $supplier['name']) ?>" 
                                    data-contact="<?= htmlspecialchars(string: $supplier['contact_person']) ?>" 
                                    data-email="<?= htmlspecialchars(string: $supplier['email']) ?>" 
                                    data-phone="<?= htmlspecialchars(string: $supplier['phone']) ?>" 
                                    data-address="<?= htmlspecialchars(string: $supplier['address']) ?>" 
                                    data-rating="<?= $supplier['rating'] ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="delete-btn" 
                                    data-id="<?= $supplier['supplier_id'] ?>" 
                                    data-name="<?= htmlspecialchars($supplier['name']) ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="supplier-header">
                            <div>
                                <h3 class="supplier-name"><?= htmlspecialchars(string: $supplier['name']) ?></h3>
                                <div class="supplier-rating">
                                    <div class="rating-stars">
                                        <?php 
                                        $fullStars = floor($supplier['rating']);
                                        $halfStar = ($supplier['rating'] - $fullStars) >= 0.5;
                                        
                                        for ($i = 1; $i <= 5; $i++): 
                                            if ($i <= $fullStars): ?>
                                                <span class="rating-star active">
                                                    <i class="fas fa-star"></i>
                                                </span>
                                            <?php elseif ($i == $fullStars + 1 && $halfStar): ?>
                                                <span class="rating-star active">
                                                    <i class="fas fa-star-half-alt"></i>
                                                </span>
                                            <?php else: ?>
                                                <span class="rating-star">
                                                    <i class="far fa-star"></i>
                                                </span>
                                            <?php endif;
                                        endfor; ?>
                                    </div>
                                    <span class="rating-value"><?= number_format(num: $supplier['rating'], decimals: 1) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="supplier-info">
                            <div class="info-item">
                                <span class="info-label">Контактна особа:</span>
                                <span class="info-value"><?= htmlspecialchars(string: $supplier['contact_person']) ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Email:</span>
                                <span class="info-value"><?= htmlspecialchars(string: $supplier['email']) ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Телефон:</span>
                                <span class="info-value"><?= htmlspecialchars(string: $supplier['phone']) ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Адреса:</span>
                                <span class="info-value"><?= htmlspecialchars(string: $supplier['address']) ?></span>
                            </div>
                        </div>
                        <a href="tel:<?= htmlspecialchars(string: $supplier['phone']) ?>" class="contact-button">
                            <i class="fas fa-phone-alt"></i> Зателефонувати
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Модальне вікно для додавання/редагування -->
    <div class="modal" id="supplier-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modal-title">Додати постачальника</h3>
                <button class="close-btn" id="close-modal">&times;</button>
            </div>
            <form id="supplier-form" class="supplier-form" method="post">
                <input type="hidden" name="supplier_id" id="supplier_id" value="0">
                
                <div class="modal-input-group">
                    <label for="name">Назва компанії:</label>
                    <input type="text" id="name" name="name" required>
                </div>
                
                <div class="modal-input-group">
                    <label for="contact">Контактна особа:</label>
                    <input type="text" id="contact" name="contact" required>
                </div>
                
                <div class="modal-input-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="modal-input-group">
                    <label for="phone">Телефон:</label>
                    <input type="tel" id="phone" name="phone" required>
                </div>
                
                <div class="modal-input-group">
                    <label for="address">Адреса:</label>
                    <textarea id="address" name="address" rows="3" required></textarea>
                </div>
                
                <div class="modal-input-group">
                    <label for="rating">Рейтинг (0-5):</label>
                    <input type="number" id="rating" name="rating" min="0" max="5" step="0.1" value="0" required>
                </div>
                
                <div class="modal-buttons">
                    <button type="button" class="cancel-btn" id="cancel-edit">Скасувати</button>
                    <button type="submit" class="save-btn">Зберегти</button>
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
                <p>Ви дійсно бажаєте видалити постачальника <span id="delete-name"></span>?</p>
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
            rating: '0',
            search: ''
        };
        
        // Налаштування обробників подій
        function setupEventListeners() {
            // Обробники фільтрів рейтингу
            document.querySelectorAll('#rating-filter .category-item').forEach(item => {
                item.addEventListener('click', function() {
                    document.querySelectorAll('#rating-filter .category-item').forEach(i => {
                        i.classList.remove('active');
                    });
                    this.classList.add('active');
                    activeFilters.rating = this.dataset.filterValue;
                    filterSuppliers();
                });
            });
            
            // Пошук постачальників
            document.getElementById('supplier-search').addEventListener('input', function() {
                activeFilters.search = this.value.toLowerCase();
                filterSuppliers();
            });
            
            // Кнопка "Додати постачальника"
            if (document.getElementById('open-add-modal')) {
                document.getElementById('open-add-modal').addEventListener('click', function() {
                    document.getElementById('modal-title').textContent = 'Додати постачальника';
                    document.getElementById('supplier_id').value = '0';
                    document.getElementById('supplier-form').reset();
                    document.getElementById('supplier-modal').style.display = 'flex';
                });
            }
            
            // Кнопки редагування
            document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.getElementById('modal-title').textContent = 'Редагувати постачальника';
                    document.getElementById('supplier_id').value = this.dataset.id;
                    document.getElementById('name').value = this.dataset.name;
                    document.getElementById('contact').value = this.dataset.contact;
                    document.getElementById('email').value = this.dataset.email;
                    document.getElementById('phone').value = this.dataset.phone;
                    document.getElementById('address').value = this.dataset.address;
                    document.getElementById('rating').value = this.dataset.rating;
                    document.getElementById('supplier-modal').style.display = 'flex';
                });
            });
            
            // Кнопки видалення
            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const supplierId = this.dataset.id;
                    document.getElementById('delete-name').textContent = this.dataset.name;
                    
                    // Використовуємо окремий файл для видалення
                    document.getElementById('confirm-delete').href = 
                        `delete-supplier.php?delete=${supplierId}`;
                    document.getElementById('delete-modal').style.display = 'flex';
                });
            });
            
            // Закриття модальних вікон
            document.getElementById('close-modal').addEventListener('click', function() {
                document.getElementById('supplier-modal').style.display = 'none';
            });
            
            document.getElementById('cancel-edit').addEventListener('click', function() {
                document.getElementById('supplier-modal').style.display = 'none';
            });
            
            document.getElementById('close-delete-modal').addEventListener('click', function() {
                document.getElementById('delete-modal').style.display = 'none';
            });
            
            document.getElementById('cancel-delete').addEventListener('click', function() {
                document.getElementById('delete-modal').style.display = 'none';
            });
            
            // Закриття модальних вікон при кліку на фон
            window.addEventListener('click', function(e) {
                if (e.target === document.getElementById('supplier-modal')) {
                    document.getElementById('supplier-modal').style.display = 'none';
                }
                if (e.target === document.getElementById('delete-modal')) {
                    document.getElementById('delete-modal').style.display = 'none';
                }
            });
        }
        
        // Фільтрація постачальників
        function filterSuppliers() {
            const searchTerm = activeFilters.search.toLowerCase();
            const minRating = parseFloat(activeFilters.rating);
            
            document.querySelectorAll('.supplier-card').forEach(card => {
                const name = card.querySelector('.supplier-name').textContent.toLowerCase();
                const contact = card.querySelector('.info-item:nth-child(1) .info-value').textContent.toLowerCase();
                const address = card.querySelector('.info-item:nth-child(4) .info-value').textContent.toLowerCase();
                const rating = parseFloat(card.dataset.rating);
                
                let visible = true;
                
                // Фільтр пошуку
                if (searchTerm && 
                    !name.includes(searchTerm) && 
                    !contact.includes(searchTerm) &&
                    !address.includes(searchTerm)) {
                    visible = false;
                }
                
                // Фільтр рейтингу
                if (minRating > 0 && rating < minRating) {
                    visible = false;
                }
                
                card.style.display = visible ? 'block' : 'none';
            });
            
            // Перевірка наявності результатів
            const visibleCards = document.querySelectorAll('.supplier-card[style="display: block;"]').length;
            const noResults = document.querySelector('.no-results');
            
            if (visibleCards === 0 && !noResults) {
                document.getElementById('suppliers-list').innerHTML = `
                    <div class="no-results">
                        <i class="fas fa-search"></i>
                        <h3>Постачальників не знайдено</h3>
                        <p>Спробуйте змінити параметри фільтрів</p>
                    </div>
                `;
            } else if (visibleCards > 0 && noResults) {
                noResults.remove();
            }
        }
        
        // Прокрутка до обраного постачальника
        function scrollToHighlightedSupplier() {
            const highlightedCard = document.querySelector('.supplier-card.highlighted');
            if (highlightedCard) {
                highlightedCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // Зняти виділення через 5 секунд
                setTimeout(() => {
                    highlightedCard.classList.remove('highlighted');
                }, 5000);
            }
        }
        
        // Ініціалізація сторінки
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            filterSuppliers();
            scrollToHighlightedSupplier();
        });
    </script>
</body>
</html>