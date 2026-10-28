<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_ltiaccountmerge\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\tests\provider_testcase;
use local_ltiaccountmerge\local\audit;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/helper.php');

/**
 * Privacy provider tests.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\privacy\provider
 */
final class provider_test extends provider_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * Create data for a user in every table.
     *
     * @return \stdClass[] [user, other user]
     */
    protected function seed(): array {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        audit::log('link', audit::OK, 'identity', 1, $user->id, ['ref' => 'abcdef0123456789'], null, $other->id);
        audit::log('policycreated', audit::OK, 'policy', 1, 0, [], null, $user->id);
        audit::log('unrelated', audit::OK, 'policy', 2, 0, [], null, $other->id);
        $DB->insert_record('local_ltiaccountmerge_request', (object) ['issuerhash' => 'a', 'subhash' => 'b',
            'issuer' => 'https://x.example',
            'sub' => 'sub-1', 'targetuserid' => $user->id, 'requestedby' => $other->id, 'codehash' => 'c', 'status' => 'pending',
            'attempts' => 0, 'timecreated' => time(), 'timeexpires' => time() + 60, 'timecompleted' => 0]);
        $DB->insert_record('local_ltiaccountmerge_request', (object) ['issuerhash' => 'a2', 'subhash' => 'b2',
            'issuer' => 'https://y.example', 'sub' => 'sub-2', 'targetuserid' => $other->id, 'requestedby' => $other->id,
            'codehash' => 'c', 'status' => 'pending',
            'attempts' => 0, 'timecreated' => time(), 'timeexpires' => time() + 60, 'timecompleted' => 0]);
        $this->create_policy(['name' => 'By user', 'usermodified' => $user->id]);
        $DB->insert_record('local_ltiaccountmerge_text', (object) ['code' => 'busy', 'lang' => '*', 'title' => 't',
            'body' => '<p>x</p>',
            'bodyformat' => FORMAT_HTML, 'usermodified' => $user->id, 'timemodified' => time()]);
        return [$user, $other];
    }

    /**
     * The metadata names every table that holds personal data.
     */
    public function test_metadata(): void {
        $collection = provider::get_metadata(new collection('local_ltiaccountmerge'));
        $tables = array_map(function ($item) {
            return $item->get_name();
        }, $collection->get_collection());
        foreach (['audit', 'request', 'policy', 'text', 'merge', 'job'] as $suffix) {
            $table = 'local_ltiaccountmerge_' . $suffix;
            $this->assertContains($table, $tables);
        }
    }

    /**
     * Contexts: the system context for a user with data, none otherwise.
     */
    public function test_contexts(): void {
        $this->resetAfterTest();
        [$user] = $this->seed();
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertEquals([\context_system::instance()->id], provider::get_contexts_for_userid($user->id)->get_contextids());
        $this->assertSame([], provider::get_contexts_for_userid($nobody->id)->get_contextids());
    }

    /**
     * User list for the system context contains actors, affected users, requesters and editors.
     */
    public function test_users_in_context(): void {
        $this->resetAfterTest();
        [$user, $other] = $this->seed();
        $list = new userlist(\context_system::instance(), 'local_ltiaccountmerge');
        provider::get_users_in_context($list);
        $this->assertEqualsCanonicalizing([$user->id, $other->id], $list->get_userids());
        $wrong = new userlist(\context_user::instance($user->id), 'local_ltiaccountmerge');
        provider::get_users_in_context($wrong);
        $this->assertSame([], $wrong->get_userids());
    }

    /**
     * Export contains the user's rows only.
     */
    public function test_export(): void {
        $this->resetAfterTest();
        [$user] = $this->seed();
        $context = \context_system::instance();
        provider::export_user_data(new approved_contextlist($user, 'local_ltiaccountmerge', [$context->id]));
        $writer = \core_privacy\local\request\writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('pluginname', 'local_ltiaccountmerge')]);
        $this->assertCount(2, $data->audit);
        $this->assertCount(1, $data->requests);
        $this->assertTrue($data->requests[0]['target']);
        $this->assertSame(['By user'], $data->modified['policies']);
        $this->assertSame(['busy'], $data->modified['texts']);
    }

    /**
     * Deleting one user removes their requests, detaches the audit trail, keeps other people's data.
     */
    public function test_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $other] = $this->seed();
        $context = \context_system::instance();
        provider::delete_data_for_user(new approved_contextlist($user, 'local_ltiaccountmerge', [$context->id]));
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_request', ['targetuserid' => $user->id]));
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_request', ['requestedby' => $user->id]));
        // The other user's own request (target = requester = other) survives.
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_request'));
        // Audit rows survive as evidence without the person and without the identity reference.
        $this->assertSame(3, $DB->count_records('local_ltiaccountmerge_audit'));
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_audit', ['actorid' => $user->id]));
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_audit', ['relateduserid' => $user->id]));
        $this->assertNull($DB->get_field('local_ltiaccountmerge_audit', 'detail', ['action' => 'link']));
        $this->assertTrue($DB->record_exists(
            'local_ltiaccountmerge_audit',
            ['actorid' => $other->id, 'action' => 'unrelated']
        ));
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_policy', ['usermodified' => $user->id]));
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_text', ['usermodified' => $user->id]));
        $this->assertSame([], provider::get_contexts_for_userid($user->id)->get_contextids());
    }

    /**
     * Deleting several users and everything in the context.
     */
    public function test_delete_for_users_and_context(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $other] = $this->seed();
        $context = \context_system::instance();
        provider::delete_data_for_users(new approved_userlist($context, 'local_ltiaccountmerge', [$user->id]));
        $this->assertSame([], provider::get_contexts_for_userid($user->id)->get_contextids());
        $this->assertNotEmpty(provider::get_contexts_for_userid($other->id)->get_contextids());
        provider::delete_data_for_all_users_in_context(\context_user::instance($user->id));
        $this->assertNotEmpty($DB->count_records('local_ltiaccountmerge_audit'));
        provider::delete_data_for_all_users_in_context($context);
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_request'));
        $this->assertFalse($DB->record_exists_select('local_ltiaccountmerge_audit', 'actorid <> 0 OR relateduserid <> 0'));
        $this->assertSame([], provider::get_contexts_for_userid($other->id)->get_contextids());
    }

    /**
     * Real user deletion through the core API triggers the privacy cleanup path used by core's own tests (API contract).
     */
    public function test_delete_user_core_api_leaves_no_dangling_reference_in_requests(): void {
        global $DB;
        $this->resetAfterTest();
        [$user] = $this->seed();
        delete_user($user);
        // Core's delete_user() does not call plugin privacy providers, the data privacy tool does.
        // So the references remain until a deletion request is processed - and the provider then clears them.
        $this->assertTrue($DB->record_exists('local_ltiaccountmerge_request', ['targetuserid' => $user->id]));
        $record = $DB->get_record('user', ['id' => $user->id]);
        provider::delete_data_for_user(new approved_contextlist(
            $record,
            'local_ltiaccountmerge',
            [\context_system::instance()->id]
        ));
        $this->assertFalse($DB->record_exists('local_ltiaccountmerge_request', ['targetuserid' => $user->id]));
    }

    /**
     * Merge jobs: found, exported and deleted for the accounts involved; the consenting administrator is detached.
     */
    public function test_merge_jobs(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        [$source, $target, $admin, $bystander] = [$generator->create_user(), $generator->create_user(), $generator->create_user(),
            $generator->create_user()];
        $job = (object) ['sourceuserid' => $source->id, 'targetuserid' => $target->id, 'status' => 'succeeded',
            'previewhash' => str_repeat('a', 64), 'consentby' => $admin->id, 'timeconsented' => time()];
        $DB->insert_record('local_ltiaccountmerge_job', $job);
        $context = \context_system::instance();
        foreach ([$source, $target, $admin] as $user) {
            $this->assertEquals([$context->id], provider::get_contexts_for_userid($user->id)->get_contextids());
        }
        $this->assertSame([], provider::get_contexts_for_userid($bystander->id)->get_contextids());
        $list = new userlist($context, 'local_ltiaccountmerge');
        provider::get_users_in_context($list);
        $this->assertEqualsCanonicalizing([$source->id, $target->id, $admin->id], $list->get_userids());

        provider::export_user_data(new approved_contextlist($source, 'local_ltiaccountmerge', [$context->id]));
        $data = \core_privacy\local\request\writer::with_context($context)->get_data([get_string(
            'pluginname',
            'local_ltiaccountmerge'
        )]);
        $this->assertCount(1, $data->mergejobs);
        $this->assertTrue($data->mergejobs[0]['merged away']);
        $this->assertFalse($data->mergejobs[0]['kept']);

        provider::delete_data_for_user(new approved_contextlist($admin, 'local_ltiaccountmerge', [$context->id]));
        $this->assertEquals(0, $DB->get_field('local_ltiaccountmerge_job', 'consentby', ['sourceuserid' => $source->id]));
        provider::delete_data_for_user(new approved_contextlist($target, 'local_ltiaccountmerge', [$context->id]));
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_job'));
        $DB->insert_record('local_ltiaccountmerge_job', $job);
        provider::delete_data_for_all_users_in_context($context);
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_job'));
    }
}
