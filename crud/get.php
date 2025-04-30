<?php
$startMicrotime = microtime(true);
// GET PARAMETERS
$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 10;
$sortField = $_GET['sort'] ?? 1;
$sortRequested = $sortField != 1;
$sortValidated = !$sortRequested;
$sortOrder = $_GET['order'] ?? 'asc';
$cols = $_GET['cols'] ?? null;
$search = $_GET['search'] ?? null;
$metacrudView = $_GET['metacrudView'] ?? null;
$queryDistinct = ($_GET['distinct']??"") == "true" ?? false;

// VALIDATE PARAMETERS
if(!is_numeric($page) || !is_numeric($limit)){
  echo json_encode(["success"=>false,"error"=>"Invalid page or limit"]);
  exit();
}

if(!in_array($sortOrder, ['asc', 'desc', 'ASC', 'DESC'])){
  echo json_encode(["success"=>false,"error"=>"Invalid sort order"]);
  exit();
}

$tableStatus = null;

$view = null;

// GET TABLE STATUS
$tableStatus = getTableStatus($pdo, $tablename); 

// CHECK IF THE REQUESTED VIEW EXISTS
if($metacrudView) {
  $view = $tableStatus['Comment']['metacrud']['views'][$metacrudView]??null;
  if(!$view) {
    echo json_encode(["success"=>false,"error"=>"View not found"]);
    exit();
  }
}


// GET COLUMNS
$columns = getColumns($pdo, $tablename);

// override column data present in the view { regularColumnsOverride: {columnName: ...
// foreach($view['regularColumnsOverride']??[] as $columnName => $columnData){
//   foreach($columns as $i => $column){
//     if($column['Field'] == $columnName){
//       $columns[$i]['Comment']['metacrud'] = array_merge($columns[$i]['Comment']['metacrud']??[], $columnData);
//     }
//   }
// }

// GET PRIMARY KEY NAME
$primaryKeyName = getPrimaryKeyName($columns);



// GET FILTERS
$filters = getFilters($columns);

// GET FOREIGN PAIRS
$foreignPairs = getForeignPairs($columns);

// START BUILDING SQL QUERY
$sql = "";

// CTES
if(($view['ctes']??false) && count($view['ctes']) > 0){
  $sql.= " WITH " . PHP_EOL;
  foreach($view['ctes'] as $cte){
    $sql.= $cte . ', ' . PHP_EOL;
  }
  $sql = rtrim($sql, ', ' . PHP_EOL);
  $sql.= " ";
}

// SELECT
$sql .= "SELECT ";
$groupBy = "";

// CHECK IF ANY OF THE EXPRESSIONS IN VIEW HAS AN AGGREGATE FUNCTION
$hasAggregate = false;
foreach($view['columns']??[] as $expression){
  if($expression['isAggregate']){
    $hasAggregate = true;
    break;
  }
}

if($hasAggregate){
  $groupBy = " GROUP BY ";
}

// SELECT DISTINCT
if(($view['distinct']??false) || $queryDistinct ){
  $sql.= "DISTINCT ";
}

// SELECT REGULAR COLUMNS IF REQUESTED
if(($view['selectRegularColumns']??true)){
  // SIMPLE COLUMNS
  foreach($columns as $column){
    if($sortRequested && !$sortValidated){
      if($column['Field'] == $sortField){
        $sortValidated = true;
      }
    }
    // IF SPECIFIC COLUMNS ARE REQUESTED, ONLY SELECT THOSE
    if($cols){
      if(!in_array($column['Field'], $cols)) continue;
    } 
    $sql.= $tablename.".".$column['Field'] . ", ";
    if($hasAggregate){
      $groupBy.= $tablename.".".$column['Field'] . ", ";
    }
  }

  // FOREIGN PAIRS
  foreach($foreignPairs as $field => $pair){
    if($sortRequested && !$sortValidated){
      if(str_replace('.', '_', $pair['value']) == $sortField){
        $sortValidated = true;
      }
    }
    $sql.= $pair['value']." AS ". str_replace('.', '_', $pair['value']) . ", ";
    if($hasAggregate){
      $groupBy.= $pair['value'] . ", ";
    }
  }

}

