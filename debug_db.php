<?php
$pdo = new PDO('sqlite:database.sqlite');
$stmt = $pdo->query("SELECT language, COUNT(*) as count FROM messages GROUP BY language");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "Language: " . $row['language'] . " - Count: " . $row['count'] . "\n";
}
$stmt = $pdo->query("SELECT sm.message, m.language, m.translation FROM source_messages sm JOIN messages m ON sm.id = m.id LIMIT 5");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "Msg: " . substr($row['message'], 0, 20) . " | Lang: " . $row['language'] . " | Trans: " . substr($row['translation'], 0, 20) . "\n";
}
