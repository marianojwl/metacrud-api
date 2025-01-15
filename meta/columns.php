<?php

$stmt = $pdo->prepare("SHOW FULL COLUMNS FROM $tablename");
$stmt->execute();
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// array map to json enconde every column's Comment
$columns = array_map(function($column) {
    $column['Comment'] = json_decode($column['Comment']);
    return $column;
}, $columns);

echo json_encode(["data"=>$columns]);

