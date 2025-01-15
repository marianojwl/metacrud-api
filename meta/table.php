<?php

$stmt = $pdo->prepare("SHOW TABLE STATUS LIKE :tablename");
$stmt->bindValue(':tablename', $tablename);
$stmt->execute();

$table = $stmt->fetch(PDO::FETCH_ASSOC);

$table['Comment'] = json_decode($table['Comment']);

echo json_encode(["data"=>$table]);