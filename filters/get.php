<?php
/********************************************
 *              INIT VARIABLES              *
 ********************************************/

// EXECUTION TIME
 $startMicrotime = microtime(true);

// RESPONSE
$response = ["success"=>false, "data"=>["executionTime"=>null]];



/********************************************
 *                  OUTPUT                  *
 ********************************************/

// EXECUTION TIME
$endMicrotime = microtime(true);

$executionTime = $endMicrotime - $startMicrotime;

$response["data"]["executionTime"] = $executionTime;

$response["success"] = true;

echo json_encode($response);