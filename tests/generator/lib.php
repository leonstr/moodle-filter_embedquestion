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

use filter_embedquestion\attempt;
use filter_embedquestion\embed_id;
use filter_embedquestion\embed_location;
use filter_embedquestion\question_options;

defined('MOODLE_INTERNAL') || die();


/**
 *  Embed question filter test data generator.
 *
 * @package   filter_embedquestion
 * @copyright 2019 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later.
 */
class filter_embedquestion_generator extends component_generator_base {

    /**
     * @var core_question_generator convenient reference to the question generator.
     */
    protected $questiongenerator;

    /**
     * @var int used to generate unique ids.
     */
    protected static $uniqueid = 1;

    public function __construct(testing_data_generator $datagenerator) {
        parent::__construct($datagenerator);
        $this->questiongenerator = $this->datagenerator->get_plugin_generator('core_question');
    }

    /**
     * Use core question generator to create a question that is embeddable.
     *
     * That is, we ensure that the question has an idnumber, and that it is
     * in a category with an idnumber.
     *
     * Do not specify both isset($overrides['category'] and $categoryrecord.
     * (Generally, you don't want to specify either.)
     *
     * @param string $qtype as for {@link core_question_generator::create_question()}
     * @param string|null $which as for {@link core_question_generator::create_question()}
     * @param array|null $overrides as for {@link core_question_generator::create_question()}.
     * @param array $categoryrecord as for {@link core_question_generator::create_question_category()}.
     * @return stdClass the data for the newly created question.
     */
    public function create_embeddable_question(string $qtype, string $which = null,
            array $overrides = null, array $categoryrecord = []): stdClass {

        // Create the category, if one is not specified.
        if (!isset($overrides['category'])) {
            if (!isset($categoryrecord['idnumber'])) {
                $categoryrecord['idnumber'] = 'embeddablecat' . (self::$uniqueid++);
            }
            if (isset($categoryrecord['contextid'])) {
                if (context::instance_by_id($categoryrecord['contextid'])->contextlevel !== CONTEXT_COURSE) {
                    throw new coding_exception('Categorycontextid must refer to a course context.');
                }
            } else {
                $categoryrecord['contextid'] = context_course::instance(SITEID)->id;
            }
            $category = $this->questiongenerator->create_question_category($categoryrecord);
            $overrides['category'] = $category->id;
        } else if (!empty($categoryrecord)) {
            // Both $overrides['category'] and $categoryrecord specified.
            throw new coding_exception('You cannot sepecify both the question category, ' .
                    'and details of a category to create.');
        }

        // Create the question.
        if (!isset($overrides['idnumber'])) {
            $overrides['idnumber'] = 'embeddableq' . (self::$uniqueid++);
        }
        return $this->questiongenerator->create_question($qtype, $which, $overrides);
    }

    /**
     * Get the embed id corresponding to a question.
     *
     * @param stdClass $question the question.
     * @return array embed_id and context.
     */
    public function get_embed_id_and_context(stdClass $question): array {
        global $DB;

        if ($question->idnumber === null || $question->idnumber === '') {
            throw new coding_exception('$question->idnumber must be set.');
        }

        $category = $DB->get_record('question_categories', ['id' => $question->category], '*', MUST_EXIST);
        if ($category->idnumber === null || $category->idnumber === '') {
            throw new coding_exception('Category idnumber must be set.');
        }

        $context = context::instance_by_id($category->contextid);
        if ($context->contextlevel !== CONTEXT_COURSE) {
            throw new coding_exception('Categorycontextid must refer to a course context.');
        }

        return [new embed_id($category->idnumber, $question->idnumber), $context];
    }

    /**
     * Create an attempt at a given question by a given user.
     *
     * @param stdClass $question the question to attempt.
     * @param stdClass $user the user making the attempt.
     * @param string $response Response to submit. (Sent to the
     *      un_summarise_response method of the correspnoding question type).
     * @return attempt the newly generated attempt.
     */
    public function create_attempt_at_embedded_question(stdClass $question,
            stdClass $user, string $response): attempt {

        [$embedid, $context] = $this->get_embed_id_and_context($question);

        $embedlocation = embed_location::make_for_test($context, $context->get_url(), 'Test embed location');
        $options = new question_options();
        $options->behaviour = 'immediatefeedback';

        $attempt = new attempt($embedid, $embedlocation, $user, $options);
        $this->verify_attempt_valid($attempt);
        $attempt->find_or_create_attempt();
        $this->verify_attempt_valid($attempt);

        $postdata = $this->questiongenerator->get_simulated_post_data_for_questions_in_usage(
                $attempt->get_question_usage(), [$attempt->get_slot() => $response], true);
        $attempt->process_submitted_actions($postdata);

        return $attempt;
    }

    /**
     * Helper: throw an exception if attempt is not valid.
     *
     * @param attempt $attempt the attempt to check.
     */
    protected function verify_attempt_valid(attempt $attempt): void {
        if (!$attempt->is_valid()) {
            throw new coding_exception($attempt->get_problem_description());
        }
    }
}
