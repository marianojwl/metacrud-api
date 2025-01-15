<?php
$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 10;
$sortField = $_GET['sort'] ?? 1;
$sortOrder = $_GET['order'] ?? 'asc';
$cols = $_GET['cols'] ?? null;
$search = $_GET['search'] ?? null;

// 	{ "metacrud":{ "label":"ID Categoría", "regex_pattern":"^[0-9]+$", "foreign_key":"cm_categorias.categoria_id", "foreign_value":"cm_categorias.categoria" } }

$columns = getColumns($pdo, $tablename);
$primaryKeyName = getPrimaryKeyName($columns);

function getForeignPairs($columns){
  $pairs = [];
  foreach($columns as $column){
    $metacrud = $column['Comment']['metacrud'] ?? [];
    if(isset($metacrud['foreign_key']) && isset($metacrud['foreign_value'])){
      $pairs[$column['Field']] = ['key'=>$metacrud['foreign_key'], 'value'=>$metacrud['foreign_value']];
    }
  }
  return $pairs;
}


$foreignPairs = getForeignPairs($columns);

$sql = "SELECT ";

foreach($columns as $column){
  if($cols){
    if(!in_array($column['Field'], $cols)) continue;
  } 
  $sql.= $tablename.".".$column['Field'] . ", ";
}

$sql = rtrim($sql, ', ');

foreach($foreignPairs as $field => $pair){
  $sql.= ", ".$pair['value']." AS ". str_replace('.', '_', $pair['value']);
}
$sql.= " FROM $tablename "; 

foreach($foreignPairs as $field => $pair){
  $parts = array_reverse(explode('.', $pair['key']));
  $col = $parts[0];
  $tab = $parts[1];
  $db = $parts[2]??null;
  $sql.= " LEFT JOIN ". ($db ? "$db." : "") . "$tab ON $tablename.$field = $tab.$col";
}

if($requested_id){
  
  $sql.= " WHERE $tablename.$primaryKeyName = :id";
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':id', $requested_id);

} else {

  if($search){
    $sql.= " WHERE ";
    foreach($columns as $column){
      $sql.= $tablename.".".$column['Field'] . " LIKE :search OR ";
    }
    foreach($foreignPairs as $field => $pair){
      $sql.= $pair['value']." LIKE :search OR ";
    }
    $sql = rtrim($sql, ' OR ');
  }

  $sql.= " ORDER BY $sortField $sortOrder LIMIT :limit, :offset";
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':limit', ($page - 1) * $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $limit, PDO::PARAM_INT);
  if($search) {
    $stmt->bindValue(':search', "%$search%");
  }
}

$stmt->execute();

$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(["data"=>$records]);