<?php

// Error Reporting

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Require Composer Autoloader
require '/home/zacharykai/.config/composer/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Sanitize Captcha Input (remove invisible characters and normalize whitespace)

function sanitize_captcha($input) {
    // Remove common invisible Unicode characters
    $invisible_chars = [
        "\u{00A0}",   // Non-breaking space
        "\u{200B}",   // Zero-width space
        "\u{200C}",   // Zero-width non-joiner
        "\u{200D}",   // Zero-width joiner
        "\u{FEFF}",   // Byte order mark
        "\u{00AD}",   // Soft hyphen
        "\u{2060}",   // Word joiner
        "\u{180E}",   // Mongolian vowel separator
        "\u{2000}",   // En quad
        "\u{2001}",   // Em quad
        "\u{2002}",   // En space
        "\u{2003}",   // Em space
        "\u{2004}",   // Three-per-em space
        "\u{2005}",   // Four-per-em space
        "\u{2006}",   // Six-per-em space
        "\u{2007}",   // Figure space
        "\u{2008}",   // Punctuation space
        "\u{2009}",   // Thin space
        "\u{200A}",   // Hair space
        "\u{202F}",   // Narrow no-break space
        "\u{205F}",   // Medium mathematical space
        "\u{3000}",   // Ideographic space
    ];

    $input = str_replace($invisible_chars, ' ', $input);

    // Normalize multiple spaces to single space
    $input = preg_replace('/\s+/', ' ', $input);

    return trim($input);
}

// Captcha Variations (accepted alternatives for each form)

$captcha_variations = [
    'crosscurrent' => [
        'Crosswinds + Currents Directory',
        'Crosswinds and Currents Directory',
        'Crosswinds + Currents',
        'Crosswinds and Currents',
        'Crosswinds Currents Directory',
        'Crosswinds Currents',
        '<h1>Crosswinds + Currents Directory</h1>',
    ],
    'fanfic' => [
        'Fanfic Writers Collective',
        'Fanfic Writer Collective',
        'Fanfic Writers',
        'Fan Fic Writers Collective',
        '<h1>Fanfic Writers Collective</h1>',
    ],
    'guestbook' => [
        'Guestbook',
        'Guest Book',
        'Guest book',
        '<h1>Guestbook/h1>',
    ],
    'oceania' => [
        'Oceania Web Atlas',
        'Oceania Web Directory',
        'Oceania Atlas',
        'Oceania Directory',
        '<h1>Oceania Web Atlas</h1>',
    ],
    'htmlsite' => [
        'Static HTML Site Directory',
        'Static HTML Sites Directory',
        'Static HTML Site',
        'Static HTML Sites',
        'HTML Site Directory',
        'HTML Sites Directory',
        '<h1>Static HTML Site Directory</h1>',
    ],
    'unoffice' => [
        'UnOffice Hours',
        'Unoffice Hours',
        'Un-Office Hours',
        'Un Office Hours',
        '<h1>UnOffice Hours</h1>',
    ],
    'queer' => [
        'Queer Web Directory',
        'Queer Directory',
        'Queer Web',
        '<h1>Queer Web Directory</h1>',
    ],
    'starwars' => [
        'Star Wars Webring',
        'Star Wars Web Ring',
        'Starwars Webring',
        'Starwars Web Ring',
        '<h1>Star Wars Webring</h1>',
    ],
    'proust' => [
        'Proust Questionnaire Directory',
        'Proust Questionnaire',
        'Proust Directory',
        '<h1>Proust Questionnaire Directory</h1>',
    ],
    'titletrade' => [
        'Title Traders',
        'Title Trader',
        'TitleTraders',
        '<h1>Title Traders</h1>',
    ],
    'vintagecars' => [
        'Vintage Car Webring',
        'Vintage Cars Webring',
        'Vintage Car Web Ring',
        'Vintage Cars Web Ring',
        '<h1>Vintage Car Webring</h1>',
    ],
    'guestbooks' => [
        'Guestbooks Galore',
        'Guestbook Galore',
        'Guest Books Galore',
        'Guest Book Galore',
        '<h1>Guestbooks Galore</h1>',
    ],

    // Offerings

    'beta-reading' => [
        'Beta Reading',
        'Beta-Reading',
        'Betareading',
        '<h1>Beta Reading</h1>',
    ],
    'blog-newsletter-setup' => [
        'Blog & Newsletter Setup',
        'Blog and Newsletter Setup',
        'Blog + Newsletter Setup',
        'Blog Newsletter Setup',
        'Blog & Newsletter',
        'Blog and Newsletter',
        '<h1>Blog & Newsletter Setup</h1>',
    ],
    'editing' => [
        'Editing',
        'Editing Services',
        '<h1>Editing</h1>',
    ],
    'ghostwriting' => [
        'Ghostwriting',
        'Ghost Writing',
        'Ghost-Writing',
        '<h1>Ghostwriting</h1>',
    ],
    'sensitivity-reading' => [
        'Sensitivity Reading',
        'Sensitivity-Reading',
        'Sensitivityreading',
        '<h1>Sensitivity Reading</h1>',
    ],
    'transcriptions' => [
        'Transcriptions',
        'Transcription',
        'Transcribing',
        '<h1>Transcriptions</h1>',
    ],
    'website' => [
        'Website Making',
        'Website-Making',
        'Websitemaking',
        'Websites',
        'Website',
        '<h1>Website Making</h1>',
    ],
    'workshops' => [
        'Workshops',
        'Workshop',
        '<h1>Workshops</h1>',
    ],
    'zines' => [
        'Zine-Making',
        'Zine Making',
        'Zinemaking',
        'Zines',
        'Zine',
        '<h1>Zine-Making</h1>',
    ],
];

