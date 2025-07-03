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
$sortField = 1; //$_GET['sort'] ?? 1;
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



/**********************
 *    SEARCH TERMS    *
 **********************/
// get rid of any non-alphanumeric characters, but spaces
$searchQuery = preg_replace('/[^a-zA-Z0-9ñÑ\s]/', '', $search??"");

// get rid of multiple spaces
$searchQuery = preg_replace('/\s+/', ' ', $searchQuery);

// get terms
$searchTerms = explode(' ', $searchQuery);


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


// VIEW COLUMN IN SUBQUERY
$viewColumnsInSubquery = array_filter($view['columns']??[], function($column) {
  return $column['inSubquery']??false;
});

// MERGE COLUMNS, VIEW COLUMNS AND FOREIGN VALUE COLUMNS  
$columnsToSelect = [...$view['columns']??[], ...$columns??[], ...$foreignValueColumns??[]];


// FILTER COLUMN REQUESTED
$filterColumn = array_values(array_filter($columnsToSelect, function($column) use($controller) {
  return @$column['Field'] == $controller || @$column['Comment']['metacrud']['a'] == $controller || @$column['a'] == $controller;
}))[0]??null;



$columnsToSelect = array_values(array_filter($columnsToSelect, function($column) use($filterColumn) {
  global $sortField;
  if( @$filterColumn['a'] && @$column['a'] == $filterColumn['a'] ) return true;
  if( @$column['Field'] && $column['Field'] == @$filterColumn['Field'] ) return true;
  if( @$filterColumn['Comment']['metacrud']['foreign_key'] &&  @$filterColumn['Comment']['metacrud']['foreign_value'] ) {
    $sortField = $filterColumn['Comment']['metacrud']['foreign_value'];
    return @$column['s'] == $filterColumn['Comment']['metacrud']['foreign_value'] || @$column['s'] == $filterColumn['Comment']['metacrud']['foreign_key'];
  }
  if( @$filterColumn['a'] && @$filterColumn['filterBy'] ) {
    return @$column['a'] == $filterColumn['filterBy'] || @$column['a'] == $filterColumn['a'] || @$column['Field'] == $filterColumn['filterBy'];
  }
  return false;
}));



/********************************************
 *            READ RESTRICTIONS             *
 ********************************************/
// BUILD RESTRICTION FUNCTION
$mainTableAlias = "_";
function buildRestriction($restriction){
  global $tablename, $mainTableAlias, $conn;
  if(is_string($restriction['operands'][0])){
    return  " (" . str_replace($tablename.".", $mainTableAlias.".",$restriction['operands'][0]) . " " . $restriction['operator'] . " " . ( 
      (isset($restriction['operands'][1]['var']) ?
        getVarValue($restriction['operands'][1]['var']) :
        (is_array($restriction['operands'][1]) ?
          "('" . implode("','", str_replace($tablename.".", $mainTableAlias.".", $restriction['operands'][1])) . "')" : 
          ("'".$conn->real_escape_string(str_replace($tablename.".", $mainTableAlias.".", $restriction['operands'][1]) )."'") 
        )
      )
    ) . ") ";
 } else {
    return implode($restriction['operator'], array_map(function($operand) { return " (".buildRestriction($operand).") "; }, $restriction['operands']));
  }
}
// RESTRICTIONS
$restrictions = [
  ...$tableStatus['Comment']['metacrud']['restrictions']['read']??[]
  // ...$view['restrictions']['read']??[]
];

$restr="";
if(count($restrictions) > 0){
  $restr = " AND " . buildRestriction($restrictions, $conn);
}

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

    return "LEFT JOIN $foreignTable ON $foreignTable." . $fkp[0] . " = _.$mainTableField";
    
  }, $foreignValueCols);

$gqJoins = [...$foreignValueJoints??[], ...$view['subqueryJoints']??[]];
// remove duplicates
$gqJoins = array_unique($gqJoins, SORT_STRING);
$gqJoins = array_values(array_filter($gqJoins, function($join) {
  return $join != null;
}));



/********************************************************
 *            MAIN TABLE SUBQUERY OWN FILTERS           *
 *******************************************************/
