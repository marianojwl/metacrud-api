<?php
// NOT FUNCTION ANY MORE
die();
$stmt = $pdo->prepare("SHOW TABLE STATUS LIKE :tablename");
$stmt->bindValue(':tablename', $tablename);
$stmt->execute();

$table = $stmt->fetch(PDO::FETCH_ASSOC);

print_r($table);
die();
//$table['Comment'] = json_decode($table['Comment']);
if(isset($table['data']['Comment']['metacrud']['file'])){
  try{
    echo file_get_contents($table['data']['Comment']['metacrud']['file']);
    exit;
  } catch(Exception $e){
    echo json_encode(["success"=>false, "error"=>$e->getMessage()]);
  }
}

echo json_encode(["data"=>$table]);