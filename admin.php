<?php
/**
 * Dental Survey Admin Dashboard
 */

require_once 'config.php';

$db = getDB();
$practice = getPractice();

// Simple auth check (enhance this for production!)
if (!isset($_SESSION['admin_user'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE username = ?");
        $stmt->execute([$_POST['username']]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($_POST['password'], $user['password_hash'])) {
            $_SESSION['admin_user'] = $user;
        } else {
            $loginError = 'Invalid username or password';
        }
    }
    
    if (!isset($_SESSION['admin_user'])) {
        // Show login form
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Admin Login - Dental Survey</title>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: 'Inter', sans-serif; background: #f3f4f6; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
                .login-box { background: white; padding: 48px; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
                h1 { font-size: 1.5rem; margin-bottom: 8px; color: #111827; }
                p { color: #6b7280; margin-bottom: 32px; }
                .form-group { margin-bottom: 20px; }
                label { display: block; font-size: 0.9rem; font-weight: 500; color: #374151; margin-bottom: 8px; }
                input { width: 100%; padding: 14px 16px; border: 2px solid #e5e7eb; border-radius: 10px; font-size: 1rem; }
                input:focus { outline: none; border-color: #2563eb; }
                button { width: 100%; padding: 14px; background: #2563eb; color: white; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer; }
                button:hover { background: #1d4ed8; }
                .error { background: #fef2f2; color: #dc2626; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h1>Admin Login</h1>
                <p>Sign in to access the survey dashboard</p>
                <?php if (isset($loginError)): ?>
                    <div class="error"><?= h($loginError) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="login" value="1">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" required autofocus>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required>
                    </div>
                    <button type="submit">Sign In</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_user']);
    header('Location: admin.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'get_dashboard_stats':
            // Total responses
            $totalResponses = $db->query("SELECT COUNT(*) FROM survey_responses WHERE is_complete = 1")->fetchColumn();
            
            // Today's responses
            $todayResponses = $db->query("SELECT COUNT(*) FROM survey_responses WHERE is_complete = 1 AND DATE(completed_at) = CURDATE()")->fetchColumn();
            
            // This week
            $weekResponses = $db->query("SELECT COUNT(*) FROM survey_responses WHERE is_complete = 1 AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
            
            // Average rating (from rating_5 questions)
            $avgRating = $db->query("
                SELECT AVG(sa.answer_numeric) 
                FROM survey_answers sa 
                JOIN survey_questions sq ON sa.question_id = sq.id 
                WHERE sq.question_type = 'rating_5' AND sa.answer_numeric IS NOT NULL
            ")->fetchColumn();
            
            // NPS Score
            $nps = $db->query("
                SELECT 
                    SUM(CASE WHEN answer_numeric >= 9 THEN 1 ELSE 0 END) as promoters,
                    SUM(CASE WHEN answer_numeric >= 7 AND answer_numeric <= 8 THEN 1 ELSE 0 END) as passives,
                    SUM(CASE WHEN answer_numeric <= 6 THEN 1 ELSE 0 END) as detractors,
                    COUNT(*) as total
                FROM survey_answers sa
                JOIN survey_questions sq ON sa.question_id = sq.id
                WHERE sq.question_type = 'nps' AND sa.answer_numeric IS NOT NULL
            ")->fetch();
            
            $npsScore = $nps['total'] > 0 
                ? round((($nps['promoters'] - $nps['detractors']) / $nps['total']) * 100) 
                : 0;
            
            echo json_encode([
                'total_responses' => (int)$totalResponses,
                'today_responses' => (int)$todayResponses,
                'week_responses' => (int)$weekResponses,
                'avg_rating' => $avgRating ? round($avgRating, 1) : 0,
                'nps_score' => $npsScore,
                'nps_promoters' => (int)$nps['promoters'],
                'nps_passives' => (int)$nps['passives'],
                'nps_detractors' => (int)$nps['detractors']
            ]);
            exit;
            
        case 'get_responses':
            $surveyId = $_POST['survey_id'] ?? null;
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            
            $where = "WHERE sr.is_complete = 1";
            $params = [];
            
            if ($surveyId) {
                $where .= " AND sr.survey_id = ?";
                $params[] = $surveyId;
            }
            
            $stmt = $db->prepare("
                SELECT sr.*, s.name as survey_name 
                FROM survey_responses sr 
                JOIN surveys s ON sr.survey_id = s.id 
                $where 
                ORDER BY sr.completed_at DESC 
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll());
            exit;
            
        case 'get_response_details':
            $responseId = $_POST['response_id'];
            
            $stmt = $db->prepare("
                SELECT sa.*, sq.question_text, sq.question_type, sq.options
                FROM survey_answers sa
                JOIN survey_questions sq ON sa.question_id = sq.id
                WHERE sa.response_id = ?
                ORDER BY sq.display_order
            ");
            $stmt->execute([$responseId]);
            $answers = $stmt->fetchAll();
            
            foreach ($answers as &$a) {
                if ($a['options']) $a['options'] = json_decode($a['options'], true);
                if ($a['answer_json']) $a['answer_json'] = json_decode($a['answer_json'], true);
            }
            
            echo json_encode($answers);
            exit;
            
        case 'get_surveys':
            $stmt = $db->query("
                SELECT s.*, 
                    (SELECT COUNT(*) FROM survey_responses WHERE survey_id = s.id AND is_complete = 1) as response_count
                FROM surveys s 
                ORDER BY s.name
            ");
            echo json_encode($stmt->fetchAll());
            exit;
            
        case 'get_chart_data':
            $days = (int)($_POST['days'] ?? 30);
            
            $stmt = $db->prepare("
                SELECT DATE(completed_at) as date, COUNT(*) as count
                FROM survey_responses
                WHERE is_complete = 1 AND completed_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                GROUP BY DATE(completed_at)
                ORDER BY date
            ");
            $stmt->execute([$days]);
            echo json_encode($stmt->fetchAll());
            exit;
            
        case 'get_rating_breakdown':
            $stmt = $db->query("
                SELECT sq.question_text, 
                    AVG(sa.answer_numeric) as avg_rating,
                    COUNT(*) as response_count
                FROM survey_answers sa
                JOIN survey_questions sq ON sa.question_id = sq.id
                WHERE sq.question_type = 'rating_5' AND sa.answer_numeric IS NOT NULL
                GROUP BY sq.id
                ORDER BY avg_rating DESC
            ");
            echo json_encode($stmt->fetchAll());
            exit;
    }
    exit;
}

$user = $_SESSION['admin_user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Survey Dashboard - <?= h($practice['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            line-height: 1.5;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 260px;
            background: var(--gray-900);
            padding: 24px;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 40px;
        }
        
        .sidebar-logo svg {
            width: 36px;
            height: 36px;
        }
        
        .sidebar-nav {
            flex: 1;
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--gray-400);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 4px;
        }
        
        .nav-item:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }
        
        .nav-item.active {
            background: var(--primary);
            color: white;
        }
        
        .nav-item svg {
            width: 20px;
            height: 20px;
        }
        
        .sidebar-footer {
            padding-top: 20px;
            border-top: 1px solid var(--gray-700);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            margin-bottom: 16px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        
        .user-name {
            font-weight: 600;
        }
        
        .user-role {
            font-size: 0.8rem;
            color: var(--gray-400);
        }
        
        .logout-btn {
            display: block;
            text-align: center;
            padding: 10px;
            background: rgba(255,255,255,0.05);
            color: var(--gray-400);
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 32px;
            min-height: 100vh;
        }
        
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--gray-900);
        }
        
        .page-subtitle {
            color: var(--gray-500);
            margin-top: 4px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--gray-900);
        }
        
        .stat-change {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 8px;
            padding: 4px 8px;
            border-radius: 6px;
        }
        
        .stat-change.up {
            background: #d1fae5;
            color: #059669;
        }
        
        .stat-change.down {
            background: #fee2e2;
            color: #dc2626;
        }
        
        /* NPS Card */
        .nps-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }
        
        .nps-card .stat-label {
            color: rgba(255,255,255,0.8);
        }
        
        .nps-card .stat-value {
            color: white;
        }
        
        .nps-breakdown {
            display: flex;
            gap: 16px;
            margin-top: 16px;
        }
        
        .nps-segment {
            font-size: 0.8rem;
        }
        
        .nps-segment span {
            display: block;
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        /* Charts */
        .chart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .chart-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .chart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .chart-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        /* Responses Table */
        .responses-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .responses-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .responses-title {
            font-size: 1.1rem;
            font-weight: 700;
        }
        
        .filter-select {
            padding: 8px 16px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 0.9rem;
            background: white;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 16px 24px;
            text-align: left;
        }
        
        th {
            background: var(--gray-50);
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        tr {
            border-bottom: 1px solid var(--gray-100);
        }
        
        tr:hover {
            background: var(--gray-50);
        }
        
        .response-name {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .response-date {
            color: var(--gray-500);
            font-size: 0.9rem;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .badge-blue {
            background: #dbeafe;
            color: #1d4ed8;
        }
        
        .badge-green {
            background: #d1fae5;
            color: #059669;
        }
        
        .view-btn {
            padding: 8px 16px;
            background: var(--gray-100);
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray-700);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .view-btn:hover {
            background: var(--gray-200);
        }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: white;
            border-radius: 20px;
            width: 100%;
            max-width: 600px;
            max-height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-header h3 {
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        .modal-close {
            width: 36px;
            height: 36px;
            border: none;
            background: var(--gray-100);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
        }
        
        .modal-body {
            padding: 24px;
            overflow-y: auto;
        }
        
        .answer-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .answer-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .answer-question {
            font-size: 0.9rem;
            color: var(--gray-500);
            margin-bottom: 6px;
        }
        
        .answer-value {
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .rating-display {
            display: flex;
            gap: 4px;
        }
        
        .rating-display svg {
            width: 20px;
            height: 20px;
        }
        
        .rating-display svg.filled {
            color: var(--warning);
            fill: var(--warning);
        }
        
        .rating-display svg.empty {
            color: var(--gray-300);
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-logo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2C8 2 6 5 6 8c0 2 1 4 1 6s-1 6 2 6c2 0 2-3 3-3s1 3 3 3c3 0 2-4 2-6s1-4 1-6c0-3-2-6-6-6z"/>
        </svg>
        Survey Admin
    </div>
    
    <nav class="sidebar-nav">
        <div class="nav-item active" onclick="showSection('dashboard')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/>
                <rect x="14" y="3" width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/>
                <rect x="3" y="14" width="7" height="7"/>
            </svg>
            Dashboard
        </div>
        <div class="nav-item" onclick="showSection('responses')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            Responses
        </div>
        <div class="nav-item" onclick="window.open('index.php', '_blank')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                <line x1="8" y1="21" x2="16" y2="21"/>
                <line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
            Open Kiosk
        </div>
    </nav>
    
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= h($user['name']) ?></div>
                <div class="user-role"><?= ucfirst($user['role']) ?></div>
            </div>
        </div>
        <a href="?logout=1" class="logout-btn">Sign Out</a>
    </div>
</aside>

<main class="main-content">
    <!-- Dashboard Section -->
    <div id="dashboardSection">
        <div class="page-header">
            <div>
                <h1 class="page-title">Dashboard</h1>
                <p class="page-subtitle">Overview of your patient survey responses</p>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Responses</div>
                <div class="stat-value" id="totalResponses">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Today</div>
                <div class="stat-value" id="todayResponses">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Average Rating</div>
                <div class="stat-value"><span id="avgRating">-</span> <span style="font-size: 1rem; color: var(--gray-400);">/ 5</span></div>
            </div>
            <div class="stat-card nps-card">
                <div class="stat-label">NPS Score</div>
                <div class="stat-value" id="npsScore">-</div>
                <div class="nps-breakdown">
                    <div class="nps-segment">
                        <span id="npsPromoters">-</span>
                        Promoters
                    </div>
                    <div class="nps-segment">
                        <span id="npsPassives">-</span>
                        Passives
                    </div>
                    <div class="nps-segment">
                        <span id="npsDetractors">-</span>
                        Detractors
                    </div>
                </div>
            </div>
        </div>
        
        <div class="chart-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Responses Over Time</h3>
                    <select class="filter-select" onchange="loadChartData(this.value)">
                        <option value="30">Last 30 days</option>
                        <option value="7">Last 7 days</option>
                        <option value="90">Last 90 days</option>
                    </select>
                </div>
                <canvas id="responsesChart" height="100"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Rating Breakdown</h3>
                </div>
                <div id="ratingBreakdown"></div>
            </div>
        </div>
    </div>
    
    <!-- Responses Section -->
    <div id="responsesSection" style="display: none;">
        <div class="page-header">
            <div>
                <h1 class="page-title">Responses</h1>
                <p class="page-subtitle">View and manage survey responses</p>
            </div>
        </div>
        
        <div class="responses-card">
            <div class="responses-header">
                <h3 class="responses-title">All Responses</h3>
                <select class="filter-select" id="surveyFilter" onchange="loadResponses()">
                    <option value="">All Surveys</option>
                </select>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Respondent</th>
                        <th>Survey</th>
                        <th>Date</th>
                        <th>Device</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="responsesTable">
                    <!-- Loaded dynamically -->
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Response Detail Modal -->
<div class="modal-overlay" id="responseModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Response Details</h3>
            <button class="modal-close" onclick="closeModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="modal-body" id="responseDetails">
            <!-- Loaded dynamically -->
        </div>
    </div>
</div>

<script>
let responsesChart = null;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadDashboardStats();
    loadChartData(30);
    loadRatingBreakdown();
    loadSurveyFilter();
});

function showSection(section) {
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    event.currentTarget.classList.add('active');
    
    document.getElementById('dashboardSection').style.display = section === 'dashboard' ? 'block' : 'none';
    document.getElementById('responsesSection').style.display = section === 'responses' ? 'block' : 'none';
    
    if (section === 'responses') {
        loadResponses();
    }
}

function loadDashboardStats() {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_dashboard_stats'
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('totalResponses').textContent = data.total_responses.toLocaleString();
        document.getElementById('todayResponses').textContent = data.today_responses;
        document.getElementById('avgRating').textContent = data.avg_rating;
        document.getElementById('npsScore').textContent = data.nps_score;
        document.getElementById('npsPromoters').textContent = data.nps_promoters;
        document.getElementById('npsPassives').textContent = data.nps_passives;
        document.getElementById('npsDetractors').textContent = data.nps_detractors;
    });
}

function loadChartData(days) {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_chart_data&days=${days}`
    })
    .then(r => r.json())
    .then(data => {
        const ctx = document.getElementById('responsesChart').getContext('2d');
        
        if (responsesChart) responsesChart.destroy();
        
        responsesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(d => new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
                datasets: [{
                    label: 'Responses',
                    data: data.map(d => d.count),
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    });
}

function loadRatingBreakdown() {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_rating_breakdown'
    })
    .then(r => r.json())
    .then(data => {
        const container = document.getElementById('ratingBreakdown');
        container.innerHTML = data.slice(0, 5).map(item => `
            <div style="margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="font-size: 0.9rem; color: var(--gray-600);">${escapeHtml(item.question_text.substring(0, 40))}...</span>
                    <span style="font-weight: 700;">${parseFloat(item.avg_rating).toFixed(1)}</span>
                </div>
                <div style="height: 8px; background: var(--gray-200); border-radius: 4px; overflow: hidden;">
                    <div style="height: 100%; width: ${(item.avg_rating / 5) * 100}%; background: var(--primary); border-radius: 4px;"></div>
                </div>
            </div>
        `).join('');
    });
}

function loadSurveyFilter() {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_surveys'
    })
    .then(r => r.json())
    .then(surveys => {
        const select = document.getElementById('surveyFilter');
        surveys.forEach(s => {
            const option = document.createElement('option');
            option.value = s.id;
            option.textContent = `${s.name} (${s.response_count})`;
            select.appendChild(option);
        });
    });
}

function loadResponses() {
    const surveyId = document.getElementById('surveyFilter').value;
    
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_responses&survey_id=${surveyId}`
    })
    .then(r => r.json())
    .then(responses => {
        const tbody = document.getElementById('responsesTable');
        tbody.innerHTML = responses.map(r => `
            <tr>
                <td>
                    <div class="response-name">${r.patient_name || 'Anonymous'}</div>
                    ${r.patient_email ? `<div style="font-size: 0.85rem; color: var(--gray-500);">${escapeHtml(r.patient_email)}</div>` : ''}
                </td>
                <td><span class="badge badge-blue">${escapeHtml(r.survey_name)}</span></td>
                <td class="response-date">${new Date(r.completed_at).toLocaleString()}</td>
                <td><span class="badge badge-green">${r.device_type || 'Unknown'}</span></td>
                <td><button class="view-btn" onclick="viewResponse(${r.id})">View</button></td>
            </tr>
        `).join('');
    });
}

function viewResponse(responseId) {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_response_details&response_id=${responseId}`
    })
    .then(r => r.json())
    .then(answers => {
        const container = document.getElementById('responseDetails');
        container.innerHTML = answers.map(a => {
            let valueHtml = '';
            
            if (a.question_type === 'rating_5' && a.answer_numeric) {
                valueHtml = `<div class="rating-display">
                    ${[1,2,3,4,5].map(n => `
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="${n <= a.answer_numeric ? 'filled' : 'empty'}">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                        </svg>
                    `).join('')}
                </div>`;
            } else if (a.question_type === 'nps' && a.answer_numeric !== null) {
                const score = parseInt(a.answer_numeric);
                const color = score >= 9 ? 'var(--success)' : score >= 7 ? 'var(--warning)' : 'var(--danger)';
                valueHtml = `<span style="font-size: 1.5rem; font-weight: 800; color: ${color};">${score}</span>`;
            } else if (a.answer_json) {
                valueHtml = a.answer_json.map(v => `<span class="badge badge-blue" style="margin-right: 6px;">${escapeHtml(v)}</span>`).join('');
            } else if (a.answer_text) {
                if (a.answer_text.startsWith('data:image')) {
                    valueHtml = `<img src="${a.answer_text}" style="max-width: 200px; border: 1px solid var(--gray-200); border-radius: 8px;">`;
                } else {
                    valueHtml = escapeHtml(a.answer_text);
                }
            } else if (a.answer_numeric !== null) {
                valueHtml = a.answer_numeric;
            } else {
                valueHtml = '<span style="color: var(--gray-400);">No answer</span>';
            }
            
            return `
                <div class="answer-item">
                    <div class="answer-question">${escapeHtml(a.question_text)}</div>
                    <div class="answer-value">${valueHtml}</div>
                </div>
            `;
        }).join('');
        
        document.getElementById('responseModal').classList.add('active');
    });
}

function closeModal() {
    document.getElementById('responseModal').classList.remove('active');
}

document.getElementById('responseModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}
</script>

</body>
</html>
