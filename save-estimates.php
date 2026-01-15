<?php
session_start();
header(header: 'Content-Type: application/json');

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    echo json_encode(value: ['success' => false, 'message' => 'Необхідна авторизація']);
    exit;
}

$data = json_decode(json: file_get_contents(filename: 'php://input'), associative: true);

$serverName = "WIN-C0REURL4NB2\\MSSQLSERVER01";
$database = "form_data";

try {
    $conn = new PDO(
        dsn: "sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=1;Encrypt=1",
        username: null,
        password: null
    );
    $conn->setAttribute(attribute: PDO::ATTR_ERRMODE, value: PDO::ERRMODE_EXCEPTION);
    $conn->beginTransaction();

    // 1. Створюємо запис в Calculations
    $stmtCalc = $conn->prepare(query: "
        INSERT INTO dbo.Calculations 
        (project_id, date, status, total_quantity, total_cost, notes)
        VALUES 
        (:project_id, :date, :status, :total_quantity, :total_cost, :notes)
    ");
    
    $project_id = null; // Можна додати пізніше
    $date = date(format: 'Y-m-d');
    $status = 'draft';
    $total_quantity = array_sum(array: [
        $data['roof_area'], 
        $data['walls_area'], 
        $data['floor_area']
    ]);
    $total_cost = $data['total'];
    $notes = $data['name'] . " | " . date(format: 'd.m.Y');
    
    $stmtCalc->bindParam(param: ':project_id', var: $project_id);
    $stmtCalc->bindParam(param: ':date', var: $date);
    $stmtCalc->bindParam(param: ':status', var: $status);
    $stmtCalc->bindParam(param: ':total_quantity', var: $total_quantity);
    $stmtCalc->bindParam(param: ':total_cost', var: $total_cost);
    $stmtCalc->bindParam(param: ':notes', var: $notes);
    
    $stmtCalc->execute();
    $calc_id = $conn->lastInsertId();

    // 2. Створюємо записи в Estimates для кожного типу матеріалів
    $materials = [
        ['type' => 'roof', 'price' => $data['roof_price'], 'area' => $data['roof_area']],
        ['type' => 'walls', 'price' => $data['walls_price'], 'area' => $data['walls_area']],
        ['type' => 'floor', 'price' => $data['floor_price'], 'area' => $data['floor_area']]
    ];
    
    $stmtEst = $conn->prepare(query: "
        INSERT INTO dbo.Estimates 
        (calc_id, name, material_type, unit_price, quantity, total)
        VALUES 
        (:calc_id, :name, :material_type, :unit_price, :quantity, :total)
    ");
    
    foreach ($materials as $material) {
        $total = $material['price'] * $material['area'];
        $name = $data['name'] . " - " . $material['type'];
        
        $stmtEst->bindParam(param: ':calc_id', var: $calc_id);
        $stmtEst->bindParam(param: ':name', var: $name);
        $stmtEst->bindParam(param: ':material_type', var: $material['type']);
        $stmtEst->bindParam(param: ':unit_price', var: $material['price']);
        $stmtEst->bindParam(param: ':quantity', var: $material['area']);
        $stmtEst->bindParam(param: ':total', var: $total);
        
        $stmtEst->execute();
    }

    $conn->commit();
    
    echo json_encode(value: [
        'success' => true, 
        'calc_id' => $calc_id,
        'total_cost' => $total_cost
    ]);

} catch (PDOException $e) {
    $conn->rollBack();
    echo json_encode(value: ['success' => false, 'message' => $e->getMessage()]);
}