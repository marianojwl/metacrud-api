<?php

$input = json_decode(file_get_contents('php://input'), true);

// alter table's set comment
$comment = $input['Comment'] ?? "";

$sql = "ALTER TABLE ".$tablename." COMMENT = :comment";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':comment', $comment);
$stmt->execute();


echo json_encode(['success'=>true]);
exit;