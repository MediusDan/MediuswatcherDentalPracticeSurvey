<?php
/**
 * Dental Survey Kiosk - Patient-Facing Interface
 * Optimized for iPad/tablet use
 */

require_once 'config.php';

$db = getDB();
$practice = getPractice();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'get_surveys':
            $stmt = $db->query("SELECT id, name, description, type, estimated_time FROM surveys WHERE is_active = 1 AND show_on_kiosk = 1 ORDER BY type, name");
            echo json_encode($stmt->fetchAll());
            exit;
            
        case 'get_survey':
            $stmt = $db->prepare("SELECT * FROM surveys WHERE id = ?");
            $stmt->execute([$_POST['survey_id']]);
            $survey = $stmt->fetch();
            
            $stmt = $db->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY display_order");
            $stmt->execute([$_POST['survey_id']]);
            $questions = $stmt->fetchAll();
            
            // Parse JSON options
            foreach ($questions as &$q) {
                if ($q['options']) {
                    $q['options'] = json_decode($q['options'], true);
                }
            }
            
            echo json_encode(['survey' => $survey, 'questions' => $questions]);
            exit;
            
        case 'start_response':
            $stmt = $db->prepare("INSERT INTO survey_responses (survey_id, patient_name, patient_email, is_anonymous, device_type, ip_address) VALUES (?, ?, ?, ?, 'kiosk', ?)");
            $stmt->execute([
                $_POST['survey_id'],
                $_POST['patient_name'] ?? null,
                $_POST['patient_email'] ?? null,
                empty($_POST['patient_name']) ? 1 : 0,
                $_SERVER['REMOTE_ADDR']
            ]);
            echo json_encode(['response_id' => $db->lastInsertId()]);
            exit;
            
        case 'save_answer':
            // Check if answer exists
            $stmt = $db->prepare("SELECT id FROM survey_answers WHERE response_id = ? AND question_id = ?");
            $stmt->execute([$_POST['response_id'], $_POST['question_id']]);
            $existing = $stmt->fetch();
            
            $answerText = $_POST['answer_text'] ?? null;
            $answerNumeric = is_numeric($_POST['answer_numeric'] ?? '') ? $_POST['answer_numeric'] : null;
            $answerJson = $_POST['answer_json'] ?? null;
            
            if ($existing) {
                $stmt = $db->prepare("UPDATE survey_answers SET answer_text = ?, answer_numeric = ?, answer_json = ? WHERE id = ?");
                $stmt->execute([$answerText, $answerNumeric, $answerJson, $existing['id']]);
            } else {
                $stmt = $db->prepare("INSERT INTO survey_answers (response_id, question_id, answer_text, answer_numeric, answer_json) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['response_id'], $_POST['question_id'], $answerText, $answerNumeric, $answerJson]);
            }
            echo json_encode(['success' => true]);
            exit;
            
        case 'complete_response':
            $stmt = $db->prepare("UPDATE survey_responses SET is_complete = 1, completed_at = NOW() WHERE id = ?");
            $stmt->execute([$_POST['response_id']]);
            
            // Update NPS if applicable
            $stmt = $db->prepare("
                SELECT sq.question_type, sa.answer_numeric 
                FROM survey_answers sa 
                JOIN survey_questions sq ON sa.question_id = sq.id 
                WHERE sa.response_id = ? AND sq.question_type = 'nps'
            ");
            $stmt->execute([$_POST['response_id']]);
            $npsAnswer = $stmt->fetch();
            
            if ($npsAnswer && $npsAnswer['answer_numeric'] !== null) {
                $score = (int)$npsAnswer['answer_numeric'];
                $today = date('Y-m-d');
                
                // Determine category
                $field = 'passives';
                if ($score >= 9) $field = 'promoters';
                elseif ($score <= 6) $field = 'detractors';
                
                $stmt = $db->prepare("
                    INSERT INTO nps_scores (score_date, $field, total_responses) 
                    VALUES (?, 1, 1)
                    ON DUPLICATE KEY UPDATE 
                        $field = $field + 1,
                        total_responses = total_responses + 1,
                        nps_score = ((promoters - detractors) / total_responses) * 100
                ");
                $stmt->execute([$today]);
            }
            
            // Get thank you message
            $stmt = $db->prepare("SELECT thank_you_message FROM surveys WHERE id = (SELECT survey_id FROM survey_responses WHERE id = ?)");
            $stmt->execute([$_POST['response_id']]);
            $survey = $stmt->fetch();
            
            echo json_encode(['success' => true, 'message' => $survey['thank_you_message']]);
            exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= h($practice['name']) ?> - Patient Survey</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: <?= h($practice['primary_color'] ?? '#2563eb') ?>;
            --primary-dark: color-mix(in srgb, var(--primary) 85%, black);
            --primary-light: color-mix(in srgb, var(--primary) 15%, white);
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
        
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        
        html, body {
            height: 100%;
            overflow: hidden;
        }
        
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--gray-800);
            line-height: 1.5;
        }
        
        /* Screens */
        .screen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: none;
            flex-direction: column;
        }
        
        .screen.active {
            display: flex;
        }
        
        /* Welcome Screen */
        .welcome-screen {
            align-items: center;
            justify-content: center;
            padding: 40px;
            text-align: center;
            color: white;
        }
        
        .welcome-logo {
            width: 120px;
            height: 120px;
            background: white;
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        
        .welcome-logo svg {
            width: 70px;
            height: 70px;
            color: var(--primary);
        }
        
        .welcome-title {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 16px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .welcome-subtitle {
            font-size: 1.4rem;
            opacity: 0.9;
            margin-bottom: 60px;
            max-width: 500px;
        }
        
        .welcome-btn {
            background: white;
            color: var(--primary);
            font-size: 1.5rem;
            font-weight: 700;
            padding: 24px 64px;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .welcome-btn:active {
            transform: scale(0.98);
        }
        
        .welcome-hint {
            position: absolute;
            bottom: 40px;
            font-size: 1rem;
            opacity: 0.7;
        }
        
        /* Survey Selection Screen */
        .selection-screen {
            background: var(--gray-50);
        }
        
        .screen-header {
            background: white;
            padding: 24px 32px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .screen-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .back-btn {
            width: 48px;
            height: 48px;
            border: none;
            background: var(--gray-100);
            border-radius: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
            transition: background 0.2s;
        }
        
        .back-btn:active {
            background: var(--gray-200);
        }
        
        .screen-body {
            flex: 1;
            overflow-y: auto;
            padding: 32px;
            -webkit-overflow-scrolling: touch;
        }
        
        /* Survey Cards */
        .survey-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .survey-card {
            background: white;
            border-radius: 20px;
            padding: 32px;
            cursor: pointer;
            border: 3px solid transparent;
            transition: all 0.2s;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        
        .survey-card:active {
            transform: scale(0.98);
            border-color: var(--primary);
        }
        
        .survey-card-icon {
            width: 64px;
            height: 64px;
            background: var(--primary-light);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
        
        .survey-card-icon svg {
            width: 32px;
            height: 32px;
            color: var(--primary);
        }
        
        .survey-card h3 {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
        }
        
        .survey-card p {
            color: var(--gray-500);
            font-size: 1rem;
            margin-bottom: 16px;
        }
        
        .survey-card-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
            color: var(--gray-400);
        }
        
        .survey-card-meta svg {
            width: 16px;
            height: 16px;
        }
        
        /* Survey Screen */
        .survey-screen {
            background: var(--gray-50);
        }
        
        .progress-bar {
            height: 6px;
            background: var(--gray-200);
        }
        
        .progress-fill {
            height: 100%;
            background: var(--primary);
            transition: width 0.3s ease;
        }
        
        .question-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            overflow-y: auto;
        }
        
        .question-card {
            background: white;
            border-radius: 24px;
            padding: 48px;
            max-width: 700px;
            width: 100%;
            box-shadow: 0 10px 50px rgba(0,0,0,0.08);
        }
        
        .question-number {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .question-text {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
            line-height: 1.4;
        }
        
        .question-help {
            font-size: 1rem;
            color: var(--gray-500);
            margin-bottom: 32px;
        }
        
        .section-header {
            text-align: center;
        }
        
        .section-header .question-text {
            font-size: 2rem;
            color: var(--primary);
        }
        
        /* Rating Stars */
        .rating-5 {
            display: flex;
            justify-content: center;
            gap: 16px;
        }
        
        .rating-star {
            width: 72px;
            height: 72px;
            border: 3px solid var(--gray-200);
            border-radius: 18px;
            background: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .rating-star svg {
            width: 40px;
            height: 40px;
            color: var(--gray-300);
            transition: all 0.2s;
        }
        
        .rating-star.selected {
            border-color: var(--warning);
            background: #fffbeb;
        }
        
        .rating-star.selected svg {
            color: var(--warning);
            fill: var(--warning);
        }
        
        .rating-star:active {
            transform: scale(0.95);
        }
        
        /* NPS Scale */
        .nps-scale {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .nps-numbers {
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }
        
        .nps-btn {
            flex: 1;
            height: 64px;
            border: 3px solid var(--gray-200);
            border-radius: 14px;
            background: white;
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--gray-600);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .nps-btn:active {
            transform: scale(0.95);
        }
        
        .nps-btn.selected {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }
        
        .nps-btn.detractor.selected {
            border-color: var(--danger);
            background: var(--danger);
        }
        
        .nps-btn.passive.selected {
            border-color: var(--warning);
            background: var(--warning);
        }
        
        .nps-btn.promoter.selected {
            border-color: var(--success);
            background: var(--success);
        }
        
        .nps-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--gray-400);
        }
        
        /* Yes/No */
        .yes-no {
            display: flex;
            gap: 20px;
            justify-content: center;
        }
        
        .yes-no-btn {
            width: 160px;
            height: 80px;
            border: 3px solid var(--gray-200);
            border-radius: 18px;
            background: white;
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--gray-600);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .yes-no-btn:active {
            transform: scale(0.95);
        }
        
        .yes-no-btn.selected.yes {
            border-color: var(--success);
            background: var(--success);
            color: white;
        }
        
        .yes-no-btn.selected.no {
            border-color: var(--danger);
            background: var(--danger);
            color: white;
        }
        
        /* Multiple Choice */
        .multiple-choice {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .choice-btn {
            padding: 20px 24px;
            border: 3px solid var(--gray-200);
            border-radius: 16px;
            background: white;
            font-size: 1.1rem;
            font-weight: 500;
            color: var(--gray-700);
            cursor: pointer;
            text-align: left;
            transition: all 0.2s;
        }
        
        .choice-btn:active {
            transform: scale(0.99);
        }
        
        .choice-btn.selected {
            border-color: var(--primary);
            background: var(--primary-light);
            color: var(--primary-dark);
        }
        
        /* Checkbox */
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .checkbox-btn {
            padding: 18px 24px;
            border: 3px solid var(--gray-200);
            border-radius: 16px;
            background: white;
            font-size: 1.1rem;
            font-weight: 500;
            color: var(--gray-700);
            cursor: pointer;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.2s;
        }
        
        .checkbox-btn .check {
            width: 28px;
            height: 28px;
            border: 3px solid var(--gray-300);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        
        .checkbox-btn .check svg {
            width: 18px;
            height: 18px;
            color: white;
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .checkbox-btn.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        
        .checkbox-btn.selected .check {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .checkbox-btn.selected .check svg {
            opacity: 1;
        }
        
        /* Text Input */
        .text-input {
            width: 100%;
            padding: 20px;
            border: 3px solid var(--gray-200);
            border-radius: 16px;
            font-size: 1.2rem;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        
        .text-input:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        textarea.text-input {
            min-height: 150px;
            resize: none;
        }
        
        /* Signature Pad */
        .signature-container {
            border: 3px solid var(--gray-200);
            border-radius: 16px;
            overflow: hidden;
            position: relative;
        }
        
        .signature-pad {
            width: 100%;
            height: 200px;
            background: white;
            cursor: crosshair;
        }
        
        .signature-clear {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 8px 16px;
            background: var(--gray-100);
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            color: var(--gray-600);
            cursor: pointer;
        }
        
        /* Navigation */
        .survey-nav {
            padding: 24px 40px;
            background: white;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .nav-btn {
            padding: 18px 40px;
            border: none;
            border-radius: 14px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .nav-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        
        .nav-btn.prev {
            background: var(--gray-100);
            color: var(--gray-700);
        }
        
        .nav-btn.prev:active:not(:disabled) {
            background: var(--gray-200);
        }
        
        .nav-btn.next {
            background: var(--primary);
            color: white;
        }
        
        .nav-btn.next:active:not(:disabled) {
            background: var(--primary-dark);
        }
        
        .nav-btn svg {
            width: 20px;
            height: 20px;
        }
        
        /* Thank You Screen */
        .thankyou-screen {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            align-items: center;
            justify-content: center;
            padding: 40px;
            text-align: center;
            color: white;
        }
        
        .thankyou-icon {
            width: 140px;
            height: 140px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }
        
        .thankyou-icon svg {
            width: 80px;
            height: 80px;
            color: var(--success);
        }
        
        .thankyou-title {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 20px;
        }
        
        .thankyou-message {
            font-size: 1.4rem;
            opacity: 0.9;
            max-width: 500px;
            margin-bottom: 60px;
            line-height: 1.6;
        }
        
        .thankyou-countdown {
            font-size: 1.1rem;
            opacity: 0.7;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .question-card {
            animation: fadeIn 0.4s ease;
        }
        
        /* Kiosk mode indicator */
        .kiosk-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(0,0,0,0.5);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            display: none;
        }
    </style>
</head>
<body>

<!-- Welcome Screen -->
<div class="screen welcome-screen active" id="welcomeScreen">
    <div class="welcome-logo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2C8 2 6 5 6 8c0 2 1 4 1 6s-1 6 2 6c2 0 2-3 3-3s1 3 3 3c3 0 2-4 2-6s1-4 1-6c0-3-2-6-6-6z"/>
        </svg>
    </div>
    <h1 class="welcome-title"><?= h($practice['name']) ?></h1>
    <p class="welcome-subtitle">We'd love your feedback! Tap below to begin a quick survey.</p>
    <button class="welcome-btn" onclick="showSurveySelection()">
        Start Survey
    </button>
    <p class="welcome-hint">Your feedback helps us serve you better</p>
</div>

<!-- Survey Selection Screen -->
<div class="screen selection-screen" id="selectionScreen">
    <div class="screen-header">
        <button class="back-btn" onclick="showWelcome()">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
        </button>
        <h1>Select a Survey</h1>
        <div style="width: 48px;"></div>
    </div>
    <div class="screen-body">
        <div class="survey-grid" id="surveyGrid">
            <!-- Survey cards loaded dynamically -->
        </div>
    </div>
</div>

<!-- Survey Screen -->
<div class="screen survey-screen" id="surveyScreen">
    <div class="screen-header">
        <button class="back-btn" onclick="confirmExit()">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12"/>
            </svg>
        </button>
        <h1 id="surveyTitle">Survey</h1>
        <div style="width: 48px;"></div>
    </div>
    <div class="progress-bar">
        <div class="progress-fill" id="progressFill" style="width: 0%;"></div>
    </div>
    <div class="question-container" id="questionContainer">
        <!-- Question cards rendered here -->
    </div>
    <div class="survey-nav">
        <button class="nav-btn prev" id="prevBtn" onclick="prevQuestion()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Previous
        </button>
        <button class="nav-btn next" id="nextBtn" onclick="nextQuestion()">
            Next
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </button>
    </div>
</div>

<!-- Thank You Screen -->
<div class="screen thankyou-screen" id="thankyouScreen">
    <div class="thankyou-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
            <polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
    </div>
    <h1 class="thankyou-title">Thank You!</h1>
    <p class="thankyou-message" id="thankyouMessage">Your feedback is invaluable!</p>
    <p class="thankyou-countdown">Returning to home in <span id="countdown">5</span> seconds...</p>
</div>

<script>
// State
let currentSurvey = null;
let questions = [];
let currentQuestionIndex = 0;
let responseId = null;
let answers = {};
let inactivityTimer = null;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    resetInactivityTimer();
});

// Track activity
document.addEventListener('touchstart', resetInactivityTimer);
document.addEventListener('click', resetInactivityTimer);

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(() => {
        if (document.getElementById('welcomeScreen').classList.contains('active')) return;
        if (confirm('Are you still there? Press OK to continue or Cancel to start over.')) {
            resetInactivityTimer();
        } else {
            showWelcome();
        }
    }, <?= KIOSK_TIMEOUT ?> * 1000);
}

// Screen navigation
function showScreen(id) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.getElementById(id).classList.add('active');
}

function showWelcome() {
    currentSurvey = null;
    questions = [];
    currentQuestionIndex = 0;
    responseId = null;
    answers = {};
    showScreen('welcomeScreen');
}

function showSurveySelection() {
    showScreen('selectionScreen');
    loadSurveys();
}

// Load surveys
function loadSurveys() {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_surveys'
    })
    .then(r => r.json())
    .then(surveys => {
        const grid = document.getElementById('surveyGrid');
        grid.innerHTML = surveys.map(s => `
            <div class="survey-card" onclick="startSurvey(${s.id})">
                <div class="survey-card-icon">
                    ${getSurveyIcon(s.type)}
                </div>
                <h3>${escapeHtml(s.name)}</h3>
                <p>${escapeHtml(s.description || '')}</p>
                <div class="survey-card-meta">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    ${s.estimated_time} min
                </div>
            </div>
        `).join('');
    });
}

function getSurveyIcon(type) {
    const icons = {
        'exit_survey': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>',
        'intake_form': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>',
        'medical_history': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
        'satisfaction': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>',
        'custom': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>'
    };
    return icons[type] || icons['custom'];
}

// Start survey
function startSurvey(surveyId) {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=get_survey&survey_id=${surveyId}`
    })
    .then(r => r.json())
    .then(data => {
        currentSurvey = data.survey;
        questions = data.questions.filter(q => q.question_type !== 'section_header' || true); // Keep section headers
        currentQuestionIndex = 0;
        answers = {};
        
        document.getElementById('surveyTitle').textContent = currentSurvey.name;
        
        // Start response
        return fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=start_response&survey_id=${surveyId}`
        });
    })
    .then(r => r.json())
    .then(data => {
        responseId = data.response_id;
        showScreen('surveyScreen');
        renderQuestion();
    });
}

// Render current question
function renderQuestion() {
    const q = questions[currentQuestionIndex];
    const container = document.getElementById('questionContainer');
    const answerable = questions.filter(x => x.question_type !== 'section_header');
    const currentAnswerable = answerable.findIndex(x => x.id === q.id) + 1;
    
    // Update progress
    const progress = ((currentQuestionIndex + 1) / questions.length) * 100;
    document.getElementById('progressFill').style.width = progress + '%';
    
    // Update nav buttons
    document.getElementById('prevBtn').disabled = currentQuestionIndex === 0;
    const isLast = currentQuestionIndex === questions.length - 1;
    document.getElementById('nextBtn').innerHTML = isLast 
        ? 'Submit <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>'
        : 'Next <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
    
    // Render question
    let html = `<div class="question-card${q.question_type === 'section_header' ? ' section-header' : ''}">`;
    
    if (q.question_type !== 'section_header') {
        html += `<div class="question-number">Question ${currentAnswerable} of ${answerable.length}</div>`;
    }
    
    html += `<div class="question-text">${escapeHtml(q.question_text)}${q.is_required ? ' <span style="color: var(--danger);">*</span>' : ''}</div>`;
    
    if (q.help_text) {
        html += `<div class="question-help">${escapeHtml(q.help_text)}</div>`;
    }
    
    html += '<div class="question-input">';
    html += renderInput(q);
    html += '</div></div>';
    
    container.innerHTML = html;
    
    // Restore answer if exists
    if (answers[q.id]) {
        restoreAnswer(q, answers[q.id]);
    }
}

function renderInput(q) {
    switch (q.question_type) {
        case 'section_header':
            return '<p style="color: var(--gray-500); margin-top: 20px;">Tap Next to continue</p>';
            
        case 'rating_5':
            return `
                <div class="rating-5">
                    ${[1,2,3,4,5].map(n => `
                        <button class="rating-star" data-value="${n}" onclick="selectRating(this, ${q.id}, ${n})">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                            </svg>
                        </button>
                    `).join('')}
                </div>
            `;
            
        case 'nps':
            return `
                <div class="nps-scale">
                    <div class="nps-numbers">
                        ${[0,1,2,3,4,5,6,7,8,9,10].map(n => `
                            <button class="nps-btn ${n <= 6 ? 'detractor' : n <= 8 ? 'passive' : 'promoter'}" 
                                    data-value="${n}" onclick="selectNPS(this, ${q.id}, ${n})">${n}</button>
                        `).join('')}
                    </div>
                    <div class="nps-labels">
                        <span>Not at all likely</span>
                        <span>Extremely likely</span>
                    </div>
                </div>
            `;
            
        case 'yes_no':
            return `
                <div class="yes-no">
                    <button class="yes-no-btn yes" onclick="selectYesNo(this, ${q.id}, 'yes')">Yes</button>
                    <button class="yes-no-btn no" onclick="selectYesNo(this, ${q.id}, 'no')">No</button>
                </div>
            `;
            
        case 'multiple_choice':
            return `
                <div class="multiple-choice">
                    ${(q.options || []).map((opt, i) => `
                        <button class="choice-btn" data-value="${escapeHtml(opt)}" onclick="selectChoice(this, ${q.id})">${escapeHtml(opt)}</button>
                    `).join('')}
                </div>
            `;
            
        case 'checkbox':
            return `
                <div class="checkbox-group">
                    ${(q.options || []).map((opt, i) => `
                        <button class="checkbox-btn" data-value="${escapeHtml(opt)}" onclick="toggleCheckbox(this, ${q.id})">
                            <span class="check">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </span>
                            ${escapeHtml(opt)}
                        </button>
                    `).join('')}
                </div>
            `;
            
        case 'text':
            return `<input type="text" class="text-input" id="textInput_${q.id}" placeholder="Type your answer..." oninput="saveTextAnswer(${q.id})">`;
            
        case 'textarea':
            return `<textarea class="text-input" id="textInput_${q.id}" placeholder="Type your answer..." oninput="saveTextAnswer(${q.id})"></textarea>`;
            
        case 'date':
            return `<input type="date" class="text-input" id="textInput_${q.id}" oninput="saveTextAnswer(${q.id})">`;
            
        case 'signature':
            return `
                <div class="signature-container">
                    <canvas class="signature-pad" id="signaturePad_${q.id}"></canvas>
                    <button class="signature-clear" onclick="clearSignature(${q.id})">Clear</button>
                </div>
            `;
            
        default:
            return '<p>Unknown question type</p>';
    }
}

// Input handlers
function selectRating(btn, qId, value) {
    document.querySelectorAll('.rating-star').forEach((b, i) => {
        b.classList.toggle('selected', i < value);
    });
    answers[qId] = { numeric: value };
    saveAnswer(qId, null, value);
}

function selectNPS(btn, qId, value) {
    document.querySelectorAll('.nps-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    answers[qId] = { numeric: value };
    saveAnswer(qId, null, value);
}

function selectYesNo(btn, qId, value) {
    document.querySelectorAll('.yes-no-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    answers[qId] = { text: value };
    saveAnswer(qId, value);
}

function selectChoice(btn, qId) {
    document.querySelectorAll('.choice-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    answers[qId] = { text: btn.dataset.value };
    saveAnswer(qId, btn.dataset.value);
}

function toggleCheckbox(btn, qId) {
    btn.classList.toggle('selected');
    const selected = Array.from(document.querySelectorAll('.checkbox-btn.selected')).map(b => b.dataset.value);
    answers[qId] = { json: selected };
    saveAnswer(qId, null, null, JSON.stringify(selected));
}

function saveTextAnswer(qId) {
    const input = document.getElementById(`textInput_${qId}`);
    answers[qId] = { text: input.value };
    // Debounced save
    clearTimeout(input.saveTimeout);
    input.saveTimeout = setTimeout(() => {
        saveAnswer(qId, input.value);
    }, 500);
}

function saveAnswer(qId, text = null, numeric = null, json = null) {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=save_answer&response_id=${responseId}&question_id=${qId}&answer_text=${encodeURIComponent(text || '')}&answer_numeric=${numeric || ''}&answer_json=${encodeURIComponent(json || '')}`
    });
}

function restoreAnswer(q, answer) {
    if (answer.numeric !== undefined) {
        if (q.question_type === 'rating_5') {
            document.querySelectorAll('.rating-star').forEach((b, i) => {
                b.classList.toggle('selected', i < answer.numeric);
            });
        } else if (q.question_type === 'nps') {
            const btn = document.querySelector(`.nps-btn[data-value="${answer.numeric}"]`);
            if (btn) btn.classList.add('selected');
        }
    }
    if (answer.text) {
        if (q.question_type === 'yes_no') {
            const btn = document.querySelector(`.yes-no-btn.${answer.text}`);
            if (btn) btn.classList.add('selected');
        } else if (q.question_type === 'multiple_choice') {
            const btn = document.querySelector(`.choice-btn[data-value="${answer.text}"]`);
            if (btn) btn.classList.add('selected');
        } else {
            const input = document.getElementById(`textInput_${q.id}`);
            if (input) input.value = answer.text;
        }
    }
    if (answer.json) {
        answer.json.forEach(val => {
            const btn = document.querySelector(`.checkbox-btn[data-value="${val}"]`);
            if (btn) btn.classList.add('selected');
        });
    }
}

// Navigation
function prevQuestion() {
    if (currentQuestionIndex > 0) {
        currentQuestionIndex--;
        renderQuestion();
    }
}

function nextQuestion() {
    const q = questions[currentQuestionIndex];
    
    // Validate required
    if (q.is_required && q.question_type !== 'section_header') {
        if (!answers[q.id]) {
            alert('Please answer this question before continuing.');
            return;
        }
    }
    
    if (currentQuestionIndex < questions.length - 1) {
        currentQuestionIndex++;
        renderQuestion();
    } else {
        submitSurvey();
    }
}

function submitSurvey() {
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=complete_response&response_id=${responseId}`
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('thankyouMessage').textContent = data.message;
        showScreen('thankyouScreen');
        
        // Countdown and redirect
        let count = 5;
        const countdownEl = document.getElementById('countdown');
        const interval = setInterval(() => {
            count--;
            countdownEl.textContent = count;
            if (count <= 0) {
                clearInterval(interval);
                showWelcome();
            }
        }, 1000);
    });
}

function confirmExit() {
    if (confirm('Are you sure you want to exit? Your progress will be saved.')) {
        showSurveySelection();
    }
}

// Signature pad setup
function setupSignaturePad(qId) {
    const canvas = document.getElementById(`signaturePad_${qId}`);
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    canvas.width = canvas.offsetWidth;
    canvas.height = canvas.offsetHeight;
    
    let drawing = false;
    
    function getPos(e) {
        const rect = canvas.getBoundingClientRect();
        const touch = e.touches ? e.touches[0] : e;
        return {
            x: touch.clientX - rect.left,
            y: touch.clientY - rect.top
        };
    }
    
    canvas.addEventListener('mousedown', e => { drawing = true; ctx.beginPath(); ctx.moveTo(getPos(e).x, getPos(e).y); });
    canvas.addEventListener('mousemove', e => { if (drawing) { ctx.lineTo(getPos(e).x, getPos(e).y); ctx.stroke(); } });
    canvas.addEventListener('mouseup', () => { drawing = false; saveSignature(qId); });
    
    canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; ctx.beginPath(); ctx.moveTo(getPos(e).x, getPos(e).y); });
    canvas.addEventListener('touchmove', e => { e.preventDefault(); if (drawing) { ctx.lineTo(getPos(e).x, getPos(e).y); ctx.stroke(); } });
    canvas.addEventListener('touchend', () => { drawing = false; saveSignature(qId); });
    
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
}

function clearSignature(qId) {
    const canvas = document.getElementById(`signaturePad_${qId}`);
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    answers[qId] = null;
}

function saveSignature(qId) {
    const canvas = document.getElementById(`signaturePad_${qId}`);
    answers[qId] = { text: canvas.toDataURL() };
    saveAnswer(qId, canvas.toDataURL());
}

// Utility
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Set up signature pads after question render
const originalRender = renderQuestion;
renderQuestion = function() {
    originalRender();
    const q = questions[currentQuestionIndex];
    if (q.question_type === 'signature') {
        setTimeout(() => setupSignaturePad(q.id), 100);
    }
};
</script>

</body>
</html>
