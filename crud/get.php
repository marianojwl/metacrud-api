<?php
$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 10;
$sortField = $_GET['sort'] ?? 1;
$sortOrder = $_GET['order'] ?? 'asc';
$cols = $_GET['cols'] ?? null;
$search = $_GET['search'] ?? null;
$metacrudView = $_GET['metacrudView'] ?? null;

$tableStatus = null;

$view = null;

$tableStatus = getTableStatus($pdo, $tablename); 

if($metacrudView) {
  $view = $tableStatus['Comment']['metacrud']['views'][$metacrudView]??null;
}

// read restrictions
$restrictions = $tableStatus['Comment']['metacrud']['restrictions']['read']??[];

/*
{ "statement":"SUM(totalsalesprice.Total)", "alias":"TotalSalesPriceSum", "isAggregate":true }

joints:
LEFT JOIN ewave_cca.totalsalesprice ON totalsalesprice.Pelicula = shows.ShortName
*/
/*
$view = [
  "expressions"=>[
    [
      "statement"=> "CONCAT('[',GROUP_CONCAT(DISTINCT CONCAT('\"', shows.ShortName, '\"') SEPARATOR ', '),']')",
      "alias"=> "Shows_JSON",
      "isAggregate"=> true
    ]
      
  ],
  "joints"=>[
    "LEFT JOIN peliculas_shows ON adm_facturas_distribuidoras.pelicula_id = peliculas_shows.pelicula_id",
    "LEFT JOIN ewave_cca.shows ON peliculas_shows.ewave_shows_ShowId = shows.ShowId"
  ]
];
*/
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

function getFilters($columns){
  $filters = [];
  foreach($columns as $column){
   if(isset($_GET[$column['Field']])) {
    // if is array
    if(is_array($_GET[$column['Field']])) {
      $filters[$column['Field']] = $_GET[$column['Field']];
    }
   }
  }
  return $filters;
}

$filters = getFilters($columns);


$foreignPairs = getForeignPairs($columns);

$sql = "SELECT ";

if($view['distinct']??false){
  $sql.= "DISTINCT ";
}

foreach($columns as $column){
  if($cols){
    if(!in_array($column['Field'], $cols)) continue;
  } 
  $sql.= $tablename.".".$column['Field'] . ", ";
}

foreach($view['columns']??[] as $expression){
  $sql.= $expression['s'] . " AS " . $expression['a'] . ", ";
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

foreach($view['joints']??[] as $joint){
  $sql.= " $joint ";
}

if($requested_id){
  
  $sql.= " WHERE $tablename.$primaryKeyName = :id";
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':id', $requested_id);

} else {
//if(true) {

  if($search){
    // if WHERE has not been added, add it
    if(strpos($sql, ' WHERE ') === false){
      $sql.= " WHERE ";
    } else {
      $sql.= " AND ";
    }
    foreach($columns as $column){
      $sql.= $tablename.".".$column['Field'] . " LIKE :search OR ";
    }
    foreach($foreignPairs as $field => $pair){
      $sql.= $pair['value']." LIKE :search OR ";
    }
    $sql = rtrim($sql, ' OR ');
  }

  if(count($filters)){
    // if WHERE has not been added, add it
    if(strpos($sql, ' WHERE ') === false){
      $sql.= " WHERE ";
    } else {
      $sql.= " AND ";
    }
    foreach($filters as $field => $values){
      $sql.= $tablename.".".$field . " IN (";
      foreach($values as $value){
        $sql.= ":$field$value, ";
      }
      $sql = rtrim($sql, ', ');
      $sql.= ") AND ";
    }
    $sql = rtrim($sql, ' AND ');
  }

  if(count($view['conditions']??[])){
    // if WHERE has not been added, add it
    if(strpos($sql, ' WHERE ') === false){
      $sql.= " WHERE ";
    } else {
      $sql.= " AND ";
    }
    foreach($view['conditions'] as $condition){
      $sql.= $condition . " AND ";
    }
    $sql = rtrim($sql, ' AND ');
  }

  if(count($restrictions)){
    // if WHERE has not been added, add it
    if(strpos($sql, ' WHERE ') === false){
      $sql.= " WHERE ";
    } else {
      $sql.= " AND ";
    }
    foreach($restrictions as $field => $value){
      if(is_string($value)){
        $sql.= $tablename.".".$field . " = :$field AND ";
      } else {
        $sql.= $tablename.".".$field . " IN (";
        foreach($value as $v){
          $sql.= ":$field$v, ";
        }
        $sql = rtrim($sql, ', ');
        $sql.= ") AND ";
      }
    }
    $sql = rtrim($sql, ' AND ');
  }

  // check if any of the expressions in view has an aggregate function
  $hasAggregate = false;
  foreach($view['columns']??[] as $expression){
    if($expression['isAggregate']){
      $hasAggregate = true;
      break;
    }
  }

  if($hasAggregate){
    $sql.= " GROUP BY ";
    foreach($columns as $column){
      $sql.= $tablename.".".$column['Field'] . ", ";
    }
    foreach($foreignPairs as $field => $pair){
      $sql.= $pair['value'] . ", ";
    }
    foreach($view['columns']??[] as $expression){
      if(!$expression['isAggregate']){
        $sql.= $expression['s'] . ", ";
      }
    }
        
    $sql = rtrim($sql, ', ');
  }

  $sql.= " ORDER BY $sortField $sortOrder LIMIT :limit, :offset";
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':limit', ($page - 1) * $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $limit, PDO::PARAM_INT);
  if($search) {
    $stmt->bindValue(':search', "%$search%");
  }
  foreach($filters as $field => $values){
    foreach($values as $value){
      $stmt->bindValue(":$field$value", $value);
    }
  }

  foreach($restrictions as $field => $value){
    if(is_string($value)){
      $stmt->bindValue(":$field", $value);
    } else {
      foreach($value as $v){
        $stmt->bindValue(":$field$v", $v);
      }
    }
  }
}



$stmt->execute();

$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// decode all columns ending with _JSON
$records = array_map(function($record) use ($columns){
  foreach($record as $key => $value){
    if(strpos($key, '_JSON') !== false){
      $record[$key] = json_decode($value, true);
    }
  }
  return $record;
}, $records);

$request_uri = $_SERVER['REQUEST_URI'];

echo json_encode(["data"=>["query"=>$sql, "view"=>$metacrudView, "rows"=>$records, "request_uri"=>$request_uri]]);