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

// MERGE COLUMNS, VIEW COLUMNS AND FOREIGN VALUE COLUMNS  
// $columnsToSelect = [...$view['columns']??[], ...$columns??[], ...$foreignValueColumns??[]];
$columnsToSelect = [...$view['columns']??[], ...$columns??[]];


// CHECK IF ANY OF THE EXPRESSIONS IN VIEW HAS AN AGGREGATE FUNCTION
$hasAggregate = false;
foreach($view['columns']??[] as $expression){
  if(@$expression['isAggregate']){
    $hasAggregate = true;
    break;
  }
}

if($hasAggregate){
  $groupBy = "GROUP BY " . implode(", ", array_map(function($column) {
    return $column['Field'] ?? $column['a'] ?? $column['Comment']['metacrud']['a'] ?? null;
  }, array_filter($columnsToSelect, function($column) {
    return !($column['isAggregate']??false);
  }))) . PHP_EOL;
} else {
  $groupBy = " ";
}


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

$view['joints'] = array_map(function($join) use ($tablename) {
                    return str_replace($tablename.".", "_.", $join);
                  }, $view['joints']??[]);

$gqJoins = [...$foreignValueJoints??[], ...$view['joints']??[]];
// $gqJoins = [ ...$view['joints']??[]];



/********************************************************
 *            MAIN TABLE SUBQUERY OWN FILTERS           *
 *******************************************************/
$mtFilters = array_map(function($column) {
    $key = $column['Field']; // ?? $column['a'];
    if(!$key) return null;
    return [ $key => $_GET[$key] ];
}, array_values(array_filter($columns, function($column) {
    $key = $column['Field']; // ?? $column['a'];
    if(!$key) return false;
    return (is_array($_GET[$key]??null));
  })));


/*******************************************************
 *            MAIN TABLE SUBQUERY VIEW FILTERS         *
 *******************************************************/
$viewFilters = array_map(function($column) {
    $key = $column['a'];
    if(!$key) return null;
    return [ $key => $_GET[$key] ];
}, array_values(array_filter($view['columns']??[], function($column) {
    $key = $column['a'];
    if(!$key) return false;
    return (is_array($_GET[$key]??null));
  })));


/**********************************************
 *            MAIN TABLE SUBQUERY             *
 **********************************************/
$subquery  = " SELECT _.*, ";

// FOREIGN VALUE COLUMNS
if(count($foreignValueColumns??[]) > 0){
  $subquery .= PHP_EOL . " ";
}

$subquery .= implode(", " . PHP_EOL . " ", array_map(function($column) {
  return $column['s'] . " AS " . $column['a'];
}, $foreignValueColumns)) . ", ";

// VIEW COLUMN IN SUBQUERY
$viewColumnsInSubquery = array_filter($view['columns']??[], function($column) {
  return $column['inSubquery']??false;
});

if(count($viewColumnsInSubquery) > 0){
  $subquery .= PHP_EOL . " ";
}
$subquery .= implode(", " . PHP_EOL . " ", array_map(function($column) {
    return $column['s'] . " AS " . $column['a'];
  }, $viewColumnsInSubquery )) . ", ";

$subquery  = rtrim($subquery, ", ");

$subquery .= PHP_EOL . " FROM $tablename _ ";

if(count($foreignValueJoints??[]) > 0){
  $subquery .= PHP_EOL . " ";
}
$subquery .= implode(PHP_EOL . " ", array_map(function($join) {
          return $join?? "";
        }, $foreignValueJoints)) . " ";

if(count($view['subqueryJoints']??[]) > 0){
  $subquery .= PHP_EOL . " ";
}
$subquery .= implode(PHP_EOL . " ", array_map(function($join) {
          return $join?? "";
        }, $view['subqueryJoints']??[])) . " ";

// IF SPECIFIC ID IS REQUESTED
$rid = [];
if($requested_id){
  $requested_id = $conn->real_escape_string($requested_id);
  $rid[] = ["_.".$primaryKeyName => [$requested_id]];
}

// MAIN TABLE FILTERS
$allMtFilters = [...$rid, ...$mtFilters??[], ...$viewFilters??[]];

$subquery .= " WHERE 1=1 " . PHP_EOL;

// RESTRICTIONS
$subquery .= $restr . PHP_EOL;

if(count($allMtFilters) > 0){
  $subquery .= " AND ";
  $subquery .= implode(PHP_EOL." AND ", array_map(function($filter) {
    return implode(PHP_EOL." AND ", array_map(function($key, $value) {
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


$subquery .= " ORDER BY $sortField $sortOrder " . PHP_EOL;

// FILTERING // NO LIMIT WHEN REQUESTING FILTER OPTIONS // ???????
//if(count($cols??[]) === 0)
$subquery .= " LIMIT " . ($page - 1) * $limit . ", $limit " . PHP_EOL;



/**********************************************
 *                GENERAL QUERY               *
 **********************************************/
$sql  = "";

// WITH CTES
if(count($view['ctes']??[]) > 0){
  $sql.= "WITH " . 
          implode(", ", array_map(function($cte) {
            return $cte;
          }, $view['ctes'])) . PHP_EOL;
}

// SELECT COLUMNS
$sql .= "SELECT _.*, ";
if(count($view['columns']??[]) > 0){
  $sql .= PHP_EOL;
}
$sql .= implode(", " . PHP_EOL, array_map(function($column) use ($tablename) {
        if(isset($column['s']['var'])){
          return getVarValue($column['s']['var']) . " AS " . $column['a'] ;
        }
        return $column['Field']??false ? 
          ("_.".$column['Field']) :
          ( str_replace($tablename.".", "_.", $column['s']) . " AS " . $column['a'] );
        }, array_filter($view['columns']??[],function($col){ return !($col['inSubquery']??false); })??[])) . ", ";
        // }, $columnsToSelect)) . PHP_EOL;
$sql = rtrim($sql, ", ");
$sql .= PHP_EOL . "FROM ( ";
$sql .= $subquery;
$sql .= " ) AS _ " . PHP_EOL;


// ADD JOINTS
$sql .= implode(" " . PHP_EOL, array_map(function($join) {
          return $join?? "";
        }, $gqJoins)) . PHP_EOL;

// ADD CONDITIONS
$sql .= "WHERE 1=1 AND " . PHP_EOL;
foreach($view['conditions']??[] as $condition){
  $condition = str_replace($tablename.".", "_.", $condition);
  $sql .= " " . $condition . " AND " . PHP_EOL;
}


$sql = rtrim($sql, " AND " . PHP_EOL);

// ADD GROUP BY
$sql .= PHP_EOL . $groupBy;

$sql .= " ORDER BY $sortField $sortOrder " . PHP_EOL;


// die($sql);

$response["data"]["sql"] = $sql;

$result = $conn->query($sql);

if(!$result){
  $conn->close();
  throw new Exception($conn->error . " - " . $sql);
}
$rows = $result->fetch_all(MYSQLI_ASSOC);

// decode all columns ending with _JSON
$rows = array_map(function($row) use ($columnsToSelect) {
  foreach($columnsToSelect as $column) {
    $index = $column['Field'] ?? $column['a'] ?? null;
    if(!$index) continue;
    if(substr($index, -5) == "_JSON" || @$column['Type'] == "json") {
      $row[$index] = json_decode($row[$index], true);
    }
  }
  return $row;
}, $rows);

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