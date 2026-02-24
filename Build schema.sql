-- Dental Patient Survey Application Database Schema
-- Run this SQL to set up your database

CREATE DATABASE IF NOT EXISTS dental_surveys;
USE dental_surveys;

-- Practice/Location settings
CREATE TABLE practices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(255),
    logo_url VARCHAR(500),
    primary_color VARCHAR(7) DEFAULT '#2563eb',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default practice
INSERT INTO practices (name) VALUES ('Your Dental Practice');

-- Admin users
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    practice_id INT DEFAULT 1,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    email VARCHAR(255),
    role ENUM('admin', 'staff') DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (practice_id) REFERENCES practices(id)
);

-- Insert default admin (password: admin123 - CHANGE THIS!)
INSERT INTO admin_users (username, password_hash, name, role) 
VALUES ('admin', '$2y$10$8K1p/aEXqPqJKvLJF3s8/.4Y6JBKJ4EqX5P3rP0Y3HK.9HJF3KXWG', 'Administrator', 'admin');

-- Survey templates
CREATE TABLE surveys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    practice_id INT DEFAULT 1,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    type ENUM('exit_survey', 'intake_form', 'medical_history', 'satisfaction', 'custom') NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    show_on_kiosk BOOLEAN DEFAULT TRUE,
    estimated_time INT DEFAULT 2, -- minutes
    thank_you_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (practice_id) REFERENCES practices(id)
);

-- Survey questions
CREATE TABLE survey_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating_5', 'rating_10', 'nps', 'yes_no', 'multiple_choice', 'checkbox', 'text', 'textarea', 'date', 'signature', 'section_header') NOT NULL,
    options JSON, -- For multiple choice/checkbox questions
    is_required BOOLEAN DEFAULT FALSE,
    display_order INT DEFAULT 0,
    conditional_on INT, -- Show only if this question_id has specific answer
    conditional_value VARCHAR(255),
    help_text TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
);

-- Survey responses (one per submission)
CREATE TABLE survey_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    patient_name VARCHAR(255),
    patient_email VARCHAR(255),
    patient_phone VARCHAR(20),
    is_anonymous BOOLEAN DEFAULT FALSE,
    device_type VARCHAR(50), -- 'kiosk', 'mobile', 'email_link'
    ip_address VARCHAR(45),
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    is_complete BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (survey_id) REFERENCES surveys(id)
);

-- Individual question answers
CREATE TABLE survey_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    response_id INT NOT NULL,
    question_id INT NOT NULL,
    answer_text TEXT,
    answer_numeric DECIMAL(10,2),
    answer_json JSON, -- For checkbox/multiple selections
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES survey_questions(id)
);

-- NPS tracking (calculated from responses)
CREATE TABLE nps_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    practice_id INT DEFAULT 1,
    score_date DATE NOT NULL,
    promoters INT DEFAULT 0,
    passives INT DEFAULT 0,
    detractors INT DEFAULT 0,
    nps_score DECIMAL(5,2),
    total_responses INT DEFAULT 0,
    UNIQUE KEY unique_date (practice_id, score_date),
    FOREIGN KEY (practice_id) REFERENCES practices(id)
);

-- ============================================
-- INSERT DEFAULT SURVEYS
-- ============================================

-- Exit Survey (Quick satisfaction check)
INSERT INTO surveys (name, description, type, estimated_time, thank_you_message) VALUES
('Exit Survey', 'Quick feedback after your visit', 'exit_survey', 1, 'Thank you for your feedback! We appreciate you taking the time to help us improve.');

SET @exit_survey_id = LAST_INSERT_ID();

INSERT INTO survey_questions (survey_id, question_text, question_type, is_required, display_order) VALUES
(@exit_survey_id, 'How was your visit today?', 'rating_5', TRUE, 1),
(@exit_survey_id, 'How friendly was our staff?', 'rating_5', TRUE, 2),
(@exit_survey_id, 'How would you rate the cleanliness of our office?', 'rating_5', TRUE, 3),
(@exit_survey_id, 'Was your wait time acceptable?', 'yes_no', TRUE, 4),
(@exit_survey_id, 'How likely are you to recommend us to friends and family?', 'nps', TRUE, 5),
(@exit_survey_id, 'Any additional comments or suggestions?', 'textarea', FALSE, 6);

