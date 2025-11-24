<?php
/**
 * Fichier pour récupérer les cours où l'utilisateur connecté est "editing teacher"
 * À placer dans: /local/ifen/get_my_prestations.php
 */

// Inclure les fichiers de configuration Moodle
require_once('../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

// Vérifier que l'utilisateur est connecté
require_login();

// S'assurer que c'est une requête AJAX
if (!defined('AJAX_SCRIPT')) {
    define('AJAX_SCRIPT', true);
}

// Définir le type de contenu JSON
header('Content-Type: application/json');

try {
    global $DB, $USER, $CFG;
    
    // Récupérer les paramètres de recherche
    $search = optional_param('search', '', PARAM_TEXT);
    $period = optional_param('period', '', PARAM_TEXT);
    
    // ID du rôle "editing teacher" (généralement 3 dans Moodle, mais il vaut mieux le chercher)
    $editingteacher_role = $DB->get_record('role', array('shortname' => 'editingteacher'));
    
    if (!$editingteacher_role) {
        throw new Exception('Le rôle "editing teacher" n\'a pas été trouvé');
    }
    
    // Construire la requête SQL de base
    $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.startdate, c.enddate, c.visible
            FROM {course} c
            INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
            INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
            WHERE ra.userid = :userid
            AND ra.roleid = :roleid
            AND c.id != 1";  // Exclure le site principal
    
    $params = array(
        'userid' => $USER->id,
        'roleid' => $editingteacher_role->id
    );
    
    // Exclure les cours ePortfolio si nécessaire
    $sql .= " AND c.fullname NOT LIKE '%ePortfolio%'";
    
    // Ajouter le filtre de recherche si présent
    if (!empty($search)) {
        $sql .= " AND (c.fullname LIKE :search1 OR c.shortname LIKE :search2)";
        $params['search1'] = '%' . $DB->sql_like_escape($search) . '%';
        $params['search2'] = '%' . $DB->sql_like_escape($search) . '%';
    }
    
    // Ajouter le filtre de période
    $current_time = time();
    switch ($period) {
        case 'current':
            // Cours en cours (startdate <= maintenant <= enddate)
            $sql .= " AND c.startdate <= :now1 AND (c.enddate = 0 OR c.enddate >= :now2)";
            $params['now1'] = $current_time;
            $params['now2'] = $current_time;
            break;
            
        case 'past':
            // Cours passés (enddate < maintenant)
            $sql .= " AND c.enddate > 0 AND c.enddate < :now";
            $params['now'] = $current_time;
            break;
            
        case 'future':
            // Cours à venir (startdate > maintenant)
            $sql .= " AND c.startdate > :now";
            $params['now'] = $current_time;
            break;
            
        case 'last30':
            // 30 derniers jours
            $thirty_days_ago = $current_time - (30 * 24 * 60 * 60);
            $sql .= " AND c.startdate >= :thirty_days";
            $params['thirty_days'] = $thirty_days_ago;
            break;
            
        case 'last90':
            // 90 derniers jours
            $ninety_days_ago = $current_time - (90 * 24 * 60 * 60);
            $sql .= " AND c.startdate >= :ninety_days";
            $params['ninety_days'] = $ninety_days_ago;
            break;
            
        case 'thisyear':
            // Cette année
            $year_start = mktime(0, 0, 0, 1, 1, date('Y'));
            $sql .= " AND c.startdate >= :year_start";
            $params['year_start'] = $year_start;
            break;
    }
    
    // Trier par date de début (les plus récents en premier)
    $sql .= " ORDER BY c.startdate DESC";
    
    // Exécuter la requête
    $courses = $DB->get_records_sql($sql, $params);
    
    // Préparer les données pour le JSON
    $courses_array = array();
    
    foreach ($courses as $course) {
        // Déterminer le statut du cours
        $status = 'past';
        if ($course->startdate > $current_time) {
            $status = 'future';
        } elseif ($course->enddate == 0 || $course->enddate >= $current_time) {
            $status = 'current';
        }
        
        // Compter les participants (étudiants) dans le cours
        $context = context_course::instance($course->id);
        $student_role = $DB->get_record('role', array('shortname' => 'student'));
        $participants_count = 0;
        
        if ($student_role) {
            $participants_count = $DB->count_records('role_assignments', array(
                'contextid' => $context->id,
                'roleid' => $student_role->id
            ));
        }
        
        // Formater les dates
        $startdate_formatted = $course->startdate > 0 ? userdate($course->startdate, '%d/%m/%Y') : 'Non défini';
        $enddate_formatted = $course->enddate > 0 ? userdate($course->enddate, '%d/%m/%Y') : 'Non défini';
        
        // URL du cours
        $course_url = new moodle_url('/course/view.php', array('id' => $course->id));
        
        $courses_array[] = array(
            'id' => $course->id,
            'fullname' => format_string($course->fullname),
            'shortname' => format_string($course->shortname),
            'startdate' => $startdate_formatted,
            'enddate' => $enddate_formatted,
            'status' => $status,
            'participants' => $participants_count,
            'url' => $course_url->out(false),
            'visible' => $course->visible
        );
    }
    
    // Retourner les résultats en JSON
    echo json_encode(array(
        'success' => true,
        'courses' => $courses_array,
        'count' => count($courses_array),
        'userid' => $USER->id
    ));
    
} catch (Exception $e) {
    // En cas d'erreur, retourner un message d'erreur en JSON
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error' => $e->getMessage()
    ));
}