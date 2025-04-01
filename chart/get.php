<?php
$startMicrotime = microtime(true);

$response = ['success'=>false, 'error' => '', 'data'=>[]];

// GET PARAMETERS
$name = $_GET['name'] ?? null;

if($name == null) {
    echo json_encode(['success'=>false, 'error' => 'Chart Name not defined']);
    http_response_code(400);
    exit;
}

// GET COLUMNS
$columns = getColumns($pdo, $tablename);

// GET PRIMARY KEY NAME
//$primaryKeyName = getPrimaryKeyName($columns);

// GET FILTERS
$filters = getFilters($columns);


$tableStatus = null;

$view = null;

// GET TABLE STATUS
$tableStatus = getTableStatus($pdo, $tablename); 

// GET CHART DATA
// look for chart with name $name in $tableStatus['Comment']['metacrud']['charts']
$chart = array_filter($tableStatus['Comment']['metacrud']['charts'], function($chart) use ($name) {
    return $chart['name'] == $name;
});
$chart = array_values($chart)[0] ?? null;

if($chart == null) {
    echo json_encode(['success'=>false, 'error' => 'Chart not found']);
    http_response_code(400);
    exit;
}

$records = null;

// SWITCH ON CHART TYPE
switch($chart['type']) {
    case 'PivotTable':
        include_once(__DIR__ . '/types/PivotTable.php');
        break;
    default:
        echo json_encode(['success'=>false, 'error' => 'Chart type not supported']);
        http_response_code(400);
        exit;
}

$endMicrotime = microtime(true);

$executionTime = $endMicrotime - $startMicrotime;
$response['success'] = true;
$response['data']['executionTime']= $executionTime;
echo json_encode($response);