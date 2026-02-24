# Dental Patient Survey App

A touch-friendly patient survey system designed for dental practice waiting rooms and exit surveys. Optimized for iPad kiosk mode.

## Features

### Patient-Facing Kiosk (index.php)
- **Touch-optimized interface** - Large buttons, intuitive navigation
- **Multiple survey types** - Exit surveys, patient intake, medical history, satisfaction surveys
- **Question types**:
  - 5-star ratings
  - NPS (Net Promoter Score) 0-10 scale
  - Yes/No questions
  - Multiple choice
  - Checkboxes (multi-select)
  - Text input
  - Text areas
  - Date picker
  - Signature capture
- **Auto-reset** - Returns to home screen after inactivity
- **Progress tracking** - Visual progress bar
- **Customizable branding** - Practice name, colors

### Admin Dashboard (admin.php)
- **Overview stats** - Total responses, today's responses, average rating, NPS score
- **Response timeline chart** - Visualize trends over 7/30/90 days
- **Rating breakdown** - See how individual questions perform
- **Response viewer** - View individual survey submissions
- **Filter by survey type**

## Pre-loaded Surveys

1. **Exit Survey** - Quick 1-minute feedback after visits
2. **Patient Satisfaction Survey** - Detailed 3-minute experience review
3. **New Patient Intake** - Contact info, emergency contacts, insurance, referral source
4. **Medical History** - Health conditions, allergies, medications, dental history

## Installation

### 1. Database Setup

```bash
mysql -u your_username -p < database.sql
```

Or import `database.sql` via phpMyAdmin.

### 2. Configure Database

Edit `config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'dental_surveys');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

### 3. Upload Files

Upload all files to your web server:
- `index.php` - Patient kiosk
- `admin.php` - Admin dashboard
- `config.php` - Configuration

### 4. Access

- **Kiosk**: `https://yourdomain.com/dental-survey/`
- **Admin**: `https://yourdomain.com/dental-survey/admin.php`

**Default admin login:**
- Username: `admin`
- Password: `admin123`

⚠️ **Change the default password immediately!**

## iPad Kiosk Setup

### Enable Guided Access (Kiosk Mode)

1. Go to **Settings > Accessibility > Guided Access**
2. Turn on **Guided Access**
3. Set a passcode
4. Open Safari and navigate to your survey URL
5. Triple-click the side button to start Guided Access
6. Tap **Start**

### Add to Home Screen

1. Open Safari
2. Navigate to your survey URL
3. Tap the Share button
4. Tap "Add to Home Screen"
5. Name it "Patient Survey"

### Recommended iPad Settings

- **Auto-Lock**: Never (Settings > Display & Brightness)
- **Guided Access**: Enabled
- **Do Not Disturb**: Enabled during office hours

## Customization

### Change Practice Branding

Update the `practices` table:

```sql
UPDATE practices SET 
    name = 'Your Practice Name',
    primary_color = '#your-color-hex'
WHERE id = 1;
```

### Add Custom Surveys

1. Insert into `surveys` table
2. Add questions to `survey_questions` table

### Question Types Reference

| Type | Description |
|------|-------------|
| `rating_5` | 5-star rating |
| `rating_10` | 10-point scale |
| `nps` | Net Promoter Score (0-10) |
| `yes_no` | Yes/No buttons |
| `multiple_choice` | Single select from options |
| `checkbox` | Multi-select from options |
| `text` | Single line text input |
| `textarea` | Multi-line text input |
| `date` | Date picker |
| `signature` | Signature capture pad |
| `section_header` | Visual section divider |

### Adding Questions

```sql
INSERT INTO survey_questions 
(survey_id, question_text, question_type, options, is_required, display_order) 
VALUES 
(1, 'How was your experience?', 'rating_5', NULL, TRUE, 1),
(1, 'Select your appointment type', 'multiple_choice', '["Cleaning", "Filling", "Consultation", "Other"]', TRUE, 2);
```

## Security Recommendations

1. **Change default admin password**
2. **Use HTTPS**
3. **Add authentication for admin area** (enhance the basic auth provided)
4. **Consider IP restrictions** for admin access
5. **Regular database backups**

## File Structure

```
dental-survey/
├── index.php      # Patient-facing kiosk
├── admin.php      # Admin dashboard
├── config.php     # Database configuration
├── database.sql   # Database schema
└── README.md      # This file
```

## Requirements

- PHP 7.4+
- MySQL 5.7+ or MariaDB 10.3+
- Modern web browser (Safari for iPad recommended)

## NPS Score Calculation

NPS = % Promoters - % Detractors

- **Promoters**: Score 9-10
- **Passives**: Score 7-8
- **Detractors**: Score 0-6

## License

Free for use by dental practices.

---

## Quick Start Checklist

- [ ] Import database.sql
- [ ] Update config.php with database credentials
- [ ] Upload files to server
- [ ] Login to admin.php and change password
- [ ] Update practice name in database
- [ ] Test surveys on iPad
- [ ] Enable Guided Access for kiosk mode
- [ ] Place iPad in waiting room!
