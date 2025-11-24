<?php
/**
 * Fichier pour récupérer les cours où l'utilisateur connecté est inscrit (tous rôles confondus)
 * Avec calcul de la progression basée sur l'achèvement des activités et gestion des restrictions de groupe
 * 
 * À placer dans: /ifen_html/mes_formations/get_my_formations.php
 * 
 * @author Boris - 24/11/2025
 * @updated 24/11/2025 - Ajout progression avec restrictions de groupe
 */

// Inclure les fichiers de configuration Moodle
require_once('../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/lib/completionlib.php');

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
    
    // Construire la requête SQL de base
    // On récupère tous les cours où l'utilisateur a une inscription (peu importe le rôle)
    $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.startdate, c.enddate, c.visible, c.enablecompletion
            FROM {course} c
            INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
            INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id
            WHERE ra.userid = :userid
            AND c.id != 1";  // Exclure le site principal
    
    $params = array(
        'userid' => $USER->id
    );
    
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
        
        // Récupérer les groupes auxquels l'utilisateur appartient dans ce cours
        $user_groups = $DB->get_records_sql(
            "SELECT g.id, g.name 
             FROM {groups} g
             INNER JOIN {groups_members} gm ON gm.groupid = g.id
             WHERE g.courseid = :courseid AND gm.userid = :userid",
            array('courseid' => $course->id, 'userid' => $USER->id)
        );
        $user_group_ids = array_keys($user_groups);
        
        // Calculer la progression si l'achèvement est activé pour ce cours
        $progress_data = calculate_user_progress($course->id, $USER->id, $user_group_ids);
        
        // Récupérer le rôle de l'utilisateur dans ce cours
        $user_roles_sql = "SELECT r.shortname, r.name
                          FROM {role} r
                          INNER JOIN {role_assignments} ra ON ra.roleid = r.id
                          INNER JOIN {context} ctx ON ctx.id = ra.contextid
                          WHERE ra.userid = :userid
                          AND ctx.instanceid = :courseid
                          AND ctx.contextlevel = 50";
        $user_roles = $DB->get_records_sql($user_roles_sql, array(
            'userid' => $USER->id,
            'courseid' => $course->id
        ));
        
        // Formater les rôles
        $roles_array = array();
        foreach ($user_roles as $role) {
            $roles_array[] = $role->shortname;
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
            'roles' => $roles_array,
            'url' => $course_url->out(false),
            'visible' => $course->visible,
            'enablecompletion' => $course->enablecompletion,
            'progress' => $progress_data
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

/**
 * Calcule la progression de l'utilisateur dans un cours
 * en tenant compte des restrictions de groupe
 *
 * @param int $courseid L'ID du cours
 * @param int $userid L'ID de l'utilisateur
 * @param array $user_group_ids Les IDs des groupes auxquels l'utilisateur appartient
 * @return array Les données de progression
 */
function calculate_user_progress($courseid, $userid, $user_group_ids) {
    global $DB;
    
    // Récupérer toutes les activités avec achèvement activé dans ce cours
    // completion: 0 = pas de suivi, 1 = manuel, 2 = automatique
    $sql = "SELECT cm.id, cm.module, cm.instance, cm.completion, cm.availability, m.name as modname
            FROM {course_modules} cm
            INNER JOIN {modules} m ON m.id = cm.module
            WHERE cm.course = :courseid
            AND cm.completion > 0
            AND cm.deletioninprogress = 0
            AND cm.visible = 1";
    
    $activities = $DB->get_records_sql($sql, array('courseid' => $courseid));
    
    if (empty($activities)) {
        return array(
            'total' => 0,
            'completed' => 0,
            'percentage' => 0,
            'has_completion' => false
        );
    }
    
    $total_activities = 0;
    $completed_activities = 0;
    
    foreach ($activities as $activity) {
        // Vérifier si l'activité est accessible à l'utilisateur (restrictions de groupe)
        if (!is_activity_accessible_to_user($activity, $user_group_ids)) {
            continue; // Cette activité n'est pas accessible à l'utilisateur, on l'ignore
        }
        
        $total_activities++;
        
        // Vérifier si l'activité est complétée par l'utilisateur
        // completionstate: 0 = incomplet, 1 = complet, 2 = complet avec passage, 3 = complet avec échec
        $completion = $DB->get_record('course_modules_completion', array(
            'coursemoduleid' => $activity->id,
            'userid' => $userid
        ));
        
        if ($completion && ($completion->completionstate == 1 || $completion->completionstate == 2)) {
            // Complet (1) ou complet avec passage (2) = considéré comme terminé avec succès
            $completed_activities++;
        }
    }
    
    // Calculer le pourcentage
    $percentage = $total_activities > 0 ? round(($completed_activities / $total_activities) * 100) : 0;
    
    return array(
        'total' => $total_activities,
        'completed' => $completed_activities,
        'percentage' => $percentage,
        'has_completion' => $total_activities > 0
    );
}

/**
 * Vérifie si une activité est accessible à l'utilisateur en fonction des restrictions de groupe
 *
 * @param object $activity L'activité à vérifier
 * @param array $user_group_ids Les IDs des groupes auxquels l'utilisateur appartient
 * @return bool True si l'activité est accessible, false sinon
 */
function is_activity_accessible_to_user($activity, $user_group_ids) {
    // Si pas de restriction d'accès, l'activité est accessible à tous
    if (empty($activity->availability)) {
        return true;
    }
    
    // Décoder le JSON des restrictions
    $availability = json_decode($activity->availability);
    
    if (!$availability) {
        return true; // JSON invalide, on considère l'activité accessible
    }
    
    // Analyser les restrictions de groupe
    return check_group_restrictions($availability, $user_group_ids);
}

/**
 * Vérifie récursivement les restrictions de groupe dans les conditions d'accès
 *
 * @param object $availability Les conditions d'accès
 * @param array $user_group_ids Les IDs des groupes auxquels l'utilisateur appartient
 * @return bool True si l'utilisateur satisfait les conditions de groupe
 */
function check_group_restrictions($availability, $user_group_ids) {
    // Si c'est une condition simple (pas d'opérateur)
    if (!isset($availability->op)) {
        // Vérifier si c'est une restriction de groupe
        if (isset($availability->type) && $availability->type === 'group') {
            if (isset($availability->id)) {
                // Restriction sur un groupe spécifique
                return in_array($availability->id, $user_group_ids);
            } else {
                // Restriction "doit appartenir à un groupe" (n'importe lequel)
                return !empty($user_group_ids);
            }
        }
        // Ce n'est pas une restriction de groupe, on considère que c'est accessible
        return true;
    }
    
    // Si c'est un ensemble de conditions
    if (isset($availability->c) && is_array($availability->c)) {
        $has_group_restriction = false;
        $group_condition_met = false;
        
        foreach ($availability->c as $condition) {
            // Vérifier récursivement
            if (isset($condition->type) && $condition->type === 'group') {
                $has_group_restriction = true;
                
                if (isset($condition->id)) {
                    // Restriction sur un groupe spécifique
                    if (in_array($condition->id, $user_group_ids)) {
                        $group_condition_met = true;
                    }
                } else {
                    // Restriction "doit appartenir à un groupe"
                    if (!empty($user_group_ids)) {
                        $group_condition_met = true;
                    }
                }
            } else if (isset($condition->op)) {
                // Condition imbriquée
                $nested_result = check_group_restrictions($condition, $user_group_ids);
                if (isset($condition->c)) {
                    foreach ($condition->c as $nested_cond) {
                        if (isset($nested_cond->type) && $nested_cond->type === 'group') {
                            $has_group_restriction = true;
                            if ($nested_result) {
                                $group_condition_met = true;
                            }
                        }
                    }
                }
            }
        }
        
        // S'il n'y a pas de restriction de groupe, l'activité est accessible
        if (!$has_group_restriction) {
            return true;
        }
        
        // Selon l'opérateur
        switch ($availability->op) {
            case '&': // AND - toutes les conditions doivent être vraies
                // Pour les restrictions de groupe avec AND, on vérifie si au moins une condition de groupe est satisfaite
                // car généralement on ne met pas plusieurs groupes en AND
                return $group_condition_met;
                
            case '|': // OR - au moins une condition doit être vraie
                return $group_condition_met;
                
            case '!&': // NOT AND
                return !$group_condition_met;
                
            case '!|': // NOT OR
                return !$group_condition_met;
                
            default:
                return true;
        }
    }
    
    return true;
}