$mtFilters = array_map(function($column) {
    $key = $column['Field']; // ?? $column['a'];
    if(!$key) return null;
    return [ $key => $_GET[$key] ];
}, array_values(array_filter($columns, function($column) use ($filterColumn) {
    $key = $column['Field'] ?? null;
    if(!$key) return false;
    if(@$filterColumn['a'] && @$column['a'] == $filterColumn['a'] ) return false;
    if(@$filterColumn['Field'] && $column['Field'] == @$filterColumn['Field'] ) return false;
    if(@$filterColumn['Comment']['metacrud']['a'] && $filterColumn['Comment']['metacrud']['a'] == $column['Comment']['metacrud']['a']) return false;

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
    $subquery .= "_." . $cts['Field'] . ", " . PHP_EOL;
  } else if(@$cts['s'] && @$cts['a']) {
    $subquery .= $cts['s'] . " AS " . $cts['a'] . ", " . PHP_EOL;
  } else {}
}

$subquery  = rtrim($subquery, ", " . PHP_EOL);
$subquery .= " " . PHP_EOL;
  
// VIEW COLUMN IN SUBQUERY
// $subquery .= implode(", ", array_map(function($column) {
//           return $column['s'] . " AS " . $column['a'];
//         }, array_filter($view['columns']??[], function($column) use($cols) {
//           return ($column['inSubquery']??false) && in_array($column['a'], $cols??[]);
//         }))) . ", " . PHP_EOL;

// $subquery  = rtrim($subquery, ", ") . PHP_EOL;

$subquery .= "FROM $tablename _" . PHP_EOL;

$subquery .= implode(" " . PHP_EOL, array_map(function($join) {
          return $join?? "";
        }, $gqJoins)) . " " . PHP_EOL;


// MAIN TABLE FILTERS
$allMtFilters = [ ...$mtFilters??[], ...$viewFilters??[]];

$subquery .= " WHERE 1=1 " . PHP_EOL;

// RESTRICTIONS
$subquery .= $restr . PHP_EOL;

if(count($allMtFilters) > 0){
  $subquery .= " AND ";
  $subquery .= implode(PHP_EOL." AND ", array_map(function($filter) {
    return implode(PHP_EOL." AND ", array_map(function($key, $value) {
      // if $key contains no dots, it is a regular column
      if(strpos($key, ".") === false){
        $key = "_." . $key;
      }
      $q = "( $key IN (" . implode(", ", array_map(function($v){return "'".$v."'";},$value)) . ")";
      if($value[0] == ""){
        $q .= " OR $key IS NULL";
      }
      $q .= " )";
      return $q;
    }, array_keys($filter), array_values($filter)));
  }, $allMtFilters)) . PHP_EOL;
}

// SEARCH
if($search) {
  $searchQueryFragment = "";
  foreach ($searchTerms as $searchTerm) {
    $sqf = "(";
    $sqf .= implode(" OR ", [
      ...array_map(function($column) use ($tablename, $searchTerm) {
        return "_.".str_replace($tablename.".", "", $column['Field']) . " LIKE '%$searchTerm%'";
      }, array_filter($columns, function($col){ return in_array(explode("(",$col['Type'])[0]??"", ['varchar', 'text', 'char', 'longtext', 'tinytext']); }))??[], 
      ...array_map(function($column) use ($tablename, $searchTerm) {
        return $column['s'] . " LIKE '%$searchTerm%'";
      }, $viewColumnsInSubquery)??[],
      ...array_map(function($column) use ($tablename, $searchTerm) {
        return $column['s'] . " LIKE '%$searchTerm%'";
      }, $foreignValueColumns)??[]
    ]);
    $sqf .= ")";
    $sqf .= PHP_EOL . " AND ";
    $searchQueryFragment .= $sqf;
  }
  $searchQueryFragment = rtrim($searchQueryFragment, " AND ");

  if($searchQueryFragment !== "") {
    $subquery .= " AND ( " . $searchQueryFragment . " ) " . PHP_EOL;
  }

} // SEARCH

$subquery .= "ORDER BY $sortField ASC " . PHP_EOL;

// FILTERING // NO LIMIT WHEN REQUESTING FILTER OPTIONS // ???????
//if(count($cols??[]) === 0)
$subquery .= "LIMIT 1000";

//die($subquery);

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