-- Patient Satisfaction Survey (More detailed)
INSERT INTO surveys (name, description, type, estimated_time, thank_you_message) VALUES
('Patient Satisfaction Survey', 'Help us serve you better', 'satisfaction', 3, 'Your feedback is invaluable! Thank you for helping us provide the best care possible.');

SET @satisfaction_id = LAST_INSERT_ID();

INSERT INTO survey_questions (survey_id, question_text, question_type, options, is_required, display_order) VALUES
(@satisfaction_id, 'Your Experience', 'section_header', NULL, FALSE, 1),
(@satisfaction_id, 'Overall, how satisfied are you with your visit?', 'rating_5', NULL, TRUE, 2),
(@satisfaction_id, 'How would you rate the quality of care you received?', 'rating_5', NULL, TRUE, 3),
(@satisfaction_id, 'Did your provider explain your treatment options clearly?', 'rating_5', NULL, TRUE, 4),
(@satisfaction_id, 'Our Team', 'section_header', NULL, FALSE, 5),
(@satisfaction_id, 'How would you rate our front desk staff?', 'rating_5', NULL, TRUE, 6),
(@satisfaction_id, 'How would you rate your dental hygienist?', 'rating_5', NULL, FALSE, 7),
(@satisfaction_id, 'How would you rate your dentist?', 'rating_5', NULL, TRUE, 8),
(@satisfaction_id, 'Our Office', 'section_header', NULL, FALSE, 9),
(@satisfaction_id, 'How would you rate the cleanliness of our facility?', 'rating_5', NULL, TRUE, 10),
(@satisfaction_id, 'How comfortable was the waiting area?', 'rating_5', NULL, FALSE, 11),
(@satisfaction_id, 'How was your wait time?', 'multiple_choice', '["Much shorter than expected", "About what I expected", "Longer than expected", "Much longer than expected"]', TRUE, 12),
(@satisfaction_id, 'Recommendation', 'section_header', NULL, FALSE, 13),
(@satisfaction_id, 'How likely are you to recommend our practice to others?', 'nps', NULL, TRUE, 14),
(@satisfaction_id, 'What did we do well?', 'textarea', NULL, FALSE, 15),
(@satisfaction_id, 'How can we improve?', 'textarea', NULL, FALSE, 16);

-- New Patient Intake Form
INSERT INTO surveys (name, description, type, estimated_time, thank_you_message) VALUES
('New Patient Intake', 'Welcome! Please complete this form before your appointment', 'intake_form', 5, 'Thank you for completing your intake form. We look forward to seeing you!');

SET @intake_id = LAST_INSERT_ID();

INSERT INTO survey_questions (survey_id, question_text, question_type, options, is_required, display_order, help_text) VALUES
(@intake_id, 'Personal Information', 'section_header', NULL, FALSE, 1, NULL),
(@intake_id, 'Full Legal Name', 'text', NULL, TRUE, 2, NULL),
(@intake_id, 'Date of Birth', 'date', NULL, TRUE, 3, NULL),
(@intake_id, 'Phone Number', 'text', NULL, TRUE, 4, NULL),
(@intake_id, 'Email Address', 'text', NULL, TRUE, 5, NULL),
(@intake_id, 'Home Address', 'textarea', NULL, TRUE, 6, NULL),
(@intake_id, 'Emergency Contact', 'section_header', NULL, FALSE, 7, NULL),
(@intake_id, 'Emergency Contact Name', 'text', NULL, TRUE, 8, NULL),
(@intake_id, 'Emergency Contact Phone', 'text', NULL, TRUE, 9, NULL),
(@intake_id, 'Relationship to Patient', 'text', NULL, TRUE, 10, NULL),
(@intake_id, 'Insurance Information', 'section_header', NULL, FALSE, 11, NULL),
(@intake_id, 'Do you have dental insurance?', 'yes_no', NULL, TRUE, 12, NULL),
(@intake_id, 'Insurance Provider', 'text', NULL, FALSE, 13, NULL),
(@intake_id, 'Policy Number', 'text', NULL, FALSE, 14, NULL),
(@intake_id, 'Group Number', 'text', NULL, FALSE, 15, NULL),
(@intake_id, 'How did you hear about us?', 'multiple_choice', '["Google Search", "Facebook/Social Media", "Friend or Family Referral", "Insurance Provider List", "Drove By", "Other"]', FALSE, 16, NULL);

