<?php

$columns = getColumns($pdo, $tablename);

$input = json_decode(file_get_contents('php://input'), true);

$sql = "UPDATE $tablename SET ";

foreach($columns as $column){

  if(!isset($input[$column['Field']])) {
    continue; // skip this column
  }
  
  if(!validateInputField($column, $input)){
    continue; // skip this column (id)
  }


  $sql .= $column['Field'] . ' = :' . $column['Field'] . ',';
}

$sql = rtrim($sql, ',');

$primaryKeyName = getPrimaryKeyName($columns);

$sql .= " WHERE $primaryKeyName = :$primaryKeyName";

$stmt = $pdo->prepare($sql);

foreach($columns as $column){
  if(!isset($input[$column['Field']])) {
    continue; // skip this column
  }
  $stmt->bindValue(':' . $column['Field'], $input[$column['Field']]);
}

$stmt->execute();

echo json_encode(['success'=>true, 'message'=>'Record updated']);
