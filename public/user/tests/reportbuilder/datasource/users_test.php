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

declare(strict_types=1);

namespace core_user\reportbuilder\datasource;

use core_reportbuilder_generator;
use core_reportbuilder\local\filters\{boolean_select, date, select, tags, text, user as user_filter};
use core_reportbuilder\tests\core_reportbuilder_testcase;

/**
 * Unit tests for users datasource
 *
 * @package     core_user
 * @covers      \core_user\reportbuilder\datasource\users
 * @copyright   2022 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class users_test extends core_reportbuilder_testcase {

    /**
     * Test default datasource
     */
    public function test_datasource_default(): void {
        $this->resetAfterTest();

        $user2 = $this->getDataGenerator()->create_user(['firstname' => 'Charles']);
        $user3 = $this->getDataGenerator()->create_user(['firstname' => 'Brian']);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 1]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(3, $content);

        // Default columns are fullname, username, email. Results are sorted by the fullname.
        [$adminrow, $userrow1, $userrow2] = array_map('array_values', $content);

        $this->assertEquals(['Admin User', 'admin', 'admin@example.com'], $adminrow);
        $this->assertEquals([fullname($user3), $user3->username, $user3->email], $userrow1);
        $this->assertEquals([fullname($user2), $user2->username, $user2->email], $userrow2);
    }

    /**
     * Test datasource columns that aren't added by default
     */
    public function test_datasource_non_default_columns(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Zoe',
            'idnumber' => 'U0001',
            'city' => 'London',
            'country' => 'GB',
            'lang' => 'en',
            'timezone' => 'Europe/London',
            'theme' => 'boost',
            'interests' => ['Horses'],
        ]);

        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'My cohort']);
        cohort_add_member($cohort->id, $user->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);

        // User.
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullnamewithlink',
            'sortenabled' => 1]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullnamewithpicture']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullnamewithpicturelink']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:picture']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstname']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:lastname']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:city']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:country']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:lang']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:timezone']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:description']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstnamephonetic']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:lastnamephonetic']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:middlename']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:alternatename']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:idnumber']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:institution']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:department']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:phone1']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:phone2']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:address']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:firstaccess']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:lastaccess']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:suspended']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:confirmed']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:auth']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:moodlenetprofile']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:timecreated']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:timemodified']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:lastip']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:theme']);

        // Tags.
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'tag:name']);

        // Cohort.
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'cohort:name']);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(2, $content);

        // Admin row.
        [
            $fullnamewithlink,
            $fullnamewithpicture,
            $fullnamewithpicturelink,
            $picture,
            $lastname,
            $firstname,
        ] = array_values($content[0]);

        $this->assertStringContainsString('Admin User', $fullnamewithlink);
        $this->assertStringContainsString('Admin User', $fullnamewithpicture);
        $this->assertStringContainsString('Admin User', $fullnamewithpicturelink);
        $this->assertNotEmpty($picture);
        $this->assertEquals('Admin', $lastname);
        $this->assertEquals('User', $firstname);

        // User row.
        [
            $fullnamewithlink,
            $fullnamewithpicture,
            $fullnamewithpicturelink,
            $picture,
            $firstname,
            $lastname,
            $city,
            $country,
            $lang,
            $timezone,
            $description,
            $firstnamephonetic,
            $lastnamephonetic,
            $middlename,
            $alternatename,
            $idnumber,
            $institution,
            $department,
            $phone1,
            $phone2,
            $address,
            $firstaccess,
            $lastaccess,
            $suspended,
            $confirmed,
            $auth,
            $moodlenetprofile,
            $timecreated,
            $timemodified,
            $lastip,
            $theme,
            $tag,
            $cohortname,
        ] = array_values($content[1]);

        $this->assertStringContainsString(fullname($user), $fullnamewithlink);
        $this->assertStringContainsString(fullname($user), $fullnamewithpicture);
        $this->assertStringContainsString(fullname($user), $fullnamewithpicturelink);
        $this->assertNotEmpty($picture);
        $this->assertEquals($user->firstname, $firstname);
        $this->assertEquals($user->lastname, $lastname);
        $this->assertEquals($user->city, $city);
        $this->assertEquals('United Kingdom', $country);
        $this->assertEquals('English ‎(en)‎', $lang);
        $this->assertEquals('Europe/London', $timezone);
        $this->assertEquals($user->description, $description);
        $this->assertEquals($user->firstnamephonetic, $firstnamephonetic);
        $this->assertEquals($user->lastnamephonetic, $lastnamephonetic);
        $this->assertEquals($user->middlename, $middlename);
        $this->assertEquals($user->alternatename, $alternatename);
        $this->assertEquals($user->idnumber, $idnumber);
        $this->assertEquals($user->institution, $institution);
        $this->assertEquals($user->department, $department);
        $this->assertEquals($user->phone1, $phone1);
        $this->assertEquals($user->phone2, $phone2);
        $this->assertEquals($user->address, $address);
        $this->assertEmpty($firstaccess);
        $this->assertEmpty($lastaccess);
        $this->assertEquals('No', $suspended);
        $this->assertEquals('Yes', $confirmed);
        $this->assertEquals('Manual accounts', $auth);
        $this->assertEquals($user->moodlenetprofile, $moodlenetprofile);
        $this->assertNotEmpty($timecreated);
        $this->assertNotEmpty($timemodified);
        $this->assertEquals('0.0.0.0', $lastip);
        $this->assertEquals('Boost', $theme);
        $this->assertEquals('Horses', $tag);
        $this->assertEquals($cohort->name, $cohortname);
    }

    /**
     * Test fullname columns when alternative fullname format is configured
     */
    public function test_datasource_alternative_fullname_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('alternativefullnameformat', '(alternatename) firstname lastname');

        $this->getDataGenerator()->create_user(['firstname' => 'John', 'lastname' => 'Smith', 'alternatename' => 'JS']);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);

        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullname', 'sortenabled' => 1]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullnamewithlink']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullnamewithpicture']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullnamewithpicturelink']);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(2, $content);

        // Admin row.
        [
            $fullname,
            $fullnamewithlink,
            $fullnamewithpicture,
            $fullnamewithpicturelink
        ] = array_values($content[0]);

        $this->assertEquals('Admin User', $fullname);
        $this->assertStringContainsString('Admin User', $fullnamewithlink);
        $this->assertStringContainsString('Admin User', $fullnamewithpicture);
        $this->assertStringContainsString('Admin User', $fullnamewithpicturelink);

        // User row.
        [
            $fullname,
            $fullnamewithlink,
            $fullnamewithpicture,
            $fullnamewithpicturelink
        ] = array_values($content[1]);

        $this->assertEquals('(JS) John Smith', $fullname);
        $this->assertStringContainsString('(JS) John Smith', $fullnamewithlink);
        $this->assertStringContainsString('(JS) John Smith', $fullnamewithpicture);
        $this->assertStringContainsString('(JS) John Smith', $fullnamewithpicturelink);
    }

    /**
     * Data provider for {@see test_datasource_filters}
     *
     * @return array[]
     */
    public static function datasource_filters_provider(): array {
        return [
            // User.
            'Filter user' => ['user:userselect', [
                'user:userselect_operator' => user_filter::USER_SELECT,
                'user:userselect_value' => [-1],
            ], false],
            'Filter fullname' => ['user:fullname', [
                'user:fullname_operator' => text::CONTAINS,
                'user:fullname_value' => 'Zoe',
            ], true],
            'Filter fullname (no match)' => ['user:fullname', [
                'user:fullname_operator' => text::CONTAINS,
                'user:fullname_value' => 'Alfie',
            ], false],
            'Filter picture' => ['user:picture', [
                'user:picture_operator' => boolean_select::NOT_CHECKED,
            ], true],
            'Filter picture (no match)' => ['user:picture', [
                'user:picture_operator' => boolean_select::CHECKED,
            ], false],
            'Filter firstname' => ['user:firstname', [
                'user:firstname_operator' => text::IS_EQUAL_TO,
                'user:firstname_value' => 'Zoe',
            ], true],
            'Filter firstname (no match)' => ['user:firstname', [
                'user:firstname_operator' => text::IS_EQUAL_TO,
                'user:firstname_value' => 'Alfie',
            ], false],
            'Filter middlename' => ['user:middlename', [
                'user:middlename_operator' => text::IS_EQUAL_TO,
                'user:middlename_value' => 'Zebediah',
            ], true],
            'Filter middlename (no match)' => ['user:middlename', [
                'user:middlename_operator' => text::IS_EQUAL_TO,
                'user:middlename_value' => 'Aardvark',
            ], false],
            'Filter lastname' => ['user:lastname', [
                'user:lastname_operator' => text::IS_EQUAL_TO,
                'user:lastname_value' => 'Zebra',
            ], true],
            'Filter lastname (no match)' => ['user:lastname', [
                'user:lastname_operator' => text::IS_EQUAL_TO,
                'user:lastname_value' => 'Aardvark',
            ], false],
            'Filter firstnamephonetic' => ['user:firstnamephonetic', [
                'user:firstnamephonetic_operator' => text::IS_EQUAL_TO,
                'user:firstnamephonetic_value' => 'Eoz',
            ], true],
            'Filter firstnamephonetic (no match)' => ['user:firstnamephonetic', [
                'user:firstnamephonetic_operator' => text::IS_EQUAL_TO,
                'user:firstnamephonetic_value' => 'Alfie',
            ], false],
            'Filter lastnamephonetic' => ['user:lastnamephonetic', [
                'user:lastnamephonetic_operator' => text::IS_EQUAL_TO,
                'user:lastnamephonetic_value' => 'Arbez',
            ], true],
            'Filter lastnamephonetic (no match)' => ['user:lastnamephonetic', [
                'user:lastnamephonetic_operator' => text::IS_EQUAL_TO,
                'user:lastnamephonetic_value' => 'Aardvark',
            ], false],
            'Filter alternatename' => ['user:alternatename', [
                'user:alternatename_operator' => text::IS_EQUAL_TO,
                'user:alternatename_value' => 'Zee',
            ], true],
            'Filter alternatename (no match)' => ['user:alternatename', [
                'user:alternatename_operator' => text::IS_EQUAL_TO,
                'user:alternatename_value' => 'Aardvark',
            ], false],
            'Filter email' => ['user:email', [
                'user:email_operator' => text::CONTAINS,
                'user:email_value' => 'zoe1',
            ], true],
            'Filter email (no match)' => ['user:email', [
                'user:email_operator' => text::CONTAINS,
                'user:email_value' => 'alfie1',
            ], false],
            'Filter phone1' => ['user:phone1', [
                'user:phone1_operator' => text::IS_EQUAL_TO,
                'user:phone1_value' => '111',
            ], true],
            'Filter phone1 (no match)' => ['user:phone1', [
                'user:phone1_operator' => text::IS_EQUAL_TO,
                'user:phone1_value' => '119',
            ], false],
            'Filter phone2' => ['user:phone2', [
                'user:phone2_operator' => text::IS_EQUAL_TO,
                'user:phone2_value' => '222',
            ], true],
            'Filter phone2 (no match)' => ['user:phone2', [
                'user:phone2_operator' => text::IS_EQUAL_TO,
                'user:phone2_value' => '229',
            ], false],
            'Filter address' => ['user:address', [
                'user:address_operator' => text::IS_EQUAL_TO,
                'user:address_value' => 'Big Farm',
            ], true],
            'Filter address (no match)' => ['user:address', [
                'user:address_operator' => text::IS_EQUAL_TO,
                'user:address_value' => 'Small Farm',
            ], false],
            'Filter city' => ['user:city', [
                'user:city_operator' => text::IS_EQUAL_TO,
                'user:city_value' => 'Barcelona',
            ], true],
            'Filter city (no match)' => ['user:city', [
                'user:city_operator' => text::IS_EQUAL_TO,
                'user:city_value' => 'Perth',
            ], false],
            'Filter country' => ['user:country', [
                'user:country_operator' => select::EQUAL_TO,
                'user:country_value' => 'ES',
            ], true],
            'Filter country (no match)' => ['user:country', [
                'user:country_operator' => select::EQUAL_TO,
                'user:country_value' => 'AU',
            ], false],
            'Filter lang' => ['user:lang', [
                'user:lang_operator' => select::EQUAL_TO,
                'user:lang_value' => 'en',
            ], true],
            'Filter lang (no match)' => ['user:lang', [
                'user:lang_operator' => select::NOT_EQUAL_TO,
                'user:lang_value' => 'en',
            ], false],
            'Filter timezone' => ['user:timezone', [
                'user:timezone_operator' => select::EQUAL_TO,
                'user:timezone_value' => 'Europe/Barcelona',
            ], true],
            'Filter timezone (no match)' => ['user:timezone', [
                'user:timezone_operator' => select::EQUAL_TO,
                'user:timezone_value' => 'Australia/Perth',
            ], false],
            'Filter theme' => ['user:theme', [
                'user:theme_operator' => select::EQUAL_TO,
                'user:theme_value' => 'boost',
            ], true],
            'Filter theme (no match)' => ['user:theme', [
                'user:theme_operator' => select::EQUAL_TO,
                'user:theme_value' => 'classic',
            ], false],
            'Filter description' => ['user:description', [
                'user:description_operator' => text::CONTAINS,
                'user:description_value' => 'Hello there',
            ], true],
            'Filter description (no match)' => ['user:description', [
                'user:description_operator' => text::CONTAINS,
                'user:description_value' => 'Goodbye',
            ], false],
            'Filter auth' => ['user:auth', [
                'user:auth_operator' => select::EQUAL_TO,
                'user:auth_value' => 'manual',
            ], true],
            'Filter auth (no match)' => ['user:auth', [
                'user:auth_operator' => select::EQUAL_TO,
                'user:auth_value' => 'ldap',
            ], false],
            'Filter username' => ['user:username', [
                'user:username_operator' => text::IS_EQUAL_TO,
                'user:username_value' => 'zoe1',
            ], true],
            'Filter username (no match)' => ['user:username', [
                'user:username_operator' => text::IS_EQUAL_TO,
                'user:username_value' => 'alfie1',
            ], false],
            'Filter idnumber' => ['user:idnumber', [
                'user:idnumber_operator' => text::IS_EQUAL_TO,
                'user:idnumber_value' => 'Z0001',
            ], true],
            'Filter idnumber (no match)' => ['user:idnumber', [
                'user:idnumber_operator' => text::IS_EQUAL_TO,
                'user:idnumber_value' => 'A0001',
            ], false],
            'Filter institution' => ['user:institution', [
                'user:institution_operator' => text::IS_EQUAL_TO,
                'user:institution_value' => 'Farm',
            ], true],
            'Filter institution (no match)' => ['user:institution', [
                'user:institution_operator' => text::IS_EQUAL_TO,
                'user:institution_value' => 'University',
            ], false],
            'Filter department' => ['user:department', [
                'user:department_operator' => text::IS_EQUAL_TO,
                'user:department_value' => 'Stable',
            ], true],
            'Filter department (no match)' => ['user:department', [
                'user:department_operator' => text::IS_EQUAL_TO,
                'user:department_value' => 'Office',
            ], false],
            'Filter moodlenetprofile' => ['user:moodlenetprofile', [
                'user:moodlenetprofile_operator' => text::IS_EQUAL_TO,
                'user:moodlenetprofile_value' => '@zoe1@example.com',
            ], true],
            'Filter moodlenetprofile (no match)' => ['user:moodlenetprofile', [
                'user:moodlenetprofile_operator' => text::IS_EQUAL_TO,
                'user:moodlenetprofile_value' => '@alfie1@example.com',
            ], false],
            'Filter suspended' => ['user:suspended', [
                'user:suspended_operator' => boolean_select::NOT_CHECKED,
            ], true],
            'Filter suspended (no match)' => ['user:suspended', [
                'user:suspended_operator' => boolean_select::CHECKED,
            ], false],
            'Filter confirmed' => ['user:confirmed', [
                'user:confirmed_operator' => boolean_select::CHECKED,
            ], true],
            'Filter confirmed (no match)' => ['user:confirmed', [
                'user:confirmed_operator' => boolean_select::NOT_CHECKED,
            ], false],
            'Filter timecreated' => ['user:timecreated', [
                'user:timecreated_operator' => date::DATE_RANGE,
                'user:timecreated_from' => 1622502000,
            ], true],
            'Filter timecreated (no match)' => ['user:timecreated', [
                'user:timecreated_operator' => date::DATE_RANGE,
                'user:timecreated_from' => 1619823600,
                'user:timecreated_to' => 1622502000,
            ], false],
            'Filter timemodified' => ['user:timemodified', [
                'user:timemodified_operator' => date::DATE_RANGE,
                'user:timemodified_from' => 1622502000,
            ], true],
            'Filter timemodified (no match)' => ['user:timemodified', [
                'user:timemodified_operator' => date::DATE_RANGE,
                'user:timemodified_to' => 1622502000,
            ], false],
            'Filter firstaccess' => ['user:firstaccess', [
                'user:firstaccess_operator' => date::DATE_EMPTY,
            ], true],
            'Filter firstaccess (no match)' => ['user:firstaccess', [
                'user:firstaccess_operator' => date::DATE_RANGE,
                'user:firstaccess_from' => 1619823600,
                'user:firstaccess_to' => 1622502000,
            ], false],
            'Filter lastaccess' => ['user:lastaccess', [
                'user:lastaccess_operator' => date::DATE_EMPTY,
            ], true],
            'Filter lastaccess (no match)' => ['user:lastaccess', [
                'user:lastaccess_operator' => date::DATE_RANGE,
                'user:lastaccess_from' => 1619823600,
                'user:lastaccess_to' => 1622502000,
            ], false],
            'Filter neveraccessed' => ['user:neveraccessed', [
                'user:neveraccessed_operator' => boolean_select::CHECKED,
            ], true],
            'Filter neveraccessed (no match)' => ['user:neveraccessed', [
                'user:neveraccessed_operator' => boolean_select::NOT_CHECKED,
            ], false],
            'Filter lastip' => ['user:lastip', [
                'user:lastip_operator' => text::IS_EQUAL_TO,
                'user:lastip_value' => '0.0.0.0',
            ], true],
            'Filter lastip (no match)' => ['user:lastip', [
                'user:lastip_operator' => text::IS_EQUAL_TO,
                'user:lastip_value' => '1.2.3.4',
            ], false],

            // Tags.
            'Filter tag name' => ['tag:name', [
                'tag:name_operator' => tags::EQUAL_TO,
                'tag:name_value' => [-1],
            ], false],
            'Filter tag name not empty' => ['tag:name', [
                'tag:name_operator' => tags::NOT_EMPTY,
            ], true],

            // Cohort.
            'Filter cohort name' => ['cohort:name', [
                'cohort:name_operator' => text::IS_EQUAL_TO,
                'cohort:name_value' => 'My cohort',
            ], true],
            'Filter cohort name (no match)' => ['cohort:name', [
                'cohort:name_operator' => text::IS_EQUAL_TO,
                'cohort:name_value' => 'Not my cohort',
            ], false],
        ];
    }

    /**
     * Test datasource filters
     *
     * @param string $filtername
     * @param array $filtervalues
     * @param bool $expectmatch
     *
     * @dataProvider datasource_filters_provider
     */
    public function test_datasource_filters(string $filtername, array $filtervalues, bool $expectmatch): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user([
            'username' => 'zoe1',
            'email' => 'zoe1@example.com',
            'firstname' => 'Zoe',
            'middlename' => 'Zebediah',
            'lastname' => 'Zebra',
            'firstnamephonetic' => 'Eoz',
            'lastnamephonetic' => 'Arbez',
            'alternatename' => 'Zee',
            'idnumber' => 'Z0001',
            'institution' => 'Farm',
            'department' => 'Stable',
            'phone1' => '111',
            'phone2' => '222',
            'address' => 'Big Farm',
            'city' => 'Barcelona',
            'country' => 'ES',
            'lang' => 'en',
            'timezone' => 'Europe/Barcelona',
            'theme' => 'boost',
            'description' => 'Hello there',
            'moodlenetprofile' => '@zoe1@example.com',
            'interests' => ['Horses'],
            'lastip' => '0.0.0.0',
        ]);

        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'My cohort']);
        cohort_add_member($cohort->id, $user->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        // Create report containing single column, and given filter.
        $report = $generator->create_report(['name' => 'Users', 'source' => users::class, 'default' => 0]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);

        // Add filter, set it's values.
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => $filtername]);
        $content = $this->get_custom_report_content($report->get('id'), 0, $filtervalues);

        if ($expectmatch) {
            // Merge report usernames into easily traversable array.
            $usernames = array_merge(...array_map('array_values', $content));
            $this->assertContains($user->username, $usernames);
        } else {
            $this->assertEmpty($content);
        }
    }

    /**
     * Stress test datasource
     *
     * In order to execute this test PHPUNIT_LONGTEST should be defined as true in phpunit.xml or directly in config.php
     */
    public function test_stress_datasource(): void {
        if (!PHPUNIT_LONGTEST) {
            $this->markTestSkipped('PHPUNIT_LONGTEST is not defined');
        }

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        $this->datasource_stress_test_columns(users::class);
        $this->datasource_stress_test_columns_aggregation(users::class);
        $this->datasource_stress_test_conditions(users::class, 'user:username');
    }
}
