<?php
$columns = getColumns($pdo, $tablename);

$input = json_decode(file_get_contents('php://input'), true);

// alter table, set comment
foreach($columns as $column){
  if($input['Field'] == $column['Field']){
    $comment = "";
    try {
      $comment = json_decode($input['Comment'],true);
    } catch (Exception $e) {
      echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
      exit;
    }
    $sql = "ALTER TABLE $tablename CHANGE COLUMN {$column['Field']} {$column['Field']} {$column['Type']} " . ($column['Null'] == 'NO' ? 'NOT NULL' : 'NULL') . " " . ($column['Extra'] == 'auto_increment' ? 'AUTO_INCREMENT' : '') . " " . ($column['Default'] ? "DEFAULT '{$column['Default']}'" : '') . " COMMENT '".json_encode($comment)."'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
  }

}

echo json_encode(['success'=>true]);
exit;