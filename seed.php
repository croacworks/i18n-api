<?php

$dbFile = __DIR__ . '/database.sqlite';
$needsInit = !file_exists($dbFile);

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');

if ($needsInit) {
    try {
        $pdo->exec("CREATE TABLE source_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT NOT NULL,
            message TEXT NOT NULL,
            UNIQUE(category, message)
        )");
        
        $pdo->exec("CREATE TABLE messages (
            id INTEGER NOT NULL,
            language TEXT NOT NULL,
            translation TEXT,
            PRIMARY KEY(id, language),
            FOREIGN KEY(id) REFERENCES source_messages(id) ON DELETE CASCADE
        )");
        echo "Database initialized.\n";
    } catch (Exception $e) {
        echo "Error initializing database: " . $e->getMessage() . "\n";
    }
}

$csvFiles = glob(__DIR__ . '/*.csv');

foreach ($csvFiles as $csvFile) {
    $filename = basename($csvFile);
    echo "Processing $filename...\n";
    
    if (($handle = fopen($csvFile, "r")) !== FALSE) {
        $header = fgetcsv($handle, 0, ",", "\"", "\\");
        if (!$header) continue;
        
        $langCols = [];
        foreach ($header as $index => $colName) {
            if (strpos($colName, 'translation_') === 0) {
                $lang = str_replace('translation_', '', $colName);
                $lang = str_replace('_', '-', $lang); // Normalize to pt-BR standard
                $langCols[$index] = $lang;
            }
        }
        
        $catIdx = array_search('category', $header);
        $msgIdx = array_search('message', $header);
        
        if ($catIdx === false || $msgIdx === false || empty($langCols)) {
            echo "  Warning: Skipping $filename: missing 'category', 'message' or 'translation_*' columns.\n";
            fclose($handle);
            continue;
        }

        echo "  Found languages: " . implode(", ", $langCols) . "\n";

        $pdo->beginTransaction();
        
        $stmtFind = $pdo->prepare("SELECT id FROM source_messages WHERE category = :category AND message = :message");
        $stmtInsertSrc = $pdo->prepare("INSERT INTO source_messages (category, message) VALUES (:category, :message)");
        
        $stmtInsertMsg = $pdo->prepare("INSERT INTO messages (id, language, translation) VALUES (:id, :lang, :trans)
                                        ON CONFLICT(id, language) DO UPDATE SET translation = excluded.translation");
        
        $count = 0;
        $totalTrans = 0;
        while (($data = fgetcsv($handle, 0, ",", "\"", "\\")) !== FALSE) {
            $category = $data[$catIdx] ?? '';
            $message = $data[$msgIdx] ?? '';
            
            if (!$category || !$message) continue;
            
            $stmtFind->execute(['category' => $category, 'message' => $message]);
            $id = $stmtFind->fetchColumn();
            
            if (!$id) {
                $stmtInsertSrc->execute(['category' => $category, 'message' => $message]);
                $id = $pdo->lastInsertId();
            }
            
            foreach ($langCols as $idx => $lang) {
                $trans = $data[$idx] ?? '';
                if ($trans !== '') {
                    $stmtInsertMsg->execute(['id' => $id, 'lang' => $lang, 'trans' => $trans]);
                    $totalTrans++;
                }
            }
            $count++;
        }
        $pdo->commit();
        echo "  Success: Seeded $count messages and $totalTrans translations from $filename.\n";
        fclose($handle);
    }
}

echo "Seeding process complete.\n";
