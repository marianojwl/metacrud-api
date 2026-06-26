<?php

$columns = getColumns($pdo, $tablename);

$input = json_decode(file_get_contents('php://input'), true);

if(isset($input['ids']) && is_array($input['ids']) && count($input['ids']) > 0 && isset($input['field'])) {

  $field = $input['field'];
  $value = array_key_exists('value', $input) ? $input['value'] : null;

  $column = null;
  foreach($columns as $col){
    if($col['Field'] === $field){
      $column = $col;
      break;
    }
  }

  if(!$column){
    echo json_encode(['success'=>false, 'error' => 'Field not found']);
    http_response_code(400);
    exit;
  }

  if(!validateInputField($column, [$field => $value])){
    exit;
  }

  $primaryKeyName = getPrimaryKeyName($columns);

  $placeholders = [];
  foreach($input['ids'] as $i => $id){
    $placeholders[] = ':id' . $i;
  }

  $sql = "UPDATE $tablename SET $field = :value WHERE $primaryKeyName IN (" . implode(',', $placeholders) . ")";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':value', $value);

  foreach($input['ids'] as $i => $id){
    $stmt->bindValue(':id' . $i, $id);
  }

  $stmt->execute();

  echo json_encode(['success'=>true, 'message'=>'Registros actualizados']);
  exit;
}

$sql = "UPDATE $tablename SET ";

foreach($columns as $column){

  if(!array_key_exists($column['Field'], $input)) {
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
  //if(!isset($input[$column['Field']])) {
  if(!array_key_exists($column['Field'], $input)) {
    continue; // skip this column
  }
  $stmt->bindValue(':' . $column['Field'], $input[$column['Field']===null ? 'NULL' : $column['Field']]);
}

$stmt->execute();

echo json_encode(['success'=>true, 'message'=>'Registro actualizado']);
