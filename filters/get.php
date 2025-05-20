<?php
/********************************************
 *              INIT VARIABLES              *
 ********************************************/

// EXECUTION TIME
 $startMicrotime = microtime(true);

// RESPONSE
$response = ["success"=>false, "data"=>["executionTime"=>null]];



/******************
 * GET PARAMETERS *
 ******************/
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



/***********************
 * VALIDATE PARAMETERS *
 ***********************/
if(!is_numeric($page) || !is_numeric($limit)){
  echo json_encode(["success"=>false,"error"=>"Invalid page or limit"]);
  exit();
}

if(!in_array($sortOrder, ['asc', 'desc', 'ASC', 'DESC'])){
  echo json_encode(["success"=>false,"error"=>"Invalid sort order"]);
  exit();
}



/**************
 * TABLE META *
 **************/

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



/********************************************
 *            COLUMNS TO SELECT             *
 ********************************************/

// GET COLUMNS
$columns = getColumns($pdo, $tablename);

// FILTER COLUMN REQUESTED
$filterColumn = array_values(array_filter($columns, function($column) use($controller) {
  return @$column['Field'] == $controller || @$column['Comment']['metacrud']['a'] == $controller;
}))[0]??null;


// GET PRIMARY KEY NAME
$primaryKeyName = getPrimaryKeyName($columns);

// override column data present in the view { regularColumnsOverride: {columnName: ...
foreach($view['regularColumnsOverride']??[] as $columnName => $columnData){
  foreach($columns as $i => $column){
    if($column['Field'] == $columnName){
      $columns[$i]['Comment']['metacrud'] = array_merge($columns[$i]['Comment']['metacrud']??[], $columnData);
    }
  }
}

$foreignValueCols= array_filter($columns, function($column) {
    return ($column['Comment']['metacrud']['foreign_value']??false && $column['Comment']['metacrud']['foreign_key']??false );
  });

$foreignValueColumns = array_map(function($column) {
    return ["s" => $column['Comment']['metacrud']['foreign_value'], "a" => str_replace(".", "_", $column['Comment']['metacrud']['foreign_value'])];
  }, $foreignValueCols);

// MERGE COLUMNS, VIEW COLUMNS AND FOREIGN VALUE COLUMNS  
$columnsToSelect = [...$view['columns']??[], ...$columns??[], ...$foreignValueColumns??[]];

$columnsToSelect = array_values(array_filter($columnsToSelect, function($column) use($filterColumn) {
  global $sortField;
  if( @$filterColumn['a'] && @$column['a'] == $filterColumn['a'] ) return true;
  if( @$column['Field'] && $column['Field'] == @$filterColumn['Field'] ) return true;
  if( @$filterColumn['Comment']['metacrud']['foreign_key'] &&  @$filterColumn['Comment']['metacrud']['foreign_value'] ) {
    $sortField = $filterColumn['Comment']['metacrud']['foreign_value'];
    return @$column['s'] == $filterColumn['Comment']['metacrud']['foreign_value'] || @$column['s'] == $filterColumn['Comment']['metacrud']['foreign_key'];
  }
  if( @$filterColumn['a'] && @$filterColumn['filterBy'] ) {
    return @$column['a'] == $filterColumn['filterBy'] || @$column['a'] == $filterColumn['a'];
  }
  return false;
}));



/********************************************
 *           GENERAL QUERY JOINS            *
 ********************************************/

$foreignValueJoints = array_map(function($column) use ($columns) {
    $mainTableField = $column['Field'];
    // key
    $foreignKey = $column['Comment']['metacrud']['foreign_key'];
    $foreignParts = explode('.', $foreignKey);
    $fkp = array_reverse($foreignParts);
    
    // foreign table
    $foreignTable = $fkp[2]??null ? ( $fkp[2] . '.' . $fkp[1] ) : $fkp[1];

    return "LEFT JOIN $foreignTable ON $foreignTable." . $fkp[0] . " = _.$mainTableField " . PHP_EOL;
    
  }, $foreignValueCols);