// SELECT VIEW COLUMNS IF VIEW IS DEFINED
foreach($view['columns']??[] as $expression){
  if($sortRequested && !$sortValidated){
    if($expression['a'] == $sortField){
      $sortValidated = true;
    }
  }
  // check if s is reference to a variable
  if(isset($expression['s']['var'])){
    $expression['s'] = getVarValue($expression['s']['var']);
  }
  $sql.= $expression['s'] . " AS " . $expression['a'] . ", ";
  if($hasAggregate && !$expression['isAggregate']){
    $groupBy.= $expression['s'] . ", ";
  }
}

// SORT FIELD SHOULD BE VALIDATED BY NOW
if($sortRequested && !$sortValidated){
  echo json_encode(["success"=>false,"error"=>"Invalid sort field"]);
  exit();
}

$sql = rtrim($sql, ', ');
$groupBy = rtrim($groupBy, ', ');

$sql .= PHP_EOL;
$sql.= " FROM $tablename " . PHP_EOL;

// JOIN FOREIGN TABLES
foreach($foreignPairs as $field => $pair){
  $parts = array_reverse(explode('.', $pair['key']));
  $col = $parts[0];
  $tab = $parts[1];
  $db = $parts[2]??null;
  $sql.= " LEFT JOIN ". ($db ? "$db." : "") . "$tab ON $tablename.$field = $tab.$col" . PHP_EOL;
}
// foreach($foreignPairs as $field => $pair){
//   $parts = array_reverse(explode('.', $pair['key']));
//   $col = $parts[0];
//   $tab = $parts[1];

//   $table_alias = $pair['table_alias'];
//   $foreign_table = $pair['foreign_table'];
//   $sql.= " LEFT JOIN $foreign_table $table_alias ON $tablename.$field = $tab.$col" . PHP_EOL;
 
// }
// JOIN JOINTS
foreach($view['joints']??[] as $joint){
  $sql.= " $joint " . PHP_EOL;
}

$sql.= " WHERE 1=1 AND " . PHP_EOL;

// GET VARIABLE VALUE
function getVarValue($var) {
  $parts = explode('.', $var);
  $first = array_shift($parts);

  // Determinar la variable base
  switch ($first) {
      case '_SESSION':
          $value = $_SESSION;
          break;
      default:
          return null; // No es una variable válida
  }

  // Recorrer los niveles de la variable
  foreach ($parts as $part) {
      if (!isset($value[$part])) {
          return null; // Retorna null si la clave no existe
      }
      $value = $value[$part];
  }

  // Si es un array, formatearlo como una cadena para SQL
  return is_array($value) ? ("('" . implode("','", $value) . "')") : $value;
}


// BUILD RESTRICTION FUNCTION
function buildRestriction($restriction, $conn){
  if(is_string($restriction['operands'][0])){
    return  " (" . $restriction['operands'][0] . " " . $restriction['operator'] . " " . (
      (isset($restriction['operands'][1]['var']) ?
        getVarValue($restriction['operands'][1]['var']) :
        (is_array($restriction['operands'][1]) ?
          "('".implode("','", $restriction['operands'][1])."')" :
          ("'".$conn->real_escape_string($restriction['operands'][1])."'")
        )
      )
    ) . ") ";
 } else {
    return implode($restriction['operator'], array_map(function($operand) use ($conn) { return " (".buildRestriction($operand, $conn).") "; }, $restriction['operands']));
  }
}
// RESTRICTIONS
$restrictions = $tableStatus['Comment']['metacrud']['restrictions']['read']??[];


if(count($restrictions) > 0){
  $sql.= buildRestriction($restrictions, $conn) . " AND " . PHP_EOL;
}

// VIEW RESTRICTIONS
$viewRestrictions = $view['restrictions']['read']??[];
if(count($viewRestrictions) > 0){
  $sql.= buildRestriction($viewRestrictions, $conn) . " AND " . PHP_EOL;
}

// IF SPECIFIC ID IS REQUESTED
if($requested_id){
  $requested_id = $conn->real_escape_string($requested_id);
  $sql.= " $tablename.$primaryKeyName = '" . $requested_id . "' AND " . PHP_EOL;
}

