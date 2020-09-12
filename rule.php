<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * FaceID quiz access plugin
 *
 * @package    quizaccess_faceid
 * @copyright  2020 Lobster Pizza <lobznet@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/accessrule/accessrulebase.php');


class quizaccess_faceid extends quiz_access_rule_base
{

    public static function make(quiz $quizobj, $timenow, $canignoretimelimits)
    {
        $cm = $quizobj->get_cm();
        if (is_null($cm)) {
            return null;
        } else if (!self::is_enabled_in_quizid($cm->id)) {
            return null;
        }
        return new self($quizobj, $timenow);
    }

    public static function save_settings($quiz)
    {
        global $DB;

        if (isset($quiz->faceidenabled) && ($quiz->faceidenabled == 1) && !self::is_enabled_in_quizid((int)$quiz->coursemodule)) {
            $DB->insert_record('quizaccess_faceid', (object)['quizid' => $quiz->coursemodule]);
        } else {
            $DB->delete_records('quizaccess_faceid', ['quizid' => $quiz->coursemodule]);
        }
    }

    public function description()
    {
        return get_string('faceidrule', 'quizaccess_faceid');
    }

    /**
     * Add any fields that this rule requires to the quiz settings form. This
     * method is called from {@link mod_quiz_mod_form::definition()}, while the
     * security seciton is being built.
     * @param mod_quiz_mod_form $quizform the quiz settings form that is being built.
     * @param MoodleQuickForm $mform the wrapped MoodleQuickForm.
     */

    public static function add_settings_form_fields(mod_quiz_mod_form $quizform, MoodleQuickForm $mform)
    {

        $mform->addElement('advcheckbox', 'faceidenabled', get_string('enable', 'quizaccess_faceid'),
            get_string('enabledesc', 'quizaccess_faceid'));
        $cm = $quizform->get_coursemodule();
        if (empty($cm)) {
            $default = false;
        } else {
            $default = self::is_enabled_in_quizid($cm->id);
        }
        $mform->setDefault('faceidenabled', $default);
    }

    /**
     * Return true if enabled on given quiz
     *
     * @param quiz $quiz
     * @return bool
     */
    private static function is_enabled_in_quizid(int $quizid): bool
    {
        global $DB;
        return $DB->record_exists('quizaccess_faceid', ['quizid' => $quizid]);
    }

    /**
     * Whether the user should be blocked from starting a new attempt or continuing
     * an attempt now.
     * @return string false if access should be allowed, a message explaining the
     *      reason if access should be prevented.
     */
    public function prevent_access()
    {
        if(!$vars = $this->get_vars()) {
            return 'Something went wrong';
        }

        $response = $this->post_data($vars['auth_url'], array(
            'cm_id' => $vars['cm_id'],
            'quiz_id' => $vars['quiz_id'],
            'metric_id' => $vars['student_id'],
            'sesskey' => $vars['sesskey'],
            'auth_id' => $vars['auth_id'],
            'page' => $_SERVER['PHP_SELF'],
            'referer' => $_SERVER['HTTP_REFERER'],
            'test_mode' => $vars['test_mode']
        ), $vars['timeout']);

        if ($response === false && !$vars['test_mode']) {
            return 'Connection error';
        }

        $response_data = json_decode($response);

        if ($response_data->{'auth_id'})
            redirect($vars['auth_url'] . '?auth_id=' . $response_data->{'auth_id'});

        return false;
    }

    function post_data($auth_url, $data, $timeout=120)
    {
        $headers = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'timeout' => $timeout,
                'content' => http_build_query($data, '', '&')
            )
        ));
        return file_get_contents($auth_url, false, $headers);
    }

    function get_vars()
    {
        global $USER, $DB;

        $vars = array();

        $config = get_config('quizaccess_faceid');
        if (!$config->authurl) {
            return false;
        }
        $vars['auth_url'] = $config->authurl;
        $vars['test_mode'] = $config->testmode;

        if ($config->timeout > 0 && $config->timeout < 120) {
            $vars['timeout'] = $config->timeout;
        } else {
            $vars['timeout'] = 120;
        }

        $cm = get_coursemodule_from_instance('quiz', $this->quiz->id);
        $vars['cm_id'] = $cm->id;
        $vars['quiz_id'] = $this->quiz->id;
        $vars['student_id'] = $DB->get_record('user', array('id' => $USER->id), 'idnumber', MUST_EXIST)->idnumber;
        $vars['sesskey'] = sesskey();
        $vars['auth_id'] = optional_param('auth_id', 0, PARAM_TEXT) ?: false;

        return $vars;
    }

}