-- Medical History Form
INSERT INTO surveys (name, description, type, estimated_time, thank_you_message) VALUES
('Medical History', 'Please update your medical history', 'medical_history', 4, 'Thank you for updating your medical history. This helps us provide safe, personalized care.');

SET @medical_id = LAST_INSERT_ID();

INSERT INTO survey_questions (survey_id, question_text, question_type, options, is_required, display_order, help_text) VALUES
(@medical_id, 'General Health', 'section_header', NULL, FALSE, 1, NULL),
(@medical_id, 'How would you describe your overall health?', 'multiple_choice', '["Excellent", "Good", "Fair", "Poor"]', TRUE, 2, NULL),
(@medical_id, 'Are you currently under a physician''s care?', 'yes_no', NULL, TRUE, 3, NULL),
(@medical_id, 'If yes, please explain', 'textarea', NULL, FALSE, 4, NULL),
(@medical_id, 'Medical Conditions', 'section_header', NULL, FALSE, 5, 'Check all that apply'),
(@medical_id, 'Do you have any of the following conditions?', 'checkbox', '["Heart Disease", "High Blood Pressure", "Diabetes", "Asthma", "Arthritis", "Thyroid Problems", "Hepatitis", "HIV/AIDS", "Cancer", "Stroke", "Kidney Disease", "Liver Disease", "None of the above"]', TRUE, 6, NULL),
(@medical_id, 'Allergies', 'section_header', NULL, FALSE, 7, NULL),
(@medical_id, 'Are you allergic to any medications?', 'yes_no', NULL, TRUE, 8, NULL),
(@medical_id, 'Please list any medication allergies', 'textarea', NULL, FALSE, 9, NULL),
(@medical_id, 'Are you allergic to latex?', 'yes_no', NULL, TRUE, 10, NULL),
(@medical_id, 'Are you allergic to any metals?', 'yes_no', NULL, TRUE, 11, NULL),
(@medical_id, 'Medications', 'section_header', NULL, FALSE, 12, NULL),
(@medical_id, 'Please list all medications you are currently taking', 'textarea', NULL, FALSE, 13, 'Include vitamins and supplements'),
(@medical_id, 'Are you taking blood thinners?', 'yes_no', NULL, TRUE, 14, NULL),
(@medical_id, 'Dental History', 'section_header', NULL, FALSE, 15, NULL),
(@medical_id, 'When was your last dental visit?', 'multiple_choice', '["Within the last 6 months", "6-12 months ago", "1-2 years ago", "More than 2 years ago", "I don''t remember"]', TRUE, 16, NULL),
(@medical_id, 'Do you have any dental concerns you''d like to discuss?', 'textarea', NULL, FALSE, 17, NULL),
(@medical_id, 'Acknowledgment', 'section_header', NULL, FALSE, 18, NULL),
(@medical_id, 'I certify that the above information is accurate to the best of my knowledge', 'yes_no', NULL, TRUE, 19, NULL),
(@medical_id, 'Patient Signature', 'signature', NULL, TRUE, 20, NULL);

-- Create indexes
CREATE INDEX idx_responses_survey ON survey_responses(survey_id);
CREATE INDEX idx_responses_date ON survey_responses(completed_at);
CREATE INDEX idx_answers_response ON survey_answers(response_id);
CREATE INDEX idx_questions_survey ON survey_questions(survey_id);