// IF SEARCH IS REQUESTED
if($search){
  $search = preg_replace('!\s+!', ' ', $search);
  // get rid of any non-alphanumeric characters, but spaces
  $search = preg_replace('/[^a-zA-Z0-9\s]/', '', $search);
  
  $terms = explode(' ', $search);
  $sql.= " (";
  // foreach($terms as $term){
  //   $sql.= " CONCAT(";
  //   foreach($columns??[] as $column){
  //     $sql.= $tablename.".".$column['Field'] . ", ' ', ";
  //   }
  //   foreach($foreignPairs??[] as $field => $pair){
  //     $sql.= $pair['value'] . ", ' ', ";
  //   }
  //   foreach($view['columns']??[] as $expression){
  //     $sql.= $expression['s'] . ", ' ', ";
  //   }
  //   $sql = rtrim($sql, ", ' ', ");
  //   $sql.= ") LIKE '%". $conn->real_escape_string($term) . "%' AND ";
  // }
  // $sql = rtrim($sql, " AND " . PHP_EOL);
  foreach($terms as $term){
    $sql .= " ( ";
    foreach($columns??[] as $column){
      $sql.= $tablename.".".$column['Field'] . " LIKE '%". $conn->real_escape_string($term) . "%' OR ";
    }
    foreach($foreignPairs??[] as $field => $pair){
      $sql.= $pair['value'] . " LIKE '%". $conn->real_escape_string($term) . "%' OR ";
    }
    foreach($view['columns']??[] as $expression){
      $sql.= $expression['s'] . " LIKE '%". $conn->real_escape_string($term) . "%' OR ";
    }
    $sql = rtrim($sql, " OR ");
    $sql.= ") AND " . PHP_EOL;
  }
  $sql = rtrim($sql, " AND " . PHP_EOL);
  $sql.= ") AND " . PHP_EOL;
}
// IF FILTERS ARE REQUESTED
foreach($filters??[] as $field => $values){
  $sql.= " $tablename.$field IN (";
  foreach($values as $value){
    $sql.= "'" . $conn->real_escape_string($value) . "', ";
  }
  $sql = rtrim($sql, ', ');
  $sql.= ") AND " . PHP_EOL;
}

// IF VIEW CONDITIONS ARE REQUESTED
foreach($view['conditions']??[] as $condition){
  $sql.= " $condition AND " . PHP_EOL;
}

$sql = rtrim($sql, " AND " . PHP_EOL);

// GROUP BY
$sql.= $groupBy . PHP_EOL;

// ORDER BY
$sql.= " ORDER BY $sortField $sortOrder " . PHP_EOL;

// LIMIT
$sql.= " LIMIT " . ($page - 1) * $limit . ", $limit";
// die($sql);
try {
  $result = $conn->query($sql);
} catch (Exception $e) {
  throw new Exception($e->getMessage() . " - " . $sql);
}
$records = $result->fetch_all(MYSQLI_ASSOC);

$columnsAndViewColumns = array_merge(
  $columns, 
  array_map(function($c) { 
      return array_merge($c, ["Field" => $c["a"] ?? null, "Type" => ""]); 
  }, $view['columns'] ?? [])
);


// decode all columns ending with _JSON
$records = array_map(function($record) use ($columnsAndViewColumns){
  foreach($columnsAndViewColumns as $column){
    if( (strpos($column['Field'], '_JSON') !== false) || (strpos($column['Field'], '_JSON') !== false) || $column['Type'] == 'json'){
      if(isset($record[$column['Field']])) {
        $record[$column['Field']] = json_decode($record[$column['Field']], true);
      }
    }
  }
  return $record;
}, $records);

$request_uri = $_SERVER['REQUEST_URI'];

$endMicrotime = microtime(true);

$executionTime = $endMicrotime - $startMicrotime;
// $sql = "";
echo json_encode(["data"=>["executionTime"=>$executionTime, "query"=>$sql, "view"=>$metacrudView, "rows"=>$records, "request_uri"=>$request_uri]]);