<?php
$columns = getColumns($pdo, $tablename);

$input = json_decode(file_get_contents('php://input'), true);

$columnsWithBathCreationAllowed = array_filter($columns, function($column){
  return $column['Comment']['metacrud']['allowBatchCreate'] ?? false;
});

$fieldsToInsert= array_filter($columns, function($column) use ($input){
  //return (isset($input[$column['Field']]) && !($column['Key'] == 'PRI' && $column['Extra'] == 'auto_increment'));
  return (array_key_exists($column['Field'], $input) && !($column['Key'] == 'PRI' && $column['Extra'] == 'auto_increment'));
});

// check if any contains arrays
$arrayInputColumns = array_filter($fieldsToInsert, function($column) use ($input){
  return is_array($input[$column['Field']]);
});

// check if batchCreateIsAllowed
foreach($arrayInputColumns as $column){
  if(!($column['Comment']['metacrud']['allowBatchCreate'] ?? false)){
    echo json_encode(['success'=>false, 'error' => 'Batch Create Not Allowed']);
    http_response_code(400);
    exit;
  }
}

$sql_p1 = "INSERT INTO ".$tablename." (".implode(',', array_map(function($column){return $column['Field'];}, $fieldsToInsert)).") VALUES ";

// turn all to array
foreach($fieldsToInsert as $column){
  if(!is_array($input[$column['Field']])){
    $input[$column['Field']] = [$input[$column['Field']]];
  }
}

// Extract only the necessary fields from $input
$inputForCombinations = [];
foreach ($fieldsToInsert as $column) {
  // validate all values in $input[$column['Field'] array
  foreach ($input[$column['Field']] as $value) { // ****
    // CHECK IF REQUIRED
    if(( $column['Null'] == 'NO' && empty($column['Default']) ) && empty($value)){
      echo json_encode(['error' => ($column['Comment']['metacrud']['label']??$column['Field']) . ' requerido. Valor vacío encontrado.']);
      http_response_code(400);
      exit;
    }
    // REGEX VALIDATION IF SET
    if(isset($column['Comment']['metacrud']['regex_pattern'])){
      $pattern = '/' . $column['Comment']['metacrud']['regex_pattern'] . '/';
      if(!preg_match($pattern, $value)) {
        echo json_encode(['success'=>false, 'error' => ($column['Comment']['metacrud']['label']??$column['Field']) . ' inválido: ' . $value]);
        http_response_code(400);
        exit;
      }
    }
  } // ***
  $inputForCombinations[$column['Field']] = $input[$column['Field']];
}

// 
$insertSets = generateCombinations($inputForCombinations);

$sql_p2 = "";

$setCount = count($insertSets);
for($i=0; $i<$setCount; $i++){
  $sql_p2 .= "(";
  foreach($insertSets[$i] as $key => $value){
    $sql_p2 .= ":" . $key . "_" . $i . ", ";
  }
  $sql_p2 = rtrim($sql_p2, ', ');
  $sql_p2 .= "), ";
}

$sql_p2 = rtrim($sql_p2, ', ');

$sql = $sql_p1 . $sql_p2;

$stmt = $pdo->prepare($sql);

for($i=0; $i<$setCount; $i++){
  foreach($insertSets[$i] as $key => $value){
    $stmt->bindValue(':' . $key . '_' . $i, $value);
  }
}

$stmt->execute();

$recordsInserted = $stmt->rowCount();

echo json_encode(['success'=>true, 'message'=>$recordsInserted . ' registros creados.']);







/*
$columns = getColumns($pdo, $tablename);

$input = json_decode(file_get_contents('php://input'), true);

$columnsWithBathCreationAllowed = array_filter($columns, function($column){
  return $column['Comment']['metacrud']['allowBatchCreate'] ?? false;
});

$sql_p1 = "INSERT INTO $tablename (";
$sql_p2 = " VALUES (";

foreach($columns as $column){
  
  if(!validateInputField($column, $input)){
    continue; // skip this column (id)
  }

  if(!isset($input[$column['Field']])){
    continue;
  }

  $sql_p1 .= $column['Field'] . ',';
  $sql_p2 .= ':' . $column['Field'] . ',';

}

$sql_p1 = rtrim($sql_p1, ',');
$sql_p2 = rtrim($sql_p2, ',');

$sql_p1 .= ')';
$sql_p2 .= ')';

$sql = $sql_p1 . $sql_p2;

$stmt = $pdo->prepare($sql);

foreach($columns as $column){

  // CONTINUE ON PRIMARY KEY AUTO_INCREMENT
  if($column['Key'] == 'PRI' && $column['Extra'] == 'auto_increment'){
    continue;
  }

  if(!isset($input[$column['Field']])){
    continue;
  }
  $stmt->bindValue(':' . $column['Field'], $input[$column['Field']]);
}

$stmt->execute();

echo json_encode(['success'=>true, 'message'=>'Record inserted']);
*/