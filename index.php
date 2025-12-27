<?php
// Fehler anzeigen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Datenbank
$host = 'localhost'; $db = 'familybase'; $user = 'admin'; $pass = 'Password';
try { 
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass); 
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
} catch (PDOException $e) { 
    die("Datenbankfehler: " . $e->getMessage()); 
}

// --- DATUMS LOGIK ---
$view_date = $_GET['date'] ?? date('Y-m-d');
// Validierung: Wenn Datum ungültig, nimm heute
if (!strtotime($view_date)) {
    $view_date = date('Y-m-d');
}
$view_time = strtotime($view_date);

$prev_date = date('Y-m-d', strtotime('-1 day', $view_time));
$next_date = date('Y-m-d', strtotime('+1 day', $view_time));
$display_date = date('d.m.Y', $view_time);

// Indizes
$day_index = (int)date('N', $view_time); 
$dom = (int)date('j', $view_time);

// Wochentag
$days_de = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
$day_name = $days_de[(int)date('w', $view_time)];

// Bereiche
$areas = $pdo->query("SELECT * FROM areas ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Aufgaben
$stmt = $pdo->prepare("
    SELECT h.*, a.name as area_name, 
    (SELECT COUNT(*) FROM completions c WHERE c.habit_id = h.id AND c.completed_at = :vdate) as is_done 
    FROM habits h 
    JOIN areas a ON h.area_id = a.id 
    WHERE h.is_archived = 0 
    ORDER BY is_done ASC, h.type DESC, h.id DESC
");
$stmt->execute(['vdate' => $view_date]); 
$all_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filterlogik
$items = array_filter($all_items, function($item) use ($day_index, $dom, $view_date) {
    // 1. Task (Einmalig)
    if ($item['type'] === 'task') {
        // Zeigen, wenn Datum stimmt ODER wenn Datum leer ist (Fallback)
        if (empty($item['due_date'])) return true;
        return $item['due_date'] === $view_date;
    }
    // 2. Habit (Wiederkehrend)
    if ($item['repetition_type'] === 'daily') return true;
    if ($item['repetition_type'] === 'workdays' && $day_index <= 5) return true;
    if ($item['repetition_type'] === 'monthly') {
        $target = (int)($item['repetition_days'] ?? 1);
        return $dom == $target;
    }
    // Weekly/Custom
    $days = explode(',', $item['repetition_days'] ?? '1,2,3,4,5,6,7'); 
    return in_array($day_index, $days);
});

// Tagebuch
$diary_stmt = $pdo->prepare("SELECT d.*, a.name as area_name FROM diary d JOIN areas a ON d.area_id = a.id WHERE d.entry_date = ?");
$diary_stmt->execute([$view_date]);
$diary_entries = $diary_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>RPG Habit Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --primary: #6366f1; --secondary: #ec4899; --bg: #f8fafc; --success: #22c55e; --card: #ffffff; --text: #1e293b; --subtext: #64748b; }
        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); margin: 0; padding: 10px; color: var(--text); padding-bottom: 70px; }
        .container { max-width: 600px; margin: 0 auto; }
        
        .date-nav { display: flex; justify-content: space-between; align-items: center; background: white; padding: 12px 20px; border-radius: 16px; margin-bottom: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .date-nav a { text-decoration: none; color: var(--primary); font-size: 1.3rem; font-weight: bold; padding: 5px 15px; border-radius: 8px; }
        .date-display { text-align: center; }
        .date-main { font-weight: 800; font-size: 1.1rem; }
        .date-sub { font-size: 0.8rem; color: var(--subtext); font-weight: normal; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); gap: 10px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 12px; border-radius: 14px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.03); cursor: pointer; transition: transform 0.2s; border-bottom: 3px solid var(--primary); }
        .stat-card:active { transform: scale(0.96); }
        .stat-name { font-size: 0.65rem; text-transform: uppercase; color: var(--subtext); font-weight: bold; display: block; letter-spacing: 0.5px; }
        .stat-val { font-weight: 800; font-size: 1.1rem; color: var(--primary); margin: 2px 0; display:block;}
        .xp-bar { height: 5px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-top: 5px; }
        .xp-fill { height: 100%; background: var(--primary); transition: width 0.5s; }

        .tab-nav { display: flex; gap: 8px; margin-bottom: 20px; background: #fff; padding: 6px; border-radius: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.03); overflow-x: auto; }
        .tab-nav button { flex: 1; padding: 10px; border: none; background: none; border-radius: 10px; font-weight: 600; cursor: pointer; color: var(--subtext); font-size: 0.9rem; transition: 0.2s; white-space: nowrap; }
        .tab-nav button.active { background: var(--primary); color: white; box-shadow: 0 2px 4px rgba(99,102,241,0.3); }

        .section { display: none; animation: fadeIn 0.3s ease-out; }
        .section.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .add-box { background: white; padding: 20px; border-radius: 20px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); margin-bottom: 25px; }
        h3 { margin-top: 0; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; color: #334155; margin-bottom: 15px; }
        .input-group { display: flex; flex-direction: column; gap: 12px; }
        input, select, textarea { padding: 12px 15px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 0.95rem; font-family: inherit; outline: none; width: 100%; box-sizing: border-box; background: #f8fafc; transition: border 0.2s; }
        .row { display: flex; gap: 10px; }
        .btn-main { background: var(--primary); color: white; border: none; padding: 14px; border-radius: 12px; font-weight: bold; cursor: pointer; width: 100%; }
        .btn-secondary { background: var(--secondary); }

        .card { background: white; padding: 15px 18px; border-radius: 16px; display: flex; align-items: center; gap: 15px; margin-bottom: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); border-left: 5px solid #cbd5e1; position: relative; }
        .card.done { opacity: 0.6; border-left-color: var(--success) !important; filter: grayscale(0.2); background: #f8fafc; }
        .card.type-task { border-left-color: #f59e0b; }
        .card.type-habit { border-left-color: var(--primary); }
        .info { flex-grow: 1; overflow: hidden; }
        .name { font-weight: 700; font-size: 1rem; display: block; margin-bottom: 4px; color: #334155; }
        .badge { font-size: 0.7rem; padding: 3px 8px; border-radius: 6px; background: #f1f5f9; font-weight: 600; color: var(--subtext); border: 1px solid #e2e8f0; }
        
        .check-container { position: relative; width: 28px; height: 28px; flex-shrink: 0; }
        input[type="checkbox"] { width: 100%; height: 100%; margin: 0; cursor: pointer; accent-color: var(--success); opacity: 0; position: absolute; z-index: 2; }
        .custom-check { width: 100%; height: 100%; border: 2px solid #cbd5e1; border-radius: 8px; position: absolute; top: 0; left: 0; z-index: 1; display: flex; align-items: center; justify-content: center; color: white; transition: 0.2s; font-size: 1rem; }
        input:checked + .custom-check { background: var(--success); border-color: var(--success); }
        input:checked + .custom-check::after { content: '✓'; font-weight: bold; }

        .history-item { background: white; border-radius: 12px; padding: 15px; margin-bottom: 12px; border-left: 4px solid #cbd5e1; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .h-type-diary { border-left-color: var(--secondary); }
        .h-type-task { border-left-color: var(--success); }
        
        .delete-btn { background:none; border:none; color:#cbd5e1; font-size:1.4rem; padding:0 5px; cursor:pointer; }
        .diary-item-mini { font-size: 0.85rem; border-left: 3px solid var(--secondary); padding-left: 10px; margin-bottom: 12px; }
        
        .overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); backdrop-filter: blur(2px); z-index: 100; display: none; align-items: flex-end; }
        .modal { background: white; width: 100%; max-width: 550px; margin: 0 auto; border-radius: 25px 25px 0 0; padding: 25px; box-sizing: border-box; max-height: 85vh; overflow-y: auto; transform: translateY(100%); transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1); }
        .overlay.active { display: flex; } .overlay.active .modal { transform: translateY(0); }
        .heatmap-container { overflow-x: auto; padding: 10px 0; display: flex; gap: 3px; margin-bottom: 20px; }
        .heatmap-week { display: grid; grid-template-rows: repeat(7, 10px); gap: 3px; }
        .heatmap-day { width: 10px; height: 10px; border-radius: 2px; background: #e2e8f0; }
        .level-1 { background: #c7d2fe; } .level-2 { background: #818cf8; } .level-3 { background: #4f46e5; } .level-4 { background: #312e81; }
        .reflection-tag { display: inline-block; font-size: 0.65rem; padding: 2px 6px; border-radius: 4px; margin-bottom: 5px; font-weight: bold; text-transform: uppercase; }
        .type-growth { background: #dcfce7; color: #166534; } .type-conflict { background: #fee2e2; color: #991b1b; } .type-avoidance { background: #fef9c3; color: #854d0e; } .type-neutral { background: #f1f5f9; color: #475569; }
    </style>
</head>
<body>

<div id="reward" style="position:fixed; top:20px; left:50%; transform:translateX(-50%); background:#1e293b; color:white; padding:15px 25px; border-radius:50px; z-index:200; display:none; box-shadow:0 10px 20px rgba(0,0,0,0.2);">XP erhalten! 🏆</div>

<!-- DETAIL MODAL -->
<div id="areaOverlay" class="overlay" onclick="closeAreaDetails(event)">
    <div class="modal" onclick="event.stopPropagation()">
        <h2 id="modalTitle" style="margin-top:0">Bereich</h2>
        <div id="modalStats">
            <span id="modalLevel" class="badge">Lvl 1</span>
            <div class="xp-bar" style="margin: 15px 0;"><div id="modalXpFill" class="xp-fill"></div></div>
        </div>
        <h4>Heatmap (1 Jahr)</h4>
        <div id="heatmap" class="heatmap-container"></div>
        <h4>Reflexions-Verlauf</h4>
        <div id="modalDiary"></div>
        <h4>Aufgaben</h4>
        <div id="modalItems"></div>
        <button class="btn-main" style="background:#f1f5f9; color:#475569; margin-top:20px" onclick="closeAreaDetails()">Schließen</button>
    </div>
</div>

<div class="container">
    <div class="date-nav">
        <a href="?date=<?= $prev_date ?>"><i class="fa fa-chevron-left"></i></a>
        <div class="date-display">
            <div class="date-main"><?= $display_date ?></div>
            <div class="date-sub"><?= ($view_date == date('Y-m-d')) ? 'Heute' : $day_name ?></div>
        </div>
        <a href="?date=<?= $next_date ?>"><i class="fa fa-chevron-right"></i></a>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <?php foreach($areas as $area) { ?>
        <div class="stat-card" onclick="openAreaDetails(<?= $area['id'] ?>)">
            <span class="stat-name"><?= htmlspecialchars($area['name']) ?></span>
            <span class="stat-val">Lvl <?= $area['level'] ?></span>
            <div class="xp-bar"><div class="xp-fill" style="width: <?= ($area['xp'] % 200) / 2 ?>%"></div></div>
        </div>
        <?php } ?>
    </div>

    <!-- NAV -->
    <div class="tab-nav">
        <button onclick="showTab('tasks')" id="btn-tasks" class="active"><i class="fa fa-list-check"></i> Aufgaben</button>
        <button onclick="showTab('diary')" id="btn-diary"><i class="fa fa-book-open"></i> Diary</button>
        <button onclick="showTab('history')" id="btn-history" onclick="loadHistory()"><i class="fa fa-clock-rotate-left"></i> Historie</button>
        <button onclick="window.location.href='finance.php'" style="color: #10b981;"><i class="fa fa-wallet"></i> Finanzen</button>
        <button onclick="showTab('setup')" id="btn-setup"><i class="fa fa-gear"></i> Setup</button>
    </div>

    <!-- AUFGABEN -->
    <div id="section-tasks" class="section active">
        <div class="add-box">
            <h3 style="margin-top:0">Neue Herausforderung</h3>
            <div class="input-group">
                <input type="text" id="n_task" placeholder="Was willst du tun?">
                <div class="row">
                    <select id="n_type" style="flex:1;" onchange="toggleRepetitionFields()">
                        <option value="habit">Habit</option>
                        <option value="task">Einmal-Task</option>
                    </select>
                    <select id="n_diff" style="flex:1;">
                        <option value="1">Leicht</option>
                        <option value="2">Mittel</option>
                        <option value="3">Schwer</option>
                        <option value="4">Heroisch</option>
                    </select>
                </div>
                <div class="row">
                    <select id="n_area" style="flex:1;">
                        <?php foreach($areas as $a) { ?> <option value="<?= $a['id'] ?>"><?= $a['name'] ?></option> <?php } ?>
                    </select>
                    <select id="n_rep" style="flex:1;" onchange="toggleRepetitionFields()">
                        <option value="daily">Täglich</option>
                        <option value="workdays">Mo-Fr</option>
                        <option value="weekly">Wöchentlich</option>
                        <option value="monthly">Monatlich</option>
                    </select>
                </div>
                
                <div id="extra-fields" style="display:none; background:#f1f5f9; padding:10px; border-radius:8px;">
                    <div id="field-date" style="display:none;">
                        <label style="font-size:0.7rem; font-weight:bold;">Datum:</label>
                        <input type="date" id="n_due_date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div id="field-month-day" style="display:none;">
                        <label style="font-size:0.7rem; font-weight:bold;">Tag (1-31):</label>
                        <select id="n_month_day">
                            <?php for($i=1; $i<=31; $i++) { ?> <option value="<?= $i ?>" <?= $i==1?'selected':'' ?>><?= $i ?>.</option> <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="row" style="align-items: center;">
                    <span style="font-size: 0.85rem; color: #64748b;">Zeit:</span>
                    <input type="time" id="n_time" value="20:00" style="flex:1;">
                    <button class="btn-main" onclick="addTask()" style="flex:1.5;"><i class="fa fa-save"></i> Erstellen</button>
                </div>
            </div>
        </div>

        <div id="list">
            <?php foreach ($items as $i) { ?>
                <div class="card type-<?= $i['type'] ?> <?= $i['is_done'] ? 'done' : '' ?>">
                    <div class="check-container">
                        <input type="checkbox" onchange="toggle(<?= $i['id'] ?>, 1)" <?= $i['is_done'] ? 'checked disabled' : '' ?>>
                        <div class="custom-check"></div>
                    </div>
                    <div class="info">
                        <span class="name"><?= htmlspecialchars($i['name']) ?></span>
                        <div class="meta">
                            <span class="badge"><?= $i['area_name'] ?></span>
                            <?php if($i['type'] === 'task') { ?>
                                <span class="badge" style="background:#fef3c7;color:#92400e"><?= !empty($i['due_date']) ? date('d.m.', strtotime($i['due_date'])) : '' ?></span>
                            <?php } ?>
                            <?php if($i['repetition_type'] === 'monthly') { ?>
                                <span class="badge" style="background:#e0e7ff;color:#3730a3">Am <?= $i['repetition_days'] ?>.</span>
                            <?php } ?>
                            <span style="font-size:0.7rem; color:#64748b">⏰ <?= substr($i['due_time'], 0, 5) ?></span>
                        </div>
                    </div>
                    <button class="delete-btn" onclick="deleteItem(<?= $i['id'] ?>)">&times;</button>
                </div>
            <?php } ?>
            <?php if(empty($items)) { ?>
                <div style="text-align:center; padding:40px; color:#94a3b8;">Keine Aufgaben für diesen Tag.</div>
            <?php } ?>
        </div>
    </div>

    <!-- DIARY -->
    <div id="section-diary" class="section">
        <div class="add-box">
            <h3 style="margin-top:0">Tagebuch & Reflexion</h3>
            <div class="input-group">
                <div class="row" style="align-items:center; background:#f1f5f9; padding:10px; border-radius:12px;">
                    <i class="fa fa-calendar" style="color:var(--subtext)"></i>
                    <span style="font-weight:bold; font-size:0.9rem; margin-left:5px;">Eintrag für: <?= $display_date ?></span>
                    <input type="hidden" id="d_date" value="<?= $view_date ?>">
                </div>
                <div class="row">
                    <select id="d_area" style="flex:1;">
                        <option value="">Bereich wählen...</option>
                        <?php foreach($areas as $a) { ?> <option value="<?= $a['id'] ?>"><?= $a['name'] ?></option> <?php } ?>
                    </select>
                </div>
                <select id="d_refl_type" onchange="updatePlaceholder()">
                    <option value="neutral">Allgemein (Neutral)</option>
                    <option value="growth">Wachstum (+35 XP)</option>
                    <option value="conflict">Konfliktlösung (+35 XP)</option>
                    <option value="avoidance">Vermeidung (+25 XP)</option>
                </select>
                <textarea id="d_refl_text" placeholder="Was ist heute passiert?"></textarea>
                <div class="row">
                    <textarea id="d_self" style="flex:1;" placeholder="Für MICH getan..."></textarea>
                    <textarea id="d_others" style="flex:1;" placeholder="Für ANDERE getan..."></textarea>
                </div>
                <button class="btn-main btn-secondary" onclick="saveDiary()">Eintrag speichern</button>
            </div>
        </div>
        <div id="diary-history">
            <?php foreach($diary_entries as $entry) { ?>
                <div class="history-item h-type-diary">
                    <span class="badge" style="margin-bottom:8px;"><?= htmlspecialchars($entry['area_name']) ?></span>
                    <span class="reflection-tag type-<?= htmlspecialchars($entry['reflection_type'] ?? 'neutral') ?>"><?= htmlspecialchars($entry['reflection_type'] ?? 'neutral') ?></span>
                    <div style="font-size:0.95rem; margin:8px 0; font-weight:500;"><?= nl2br(htmlspecialchars($entry['reflection_text'] ?? '')) ?></div>
                    <div style="font-size:0.85rem; color:#64748b; background:#f8fafc; padding:8px; border-radius:8px;">
                        <div><strong>Ich:</strong> <?= htmlspecialchars($entry['for_self'] ?? '') ?></div>
                        <div style="margin-top:4px;"><strong>Andere:</strong> <?= htmlspecialchars($entry['for_others'] ?? '') ?></div>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>

    <!-- HISTORY -->
    <div id="section-history" class="section">
        <div style="background:white; padding:15px; border-radius:16px; margin-bottom:20px; display:flex; align-items:center; gap:10px;">
            <input type="checkbox" id="hist_filter_text_only" onchange="loadHistory()" style="width:20px; height:20px;">
            <label for="hist_filter_text_only" style="font-weight:bold; color:#334155;">Nur Texte (Tagebuch) anzeigen</label>
        </div>
        <div id="full-history-list">
            <p style="text-align:center; color:#94a3b8;">Lade Historie...</p>
        </div>
    </div>

    <!-- SETUP -->
    <div id="section-setup" class="section">
        <div class="add-box">
            <h3>Neuer Bereich</h3>
            <div class="input-group">
                <input type="text" id="n_area_name" placeholder="Name (z.B. Sport)">
                <button class="btn-main" onclick="addArea()">Anlegen</button>
            </div>
        </div>
    </div>
</div>

<script>
    const CURRENT_VIEW_DATE = "<?= $view_date ?>";

    function toggleRepetitionFields() {
        const type = document.getElementById('n_type').value;
        const rep = document.getElementById('n_rep').value;
        const container = document.getElementById('extra-fields');
        const fieldDate = document.getElementById('field-date');
        const fieldMonth = document.getElementById('field-month-day');
        const repSelect = document.getElementById('n_rep');

        container.style.display = 'none';
        fieldDate.style.display = 'none';
        fieldMonth.style.display = 'none';
        repSelect.disabled = false; repSelect.style.opacity = '1';

        if (type === 'task') {
            repSelect.disabled = true; repSelect.style.opacity = '0.5';
            container.style.display = 'block'; fieldDate.style.display = 'block';
        } else if (rep === 'monthly') {
            container.style.display = 'block'; fieldMonth.style.display = 'block';
        }
    }
    // Init call
    toggleRepetitionFields();

    function updatePlaceholder() {
        const map = {
            neutral: "Was ist heute passiert?",
            growth: "Wo bin ich heute über mich hinausgewachsen?",
            conflict: "Wie habe ich ein Problem gelöst?",
            avoidance: "Wovor habe ich mich gedrückt? (Ehrlichkeit lohnt sich)"
        };
        const el = document.getElementById('d_refl_text');
        if(el) el.placeholder = map[document.getElementById('d_refl_type').value];
    }

    function showTab(t) {
        document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
        document.querySelectorAll('.tab-nav button').forEach(b => b.classList.remove('active'));
        document.getElementById('section-'+t).classList.add('active');
        document.getElementById('btn-'+t).classList.add('active');
        if(t === 'history') loadHistory();
    }

    async function loadHistory() {
        const textOnly = document.getElementById('hist_filter_text_only').checked ? 1 : 0;
        try {
            const res = await fetch(`api.php?action=get_history&text_only=${textOnly}`);
            const data = await res.json();
            const container = document.getElementById('full-history-list');
            if(!data.success || data.history.length === 0) {
                container.innerHTML = '<p style="text-align:center; color:#94a3b8; padding:20px;">Keine Einträge.</p>';
                return;
            }
            container.innerHTML = data.history.map(h => {
                if(h.source === 'diary') {
                    return `<div class="history-item h-type-diary">
                        <span class="h-date"><i class="fa fa-book"></i> ${h.date} | ${h.area_name}</span>
                        <div style="font-weight:bold; margin-bottom:5px;">${h.reflection_type}</div>
                        <div class="h-content">${h.reflection_text || '<i>Kein Text</i>'}</div>
                        ${h.for_self ? `<div style="margin-top:5px; font-size:0.85rem; color:#64748b"><b>Ich:</b> ${h.for_self}</div>` : ''}
                        ${h.for_others ? `<div style="margin-top:2px; font-size:0.85rem; color:#64748b"><b>Andere:</b> ${h.for_others}</div>` : ''}
                    </div>`;
                } else {
                    return `<div class="history-item h-type-task" style="display:flex; align-items:center; justify-content:space-between;">
                        <div><span class="h-date"><i class="fa fa-check-circle"></i> ${h.date} | ${h.area_name}</span><div style="font-weight:bold;">${h.reflection_text}</div></div><span class="badge" style="background:#dcfce7; color:#166534">+XP</span></div>`;
                }
            }).join('');
        } catch(e) { console.error(e); }
    }

    async function openAreaDetails(id) {
        try {
            const res = await fetch(`api.php?action=get_area_stats&area_id=${id}`);
            const data = await res.json();
            if(!data.success) { alert("Fehler beim Laden"); return; }

            document.getElementById('modalTitle').innerText = data.area.name;
            document.getElementById('modalLevel').innerText = `Level ${data.area.level}`;
            document.getElementById('modalXpFill').style.width = (data.area.xp % 200) / 2 + '%';
            
            const heatmap = document.getElementById('heatmap');
            heatmap.innerHTML = '';
            const today = new Date();
            const actMap = {};
            if(data.activity && Array.isArray(data.activity)) {
                data.activity.forEach(a => { actMap[a.date] = parseInt(a.count); });
            }

            for(let i=0; i<52; i++) {
                const week = document.createElement('div'); week.className = 'heatmap-week';
                for(let j=0; j<7; j++) {
                    const day = document.createElement('div'); day.className = 'heatmap-day';
                    const d = new Date(today); d.setDate(today.getDate() - (51 - i) * 7 + (j - today.getDay() + 1));
                    const iso = d.toISOString().split('T')[0];
                    const val = actMap[iso] || 0;
                    if(val > 0) day.classList.add('level-' + Math.min(val, 4));
                    week.appendChild(day);
                }
                heatmap.appendChild(week);
            }

            document.getElementById('modalDiary').innerHTML = data.diary.length ? data.diary.map(d => `
                <div class="diary-item-mini">
                    <span class="reflection-tag type-${d.reflection_type || 'neutral'}">${d.reflection_type || 'neutral'}</span>
                    <span class="badge" style="font-size:0.6rem; float:right;">${d.entry_date}</span>
                    <div style="margin-top:5px"><b>Reflexion:</b> ${d.reflection_text || '---'}</div>
                    <div style="color:#64748b; font-size:0.75rem; margin-top:2px;">Ich: ${d.for_self || ''} | Andere: ${d.for_others || ''}</div>
                </div>
            `).join('') : '<p style="font-size:0.8rem;color:#94a3b8">Leer.</p>';

            document.getElementById('modalItems').innerHTML = data.items.map(i => `
                <div class="card" style="font-size:0.85rem; padding:10px;"><b>${i.name}</b> <span style="float:right; opacity:0.5">${i.type}</span></div>
            `).join('') || '<p style="font-size:0.8rem;color:#94a3b8">Keine Aufgaben.</p>';

            document.getElementById('areaOverlay').classList.add('active');
        } catch(e) { console.error(e); }
    }

    function closeAreaDetails(e) { 
        if(e && e.target !== document.getElementById('areaOverlay')) return;
        document.getElementById('areaOverlay').classList.remove('active'); 
    }

    function toggle(id, completed) {
        if(!completed) return;
        fetch('api.php', { method: 'POST', body: new URLSearchParams({action:'toggle', id:id, completed:1, date: CURRENT_VIEW_DATE}) })
        .then(res => res.json()).then(d => {
            if(d.success) {
                document.getElementById('reward').style.display='block';
                setTimeout(() => location.reload(), 1200);
            } else alert(d.error);
        });
    }

    function saveDiary() {
        const areaId = document.getElementById('d_area').value;
        if(!areaId) return alert("Bereich wählen!");
        const params = new URLSearchParams({
            action: 'add_diary',
            for_self: document.getElementById('d_self').value,
            for_others: document.getElementById('d_others').value,
            area_id: areaId,
            entry_date: document.getElementById('d_date').value,
            reflection_type: document.getElementById('d_refl_type').value,
            reflection_text: document.getElementById('d_refl_text').value
        });
        fetch('api.php', {method:'POST', body:params}).then(()=>location.reload());
    }

    function addTask() {
        if(!document.getElementById('n_task').value) return alert("Name fehlt!");
        const type = document.getElementById('n_type').value;
        const rep = document.getElementById('n_rep').value;
        let repDays = '1,2,3,4,5,6,7';
        let dueDate = '';
        if(type === 'task') dueDate = document.getElementById('n_due_date').value;
        else if(rep === 'monthly') repDays = document.getElementById('n_month_day').value;

        const params = new URLSearchParams({
            action: 'add',
            name: document.getElementById('n_task').value,
            type: type, difficulty: document.getElementById('n_diff').value,
            area_id: document.getElementById('n_area').value,
            repetition_type: type === 'task' ? 'custom' : rep,
            repetition_days: repDays, due_date: dueDate,
            due_time: document.getElementById('n_time').value
        });
        fetch('api.php', {method:'POST', body:params}).then(()=>location.reload());
    }

    function addArea() {
        if(!document.getElementById('n_area_name').value) return;
        fetch('api.php', {method:'POST', body:new URLSearchParams({action:'add_area', name:document.getElementById('n_area_name').value})}).then(()=>location.reload());
    }

    function deleteItem(id) {
        if(confirm('Löschen?')) fetch('api.php', {method:'POST', body:new URLSearchParams({action:'delete', id:id})}).then(()=>location.reload());
    }
</script>
</body>
</html>
