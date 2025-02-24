<?php
$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 10;
$sortField = $_GET['sort'] ?? 1;
$sortOrder = $_GET['order'] ?? 'asc';
$cols = $_GET['cols'] ?? null;
$search = $_GET['search'] ?? null;
$metacrudView = $_GET['metacrudView'] ?? null;
$queryDistinct = ($_GET['distinct']??"") == "true" ?? false;

$tableStatus = null;

$view = null;

$tableStatus = getTableStatus($pdo, $tablename); 

if($metacrudView) {
  $view = $tableStatus['Comment']['metacrud']['views'][$metacrudView]??null;
  if(!$view) {
    echo json_encode(["success"=>false,"error"=>"View not found"]);
    exit();
  }
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

if(($view['distinct']??false) || $queryDistinct ){
  $sql.= "DISTINCT ";
}

if(($view['selectRegularColumns']??true)){
  foreach($columns as $column){
    if($cols){
      if(!in_array($column['Field'], $cols)) continue;
    } 
    $sql.= $tablename.".".$column['Field'] . ", ";
  }

  foreach($foreignPairs as $field => $pair){
    $sql.= $pair['value']." AS ". str_replace('.', '_', $pair['value']) . ", ";
  }
}

foreach($view['columns']??[] as $expression){
  $sql.= $expression['s'] . " AS " . $expression['a'] . ", ";
}

$sql = rtrim($sql, ', ');

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
    // look also in view columns
    foreach($view['columns']??[] as $expression){
      $sql.= $expression['s'] . " LIKE :search OR ";
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
    foreach($restrictions as $field => $value){
      if(is_string($value)){
        // if WHERE has not been added, add it
        if(strpos($sql, ' WHERE ') === false){ $sql.= " WHERE "; } else { $sql.= " AND "; }

        $sql.= $tablename.".".$field . " = :$field AND ";
      } else {
        if(count($value)) {
          // if WHERE has not been added, add it
          if(strpos($sql, ' WHERE ') === false){ $sql.= " WHERE "; } else { $sql.= " AND "; }

          if(strpos($field, '.') === false){
            $sql.= $tablename.".".$field;
          } else {
            $sql.= $field;
          }
          $sql .= " IN (";
          foreach($value as $v){
            $fieldv = str_replace('.', '_', $field).$v;
            $sql.= ":$fieldv, ";
            //$sql.= ":$field$v, ";
          }
          $sql = rtrim($sql, ', ');
          $sql.= ") AND ";
        }
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
    if(($view['selectRegularColumns']??true)){
      foreach($columns as $column){
        $sql.= $tablename.".".$column['Field'] . ", ";
      }
      foreach($foreignPairs as $field => $pair){
        $sql.= $pair['value'] . ", ";
      }
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
        $fieldv = str_replace('.', '_', $field).$v;
        $stmt->bindValue(":$fieldv", $v);
      }
    }
  }
}

//echo json_encode(["data"=>["query"=>$sql, "view"=>$metacrudView]]); die();

$stmt->execute();

$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$columnsAndViewColumns = array_merge(
  $columns, 
  array_map(function($c) { 
      return array_merge($c, ["Field" => $c["a"] ?? null, "Type" => ""]); 
  }, $view['columns'] ?? [])
);


// decode all columns ending with _JSON
$records = array_map(function($record) use ($columnsAndViewColumns){
  // foreach($record as $key => $value){
  //   if(strpos($key, '_JSON') !== false){
  //     $record[$key] = json_decode($value, true);
  //   }
  // }
  foreach($columnsAndViewColumns as $column){
    if( (strpos($column['Field'], '_JSON') !== false) || $column['Type'] == 'json'){
      $record[$column['Field']] = json_decode($record[$column['Field']], true);
    }
  }
  return $record;
}, $records);

$request_uri = $_SERVER['REQUEST_URI'];

echo json_encode(["data"=>["query"=>$sql, "view"=>$metacrudView, "rows"=>$records, "request_uri"=>$request_uri]]);