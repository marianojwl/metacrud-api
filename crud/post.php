<?php

$columns = getColumns($pdo, $tablename);

$input = json_decode(file_get_contents('php://input'), true);

$sql_p1 = "INSERT INTO $tablename (";
$sql_p2 = " VALUES (";

foreach($columns as $column){
  
  if(!validateInputField($column, $input)){
    continue; // skip this column (id)
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

  $stmt->bindValue(':' . $column['Field'], $input[$column['Field']]);
}

$stmt->execute();

echo json_encode(['success'=>true, 'message'=>'Record inserted']);
