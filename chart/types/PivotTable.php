<?php
$settings = $chart['settings'] ?? null;
if($settings == null) {
    echo json_encode(['success'=>false, 'error' => 'Chart settings not defined']);
    http_response_code(400);
    exit;
}

$columnsField = $settings['columnsField'] ?? null;
$rowsFields = $settings['rowsFields'] ?? null;
$valuesField = $settings['valuesField'] ?? null;

$qCol = "SELECT DISTINCT ".$columnsField["s"]." FROM $tablename WHERE 1=1 AND ";
// IF FILTERS ARE REQUESTED
foreach($filters??[] as $field => $values){
  if(!is_array($values)) {
    $values = [$values];
  }
  $qCol.= " $tablename.$field IN (";
  foreach($values as $value){
    $qCol.= "'" . $conn->real_escape_string($value) . "', ";
  }
  $qCol = rtrim($qCol, ', ');
  $qCol.= ") AND " . PHP_EOL;
}
$qCol = rtrim($qCol, " AND " . PHP_EOL);
$qCol .=" ORDER BY ".$columnsField["s"];

try {
  $rCol= $conn->query($qCol);
} catch (Exception $e) {
  throw new Exception($e->getMessage() . " - " . $qCol);
}
// fethc all values
$cols = $rCol->fetch_all(MYSQLI_ASSOC);

if(count($cols) == 0) {
    $response['success'] = true;
    $response['data']['rows'] = []; 
    echo json_encode($response);
    exit;
}
// get all values from $columnsField
$cols = array_map(function($col) use ($columnsField) {
  return $col[$columnsField["s"]];
}, $cols);


// Generate dynamic SQL columns
$colstatements = array_map(fn($col) => "SUM(CASE WHEN ".$columnsField["s"]." = '" . addslashes($col) . "' THEN ".$valuesField['s']." ELSE 0 END) AS `" . addslashes($col) . "`", $cols);
$rowStatements = array_map(fn($rowField) => $rowField['s'], $rowsFields);
$sql = "SELECT ".implode(", ", $rowStatements).", " . implode(", ", $colstatements) . " 
FROM $tablename WHERE 1=1 AND ";

// IF FILTERS ARE REQUESTED
foreach($filters??[] as $field => $values){
  if(!is_array($values)) {
    $values = [$values];
  }
  $sql.= " $tablename.$field IN (";
  foreach($values as $value){
    $sql.= "'" . $conn->real_escape_string($value) . "', ";
  }
  $sql = rtrim($sql, ', ');
  $sql.= ") AND " . PHP_EOL;
}
$sql = rtrim($sql, " AND " . PHP_EOL);

// Filter rows with 'sortOrder'
$filteredRows = array_filter($rowsFields, function($rowField) {
  return isset($rowField['sortOrder']);
});

// Sort by 'sortOrder'
usort($filteredRows, function($a, $b) {
  return $a['sortOrder'] <=> $b['sortOrder'];
});

// Extract column names (change 'columnName' to the actual key)
$sortedColumnNames = array_map(function($rowField) {
  return $rowField['s']; // Change to actual key in your array
}, $filteredRows);

$sql .= " GROUP BY " . implode(", ", $rowStatements) . 
      (count($sortedColumnNames) ? " ORDER BY " . implode(", ", $sortedColumnNames) . " ASC" : "");



//echo $sql;

$r = $conn->query($sql);
if ($r === false) {
  throw new Exception($conn->error . " - " . $sql);
}

$records = $r->fetch_all(MYSQLI_ASSOC);
$response['data']['headers'] = $cols;
$response['data']['rows'] = $records; 
$response['data']['sql'] = $sql; 