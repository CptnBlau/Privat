<?php
header('Content-Type: application/json');

// Fehler im Live-Betrieb unterdrücken
ini_set('display_errors', 0);
error_reporting(E_ALL);

date_default_timezone_set('Europe/Berlin');

// --- DATENBANK ---
$host = 'localhost';
$db   = 'familybase';
$user = 'admin';
$pass = 'password';
$default_ntfy_topic = "ntfy_topic"; 

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Datenbankverbindung fehlgeschlagen']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

// --- HELPER ---
function sendNtfy($topic, $title, $message, $priority = 'default') {
    if (empty($topic) || !function_exists('curl_init')) return false;
    $url = "https://ntfy.sh/" . trim($topic);
    $headers = ["Title: " . $title, "Priority: " . $priority, "Tags: rocket,calendar"];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
    return true;
}

function processExperience($pdo, $habit_id) {
    try {
        $stmt = $pdo->prepare("SELECT difficulty, area_id, type, name FROM habits WHERE id = ?");
        $stmt->execute([$habit_id]);
        $habit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$habit) return null;

        $xp_map = [1 => 10, 2 => 25, 3 => 50, 4 => 100];
        $xp = $xp_map[$habit['difficulty'] ?? 1] ?? 10;
        if (($habit['type'] ?? '') === 'task') $xp *= 2; 

        if (!empty($habit['area_id'])) {
            $pdo->prepare("UPDATE areas SET xp = xp + ? WHERE id = ?")->execute([$xp, $habit['area_id']]);
            $pdo->prepare("UPDATE areas SET level = FLOOR(xp / 200) + 1 WHERE id = ?")->execute([$habit['area_id']]);
        }
        
        return ['xp' => $xp, 'name' => $habit['name']];
    } catch (Exception $e) {
        return ['xp' => 0, 'name' => 'Error']; 
    }
}

