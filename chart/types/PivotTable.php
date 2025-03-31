<?php
$settings = $chart['settings'] ?? null;
if($settings == null) {
    echo json_encode(['success'=>false, 'error' => 'Chart settings not defined']);
    http_response_code(400);
    exit;
}

$columnsField = $settings['columnsField'] ?? null;
$rowsField = $settings['rowsField'] ?? null;
$valuesField = $settings['valuesField'] ?? null;

$qCol = "SELECT DISTINCT $columnsField FROM $tablename ORDER BY $columnsField";

try {
  $rCol= $conn->query($qCol);
} catch (Exception $e) {
  throw new Exception($e->getMessage() . " - " . $sql);
}
// fethc all values
$cols = $rCol->fetch_all(MYSQLI_ASSOC);
// get all values from $columnsField
$cols = array_map(function($col) use ($columnsField) {
  return $col[$columnsField];
}, $cols);


// Generate dynamic SQL columns
$colstatements = array_map(fn($col) => "SUM(CASE WHEN descripcion = '" . addslashes($col) . "' THEN ventas_unidades ELSE 0 END) AS `" . addslashes($col) . "`", $cols);
$sql = "SELECT $tablename.$rowsField, " . implode(", ", $colstatements) . " 
FROM $tablename 
GROUP BY $tablename.$rowsField ORDER BY $tablename.$rowsField";

$r = $conn->query($sql);
if ($r === false) {
  throw new Exception($conn->error . " - " . $sql);
}

$records = $r->fetch_all(MYSQLI_ASSOC);
$response['data']['headers'] = $cols;
$response['data']['rows'] = $records; 

