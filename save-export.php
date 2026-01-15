<?php
session_start();
require_once 'db_connection.php'; // Підключення до БД

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(json: file_get_contents(filename: 'php://input'), associative: true);
    
    try {
        // Генерація estimate_id
        $maxEstStmt = $conn->prepare(query: "SELECT MAX(estimate_id) AS max_id FROM dbo.Estimates");
        $maxEstStmt->execute();
        $maxEstRow = $maxEstStmt->fetch(mode: PDO::FETCH_ASSOC);
        $newEstId = $maxEstRow && $maxEstRow['max_id'] !== null ? ((int)$maxEstRow['max_id'] + 1) : 1;

        // Збереження інформації про експорт
        $insertEstSql = "INSERT INTO dbo.Estimates (
                            estimate_id, name, total_cost, 
                            status, export_format, created_at
                         ) VALUES (
                            :estimate_id, :name, :total_cost, 
                            'exported', :export_format, GETDATE()
                         )";
        
        $insertEstStmt = $conn->prepare(query: $insertEstSql);
        $insertEstStmt->bindParam(param: ':estimate_id', var: $newEstId, type: PDO::PARAM_INT);
        $insertEstStmt->bindParam(param: ':name', var: $data['estimate_name']);
        $insertEstStmt->bindParam(param: ':total_cost', var: $data['total_cost']);
        $insertEstStmt->bindParam(param: ':export_format', var: $data['export_format']);
        $insertEstStmt->execute();

        echo json_encode(value: ['success' => true]);
    } catch (Exception $e) {
        echo json_encode(value: ['error' => $e->getMessage()]);
    }
}