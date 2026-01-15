<?php
session_start();

// Перевірка автентифікації
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header(header: 'Location: login.php');
    exit;
}

$serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
$database = "form_data";
$error = '';
$success = '';

try {
    $conn = new PDO(
        dsn: "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
        username: null,
        password: null
    );
    $conn->setAttribute(attribute: PDO::ATTR_ERRMODE, value: PDO::ERRMODE_EXCEPTION);
    
    // Обробка оновлення профілю
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $company = $_POST['company'] ?? '';
        $role = $_POST['role'] ?? '';
        
        // Оновлення даних користувача
        $stmt = $conn->prepare(query: "
            UPDATE dbo.Users 
            SET email = :email, 
                contact_info = :phone, 
                company = :company, 
                role = :role 
            WHERE user_id = :user_id
        ");
        
        $stmt->bindParam(param: ':email', var: $email);
        $stmt->bindParam(param: ':phone', var: $phone);
        $stmt->bindParam(param: ':company', var: $company);
        $stmt->bindParam(param: ':role', var: $role);
        $stmt->bindParam(param: ':user_id', var: $_SESSION['user_id'], type: PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $success = "Профіль успішно оновлено!";
            // Оновлюємо дані в сесії
            $_SESSION['role'] = $role;
        } else {
            $error = "Помилка при оновленні профілю: " . implode(separator: " ", array: $stmt->errorInfo());
        }
    }
    
    // Отримання даних користувача
    $stmt = $conn->prepare("
        SELECT username, email, role, company, contact_info, created_at 
        FROM dbo.Users 
        WHERE user_id = :user_id
    ");
    $stmt->bindParam(param: ':user_id', var: $_SESSION['user_id'], type: PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(mode: PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Помилка бази даних: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мій профіль | Будівельний калькулятор</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: #fffaee;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
        }

        /* Шапка */
        .dashboard-header {
            background: #2c3e50;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        /* Навігація */
        .nav-menu ul.nav-list {
            display: flex;
            gap: 2rem;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .nav-link {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .nav-link.active {
            background: #eb7609;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        /* Панель користувача */
        .user-panel {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .username {
            color: white;
            font-weight: 500;
        }

        .logout-btn, .profile, .save-btn {
            background: #eb7609;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
            font-size: 1rem;
        }

        .logout-btn:hover, .profile:hover, .save-btn:hover {
            background: #d2690e;
        }

        /* Основний контент */
        .dashboard-main {
            padding: 2rem;
            max-width: 1000px;
            margin: 0 auto;
            min-height: calc(100vh - 130px);
        }

        /* Секції */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-title {
            color: #2c3e50;
            margin: 0;
            font-size: 1.8rem;
            font-weight: 700;
        }
        
        /* Повідомлення */
        .success-message {
            background-color: #ddffdd;
            border-left: 4px solid #4CAF50;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
            color: #388e3c;
        }
        
        .error-message {
            background-color: #ffdddd;
            border-left: 4px solid #f44336;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
            color: #d32f2f;
        }

        /* Профіль користувача */
        .profile-container {
            display: flex;
            gap: 2.5rem;
            margin-top: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .profile-container {
                flex-direction: column;
            }
        }
        
        /* Аватар та інформація */
        .profile-info {
            flex: 0 0 300px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            padding: 2rem;
            text-align: center;
            height: fit-content;
        }
        
        .avatar-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 1.5rem;
        }
        
        .user-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #eb7609;
            background: linear-gradient(135deg, #fffaee, #f0e6d2);
        }
        
        .change-avatar {
            position: absolute;
            bottom: 10px;
            right: 10px;
            width: 40px;
            height: 40px;
            background: #2c3e50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            border: 2px solid white;
            transition: all 0.3s;
        }
        
        .change-avatar:hover {
            background: #eb7609;
            transform: scale(1.1);
        }
        
        .user-name {
            font-size: 1.5rem;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .user-role {
            color: #eb7609;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding: 0.4rem 1rem;
            background: rgba(235, 118, 9, 0.1);
            border-radius: 20px;
            display: inline-block;
        }
        
        .user-stats {
            display: flex;
            justify-content: space-around;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        /* Форма редагування */
        .edit-form {
            flex: 1;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            padding: 2.5rem;
        }
        
        .form-title {
            color: #2c3e50;
            margin-bottom: 1.8rem;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        
        .form-title i {
            color: #eb7609;
        }
        
        .form-group {
            margin-bottom: 1.8rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.6rem;
            font-weight: 600;
            color: #555;
            font-size: 0.95rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.9rem 1.2rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #eb7609;
            outline: none;
            box-shadow: 0 0 0 3px rgba(235, 118, 9, 0.2);
        }
        
        .form-row {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.8rem;
        }
        
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
        }
        
        .save-btn {
            flex: 1;
            padding: 0.9rem;
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .cancel-btn {
            flex: 1;
            padding: 0.9rem;
            font-size: 1.1rem;
            background: #f1f2f6;
            color: #2c3e50;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
        }
        
        .cancel-btn:hover {
            background: #e2e3e7;
        }
        
        /* Адаптивність */
        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }

            .nav-menu ul.nav-list {
                flex-direction: column;
                gap: 1rem;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .form-row {
                flex-direction: column;
                gap: 1.8rem;
            }
        }
        
        /* Анімації */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .profile-info, .edit-form {
            animation: fadeIn 0.6s ease-out;
        }
        
        .profile-info {
            animation-delay: 0.1s;
        }
        
        .edit-form {
            animation-delay: 0.2s;
        }
        
        /* Підвал */
        footer {
            background: #2c3e50;
            color: white;
            padding: 1.5rem;
            text-align: center;
            margin-top: 2rem;
        }
        
        .footer-content {
            max-width: 1000px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            flex-wrap: wrap;
        }
        
        .footer-links a {
            color: #ddd;
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .footer-links a:hover {
            color: #eb7609;
        }
        
        .copyright {
            color: #aaa;
            font-size: 0.9rem;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <!-- Навігаційне меню -->
    <header class="dashboard-header">
        <nav class="nav-menu">
            <ul class="nav-list">
                <li><a href="estimates.php" class="nav-link">Проєкти</a></li>
                <li><a href="materials.php" class="nav-link">Матеріали</a></li>
                <li><a href="calculations.php" class="nav-link">Розрахунки</a></li>
                <li><a href="suppliers.php" class="nav-link">Постачальники</a></li>
            </ul>
        </nav>
        <div class="user-panel">
            <button class="profile" onclick="window.location.href='profile.php'">Мій профіль</button>
            <span class="username">Привіт, <?= htmlspecialchars(string: $_SESSION['username']) ?>!</span>
            <button class="logout-btn" onclick="window.location.href='logout.php'">Вихід</button>
        </div>
    </header>

    <!-- Основний вміст -->
    <main class="dashboard-main">
        <div class="section-header">
            <h2 class="section-title">Мій профіль</h2>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars(string: $error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><?= htmlspecialchars(string: $success) ?></div>
        <?php endif; ?>
        
        <div class="profile-container">
            <!-- Ліва колонка: Інформація про користувача -->
            <div class="profile-info">
                <div class="avatar-container">
                    <?php
                    $name = urlencode($_SESSION['username']);
                    $avatarUrl = "https://ui-avatars.com/api/?name=$name&background=eb7609&color=fff&size=150";
                    ?>
                    <img src="<?= $avatarUrl ?>" 
                         alt="Аватар користувача" class="user-avatar">
                    <div class="change-avatar">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>
                
                <h3 class="user-name"><?= htmlspecialchars(string: $_SESSION['username']) ?></h3>
                <div class="user-role"><?= htmlspecialchars(string: $user['role'] ?? 'Користувач') ?></div>
                
                <div class="user-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= date(format: 'd.m.Y', timestamp: strtotime(datetime: $user['created_at'])) ?></div>
                        <div class="stat-label">Дата реєстрації</div>
                    </div>
                </div>
            </div>
            
            <!-- Права колонка: Форма редагування -->
            <form method="post" class="edit-form">
                <input type="hidden" name="save_profile" value="1">
                
                <h3 class="form-title"><i class="fas fa-user-edit"></i> Редагування профілю</h3>
                
                <div class="form-group">
                    <label for="email">Електронна пошта</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           value="<?= htmlspecialchars(string: $user['email'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">Номер телефону</label>
                    <input type="tel" id="phone" name="phone" class="form-control" 
                           value="<?= htmlspecialchars(string: $user['contact_info'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label for="company">Компанія</label>
                    <input type="text" id="company" name="company" class="form-control" 
                           value="<?= htmlspecialchars(string: $user['company'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label for="role">Роль у компанії</label>
                    <select id="role" name="role" class="form-control">
                       <option value="Користувач" <?= ($user['role'] ?? '') === 'user' ? 'selected' : '' ?>>Користувач</option> 
                       <option value="Керівник проектів" <?= ($user['role'] ?? '') === 'Project Manager' ? 'selected' : '' ?>>Керівник проектів</option>
                        <option value="Будівельний менеджер" <?= ($user['role'] ?? '') === 'Construction Manager' ? 'selected' : '' ?>>Будівельний менеджер</option>
                        <option value="Архітектор" <?= ($user['role'] ?? '') === 'Architect' ? 'selected' : '' ?>>Архітектор</option>
                        <option value="Інженер" <?= ($user['role'] ?? '') === 'Engineer' ? 'selected' : '' ?>>Інженер</option>
                        <option value="Координатор" <?= ($user['role'] ?? '') === 'Coordinator' ? 'selected' : '' ?>>Координатор</option>
                        
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="cancel-btn">Скасувати</button>
                    <button type="submit" class="save-btn">Зберегти зміни</button>
                </div>
            </form>
        </div>
    </main>
    
    <!-- Підвал -->
    <footer>
        <div class="footer-content">
            <div class="footer-links">
                <a href="#">Про нас</a>
                <a href="#">Контакти</a>
                <a href="#">Умови використання</a>
                <a href="#">Політика конфіденційності</a>
            </div>
            <div class="copyright">
                &copy; <?= date(format: 'Y') ?> Будівельний калькулятор. Всі права захищені.
            </div>
        </div>
    </footer>

    <script>
        // Функціонал скасування
        document.querySelector('.cancel-btn').addEventListener('click', function() {
            // Оновлюємо сторінку для скидання змін
            window.location.reload();
        });
        
        // Функція для показу повідомлень
        function showNotification(message, isSuccess = true) {
            // Видаляємо попереднє повідомлення, якщо воно є
            const oldNotification = document.querySelector('.notification');
            if (oldNotification) oldNotification.remove();
            
            // Створюємо нове повідомлення
            const notification = document.createElement('div');
            notification.className = 'notification';
            notification.textContent = message;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${isSuccess ? '#4CAF50' : '#f44336'};
                color: white;
                padding: 15px 25px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                z-index: 1000;
                animation: fadeIn 0.3s ease-out;
            `;
            
            document.body.appendChild(notification);
            
            // Видаляємо повідомлення через 3 секунди
            setTimeout(() => {
                notification.style.animation = 'fadeOut 0.5s ease-out forwards';
                setTimeout(() => notification.remove(), 500);
            }, 3000);
        }
        
        // Додаємо анімацію fadeOut
        const style = document.createElement('style');
        style.innerHTML = `
            @keyframes fadeOut {
                from { opacity: 1; transform: translateY(0); }
                to { opacity: 0; transform: translateY(-20px); }
            }
        `;
        document.head.appendChild(style);
        
        // Обробка зміни аватара
        document.querySelector('.change-avatar').addEventListener('click', function() {
            alert('Функціонал зміни аватара буде реалізовано у наступній версії!');
        });
        
        // Автоматично закриваємо повідомлення про успіх через 5 секунд
        <?php if ($success): ?>
            setTimeout(() => {
                const successMsg = document.querySelector('.success-message');
                if (successMsg) successMsg.style.display = 'none';
            }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>