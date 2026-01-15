<?php
session_start();

// Перевірка автентифікації
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: login.php');
    exit;
}

// Обробка повідомлень про видалення
$deleteSuccess = isset($_SESSION['delete_success']) ? $_SESSION['delete_success'] : false;
$deleteError = isset($_SESSION['delete_error']) ? $_SESSION['delete_error'] : null;
$deleteEstimateSuccess = isset($_SESSION['delete_estimate_success']) ? $_SESSION['delete_estimate_success'] : false;
$deleteEstimateError = isset($_SESSION['delete_estimate_error']) ? $_SESSION['delete_estimate_error'] : null;

unset($_SESSION['delete_success']);
unset($_SESSION['delete_error']);
unset($_SESSION['delete_estimate_success']);
unset($_SESSION['delete_estimate_error']);

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
    
    // Отримання проектів користувача
    $stmt = $conn->prepare(query: "
        SELECT project_id, name, status, start_date, end_date, square, floors, foundation, floor, walls, roof 
        FROM dbo.Projects 
        WHERE user_id = :user_id
        ORDER BY created_at DESC
    ");
    $stmt->bindParam(param: ':user_id', var: $_SESSION['user_id'], type: PDO::PARAM_INT);
    $stmt->execute();
    $projects = $stmt->fetchAll(mode: PDO::FETCH_ASSOC);
    
    // Отримання останніх розрахунків з таблиці Estimates
    $stmt = $conn->prepare(query: "
        SELECT TOP 5 
            e.estimate_id,
            e.name AS activity, 
            FORMAT(e.created_at, 'dd.MM.yyyy') AS date,
            e.status,
            e.total_cost,
            p.name AS project_name
        FROM dbo.Estimates e
        INNER JOIN dbo.Calculations c ON e.calc_id = c.calc_id
        INNER JOIN dbo.Projects p ON c.project_id = p.project_id
        WHERE p.user_id = :user_id
        ORDER BY e.created_at DESC
    ");
    $stmt->bindParam(param: ':user_id', var: $_SESSION['user_id'], type: PDO::PARAM_INT);
    $stmt->execute();
    $estimates = $stmt->fetchAll(mode: PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Помилка бази даних: " . $e->getMessage());
}

// ОНОВЛЕНА функція для форматування дати
function formatDate($dateString): string {
    // Перевірка на NULL, пусту дату або некоректні значення
    if (!$dateString || $dateString === '1970-01-01' || $dateString === '1900-01-01' || $dateString === '0000-00-00') {
        return 'Не встановлено';
    }
    return date(format: 'd.m.Y', timestamp: strtotime(datetime: $dateString));
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд | Будівельний калькулятор</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #fffaee;
            margin: 0;
            font-family: Arial, sans-serif;
            line-height: 1.6;
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

        .logout-btn {
            background: #eb7609;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .logout-btn:hover {
            background: #d2690e;
        }

        .profile {
            background: #eb7609;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .profile:hover {
            background: #d2690e;
        }

        /* Основний контент */
        .dashboard-main {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Секції */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .section-title {
            color: #2c3e50;
            margin: 0;
            font-size: 1.5rem;
        }

        /* Кнопка "Новий" */
        .new-project-btn {
            background: #eb7609;
            color: white;
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
            font-size: 1rem;
            white-space: nowrap;
            position: relative;
            z-index: 100;
        }

        .new-project-btn:hover {
            background: #d2690e;
        }

        /* Картки проектів */
        .projects-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .project-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
            cursor: pointer;
            position: relative;
        }

        .project-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .project-title {
            color: #2c3e50;
            margin: 0 0 0.5rem 0;
            font-size: 1.2rem;
        }

        .project-status {
            color: #7f8c8d;
            margin: 0;
        }

        /* Таблиця розрахунків */
        .calculations-section {
            margin-top: 3rem;
        }

        .calculations-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-top: 1rem;
        }

        .calculations-table th,
        .calculations-table td {
            padding: 1.2rem 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .calculations-table th {
            background: #f8f9fa;
            color: #2c3e50;
            font-weight: 600;
        }

        /* Статуси у таблиці */
        .status-completed {
            color:rgb(40, 80, 167);
            font-weight: bold;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            background: rgba(40, 167, 69, 0.1);
        }

        .status-in-progress {
            color: #ffc107;
            font-weight: bold;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            background: rgba(255, 193, 7, 0.1);
        }

        /* ДОДАНО СТИЛЬ ДЛЯ ACTIVE */
        .status-active { 
            color: #2ecc71;
            font-weight: bold;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            background: rgba(46, 204, 113, 0.1);
        }

        .status-exported { 
            color: purple;
            font-weight: bold;
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

            .new-project-btn {
                width: 100%;
            }

            .projects-list,
            .calculations-table {
                grid-template-columns: 1fr;
            }

            .calculations-table {
                overflow-x: auto;
                display: block;
            }
        }

        /* Додаткові стилі */
        .delete-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
            transition: background 0.3s;
        }
        
        .delete-btn:hover {
            background: rgba(255, 0, 0, 0.2);
        }
        
        .delete-btn i {
            color: #e74c3c;
            font-size: 16px;
        }
        
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
        
        .cost-value {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            padding: 5px;
            color: #e74c3c;
            transition: color 0.3s;
        }
        
        .action-btn:hover {
            color: #c0392b;
        }
        
        .action-cell {
            text-align: center;
        }
        
        .new-project-container {
            position: relative;
            z-index: 100;
        }
    </style>
</head>
<body>
    <!-- Навігаційне меню -->
    <header class="dashboard-header">
        <nav class="nav-menu">
            <ul class="nav-list">
                <li><a href="#" class="nav-link active">Проєкти</a></li>
                <li><a href="materials.php" class="nav-link">Матеріали</a></li>
                <li><a href="calculations.php" class="nav-link">Розрахунки</a></li>
                <li><a href="suppliers.php" class="nav-link">Постачальники</a></li>
            </ul>
        </nav>
        <div class="user-panel">
            <button type="button" class="profile" onclick="window.location.href='profile.php'">Мій профіль</button>
            <span class="username">Привіт, <?= htmlspecialchars($_SESSION['username']) ?>!</span>
            <button type="button" class="logout-btn" onclick="window.location.href='logout.php'">Вихід</button>
        </div>
    </header>

    <!-- Основний вміст -->
    <main class="dashboard-main">
        <?php if ($deleteSuccess): ?>
            <div class="success-message">Проєкт успішно видалено!</div>
        <?php endif; ?>
        
        <?php if ($deleteError): ?>
            <div class="error-message"><?= htmlspecialchars($deleteError) ?></div>
        <?php endif; ?>
        
        <?php if ($deleteEstimateSuccess): ?>
            <div class="success-message">Кошторис успішно видалено!</div>
        <?php endif; ?>
        
        <?php if ($deleteEstimateError): ?>
            <div class="error-message"><?= htmlspecialchars($deleteEstimateError) ?></div>
        <?php endif; ?>
        
        <!-- Секція "Мої проекти" -->
        <section class="projects-section">
            <div class="section-header">
                <h2 class="section-title">Мої проєкти</h2>
                <div class="new-project-container">
                    <button type="button" class="new-project-btn" id="create-project-btn">+ Новий</button>
                </div>
            </div>
            <div class="projects-list">
                <?php if (empty($projects)): ?>
                    <p>У вас ще немає проєктів. Створіть новий проєкт!</p>
                <?php else: ?>
                    <?php foreach ($projects as $project): ?>
                        <div class="project-card" onclick="viewProject(<?= $project['project_id'] ?>)">
                            <div class="delete-btn" onclick="deleteProject(event, <?= $project['project_id'] ?>)">
                                <i class="fas fa-trash"></i>
                            </div>
                            <h3 class="project-title"><?= htmlspecialchars($project['name']) ?></h3>
                            <p class="project-status">Статус: 
                                <span class="<?= 
                                    $project['status'] === 'Completed' ? 'status-completed' : 
                                    ($project['status'] === 'Pending' ? 'status-in-progress' : 
                                    ($project['status'] === 'Active' ? 'status-active' : '')) 
                                ?>">
                                    <?= htmlspecialchars($project['status']) ?>
                                </span>
                            </p>
                            <p>Площа: <?= $project['square'] ?> м² | Поверхи: <?= $project['floors'] ?></p>
                            <p>Початок: <?= formatDate($project['start_date']) ?> | 
                               Завершення: <?= formatDate($project['end_date']) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Секція "Останні розрахунки" -->
        <section class="calculations-section">
            <div class="section-header">
                <h2 class="section-title">Останні кошториси</h2>
            </div>
            <table class="calculations-table">
                <thead>
                    <tr>
                        <th>Об'єкт</th>
                        <th>Назва кошторису</th>
                        <th>Дата створення</th>
                        <th>Вартість</th>
                        <th>Статус</th>
                        <th>Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($estimates)): ?>
                        <tr>
                            <td colspan="6">У вас ще немає кошторисів</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($estimates as $estimate): ?>
                            <tr>
                                <td><?= htmlspecialchars($estimate['project_name']) ?></td>
                                <td><?= htmlspecialchars($estimate['activity']) ?></td>
                                <td><?= htmlspecialchars($estimate['date']) ?></td>
                                <td class="cost-value"><?= number_format($estimate['total_cost'], 2, '.', ' ') ?> грн</td>
                                <td>
                                    <?php 
                                    $statusClass = '';
                                    if ($estimate['status'] === 'active') $statusClass = 'status-active';
                                    elseif ($estimate['status'] === 'exported') $statusClass = 'status-exported';
                                    elseif ($estimate['status'] === 'completed') $statusClass = 'status-completed';
                                    ?>
                                    <span class="<?= $statusClass ?>">
                                        <?= 
                                        $estimate['status'] === 'active' ? 'Активний' : 
                                        ($estimate['status'] === 'exported' ? 'Експортований' : 
                                        ($estimate['status'] === 'completed' ? 'Завершений' : $estimate['status'])) 
                                        ?>
                                    </span>
                                </td>
                                <td class="action-cell">
                                    <button type="button" class="action-btn" onclick="deleteEstimate(event, <?= $estimate['estimate_id'] ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var createBtn = document.getElementById('create-project-btn');
            if (createBtn) {
                createBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    window.location.href = 'new-project.php';
                });
            } else {
                console.error('Кнопка "create-project-btn" не знайдена!');
            }
        });

        function viewProject(projectId) {
            window.location.href = `projects-details.php?id=${projectId}`;
        }
        
        function deleteProject(event, projectId) {
            event.stopPropagation();
            
            if (confirm('Ви впевнені, що хочете видалити цей проєкт? Ця дія незворотна.')) {
                window.location.href = `delete-project.php?id=${projectId}`;
            }
        }
        
        function deleteEstimate(event, estimateId) {
            event.stopPropagation();
            
            if (confirm('Ви впевнені, що хочете видалити цей кошторис? Ця дія незворотна.')) {
                window.location.href = `delete-estimate.php?id=${estimateId}`;
            }
        }
    </script>
</body>
</html>