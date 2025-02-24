<?php
$columns = getColumns($pdo, $tablename);

$input = json_decode(file_get_contents('php://input'), true);

$primaryKeyName = getPrimaryKeyName($columns);

$sql = "DELETE FROM $tablename WHERE $primaryKeyName IN (";

$idCount = count($input);
for($i=0; $i<$idCount; $i++){
  $sql .= ":".$primaryKeyName."_".$i.", ";
}
$sql = rtrim($sql, ', ');
$sql .= ")";
$stmt = $pdo->prepare($sql);

for($i=0; $i<$idCount; $i++){
  $stmt->bindValue(':'.$primaryKeyName.'_'.$i, $input[$i][$primaryKeyName]);
}

$stmt->execute();

$recordsDeleted = $stmt->rowCount();

echo json_encode(['success'=>true, 'message'=>$recordsDeleted . ' registros eliminados.']);