// Check if captcha matches any accepted variation

function validate_captcha($input, $variations) {
    $sanitized = sanitize_captcha($input);
    foreach ($variations as $variation) {
        if (strcasecmp($sanitized, $variation) === 0) {
            return true;
        }
    }
    return false;
}

// Configuration

$to_email = "hi@zacharykai.net";

// Initialize Response

$response = [
    'success' => false,
    'message' => ''
];

// Check Form Was Submitted Via POST

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Determine Form Type Based On The Hidden Field

    $form_type = $_POST['form_type'] ?? '';
    
    $valid_form = true;
    $email_subject = "";
    $email_body = "";
    $sender_email = "";
    $sender_name = "";
    $expected_captcha = "";
    
    // Process Based On Form Type

    switch ($form_type) {

        // Crosswinds + Currents Directory

        case 'crosscurrent':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Crosswinds + Currents Directory';
            
            if (empty($name) || empty($email) || empty($website) || empty($location)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } elseif (!filter_var($website, FILTER_VALIDATE_URL)) {
                $response['message'] = "Please provide a valid website URL.";
                $valid_form = false;
            } else {
                $email_subject = "Crosswinds + Currents Directory Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Website: $website\n";
                $email_body .= "Location: $location\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Fanfic Writers Collective

        case 'fanfic':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $fandoms = trim($_POST['fandoms'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Fanfic Writers Collective';
            
            if (empty($name) || empty($email) || empty($website) || empty($fandoms)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } elseif (!preg_match('/^https?:\/\/.+\..+/i', $website)) {
                $response['message'] = "Please provide a valid website URL starting with http:// or https://";
                $valid_form = false;
            } else {
                $email_subject = "Fanfic Writers Collective Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Website: $website\n";
                $email_body .= "Fandoms: $fandoms\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;
        
        // Guestbook

        case 'guestbook':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Guestbook';
            
            if (empty($name) || empty($email) || empty($message)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "Guestbook Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                if (!empty($website)) {
                    $email_body .= "Website: $website\n";
                }
                $email_body .= "Message:\n$message\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;
        
        // Oceania Web Directory

        case 'oceania':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Oceania Web Atlas';
            
            if (empty($name) || empty($email) || empty($website) || empty($location)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } elseif (!filter_var($website, FILTER_VALIDATE_URL)) {
                $response['message'] = "Please provide a valid website URL.";
                $valid_form = false;
            } else {
                $email_subject = "Oceania Web Atlas Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Website: $website\n";
                $email_body .= "Location: $location\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;
        
        
        // Static HTML Sites

        case 'htmlsite':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Static HTML Site Directory';
            
            if (empty($name) || empty($email) || empty($website)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } elseif (!preg_match('/^https?:\/\/.+\..+/i', $website)) {
                $response['message'] = "Please provide a valid website URL starting with http:// or https://";
                $valid_form = false;
            } else {
                $email_subject = "Static HTML Site Directory Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Website: $website\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;
        
        // Unoffice Hours

        case 'unoffice':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $calltype = trim($_POST['calltype'] ?? '');
            $timezone = trim($_POST['timezone'] ?? '');
            $intro = trim($_POST['intro'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'UnOffice Hours';
            
            if (empty($name) || empty($email) || empty($calltype) || empty($timezone) || empty($intro)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "UnOffice Hours Booking";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Call Type: $calltype\n";
                $email_body .= "Timezone: $timezone\n";
                $email_body .= "Intro: $intro\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;
        
        // Queer Web Directory

        case 'queer':
            $name = trim($_POST['name'] ?? '');
            $pronouns = trim($_POST['pronouns'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Queer Web Directory';
            
            if (empty($name) || empty($email) || empty($website) || empty($pronouns)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } elseif (!preg_match('/^https?:\/\/.+\..+/i', $website)) {
                $response['message'] = "Please provide a valid website URL starting with http:// or https://";
                $valid_form = false;
            } else {
                $email_subject = "Queer Web Directory Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Pronouns: $pronouns\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Website: $website\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;
        
        // Star Wars Webring

        case 'starwars':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $site_name = trim($_POST['site_name'] ?? '');
            $site_url = trim($_POST['site_url'] ?? '');
            $favorite_character = trim($_POST['favorite_character'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Star Wars Webring';
            
            if (empty($name) || empty($email) || empty($site_name) || empty($site_url)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } elseif (!preg_match('/^https?:\/\/.+\..+/i', $site_url)) {
                $response['message'] = "Please provide a valid website URL starting with http:// or https://";
                $valid_form = false;
            } else {
                $email_subject = "Star Wars Webring Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Site Name: $site_name\n";
                $email_body .= "Site URL: $site_url\n";
                if (!empty($favorite_character)) {
                    $email_body .= "Favorite Star Wars Character: $favorite_character\n";
                }
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Proust Questionnaire Directory

        case 'proust':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Proust Questionnaire Directory';

            if (empty($name) || empty($email) || empty($website)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } elseif (!preg_match('/^https?:\/\/.+\..+/i', $website)) {
                $response['message'] = "Please provide a valid website URL starting with http:// or https://";
                $valid_form = false;
            } else {
                $email_subject = "Proust Questionnaire Directory Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Website: $website\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Title Traders

        case 'titletrade':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Title Traders';

            if (empty($name) || empty($email) || empty($website)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } elseif (!preg_match('/^https?:\/\/.+\..+/i', $website)) {
                $response['message'] = "Please provide a valid website URL starting with http:// or https://";
                $valid_form = false;
            } else {
                $email_subject = "Title Traders Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Website: $website\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Vintage Car Webring

        case 'vintagecars':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $site_name = trim($_POST['site_name'] ?? '');
            $site_url = trim($_POST['site_url'] ?? '');
            $favorite_car_era = trim($_POST['favorite_car_era'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Vintage Car Webring';

            if (empty($name) || empty($email) || empty($site_name) || empty($site_url)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } elseif (!preg_match('/^https?:\/\/.+\..+/i', $site_url)) {
                $response['message'] = "Please provide a valid website URL starting with http:// or https://";
                $valid_form = false;
            } else {
                $email_subject = "Vintage Car Webring Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Site Name: $site_name\n";
                $email_body .= "Site URL: $site_url\n";
                if (!empty($favorite_car_era)) {
                    $email_body .= "Favorite Vintage Car Era: $favorite_car_era\n";
                }
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Guestbooks Galore Directory

        case 'guestbooks':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Guestbooks Galore';

            if (empty($name) || empty($email) || empty($website)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } elseif (!preg_match('/^https?:\/\/.+\..+/i', $website)) {
                $response['message'] = "Please provide a valid website URL starting with http:// or https://";
                $valid_form = false;
            } else {
                $email_subject = "Guestbooks Galore Directory Submission";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Guestbook URL: $website\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Beta Reading

        case 'beta-reading':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $work_type = trim($_POST['work_type'] ?? '');
            $beta_service = trim($_POST['beta_service'] ?? '');
            $word_count = trim($_POST['word_count'] ?? '');
            $timeline = trim($_POST['timeline'] ?? '');
            $project_description = trim($_POST['project_description'] ?? '');
            $budget = trim($_POST['budget'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Beta Reading';

            if (empty($name) || empty($email) || empty($work_type) || empty($beta_service) || empty($word_count) || empty($timeline) || empty($project_description)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "Beta Reading Inquiry";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Type Of Work: $work_type\n";
                $email_body .= "Service: $beta_service\n";
                $email_body .= "Word Count: $word_count\n";
                $email_body .= "Timeline: $timeline\n";
                $email_body .= "Project: $project_description\n";
                $email_body .= "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Blog & Newsletter Setup

        case 'blog-newsletter-setup':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $blog_service = trim($_POST['blog_service'] ?? '');
            $existing_site = trim($_POST['existing_site'] ?? '');
            $timeline = trim($_POST['timeline'] ?? '');
            $project_description = trim($_POST['project_description'] ?? '');
            $budget = trim($_POST['budget'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Blog & Newsletter Setup';

            if (empty($name) || empty($email) || empty($blog_service) || empty($existing_site) || empty($timeline) || empty($project_description)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "Blog & Newsletter Setup Inquiry";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Service: $blog_service\n";
                $email_body .= "Existing Website: $existing_site\n";
                $email_body .= "Timeline: $timeline\n";
                $email_body .= "Project: $project_description\n";
                $email_body .= "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Editing

        case 'editing':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $work_type = trim($_POST['work_type'] ?? '');
            $edit_service = trim($_POST['edit_service'] ?? '');
            $word_count = trim($_POST['word_count'] ?? '');
            $timeline = trim($_POST['timeline'] ?? '');
            $project_description = trim($_POST['project_description'] ?? '');
            $budget = trim($_POST['budget'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Editing';

            if (empty($name) || empty($email) || empty($work_type) || empty($edit_service) || empty($word_count) || empty($timeline) || empty($project_description)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "Editing Inquiry";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Type Of Work: $work_type\n";
                $email_body .= "Service: $edit_service\n";
                $email_body .= "Word Count: $word_count\n";
                $email_body .= "Timeline: $timeline\n";
                $email_body .= "Project: $project_description\n";
                $email_body .= "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Ghostwriting

        case 'ghostwriting':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $work_type = trim($_POST['work_type'] ?? '');
            $ghost_length = trim($_POST['ghost_length'] ?? '');
            $timeline = trim($_POST['timeline'] ?? '');
            $project_description = trim($_POST['project_description'] ?? '');
            $budget = trim($_POST['budget'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Ghostwriting';

            if (empty($name) || empty($email) || empty($work_type) || empty($ghost_length) || empty($timeline) || empty($project_description)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "Ghostwriting Inquiry";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Type Of Work: $work_type\n";
                $email_body .= "Target Length: $ghost_length\n";
                $email_body .= "Timeline: $timeline\n";
                $email_body .= "Project: $project_description\n";
                $email_body .= "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Sensitivity Reading

        case 'sensitivity-reading':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $work_type = trim($_POST['work_type'] ?? '');
            $sens_service = trim($_POST['sens_service'] ?? '');
            $word_count = trim($_POST['word_count'] ?? '');
            $sens_focus = trim($_POST['sens_focus'] ?? '');
            $timeline = trim($_POST['timeline'] ?? '');
            $project_description = trim($_POST['project_description'] ?? '');
            $budget = trim($_POST['budget'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Sensitivity Reading';

            if (empty($name) || empty($email) || empty($work_type) || empty($sens_service) || empty($word_count) || empty($sens_focus) || empty($timeline) || empty($project_description)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "Sensitivity Reading Inquiry";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Type Of Work: $work_type\n";
                $email_body .= "Service: $sens_service\n";
                $email_body .= "Word Count: $word_count\n";
                $email_body .= "Focus: $sens_focus\n";
                $email_body .= "Timeline: $timeline\n";
                $email_body .= "Project: $project_description\n";
                $email_body .= "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Transcriptions

        case 'transcriptions':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $source_type = trim($_POST['source_type'] ?? '');
            $trans_service = trim($_POST['trans_service'] ?? '');
            $length = trim($_POST['length'] ?? '');
            $speakers = trim($_POST['speakers'] ?? '');
            $timeline = trim($_POST['timeline'] ?? '');
            $project_description = trim($_POST['project_description'] ?? '');
            $budget = trim($_POST['budget'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Transcriptions';

            if (empty($name) || empty($email) || empty($source_type) || empty($trans_service) || empty($length) || empty($timeline) || empty($project_description)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "Transcription Inquiry";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Source Material: $source_type\n";
                $email_body .= "Service: $trans_service\n";
                $email_body .= "Length: $length\n";
                $email_body .= "Speakers: " . ($speakers !== '' ? $speakers : 'Not specified') . "\n";
                $email_body .= "Timeline: $timeline\n";
                $email_body .= "Project: $project_description\n";
                $email_body .= "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Website Making

        case 'website':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $package_type = trim($_POST['package_type'] ?? '');
            $website_purpose = trim($_POST['website_purpose'] ?? '');
            $timeline = trim($_POST['timeline'] ?? '');
            $project_description = trim($_POST['project_description'] ?? '');
            $budget = trim($_POST['budget'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Website Making';

            if (empty($name) || empty($email) || empty($package_type) || empty($website_purpose) || empty($timeline) || empty($project_description)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "Website Making Inquiry";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Package: $package_type\n";
                $email_body .= "Purpose: $website_purpose\n";
                $email_body .= "Timeline: $timeline\n";
                $email_body .= "Project: $project_description\n";
                $email_body .= "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Workshops

        case 'workshops':
            $name = trim($_POST['name'] ?? '');
            $group_name = trim($_POST['group_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $workshop_type = trim($_POST['workshop_type'] ?? '');
            $participants = trim($_POST['participants'] ?? '');
            $age_range = trim($_POST['age_range'] ?? '');
            $potential_dates = trim($_POST['potential_dates'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $budget = trim($_POST['budget'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Workshops';

            if (empty($name) || empty($group_name) || empty($email) || empty($workshop_type) || empty($participants) || empty($age_range) || empty($potential_dates) || empty($message)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "Workshop Inquiry";
                $email_body .= "Name: $name\n";
                $email_body .= "Group: $group_name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Workshop: $workshop_type\n";
                $email_body .= "Participants: $participants\n";
                $email_body .= "Age Range: $age_range\n";
                $email_body .= "Potential Dates: $potential_dates\n";
                $email_body .= "Details: $message\n";
                $email_body .= "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        // Zine-Making

        case 'zines':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $zine_type = trim($_POST['zine_type'] ?? '');
            $page_count = trim($_POST['page_count'] ?? '');
            $timeline = trim($_POST['timeline'] ?? '');
            $project_description = trim($_POST['project_description'] ?? '');
            $budget = trim($_POST['budget'] ?? '');
            $captcha = trim($_POST['captcha'] ?? '');
            $expected_captcha = 'Zine-Making';

            if (empty($name) || empty($email) || empty($zine_type) || empty($page_count) || empty($timeline) || empty($project_description)) {
                $response['message'] = "Please fill in all required fields.";
                $valid_form = false;
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = "Please provide a valid email address.";
                $valid_form = false;
            } else {
                $email_subject = "Zine-Making Inquiry";
                $email_body .= "Name: $name\n";
                $email_body .= "Email: $email\n";
                $email_body .= "Zine Type: $zine_type\n";
                $email_body .= "Page Count: $page_count\n";
                $email_body .= "Timeline: $timeline\n";
                $email_body .= "Project: $project_description\n";
                $email_body .= "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n";
                $sender_email = $email;
                $sender_name = $name;
            }
            break;

        default:
            $response['message'] = "Invalid form type.";
            $valid_form = false;
    }
    
    // Check Captcha

    if ($valid_form && isset($captcha_variations[$form_type])) {
        $captcha = $_POST['captcha'] ?? '';
        if (!validate_captcha($captcha, $captcha_variations[$form_type])) {
            $response['message'] = "Please enter the page's title.";
            $valid_form = false;
        }
    }
    
    // Send Email If Form Is Valid

    if ($valid_form) {

        // Retrieve SMTP Credentials

        $smtpUsername = getenv('FASTMAIL_SMTP_USERNAME');
        $smtpPassword = getenv('FASTMAIL_SMTP_PASSWORD');
        
        // Create New PHPMailer Instance

        $mail = new PHPMailer(true);
        try {

            // Server settings

            $mail->isSMTP();
            $mail->Host       = 'smtp.fastmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpUsername;
            $mail->Password   = $smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            
            // Sender & Recipient settings

            $mail->setFrom('site@zacharykai.net', 'ZK Site');
            if (!empty($sender_email) && !empty($sender_name)) {
                $mail->addReplyTo($sender_email, $sender_name);
            }
            $mail->addAddress($to_email, 'Zachary Kai');
            
            $mail->isHTML(false);
            $mail->Subject = $email_subject;
            $mail->Body    = $email_body;
            
            $mail->send();
            
            // Redirect To Success Page If Email Sent

            header('Location: /successful');
            exit();

        } catch (Exception $e) {
            $response['message'] = "There was an error sending your submission. Mailer Error: " . $mail->ErrorInfo;
        }
    }

} else {
    $response['message'] = "Invalid request method.";
}

// Output Response Message If Not Redirected

echo $response['message'];
?>