$gqJoins = [...$foreignValueJoints??[], ...$view['joints']??[], ...$view['subqueryJoints']??[]];
// remove duplicates
$gqJoins = array_unique($gqJoins, SORT_STRING);
$gqJoins = array_values(array_filter($gqJoins, function($join) {
  return $join != null;
}));



/********************************************************
 *            MAIN TABLE SUBQUERY OWN FILTERS           *
 *******************************************************/
$mtFilters = array_map(function($column) use ($cols) {
    $key = $column['Field']; // ?? $column['a'];
    if(!$key) return null;
    return [ $key => $_GET[$key] ];
}, array_values(array_filter($columns, function($column) {
    $key = $column['Field'] ?? null;
    if(!$key) return false;
    if(in_array($key, $cols??[])) return false;
    return (is_array($_GET[$key]??null));
  })));


/*******************************************************
 *            MAIN TABLE SUBQUERY VIEW FILTERS         *
 *******************************************************/
$viewFilters = array_map(function($column) {
    $key = $column['a'];
    if(!$key) return null;
    return [ $key => $_GET[$key] ];
}, array_values(array_filter($view['columns']??[], function($column) use ($cols) {
    $key = $column['a'] ?? null;
    if(!$key) return false;
    if(in_array($key, $cols??[])) return false;
    return (is_array($_GET[$key]??null));
  })));



/**********************************************
 *            MAIN TABLE SUBQUERY             *
 **********************************************/
$subquery  = "SELECT DISTINCT " . PHP_EOL;
// $subquery .= implode(", ", array_map(function($column) {
//           return $column['Field'] ?? ( $column['s'] . " AS " . $column['a'] );
//         }, $columnsToSelect)) . ", " . PHP_EOL;
foreach($columnsToSelect as $cts) {
  if(@$cts['Field']) {
    $subquery .= $cts['Field'] . ", " . PHP_EOL;
  } else if(@$cts['s'] && @$cts['a']) {
    $subquery .= $cts['s'] . " AS " . $cts['a'] . ", " . PHP_EOL;
  } else {}
}

$subquery  = rtrim($subquery, ", " . PHP_EOL);

  
// VIEW COLUMN IN SUBQUERY
// $subquery .= implode(", ", array_map(function($column) {
//           return $column['s'] . " AS " . $column['a'];
//         }, array_filter($view['columns']??[], function($column) use($cols) {
//           return ($column['inSubquery']??false) && in_array($column['a'], $cols??[]);
//         }))) . ", " . PHP_EOL;

// $subquery  = rtrim($subquery, ", ") . PHP_EOL;

$subquery .= " FROM $tablename " . PHP_EOL;

$subquery .= implode(" " . PHP_EOL, array_map(function($join) {
          return $join?? "";
        }, $gqJoins)) . PHP_EOL;

// MAIN TABLE FILTERS
$allMtFilters = [...$mtFilters??[],...$viewFilters??[]];

if(count($allMtFilters) > 0){
  $subquery .= " WHERE " . PHP_EOL;
  $subquery .= implode(" AND ", array_map(function($filter) {
    return implode(" AND ", array_map(function($key, $value) {
      return "$key IN (" . implode(", ", array_map(function($v){return "'".$v."'";},$value)) . ")";
    }, array_keys($filter), array_values($filter)));
  }, $allMtFilters)) . PHP_EOL;
}

$subquery .= " ORDER BY $sortField ASC " . PHP_EOL;

// FILTERING // NO LIMIT WHEN REQUESTING FILTER OPTIONS // ???????
//if(count($cols??[]) === 0)
$subquery .= " LIMIT 1000";

die($subquery);

$response["data"]["sql"] = $subquery;

$result = $conn->query($subquery);

if(!$result){
  $conn->close();
  throw new Exception($conn->error . " - " . $sql);
}
$rows = $result->fetch_all(MYSQLI_ASSOC);

$response["data"]["rows"] = $rows;

$conn->close();


/********************************************
 *                  OUTPUT                  *
 ********************************************/

// EXECUTION TIME
$endMicrotime = microtime(true);

$executionTime = $endMicrotime - $startMicrotime;

$response["data"]["executionTime"] = $executionTime;

$response["success"] = true;

echo json_encode($response);