try {
    // 1. TOGGLE
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $completed = (int)($_POST['completed'] ?? 0);
        $today = date('Y-m-d');
        $date = $_POST['date'] ?? $today; 

        if ($completed) {
            $check = $pdo->prepare("SELECT 1 FROM completions WHERE habit_id = ? AND completed_at = ?");
            $check->execute([$id, $date]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Bereits erledigt!']);
                exit;
            }
            $pdo->prepare("INSERT INTO completions (habit_id, completed_at) VALUES (?, ?)")->execute([$id, $date]);
            $reward = processExperience($pdo, $id);
            echo json_encode(['success' => true, 'reward' => $reward]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Nicht möglich.']);
        }
    }

    // 2. ADD HABIT/TASK (Mit Datum!)
    elseif ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? 'habit';
        $area_id = (int)($_POST['area_id'] ?? 1);
        $difficulty = (int)($_POST['difficulty'] ?? 1);
        $rep_type = $_POST['repetition_type'] ?? 'daily';
        $rep_days = $_POST['repetition_days'] ?? '1,2,3,4,5,6,7';
        $due = $_POST['due_time'] ?? '20:00';
        $ntfy = trim($_POST['ntfy_topic'] ?? $default_ntfy_topic);
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;

        if ($name) {
            $sql = "INSERT INTO habits (name, type, due_time, due_date, grace_minutes, ntfy_topic, area_id, difficulty, repetition_type, repetition_days) 
                    VALUES (?, ?, ?, ?, 60, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$name, $type, $due, $due_date, $ntfy, $area_id, $difficulty, $rep_type, $rep_days]);
            echo json_encode(['success' => true]);
        }
    }

    // 3. DIARY
    elseif ($action === 'add_diary') {
        $params = [
            ':date' => $_POST['entry_date'] ?: date('Y-m-d'),
            ':self' => trim($_POST['for_self'] ?? ''),
            ':others' => trim($_POST['for_others'] ?? ''),
            ':refl' => trim($_POST['reflection_text'] ?? ''),
            ':type' => $_POST['reflection_type'] ?? 'neutral',
            ':aid' => (int)($_POST['area_id'] ?? 0)
        ];

        if ($params[':aid'] > 0) {
            $sql = "INSERT INTO diary (entry_date, for_self, for_others, reflection_text, reflection_type, area_id) 
                    VALUES (:date, :self, :others, :refl, :type, :aid)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $bonus = 20;
            if ($params[':type'] === 'growth' || $params[':type'] === 'conflict') $bonus = 35;
            elseif ($params[':type'] === 'avoidance') $bonus = 25;

            $pdo->prepare("UPDATE areas SET xp = xp + ? WHERE id = ?")->execute([$bonus, $params[':aid']]);
            $pdo->prepare("UPDATE areas SET level = FLOOR(xp / 200) + 1 WHERE id = ?")->execute([$params[':aid']]);
            
            echo json_encode(['success' => true, 'xp' => $bonus]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Kein Bereich gewählt']);
        }
    }

    // 4. ADD AREA
    elseif ($action === 'add_area') {
        $name = trim($_POST['name'] ?? '');
        if ($name) $pdo->prepare("INSERT INTO areas (name) VALUES (?)")->execute([$name]);
        echo json_encode(['success' => true]);
    }

    // 5. DELETE
    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM completions WHERE habit_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM habits WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    }

    // 6. GET STATS
    elseif ($action === 'get_area_stats') {
        $aid = (int)($_GET['area_id'] ?? 0);
        
        $items = $pdo->prepare("SELECT * FROM habits WHERE area_id = ? AND is_archived = 0 ORDER BY type DESC");
        $items->execute([$aid]);
        
        $sql = "SELECT date, SUM(cnt) as count FROM (
                    SELECT c.completed_at as date, COUNT(*) as cnt FROM completions c JOIN habits h ON c.habit_id = h.id WHERE h.area_id = :aid AND c.completed_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) GROUP BY c.completed_at
                    UNION ALL
                    SELECT entry_date as date, COUNT(*) as cnt FROM diary WHERE area_id = :aid AND entry_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) GROUP BY entry_date
                ) combined GROUP BY date";
        $act = $pdo->prepare($sql);
        $act->execute(['aid' => $aid]);
        
        $area = $pdo->prepare("SELECT * FROM areas WHERE id = ?");
        $area->execute([$aid]);
        
        $diary = $pdo->prepare("SELECT * FROM diary WHERE area_id = ? ORDER BY entry_date DESC LIMIT 30");
        $diary->execute([$aid]);

        echo json_encode([
            'success' => true,
            'area' => $area->fetch(PDO::FETCH_ASSOC),
            'items' => $items->fetchAll(PDO::FETCH_ASSOC),
            'activity' => $act->fetchAll(PDO::FETCH_ASSOC), 
            'diary' => $diary->fetchAll(PDO::FETCH_ASSOC)
        ]);
    }

    // 7. GET COMPLETE HISTORY
    elseif ($action === 'get_history') {
        $text_only = (int)($_GET['text_only'] ?? 0);
        
        $sql = "SELECT d.entry_date as date, 
                       COALESCE(d.reflection_text, '') as reflection_text, 
                       COALESCE(d.for_self, '') as for_self, 
                       COALESCE(d.for_others, '') as for_others, 
                       COALESCE(d.reflection_type, 'neutral') as reflection_type, 
                       a.name as area_name, 
                       'diary' as source 
                FROM diary d JOIN areas a ON d.area_id = a.id";
        
        if (!$text_only) {
            $sql .= " UNION ALL 
                      SELECT c.completed_at as date, 
                             h.name as reflection_text, 
                             '' as for_self, 
                             '' as for_others, 
                             'completed' as reflection_type, 
                             a.name as area_name, 
                             'task' as source
                      FROM completions c 
                      JOIN habits h ON c.habit_id = h.id 
                      JOIN areas a ON h.area_id = a.id";
        }
        $sql .= " ORDER BY date DESC LIMIT 100";
        $stmt = $pdo->query($sql);
        echo json_encode(['success' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // 8. CRONJOB
    elseif ($action === 'cron') {
        $now_time = date('H:i:00');
        $today_date = date('Y-m-d');
        $day_index = date('N'); 
        $dom = date('j');
        $count = 0;

        $stmt = $pdo->prepare("SELECT * FROM habits WHERE due_time = ? AND is_archived = 0");
        $stmt->execute([$now_time]);
        
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $notify = false;
            
            if ($t['type'] === 'task') {
                if ($t['due_date'] === $today_date) $notify = true;
            } else {
                switch ($t['repetition_type']) {
                    case 'daily': $notify = true; break;
                    case 'workdays': if ($day_index <= 5) $notify = true; break;
                    case 'monthly': 
                        $target_dom = (int)($t['repetition_days'] ?? 1);
                        if ($dom == $target_dom) $notify = true; 
                        break;
                    case 'custom':
                    case 'weekly':
                        if (in_array($day_index, explode(',', $t['repetition_days']))) $notify = true;
                        break;
                }
            }
            if ($notify) {
                sendNtfy($t['ntfy_topic'], "Aufgabe fällig: " . $t['name'], "Zeit anzugreifen!", "high");
                $count++;
            }
        }
        echo json_encode(['status' => 'cron_ok', 'sent' => $count]);
    }

} catch (Exception $e) {
    http_response_code(500); 
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
