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
 * modinfolib.php - Functions/classes relating to cached information about module instances on
 * a course.
 * @package    core
 * @subpackage lib
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     sam marshall
 */


// Maximum number of modinfo items to keep in memory cache. Do not increase this to a large
// number because:
// a) modinfo can be big (megabyte range) for some courses
// b) performance of cache will deteriorate if there are very many items in it
if (!defined('MAX_MODINFO_CACHE_SIZE')) {
    define('MAX_MODINFO_CACHE_SIZE', 10);
}

use core_courseformat\output\activitybadge;
use core_courseformat\sectiondelegate;
use core_courseformat\sectiondelegatemodule;

/**
 * Information about a course that is cached in the course table 'modinfo' field (and then in
 * memory) in order to reduce the need for other database queries.
 *
 * This includes information about the course-modules and the sections on the course. It can also
 * include dynamic data that has been updated for the current user.
 *
 * Use {@link get_fast_modinfo()} to retrieve the instance of the object for particular course
 * and particular user.
 *
 * @property-read int $courseid Course ID
 * @property-read int $userid User ID
 * @property-read array $sections Array from section number (e.g. 0) to array of course-module IDs in that
 *     section; this only includes sections that contain at least one course-module
 * @property-read cm_info[] $cms Array from course-module instance to cm_info object within this course, in
 *     order of appearance
 * @property-read cm_info[][] $instances Array from string (modname) => int (instance id) => cm_info object
 * @property-read array $groups Groups that the current user belongs to. Calculated on the first request.
 *     Is an array of grouping id => array of group id => group id. Includes grouping id 0 for 'all groups'
 */
class course_modinfo {
    /** @var int Maximum time the course cache building lock can be held */
    const COURSE_CACHE_LOCK_EXPIRY = 180;

    /** @var int Time to wait for the course cache building lock before throwing an exception */
    const COURSE_CACHE_LOCK_WAIT = 60;

    /**
     * List of fields from DB table 'course' that are cached in MUC and are always present in course_modinfo::$course
     * @var array
     */
    public static $cachedfields = array('shortname', 'fullname', 'format',
            'enablecompletion', 'groupmode', 'groupmodeforce', 'cacherev');

    /**
     * For convenience we store the course object here as it is needed in other parts of code
     * @var stdClass
     */
    private $course;

    /**
     * Array of section data from cache indexed by section number.
     * @var section_info[]
     */
    private $sectioninfobynum;

    /**
     * Array of section data from cache indexed by id.
     * @var section_info[]
     */
    private $sectioninfobyid;

    /**
     * Index of delegated sections (indexed by component and itemid)
     * @var array
     */
    private $delegatedsections;

    /**
     * Index of sections delegated by course modules, indexed by course module instance.
     * @var null|section_info[]
     */
    private ?array $delegatedbycm = null;

    /**
     * User ID
     * @var int
     */
    private $userid;

    /**
     * Array indexed by section num (e.g. 0) => array of course-module ids
     * This list only includes sections that actually contain at least one course-module
     * @var array
     */
    private $sectionmodules;

    /**
     * Array from int (cm id) => cm_info object
     * @var cm_info[]
     */
    private $cms;

    /**
     * Array from string (modname) => int (instance id) => cm_info object
     * @var cm_info[][]
     */
    private $instances;

    /**
     * Groups that the current user belongs to. This value is calculated on first
     * request to the property or function.
     * When set, it is an array of grouping id => array of group id => group id.
     * Includes grouping id 0 for 'all groups'.
     * @var int[][]
     */
    private $groups;

    /**
     * List of class read-only properties and their getter methods.
     * Used by magic functions __get(), __isset(), __empty()
     * @var array
     */
    private static $standardproperties = array(
        'courseid' => 'get_course_id',
        'userid' => 'get_user_id',
        'sections' => 'get_sections',
        'cms' => 'get_cms',
        'instances' => 'get_instances',
        'groups' => 'get_groups_all',
        'delegatedbycm' => 'get_sections_delegated_by_cm',
    );

    /**
     * Magic method getter
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name) {
        if (isset(self::$standardproperties[$name])) {
            $method = self::$standardproperties[$name];
            return $this->$method();
        } else {
            debugging('Invalid course_modinfo property accessed: '.$name);
            return null;
        }
    }

    /**
     * Magic method for function isset()
     *
     * @param string $name
     * @return bool
     */
    public function __isset($name) {
        if (isset(self::$standardproperties[$name])) {
            $value = $this->__get($name);
            return isset($value);
        }
        return false;
    }

    /**
     * Magic method for function empty()
     *
     * @param string $name
     * @return bool
     */
    public function __empty($name) {
        if (isset(self::$standardproperties[$name])) {
            $value = $this->__get($name);
            return empty($value);
        }
        return true;
    }

    /**
     * Magic method setter
     *
     * Will display the developer warning when trying to set/overwrite existing property.
     *
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value) {
        debugging("It is not allowed to set the property course_modinfo::\${$name}", DEBUG_DEVELOPER);
    }

    /**
     * Returns course object that was used in the first {@link get_fast_modinfo()} call.
     *
     * It may not contain all fields from DB table {course} but always has at least the following:
     * id,shortname,fullname,format,enablecompletion,groupmode,groupmodeforce,cacherev
     *
     * @return stdClass
     */
    public function get_course() {
        return $this->course;
    }

    /**
     * @return int Course ID
     */
    public function get_course_id() {
        return $this->course->id;
    }

    /**
     * @return int User ID
     */
    public function get_user_id() {
        return $this->userid;
    }

    /**
     * @return array Array from section number (e.g. 0) to array of course-module IDs in that
     *   section; this only includes sections that contain at least one course-module
     */
    public function get_sections() {
        return $this->sectionmodules;
    }

    /**
     * @return cm_info[] Array from course-module instance to cm_info object within this course, in
     *   order of appearance
     */
    public function get_cms() {
        return $this->cms;
    }

    /**
     * Obtains a single course-module object (for a course-module that is on this course).
     * @param int $cmid Course-module ID
     * @return cm_info Information about that course-module
     * @throws moodle_exception If the course-module does not exist
     */
    public function get_cm($cmid) {
        if (empty($this->cms[$cmid])) {
            throw new moodle_exception('invalidcoursemoduleid', 'error', '', $cmid);
        }
        return $this->cms[$cmid];
    }

    /**
     * Obtains all module instances on this course.
     * @return cm_info[][] Array from module name => array from instance id => cm_info
     */
    public function get_instances() {
        return $this->instances;
    }

    /**
     * Returns array of localised human-readable module names used in this course
     *
     * @param bool $plural if true returns the plural form of modules names
     * @return array
     */
    public function get_used_module_names($plural = false) {
        $modnames = get_module_types_names($plural);
        $modnamesused = array();
        foreach ($this->get_cms() as $cmid => $mod) {
            if (!isset($modnamesused[$mod->modname]) && isset($modnames[$mod->modname]) && $mod->uservisible) {
                $modnamesused[$mod->modname] = $modnames[$mod->modname];
            }
        }
        return $modnamesused;
    }

    /**
     * Obtains all instances of a particular module on this course.
     * @param string $modname Name of module (not full frankenstyle) e.g. 'label'
     * @return cm_info[] Array from instance id => cm_info for modules on this course; empty if none
     */
    public function get_instances_of($modname) {
        if (empty($this->instances[$modname])) {
            return array();
        }
        return $this->instances[$modname];
    }

    /**
     * Groups that the current user belongs to organised by grouping id. Calculated on the first request.
     * @return int[][] array of grouping id => array of group id => group id. Includes grouping id 0 for 'all groups'
     */
    private function get_groups_all() {
        if (is_null($this->groups)) {
            $this->groups = groups_get_user_groups($this->course->id, $this->userid);
        }
        return $this->groups;
    }

    /**
     * Returns groups that the current user belongs to on the course. Note: If not already
     * available, this may make a database query.
     * @param int $groupingid Grouping ID or 0 (default) for all groups
     * @return int[] Array of int (group id) => int (same group id again); empty array if none
     */
    public function get_groups($groupingid = 0) {
        $allgroups = $this->get_groups_all();
        if (!isset($allgroups[$groupingid])) {
            return array();
        }
        return $allgroups[$groupingid];
    }

    /**
     * Gets all sections as array from section number => data about section.
     *
     * The method will return all sections of the course, including the ones
     * delegated to a component.
     *
     * @return section_info[] Array of section_info objects organised by section number
     */
    public function get_section_info_all() {
        return $this->sectioninfobynum;
    }

    /**
     * Gets all sections listed in course page as array from section number => data about section.
     *
     * The method is similar to get_section_info_all but filtering all sections delegated to components.
     *
     * @return section_info[] Array of section_info objects organised by section number
     */
    public function get_listed_section_info_all() {
        if (empty($this->delegatedsections)) {
            return $this->sectioninfobynum;
        }
        $sections = [];
        foreach ($this->sectioninfobynum as $section) {
            if (!$section->get_component_instance()) {
                $sections[$section->section] = $section;
            }
        }
        return $sections;
    }

    /**
     * Gets data about specific numbered section.
     * @param int $sectionnumber Number (not id) of section
     * @param int $strictness Use MUST_EXIST to throw exception if it doesn't
     * @return ?section_info Information for numbered section or null if not found
     */
    public function get_section_info($sectionnumber, $strictness = IGNORE_MISSING) {
        if (!array_key_exists($sectionnumber, $this->sectioninfobynum)) {
            if ($strictness === MUST_EXIST) {
                throw new moodle_exception('sectionnotexist');
            } else {
                return null;
            }
        }
        return $this->sectioninfobynum[$sectionnumber];
    }

    /**
     * Gets data about specific section ID.
     * @param int $sectionid ID (not number) of section
     * @param int $strictness Use MUST_EXIST to throw exception if it doesn't
     * @return section_info|null Information for numbered section or null if not found
     */
    public function get_section_info_by_id(int $sectionid, int $strictness = IGNORE_MISSING): ?section_info {
        if (!array_key_exists($sectionid, $this->sectioninfobyid)) {
            if ($strictness === MUST_EXIST) {
                throw new moodle_exception('sectionnotexist');
            } else {
                return null;
            }
        }
        return $this->sectioninfobyid[$sectionid];
    }

    /**
     * Gets data about specific delegated section.
     * @param string $component Component name
     * @param int $itemid Item id
     * @param int $strictness Use MUST_EXIST to throw exception if it doesn't
     * @return section_info|null Information for numbered section or null if not found
     */
    public function get_section_info_by_component(
        string $component,
        int $itemid,
        int $strictness = IGNORE_MISSING
    ): ?section_info {
        if (!isset($this->delegatedsections[$component][$itemid])) {
            if ($strictness === MUST_EXIST) {
                throw new moodle_exception('sectionnotexist');
            } else {
                return null;
            }
        }
        return $this->delegatedsections[$component][$itemid];
    }

    /**
     * Check if the course has delegated sections.
     * @return bool
     */
    public function has_delegated_sections(): bool {
        return !empty($this->delegatedsections);
    }

    /**
     * Gets data about section delegated by course modules.
     *
     * @return section_info[] sections array indexed by course module ID
     */
    public function get_sections_delegated_by_cm(): array {
        if (!is_null($this->delegatedbycm)) {
            return $this->delegatedbycm;
        }
        $this->delegatedbycm = [];
        foreach ($this->delegatedsections as $componentsections) {
            foreach ($componentsections as $section) {
                $delegateinstance = $section->get_component_instance();
                // We only return sections delegated by course modules. Sections delegated to other
                // types of components must implement their own methods to get the section.
                if (!$delegateinstance || !($delegateinstance instanceof sectiondelegatemodule)) {
                    continue;
                }
                if (!$cm = $delegateinstance->get_cm()) {
                    continue;
                }
                $this->delegatedbycm[$cm->id] = $section;
            }
        }
        return $this->delegatedbycm;
    }

    /**
     * Static cache for generated course_modinfo instances
     *
     * @see course_modinfo::instance()
     * @see course_modinfo::clear_instance_cache()
     * @var course_modinfo[]
     */
    protected static $instancecache = array();

    /**
     * Timestamps (microtime) when the course_modinfo instances were last accessed
     *
     * It is used to remove the least recent accessed instances when static cache is full
     *
     * @var float[]
     */
    protected static $cacheaccessed = array();

    /**
     * Store a list of known course cacherev values. This is in case people reuse a course object
     * (with an old cacherev value) within the same request when calling things like
     * get_fast_modinfo, after rebuild_course_cache.
     *
     * @var int[]
     */
    protected static $mincacherevs = [];

    /**
     * Clears the cache used in course_modinfo::instance()
     *
     * Used in {@link get_fast_modinfo()} when called with argument $reset = true
     * and in {@link rebuild_course_cache()}
     *
     * If the cacherev for the course is known to have updated (i.e. when doing
     * rebuild_course_cache), it should be specified here.
     *
     * @param null|int|stdClass $courseorid if specified removes only cached value for this course
     * @param int $newcacherev If specified, the known cache rev for this course id will be updated
     */
    public static function clear_instance_cache($courseorid = null, int $newcacherev = 0) {
        if (empty($courseorid)) {
            self::$instancecache = array();
            self::$cacheaccessed = array();
            // This is called e.g. in phpunit when we just want to reset the caches, so also
            // reset the mincacherevs static cache.
            self::$mincacherevs = [];
            return;
        }
        if (is_object($courseorid)) {
            $courseorid = $courseorid->id;
        }
        if (isset(self::$instancecache[$courseorid])) {
            // Unsetting static variable in PHP is peculiar, it removes the reference,
            // but data remain in memory. Prior to unsetting, the varable needs to be
            // set to empty to remove its remains from memory.
            self::$instancecache[$courseorid] = '';
            unset(self::$instancecache[$courseorid]);
            unset(self::$cacheaccessed[$courseorid]);
        }
        // When clearing cache for a course, we record the new cacherev version, to make
        // sure that any future requests for the cache use at least this version.
        if ($newcacherev) {
            self::$mincacherevs[(int)$courseorid] = $newcacherev;
        }
    }

    /**
     * Returns the instance of course_modinfo for the specified course and specified user
     *
     * This function uses static cache for the retrieved instances. The cache
     * size is limited by MAX_MODINFO_CACHE_SIZE. If instance is not found in
     * the static cache or it was created for another user or the cacherev validation
     * failed - a new instance is constructed and returned.
     *
     * Used in {@link get_fast_modinfo()}
     *
     * @param int|stdClass $courseorid object from DB table 'course' (must have field 'id'
     *     and recommended to have field 'cacherev') or just a course id
     * @param int $userid User id to populate 'availble' and 'uservisible' attributes of modules and sections.
     *     Set to 0 for current user (default). Set to -1 to avoid calculation of dynamic user-depended data.
     * @return course_modinfo
     */
    public static function instance($courseorid, $userid = 0) {
        global $USER;
        if (is_object($courseorid)) {
            $course = $courseorid;
        } else {
            $course = (object)array('id' => $courseorid);
        }
        if (empty($userid)) {
            $userid = $USER->id;
        }

        if (!empty(self::$instancecache[$course->id])) {
            if (self::$instancecache[$course->id]->userid == $userid &&
                    (!isset($course->cacherev) ||
                    $course->cacherev == self::$instancecache[$course->id]->get_course()->cacherev)) {
                // This course's modinfo for the same user was recently retrieved, return cached.
                self::$cacheaccessed[$course->id] = microtime(true);
                return self::$instancecache[$course->id];
            } else {
                // Prevent potential reference problems when switching users.
                self::clear_instance_cache($course->id);
            }
        }
        $modinfo = new course_modinfo($course, $userid);

        // We have a limit of MAX_MODINFO_CACHE_SIZE entries to store in static variable.
        if (count(self::$instancecache) >= MAX_MODINFO_CACHE_SIZE) {
            // Find the course that was the least recently accessed.
            asort(self::$cacheaccessed, SORT_NUMERIC);
            $courseidtoremove = key(array_reverse(self::$cacheaccessed, true));
            self::clear_instance_cache($courseidtoremove);
        }

        // Add modinfo to the static cache.
        self::$instancecache[$course->id] = $modinfo;
        self::$cacheaccessed[$course->id] = microtime(true);

        return $modinfo;
    }

    /**
     * Constructs based on course.
     * Note: This constructor should not usually be called directly.
     * Use get_fast_modinfo($course) instead as this maintains a cache.
     * @param stdClass $course course object, only property id is required.
     * @param int $userid User ID
     * @throws moodle_exception if course is not found
     */
    public function __construct($course, $userid) {
        global $CFG, $COURSE, $SITE, $DB;

        if (!isset($course->cacherev)) {
            // We require presence of property cacherev to validate the course cache.
            // No need to clone the $COURSE or $SITE object here because we clone it below anyway.
            $course = get_course($course->id, false);
        }

        // If we have rebuilt the course cache in this request, ensure that requested cacherev is
        // at least that value. This ensures that we're not reusing a course object with old
        // cacherev, which could result in using old cached data.
        if (array_key_exists($course->id, self::$mincacherevs) &&
                $course->cacherev < self::$mincacherevs[$course->id]) {
            $course->cacherev = self::$mincacherevs[$course->id];
        }

        $cachecoursemodinfo = cache::make('core', 'coursemodinfo');

        // Retrieve modinfo from cache. If not present or cacherev mismatches, call rebuild and retrieve again.
        $coursemodinfo = $cachecoursemodinfo->get_versioned($course->id, $course->cacherev);
        // Note the version comparison using the data in the cache should not be necessary, but the
        // partial rebuild logic sometimes sets the $coursemodinfo->cacherev to -1 which is an
        // indicator that it needs rebuilding.
        if ($coursemodinfo === false || ($course->cacherev > $coursemodinfo->cacherev)) {
            $coursemodinfo = self::build_course_cache($course);
        }

        // Set initial values
        $this->userid = $userid;
        $this->sectionmodules = [];
        $this->cms = [];
        $this->instances = [];
        $this->groups = null;

        // If we haven't already preloaded contexts for the course, do it now
        // Modules are also cached here as long as it's the first time this course has been preloaded.
        context_helper::preload_course($course->id);

        // Quick integrity check: as a result of race conditions modinfo may not be regenerated after the change.
        // It is especially dangerous if modinfo contains the deleted course module, as it results in fatal error.
        // We can check it very cheap by validating the existence of module context.
        if ($course->id == $COURSE->id || $course->id == $SITE->id) {
            // Only verify current course (or frontpage) as pages with many courses may not have module contexts cached.
            // (Uncached modules will result in a very slow verification).
            foreach ($coursemodinfo->modinfo as $mod) {
                if (!context_module::instance($mod->cm, IGNORE_MISSING)) {
                    debugging('Course cache integrity check failed: course module with id '. $mod->cm.
                            ' does not have context. Rebuilding cache for course '. $course->id);
                    // Re-request the course record from DB as well, don't use get_course() here.
                    $course = $DB->get_record('course', array('id' => $course->id), '*', MUST_EXIST);
                    $coursemodinfo = self::build_course_cache($course, true);
                    break;
                }
            }
        }

        // Overwrite unset fields in $course object with cached values, store the course object.
        $this->course = fullclone($course);
        foreach ($coursemodinfo as $key => $value) {
            if ($key !== 'modinfo' && $key !== 'sectioncache' &&
                    (!isset($this->course->$key) || $key === 'cacherev')) {
                $this->course->$key = $value;
            }
        }

        // Loop through each piece of module data, constructing it
        static $modexists = array();
        foreach ($coursemodinfo->modinfo as $mod) {
            if (!isset($mod->name) || strval($mod->name) === '') {
                // something is wrong here
                continue;
            }

            // Skip modules which don't exist
            if (!array_key_exists($mod->mod, $modexists)) {
                $modexists[$mod->mod] = file_exists("$CFG->dirroot/mod/$mod->mod/lib.php");
            }
            if (!$modexists[$mod->mod]) {
                continue;
            }

            // Construct info for this module
            $cm = new cm_info($this, null, $mod, null);

            // Store module in instances and cms array
            if (!isset($this->instances[$cm->modname])) {
                $this->instances[$cm->modname] = array();
            }
            $this->instances[$cm->modname][$cm->instance] = $cm;
            $this->cms[$cm->id] = $cm;

            // Reconstruct sections. This works because modules are stored in order
            if (!isset($this->sectionmodules[$cm->sectionnum])) {
                $this->sectionmodules[$cm->sectionnum] = [];
            }
            $this->sectionmodules[$cm->sectionnum][] = $cm->id;
        }

        // Expand section objects
        $this->sectioninfobynum = [];
        $this->sectioninfobyid = [];
        $this->delegatedsections = [];
        foreach ($coursemodinfo->sectioncache as $data) {
            $sectioninfo = new section_info($data, $data->section, null, null,
                $this, null);
            $this->sectioninfobynum[$data->section] = $sectioninfo;
            $this->sectioninfobyid[$data->id] = $sectioninfo;
            if (!empty($sectioninfo->component)) {
                if (!isset($this->delegatedsections[$sectioninfo->component])) {
                    $this->delegatedsections[$sectioninfo->component] = [];
                }
                $this->delegatedsections[$sectioninfo->component][$sectioninfo->itemid] = $sectioninfo;
            }
        }
        ksort($this->sectioninfobynum);
    }

    /**
     * Builds a list of information about sections on a course to be stored in
     * the course cache. (Does not include information that is already cached
     * in some other way.)
     *
     * @param stdClass $course Course object (must contain fields id and cacherev)
     * @param boolean $usecache use cached section info if exists, use true for partial course rebuild
     * @return array Information about sections, indexed by section id (not number)
     */
    protected static function build_course_section_cache(\stdClass $course, bool $usecache = false): array {
        global $DB;

        // Get section data.
        $sections = $DB->get_records(
            'course_sections',
            ['course' => $course->id],
            'section',
            'id, section, course, name, summary, summaryformat, sequence, visible, availability, component, itemid'
        );
        $compressedsections = [];
        $courseformat = course_get_format($course);

        if ($usecache) {
            $cachecoursemodinfo = cache::make('core', 'coursemodinfo');
            $coursemodinfo = $cachecoursemodinfo->get_versioned($course->id, $course->cacherev);
            if ($coursemodinfo !== false) {
                $compressedsections = $coursemodinfo->sectioncache;
            }
        }

        $formatoptionsdef = course_get_format($course)->section_format_options();
        // Remove unnecessary data and add availability.
        foreach ($sections as $section) {
            $sectionid = $section->id;
            $sectioninfocached = isset($compressedsections[$sectionid]);
            if ($sectioninfocached) {
                continue;
            }
            // Add cached options from course format to $section object.
            foreach ($formatoptionsdef as $key => $option) {
                if (!empty($option['cache'])) {
                    $formatoptions = $courseformat->get_format_options($section);
                    if (!array_key_exists('cachedefault', $option) || $option['cachedefault'] !== $formatoptions[$key]) {
                        $section->$key = $formatoptions[$key];
                    }
                }
            }
            // Clone just in case it is reused elsewhere.
            $compressedsections[$sectionid] = clone($section);
            section_info::convert_for_section_cache($compressedsections[$sectionid]);
        }
        return $compressedsections;
    }

    /**
     * Builds and stores in MUC object containing information about course
     * modules and sections together with cached fields from table course.
     *
     * @param stdClass $course object from DB table course. Must have property 'id'
     *     but preferably should have all cached fields.
     * @param boolean $partialrebuild Indicate if it's partial course cache rebuild or not
     * @return stdClass object with all cached keys of the course plus fields modinfo and sectioncache.
     *     The same object is stored in MUC
     * @throws moodle_exception if course is not found (if $course object misses some of the
     *     necessary fields it is re-requested from database)
     */
    public static function build_course_cache(\stdClass $course, bool $partialrebuild = false): \stdClass {
        if (empty($course->id)) {
            throw new coding_exception('Object $course is missing required property \id\'');
        }

        $cachecoursemodinfo = cache::make('core', 'coursemodinfo');
        $cachekey = $course->id;
        $cachecoursemodinfo->acquire_lock($cachekey);
        try {
            // Only actually do the build if it's still needed after getting the lock (not if
            // somebody else, who might have been holding the lock, built it already).
            $coursemodinfo = $cachecoursemodinfo->get_versioned($course->id, $course->cacherev);
            if ($coursemodinfo === false || ($course->cacherev > $coursemodinfo->cacherev)) {
                $coursemodinfo = self::inner_build_course_cache($course);
            }
        } finally {
            $cachecoursemodinfo->release_lock($cachekey);
        }
        return $coursemodinfo;
    }

    /**
     * Called to build course cache when there is already a lock obtained.
     *
     * @param stdClass $course object from DB table course
     * @param bool $partialrebuild Indicate if it's partial course cache rebuild or not
     * @return stdClass Course object that has been stored in MUC
     */
    protected static function inner_build_course_cache(\stdClass $course, bool $partialrebuild = false): \stdClass {
        global $DB, $CFG;
        require_once("{$CFG->dirroot}/course/lib.php");

        $cachekey = $course->id;
        $cachecoursemodinfo = cache::make('core', 'coursemodinfo');
        if (!$cachecoursemodinfo->check_lock_state($cachekey)) {
            throw new coding_exception('You must acquire a lock on the course ID before calling inner_build_course_cache');
        }

        // Always reload the course object from database to ensure we have the latest possible
        // value for cacherev.
        $course = $DB->get_record('course', ['id' => $course->id],
                implode(',', array_merge(['id'], self::$cachedfields)), MUST_EXIST);
        // Retrieve all information about activities and sections.
        $coursemodinfo = new stdClass();
        $coursemodinfo->modinfo = self::get_array_of_activities($course, $partialrebuild);
        $coursemodinfo->sectioncache = self::build_course_section_cache($course, $partialrebuild);
        foreach (self::$cachedfields as $key) {
            $coursemodinfo->$key = $course->$key;
        }
        // Set the accumulated activities and sections information in cache, together with cacherev.
        $cachecoursemodinfo->set_versioned($cachekey, $course->cacherev, $coursemodinfo);
        return $coursemodinfo;
    }

    /**
     * Purge the cache of a course section by its id.
     *
     * @param int $courseid The course to purge cache in
     * @param int $sectionid The section _id_ to purge
     */
    public static function purge_course_section_cache_by_id(int $courseid, int $sectionid): void {
        $course = get_course($courseid);
        $cache = cache::make('core', 'coursemodinfo');
        $cachekey = $course->id;
        $cache->acquire_lock($cachekey);
        try {
            $coursemodinfo = $cache->get_versioned($cachekey, $course->cacherev);
            if ($coursemodinfo !== false && array_key_exists($sectionid, $coursemodinfo->sectioncache)) {
                $coursemodinfo->cacherev = -1;
                unset($coursemodinfo->sectioncache[$sectionid]);
                $cache->set_versioned($cachekey, $course->cacherev, $coursemodinfo);
            }
        } finally {
            $cache->release_lock($cachekey);
        }
    }

    /**
     * Purge the cache of a course section by its number.
     *
     * @param int $courseid The course to purge cache in
     * @param int $sectionno The section number to purge
     */
    public static function purge_course_section_cache_by_number(int $courseid, int $sectionno): void {
        $course = get_course($courseid);
        $cache = cache::make('core', 'coursemodinfo');
        $cachekey = $course->id;
        $cache->acquire_lock($cachekey);
        try {
            $coursemodinfo = $cache->get_versioned($cachekey, $course->cacherev);
            if ($coursemodinfo !== false) {
                foreach ($coursemodinfo->sectioncache as $sectionid => $sectioncache) {
                    if ($sectioncache->section == $sectionno) {
                        $coursemodinfo->cacherev = -1;
                        unset($coursemodinfo->sectioncache[$sectionid]);
                        $cache->set_versioned($cachekey, $course->cacherev, $coursemodinfo);
                        break;
                    }
                }
            }
        } finally {
            $cache->release_lock($cachekey);
        }
    }

    /**
     * Purge the cache of a course module.
     *
     * @param int $courseid Course id
     * @param int $cmid Course module id
     */
    public static function purge_course_module_cache(int $courseid, int $cmid): void {
        self::purge_course_modules_cache($courseid, [$cmid]);
    }

    /**
     * Purges the coursemodinfo caches stored in MUC.
     *
     * @param int[] $courseids Array of course ids to purge the course caches
     * for (or all courses if empty array).
     *
     */
    public static function purge_course_caches(array $courseids = []): void {
        global $DB;

        // Purging might purge all course caches, so use a recordset and close it.
        $select = '';
        $params = null;
        if (!empty($courseids)) {
            [$sql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $select = 'id ' . $sql;
        }

        $courses = $DB->get_recordset_select(
            table: 'course',
            select: $select,
            params: $params,
            fields: 'id',
        );

        // Purge each course's cache to make sure cache is recalculated next time
        // the course is viewed.
        foreach ($courses as $course) {
            self::purge_course_cache($course->id);
        }
        $courses->close();
    }

    /**
     * Purge the cache of multiple course modules.
     *
     * @param int $courseid Course id
     * @param int[] $cmids List of course module ids
     * @return void
     */
    public static function purge_course_modules_cache(int $courseid, array $cmids): void {
        $course = get_course($courseid);
        $cache = cache::make('core', 'coursemodinfo');
        $cachekey = $course->id;
        $cache->acquire_lock($cachekey);
        try {
            $coursemodinfo = $cache->get_versioned($cachekey, $course->cacherev);
            $hascache = ($coursemodinfo !== false);
            $updatedcache = false;
            if ($hascache) {
                foreach ($cmids as $cmid) {
                    if (array_key_exists($cmid, $coursemodinfo->modinfo)) {
                        unset($coursemodinfo->modinfo[$cmid]);
                        $updatedcache = true;
                    }
                }
                if ($updatedcache) {
                    $coursemodinfo->cacherev = -1;
                    $cache->set_versioned($cachekey, $course->cacherev, $coursemodinfo);
                    $cache->get_versioned($cachekey, $course->cacherev);
                }
            }
        } finally {
            $cache->release_lock($cachekey);
        }
    }

    /**
     * For a given course, returns an array of course activity objects
     *
     * @param stdClass $course Course object
     * @param bool $usecache get activities from cache if modinfo exists when $usecache is true
     * @return array list of activities
     */
    public static function get_array_of_activities(stdClass $course, bool $usecache = false): array {
        global $CFG, $DB;

        if (empty($course)) {
            throw new moodle_exception('courseidnotfound');
        }

        $rawmods = get_course_mods($course->id);
        if (empty($rawmods)) {
            return [];
        }

        $mods = [];
        if ($usecache) {
            // Get existing cache.
            $cachecoursemodinfo = cache::make('core', 'coursemodinfo');
            $coursemodinfo = $cachecoursemodinfo->get_versioned($course->id, $course->cacherev);
            if ($coursemodinfo !== false) {
                $mods = $coursemodinfo->modinfo;
            }
        }

        $courseformat = course_get_format($course);

        if ($sections = $DB->get_records('course_sections', ['course' => $course->id],
            'section ASC', 'id,section,sequence,visible')) {
            // First check and correct obvious mismatches between course_sections.sequence and course_modules.section.
            if ($errormessages = course_integrity_check($course->id, $rawmods, $sections)) {
                debugging(join('<br>', $errormessages));
                $rawmods = get_course_mods($course->id);
                $sections = $DB->get_records('course_sections', ['course' => $course->id],
                    'section ASC', 'id,section,sequence,visible');
            }
            // Build array of activities.
            foreach ($sections as $section) {
                if (!empty($section->sequence)) {
                    $cmids = explode(",", $section->sequence);
                    $numberofmods = count($cmids);
                    foreach ($cmids as $cmid) {
                        // Activity does not exist in the database.
                        $notexistindb = empty($rawmods[$cmid]);
                        $activitycached = isset($mods[$cmid]);
                        if ($activitycached || $notexistindb) {
                            continue;
                        }

                        // Adjust visibleoncoursepage, value in DB may not respect format availability.
                        $rawmods[$cmid]->visibleoncoursepage = (!$rawmods[$cmid]->visible
                            || $rawmods[$cmid]->visibleoncoursepage
                            || empty($CFG->allowstealth)
                            || !$courseformat->allow_stealth_module_visibility($rawmods[$cmid], $section)) ? 1 : 0;

                        $mods[$cmid] = new stdClass();
                        $mods[$cmid]->id = $rawmods[$cmid]->instance;
                        $mods[$cmid]->cm = $rawmods[$cmid]->id;
                        $mods[$cmid]->mod = $rawmods[$cmid]->modname;

                        // Oh dear. Inconsistent names left 'section' here for backward compatibility,
                        // but also save sectionid and sectionnumber.
                        $mods[$cmid]->section = $section->section;
                        $mods[$cmid]->sectionnumber = $section->section;
                        $mods[$cmid]->sectionid = $rawmods[$cmid]->section;

                        $mods[$cmid]->module = $rawmods[$cmid]->module;
                        $mods[$cmid]->added = $rawmods[$cmid]->added;
                        $mods[$cmid]->score = $rawmods[$cmid]->score;
                        $mods[$cmid]->idnumber = $rawmods[$cmid]->idnumber;
                        $mods[$cmid]->visible = $rawmods[$cmid]->visible;
                        $mods[$cmid]->visibleoncoursepage = $rawmods[$cmid]->visibleoncoursepage;
                        $mods[$cmid]->visibleold = $rawmods[$cmid]->visibleold;
                        $mods[$cmid]->groupmode = $rawmods[$cmid]->groupmode;
                        $mods[$cmid]->groupingid = $rawmods[$cmid]->groupingid;
                        $mods[$cmid]->indent = $rawmods[$cmid]->indent;
                        $mods[$cmid]->completion = $rawmods[$cmid]->completion;
                        $mods[$cmid]->extra = "";
                        $mods[$cmid]->completiongradeitemnumber =
                            $rawmods[$cmid]->completiongradeitemnumber;
                        $mods[$cmid]->completionpassgrade = $rawmods[$cmid]->completionpassgrade;
                        $mods[$cmid]->completionview = $rawmods[$cmid]->completionview;
                        $mods[$cmid]->completionexpected = $rawmods[$cmid]->completionexpected;
                        $mods[$cmid]->showdescription = $rawmods[$cmid]->showdescription;
                        $mods[$cmid]->availability = $rawmods[$cmid]->availability;
                        $mods[$cmid]->deletioninprogress = $rawmods[$cmid]->deletioninprogress;
                        $mods[$cmid]->downloadcontent = $rawmods[$cmid]->downloadcontent;
                        $mods[$cmid]->lang = $rawmods[$cmid]->lang;

                        $modname = $mods[$cmid]->mod;
                        $functionname = $modname . "_get_coursemodule_info";

                        if (!file_exists("$CFG->dirroot/mod/$modname/lib.php")) {
                            continue;
                        }

                        include_once("$CFG->dirroot/mod/$modname/lib.php");

                        if ($hasfunction = function_exists($functionname)) {
                            if ($info = $functionname($rawmods[$cmid])) {
                                if (!empty($info->icon)) {
                                    $mods[$cmid]->icon = $info->icon;
                                }
                                if (!empty($info->iconcomponent)) {
                                    $mods[$cmid]->iconcomponent = $info->iconcomponent;
                                }
                                if (!empty($info->name)) {
                                    $mods[$cmid]->name = $info->name;
                                }
                                if ($info instanceof cached_cm_info) {
                                    // When using cached_cm_info you can include three new fields.
                                    // That aren't available for legacy code.
                                    if (!empty($info->content)) {
                                        $mods[$cmid]->content = $info->content;
                                    }
                                    if (!empty($info->extraclasses)) {
                                        $mods[$cmid]->extraclasses = $info->extraclasses;
                                    }
                                    if (!empty($info->iconurl)) {
                                        // Convert URL to string as it's easier to store.
                                        // Also serialized object contains \0 byte,
                                        // ... and can not be written to Postgres DB.
                                        $url = new moodle_url($info->iconurl);
                                        $mods[$cmid]->iconurl = $url->out(false);
                                    }
                                    if (!empty($info->onclick)) {
                                        $mods[$cmid]->onclick = $info->onclick;
                                    }
                                    if (!empty($info->customdata)) {
                                        $mods[$cmid]->customdata = $info->customdata;
                                    }
                                } else {
                                    // When using a stdclass, the (horrible) deprecated ->extra field,
                                    // ... that is available for BC.
                                    if (!empty($info->extra)) {
                                        $mods[$cmid]->extra = $info->extra;
                                    }
                                }
                            }
                        }
                        // When there is no modname_get_coursemodule_info function,
                        // ... but showdescriptions is enabled, then we use the 'intro',
                        // ... and 'introformat' fields in the module table.
                        if (!$hasfunction && $rawmods[$cmid]->showdescription) {
                            if ($modvalues = $DB->get_record($rawmods[$cmid]->modname,
                                ['id' => $rawmods[$cmid]->instance], 'name, intro, introformat')) {
                                // Set content from intro and introformat. Filters are disabled.
                                // Because we filter it with format_text at display time.
                                $mods[$cmid]->content = format_module_intro($rawmods[$cmid]->modname,
                                    $modvalues, $rawmods[$cmid]->id, false);

                                // To save making another query just below, put name in here.
                                $mods[$cmid]->name = $modvalues->name;
                            }
                        }
                        if (!isset($mods[$cmid]->name)) {
                            $mods[$cmid]->name = $DB->get_field($rawmods[$cmid]->modname, "name",
                                ["id" => $rawmods[$cmid]->instance]);
                        }

                        // Minimise the database size by unsetting default options when they are 'empty'.
                        // This list corresponds to code in the cm_info constructor.
                        foreach (['idnumber', 'groupmode', 'groupingid',
                            'indent', 'completion', 'extra', 'extraclasses', 'iconurl', 'onclick', 'content',
                            'icon', 'iconcomponent', 'customdata', 'availability', 'completionview',
                            'completionexpected', 'score', 'showdescription', 'deletioninprogress'] as $property) {
                            if (property_exists($mods[$cmid], $property) &&
                                empty($mods[$cmid]->{$property})) {
                                unset($mods[$cmid]->{$property});
                            }
                        }
                        // Special case: this value is usually set to null, but may be 0.
                        if (property_exists($mods[$cmid], 'completiongradeitemnumber') &&
                            is_null($mods[$cmid]->completiongradeitemnumber)) {
                            unset($mods[$cmid]->completiongradeitemnumber);
                        }
                    }
                }
            }
        }
        return $mods;
    }

    /**
     * Purge the cache of a given course
     *
     * @param int $courseid Course id
     */
    public static function purge_course_cache(int $courseid): void {
        increment_revision_number('course', 'cacherev', 'id = :id', array('id' => $courseid));
        // Because this is a versioned cache, there is no need to actually delete the cache item,
        // only increase the required version number.
    }

    /**
     * Can this module type be displayed on a course page or selected from the activity types when adding an activity to a course?
     *
     * @param string $modname The module type name
     * @return bool
     */
    public static function is_mod_type_visible_on_course(string $modname): bool {
        return plugin_supports('mod', $modname, FEATURE_CAN_DISPLAY, true);
    }
}


/**
 * Data about a single module on a course. This contains most of the fields in the course_modules
 * table, plus additional data when required.
 *
 * The object can be accessed by core or any plugin (i.e. course format, block, filter, etc.) as
 * get_fast_modinfo($courseorid)->cms[$coursemoduleid]
 * or
 * get_fast_modinfo($courseorid)->instances[$moduletype][$instanceid]
 *
 * There are three stages when activity module can add/modify data in this object:
 *
 * <b>Stage 1 - during building the cache.</b>
 * Allows to add to the course cache static user-independent information about the module.
 * Modules should try to include only absolutely necessary information that may be required
 * when displaying course view page. The information is stored in application-level cache
 * and reset when {@link rebuild_course_cache()} is called or cache is purged by admin.
 *
 * Modules can implement callback XXX_get_coursemodule_info() returning instance of object
 * {@link cached_cm_info}
 *
 * <b>Stage 2 - dynamic data.</b>
 * Dynamic data is user-dependent, it is stored in request-level cache. To reset this cache
 * {@link get_fast_modinfo()} with $reset argument may be called.
 *
 * Dynamic data is obtained when any of the following properties/methods is requested:
 * - {@link cm_info::$url}
 * - {@link cm_info::$name}
 * - {@link cm_info::$onclick}
 * - {@link cm_info::get_icon_url()}
 * - {@link cm_info::$uservisible}
 * - {@link cm_info::$available}
 * - {@link cm_info::$availableinfo}
 * - plus any of the properties listed in Stage 3.
 *
 * Modules can implement callback <b>XXX_cm_info_dynamic()</b> and inside this callback they
 * are allowed to use any of the following set methods:
 * - {@link cm_info::set_available()}
 * - {@link cm_info::set_name()}
 * - {@link cm_info::set_no_view_link()}
 * - {@link cm_info::set_user_visible()}
 * - {@link cm_info::set_on_click()}
 * - {@link cm_info::set_icon_url()}
 * - {@link cm_info::override_customdata()}
 * Any methods affecting view elements can also be set in this callback.
 *
 * <b>Stage 3 (view data).</b>
 * Also user-dependend data stored in request-level cache. Second stage is created
 * because populating the view data can be expensive as it may access much more
 * Moodle APIs such as filters, user information, output renderers and we
 * don't want to request it until necessary.
 * View data is obtained when any of the following properties/methods is requested:
 * - {@link cm_info::$afterediticons}
 * - {@link cm_info::$content}
 * - {@link cm_info::get_formatted_content()}
 * - {@link cm_info::$extraclasses}
 * - {@link cm_info::$afterlink}
 *
 * Modules can implement callback <b>XXX_cm_info_view()</b> and inside this callback they
 * are allowed to use any of the following set methods:
 * - {@link cm_info::set_after_edit_icons()}
 * - {@link cm_info::set_after_link()}
 * - {@link cm_info::set_content()}
 * - {@link cm_info::set_extra_classes()}
 *
 * @property-read int $id Course-module ID - from course_modules table
 * @property-read int $instance Module instance (ID within module table) - from course_modules table
 * @property-read int $course Course ID - from course_modules table
 * @property-read string $idnumber 'ID number' from course-modules table (arbitrary text set by user) - from
 *    course_modules table
 * @property-read int $added Time that this course-module was added (unix time) - from course_modules table
 * @property-read int $visible Visible setting (0 or 1; if this is 0, students cannot see/access the activity) - from
 *    course_modules table
 * @property-read int $visibleoncoursepage Visible on course page setting - from course_modules table, adjusted to
 *    whether course format allows this module to have the "stealth" mode
 * @property-read int $visibleold Old visible setting (if the entire section is hidden, the previous value for
 *    visible is stored in this field) - from course_modules table
 * @property-read int $groupmode Group mode (one of the constants NOGROUPS, SEPARATEGROUPS, or VISIBLEGROUPS) - from
 *    course_modules table. Use {@link cm_info::$effectivegroupmode} to find the actual group mode that may be forced by course.
 * @property-read int $groupingid Grouping ID (0 = all groupings)
 * @property-read bool $coursegroupmodeforce Indicates whether the course containing the module has forced the groupmode
 *    This means that cm_info::$groupmode should be ignored and cm_info::$coursegroupmode be used instead
 * @property-read int $coursegroupmode Group mode (one of the constants NOGROUPS, SEPARATEGROUPS, or VISIBLEGROUPS) - from
 *    course table - as specified for the course containing the module
 *    Effective only if {@link cm_info::$coursegroupmodeforce} is set
 * @property-read int $effectivegroupmode Effective group mode for this module (one of the constants NOGROUPS, SEPARATEGROUPS,
 *    or VISIBLEGROUPS). This can be different from groupmode set for the module if the groupmode is forced for the course.
 *    This value will always be NOGROUPS if module type does not support group mode.
 * @property-read int $indent Indent level on course page (0 = no indent) - from course_modules table
 * @property-read int $completion Activity completion setting for this activity, COMPLETION_TRACKING_xx constant - from
 *    course_modules table
 * @property-read mixed $completiongradeitemnumber Set to the item number (usually 0) if completion depends on a particular
 *    grade of this activity, or null if completion does not depend on a grade - from course_modules table
 * @property-read int $completionview 1 if 'on view' completion is enabled, 0 otherwise - from course_modules table
 * @property-read int $completionexpected Set to a unix time if completion of this activity is expected at a
 *    particular time, 0 if no time set - from course_modules table
 * @property-read string $availability Availability information as JSON string or null if none -
 *    from course_modules table
 * @property-read int $showdescription Controls whether the description of the activity displays on the course main page (in
 *    addition to anywhere it might display within the activity itself). 0 = do not show
 *    on main page, 1 = show on main page.
 * @property-read string $extra (deprecated) Extra HTML that is put in an unhelpful part of the HTML when displaying this module in
 *    course page - from cached data in modinfo field. Deprecated, replaced by ->extraclasses and ->onclick
 * @property-read string $icon Name of icon to use - from cached data in modinfo field
 * @property-read string $iconcomponent Component that contains icon - from cached data in modinfo field
 * @property-read string $modname Name of module e.g. 'forum' (this is the same name as the module's main database
 *    table) - from cached data in modinfo field
 * @property-read int $module ID of module type - from course_modules table
 * @property-read string $name Name of module instance for display on page e.g. 'General discussion forum' - from cached
 *    data in modinfo field
 * @property-read int $sectionnum Section number that this course-module is in (section 0 = above the calendar, section 1
 *    = week/topic 1, etc) - from cached data in modinfo field
 * @property-read int $sectionid Section id - from course_modules table
 * @property-read array $conditionscompletion Availability conditions for this course-module based on the completion of other
 *    course-modules (array from other course-module id to required completion state for that
 *    module) - from cached data in modinfo field
 * @property-read array $conditionsgrade Availability conditions for this course-module based on course grades (array from
 *    grade item id to object with ->min, ->max fields) - from cached data in modinfo field
 * @property-read array $conditionsfield Availability conditions for this course-module based on user fields
 * @property-read bool $available True if this course-module is available to students i.e. if all availability conditions
 *    are met - obtained dynamically
 * @property-read string $availableinfo If course-module is not available to students, this string gives information about
 *    availability which can be displayed to students and/or staff (e.g. 'Available from 3
 *    January 2010') for display on main page - obtained dynamically
 * @property-read bool $uservisible True if this course-module is available to the CURRENT user (for example, if current user
 *    has viewhiddenactivities capability, they can access the course-module even if it is not
 *    visible or not available, so this would be true in that case)
 * @property-read context_module $context Module context
 * @property-read string $modfullname Returns a localised human-readable name of the module type - calculated on request
 * @property-read string $modplural Returns a localised human-readable name of the module type in plural form - calculated on request
 * @property-read string $content Content to display on main (view) page - calculated on request
 * @property-read moodle_url $url URL to link to for this module, or null if it doesn't have a view page - calculated on request
 * @property-read string $extraclasses Extra CSS classes to add to html output for this activity on main page - calculated on request
 * @property-read string $onclick Content of HTML on-click attribute already escaped - calculated on request
 * @property-read mixed $customdata Optional custom data stored in modinfo cache for this activity, or null if none
 * @property-read string $afterlink Extra HTML code to display after link - calculated on request
 * @property-read string $afterediticons Extra HTML code to display after editing icons (e.g. more icons) - calculated on request
 * @property-read bool $deletioninprogress True if this course module is scheduled for deletion, false otherwise.
 * @property-read bool $downloadcontent True if content download is enabled for this course module, false otherwise.
 * @property-read bool $lang the forced language for this activity (language pack name). Null means not forced.
 */
class cm_info implements IteratorAggregate {
    /**
     * State: Only basic data from modinfo cache is available.
     */
    const STATE_BASIC = 0;

    /**
     * State: In the process of building dynamic data (to avoid recursive calls to obtain_dynamic_data())
     */
    const STATE_BUILDING_DYNAMIC = 1;

    /**
     * State: Dynamic data is available too.
     */
    const STATE_DYNAMIC = 2;

    /**
     * State: In the process of building view data (to avoid recursive calls to obtain_view_data())
     */
    const STATE_BUILDING_VIEW = 3;

    /**
     * State: View data (for course page) is available.
     */
    const STATE_VIEW = 4;

    /**
     * Parent object
     * @var course_modinfo
     */
    private $modinfo;

    /**
     * Level of information stored inside this object (STATE_xx constant)
     * @var int
     */
    private $state;

    /**
     * Course-module ID - from course_modules table
     * @var int
     */
    private $id;

    /**
     * Module instance (ID within module table) - from course_modules table
     * @var int
     */
    private $instance;

    /**
     * 'ID number' from course-modules table (arbitrary text set by user) - from
     * course_modules table
     * @var string
     */
    private $idnumber;

    /**
     * Time that this course-module was added (unix time) - from course_modules table
     * @var int
     */
    private $added;

    /**
     * This variable is not used and is included here only so it can be documented.
     * Once the database entry is removed from course_modules, it should be deleted
     * here too.
     * @var int
     * @deprecated Do not use this variable
     */
    private $score;

    /**
     * Visible setting (0 or 1; if this is 0, students cannot see/access the activity) - from
     * course_modules table
     * @var int
     */
    private $visible;

    /**
     * Visible on course page setting - from course_modules table
     * @var int
     */
    private $visibleoncoursepage;

    /**
     * Old visible setting (if the entire section is hidden, the previous value for
     * visible is stored in this field) - from course_modules table
     * @var int
     */
    private $visibleold;

    /**
     * Group mode (one of the constants NONE, SEPARATEGROUPS, or VISIBLEGROUPS) - from
     * course_modules table
     * @var int
     */
    private $groupmode;

    /**
     * Grouping ID (0 = all groupings)
     * @var int
     */
    private $groupingid;

    /**
     * Indent level on course page (0 = no indent) - from course_modules table
     * @var int
     */
    private $indent;

    /**
     * Activity completion setting for this activity, COMPLETION_TRACKING_xx constant - from
     * course_modules table
     * @var int
     */
    private $completion;

    /**
     * Set to the item number (usually 0) if completion depends on a particular
     * grade of this activity, or null if completion does not depend on a grade - from
     * course_modules table
     * @var mixed
     */
    private $completiongradeitemnumber;

    /**
     * 1 if pass grade completion is enabled, 0 otherwise - from course_modules table
     * @var int
     */
    private $completionpassgrade;

    /**
     * 1 if 'on view' completion is enabled, 0 otherwise - from course_modules table
     * @var int
     */
    private $completionview;

    /**
     * Set to a unix time if completion of this activity is expected at a
     * particular time, 0 if no time set - from course_modules table
     * @var int
     */
    private $completionexpected;

    /**
     * Availability information as JSON string or null if none - from course_modules table
     * @var string
     */
    private $availability;

    /**
     * Controls whether the description of the activity displays on the course main page (in
     * addition to anywhere it might display within the activity itself). 0 = do not show
     * on main page, 1 = show on main page.
     * @var int
     */
    private $showdescription;

    /**
     * Extra HTML that is put in an unhelpful part of the HTML when displaying this module in
     * course page - from cached data in modinfo field
     * @deprecated This is crazy, don't use it. Replaced by ->extraclasses and ->onclick
     * @var string
     */
    private $extra;

    /**
     * Name of icon to use - from cached data in modinfo field
     * @var string
     */
    private $icon;

    /**
     * Component that contains icon - from cached data in modinfo field
     * @var string
     */
    private $iconcomponent;

    /**
     * The instance record form the module table
     * @var stdClass
     */
    private $instancerecord;

    /**
     * Name of module e.g. 'forum' (this is the same name as the module's main database
     * table) - from cached data in modinfo field
     * @var string
     */
    private $modname;

    /**
     * ID of module - from course_modules table
     * @var int
     */
    private $module;

    /**
     * Name of module instance for display on page e.g. 'General discussion forum' - from cached
     * data in modinfo field
     * @var string
     */
    private $name;

    /**
     * Section number that this course-module is in (section 0 = above the calendar, section 1
     * = week/topic 1, etc) - from cached data in modinfo field
     * @var int
     */
    private $sectionnum;

    /**
     * Section id - from course_modules table
     * @var int
     */
    private $sectionid;

    /**
     * Availability conditions for this course-module based on the completion of other
     * course-modules (array from other course-module id to required completion state for that
     * module) - from cached data in modinfo field
     * @var array
     */
    private $conditionscompletion;

    /**
     * Availability conditions for this course-module based on course grades (array from
     * grade item id to object with ->min, ->max fields) - from cached data in modinfo field
     * @var array
     */
    private $conditionsgrade;

    /**
     * Availability conditions for this course-module based on user fields
     * @var array
     */
    private $conditionsfield;

    /**
     * True if this course-module is available to students i.e. if all availability conditions
     * are met - obtained dynamically
     * @var bool
     */
    private $available;

    /**
     * If course-module is not available to students, this string gives information about
     * availability which can be displayed to students and/or staff (e.g. 'Available from 3
     * January 2010') for display on main page - obtained dynamically
     * @var string
     */
    private $availableinfo;

    /**
     * True if this course-module is available to the CURRENT user (for example, if current user
     * has viewhiddenactivities capability, they can access the course-module even if it is not
     * visible or not available, so this would be true in that case)
     * @var bool
     */
    private $uservisible;

    /**
     * True if this course-module is visible to the CURRENT user on the course page
     * @var bool
     */
    private $uservisibleoncoursepage;

    /**
     * @var moodle_url
     */
    private $url;

    /**
     * @var string
     */
    private $content;

    /**
     * @var bool
     */
    private $contentisformatted;

    /**
     * @var bool True if the content has a special course item display like labels.
     */
    private $customcmlistitem;

    /**
     * @var string
     */
    private $extraclasses;

    /**
     * @var moodle_url full external url pointing to icon image for activity
     */
    private $iconurl;

    /**
     * @var string
     */
    private $onclick;

    /**
     * @var mixed
     */
    private $customdata;

    /**
     * @var string
     */
    private $afterlink;

    /**
     * @var string
     */
    private $afterediticons;

    /**
     * @var bool representing the deletion state of the module. True if the mod is scheduled for deletion.
     */
    private $deletioninprogress;

    /**
     * @var int enable/disable download content for this course module
     */
    private $downloadcontent;

    /**
     * @var string|null the forced language for this activity (language pack name). Null means not forced.
     */
    private $lang;

    /**
     * List of class read-only properties and their getter methods.
     * Used by magic functions __get(), __isset(), __empty()
     * @var array
     */
    private static $standardproperties = [
        'url' => 'get_url',
        'content' => 'get_content',
        'extraclasses' => 'get_extra_classes',
        'onclick' => 'get_on_click',
        'customdata' => 'get_custom_data',
        'afterlink' => 'get_after_link',
        'afterediticons' => 'get_after_edit_icons',
        'modfullname' => 'get_module_type_name',
        'modplural' => 'get_module_type_name_plural',
        'id' => false,
        'added' => false,
        'availability' => false,
        'available' => 'get_available',
        'availableinfo' => 'get_available_info',
        'completion' => false,
        'completionexpected' => false,
        'completiongradeitemnumber' => false,
        'completionpassgrade' => false,
        'completionview' => false,
        'conditionscompletion' => false,
        'conditionsfield' => false,
        'conditionsgrade' => false,
        'context' => 'get_context',
        'course' => 'get_course_id',
        'coursegroupmode' => 'get_course_groupmode',
        'coursegroupmodeforce' => 'get_course_groupmodeforce',
        'customcmlistitem' => 'has_custom_cmlist_item',
        'effectivegroupmode' => 'get_effective_groupmode',
        'extra' => false,
        'groupingid' => false,
        'groupmembersonly' => 'get_deprecated_group_members_only',
        'groupmode' => false,
        'icon' => false,
        'iconcomponent' => false,
        'idnumber' => false,
        'indent' => false,
        'instance' => false,
        'modname' => false,
        'module' => false,
        'name' => 'get_name',
        'score' => false,
        'section' => 'get_section_id',
        'sectionid' => false,
        'sectionnum' => false,
        'showdescription' => false,
        'uservisible' => 'get_user_visible',
        'visible' => false,
        'visibleoncoursepage' => false,
        'visibleold' => false,
        'deletioninprogress' => false,
        'downloadcontent' => false,
        'lang' => false,
    ];

    /**
     * List of methods with no arguments that were public prior to Moodle 2.6.
     *
     * They can still be accessed publicly via magic __call() function with no warnings
     * but are not listed in the class methods list.
     * For the consistency of the code it is better to use corresponding properties.
     *
     * These methods be deprecated completely in later versions.
     *
     * @var array $standardmethods
     */
    private static $standardmethods = array(
        // Following methods are not recommended to use because there have associated read-only properties.
        'get_url',
        'get_content',
        'get_extra_classes',
        'get_on_click',
        'get_custom_data',
        'get_after_link',
        'get_after_edit_icons',
        // Method obtain_dynamic_data() should not be called from outside of this class but it was public before Moodle 2.6.
        'obtain_dynamic_data',
    );

    /**
     * Magic method to call functions that are now declared as private but were public in Moodle before 2.6.
     * These private methods can not be used anymore.
     *
     * @param string $name
     * @param array $arguments
     * @return mixed
     * @throws coding_exception
     */
    public function __call($name, $arguments) {
        if (in_array($name, self::$standardmethods)) {
            $message = "cm_info::$name() can not be used anymore.";
            if ($alternative = array_search($name, self::$standardproperties)) {
                $message .= " Please use the property cm_info->$alternative instead.";
            }
            throw new coding_exception($message);
        }
        throw new coding_exception("Method cm_info::{$name}() does not exist");
    }

    /**
     * Magic method getter
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name) {
        if (isset(self::$standardproperties[$name])) {
            if ($method = self::$standardproperties[$name]) {
                return $this->$method();
            } else {
                return $this->$name;
            }
        } else {
            debugging('Invalid cm_info property accessed: '.$name);
            return null;
        }
    }

    /**
     * Implementation of IteratorAggregate::getIterator(), allows to cycle through properties
     * and use {@link convert_to_array()}
     *
     * @return ArrayIterator
     */
    public function getIterator(): Traversable {
        // Make sure dynamic properties are retrieved prior to view properties.
        $this->obtain_dynamic_data();
        $ret = array();

        // Do not iterate over deprecated properties.
        $props = self::$standardproperties;
        unset($props['groupmembersonly']);

        foreach ($props as $key => $unused) {
            $ret[$key] = $this->__get($key);
        }
        return new ArrayIterator($ret);
    }

    /**
     * Magic method for function isset()
     *
     * @param string $name
     * @return bool
     */
    public function __isset($name) {
        if (isset(self::$standardproperties[$name])) {
            $value = $this->__get($name);
            return isset($value);
        }
        return false;
    }

    /**
     * Magic method for function empty()
     *
     * @param string $name
     * @return bool
     */
    public function __empty($name) {
        if (isset(self::$standardproperties[$name])) {
            $value = $this->__get($name);
            return empty($value);
        }
        return true;
    }

    /**
     * Magic method setter
     *
     * Will display the developer warning when trying to set/overwrite property.
     *
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value) {
        debugging("It is not allowed to set the property cm_info::\${$name}", DEBUG_DEVELOPER);
    }

    /**
     * @return bool True if this module has a 'view' page that should be linked to in navigation
     *   etc (note: modules may still have a view.php file, but return false if this is not
     *   intended to be linked to from 'normal' parts of the interface; this is what label does).
     */
    public function has_view() {
        return !is_null($this->url);
    }

    /**
     * Gets the URL to link to for this module.
     *
     * This method is normally called by the property ->url, but can be called directly if
     * there is a case when it might be called recursively (you can't call property values
     * recursively).
     *
     * @return moodle_url URL to link to for this module, or null if it doesn't have a view page
     */
    public function get_url() {
        $this->obtain_dynamic_data();
        return $this->url;
    }

    /**
     * Obtains content to display on main (view) page.
     * Note: Will collect view data, if not already obtained.
     * @return string Content to display on main page below link, or empty string if none
     */
    private function get_content() {
        $this->obtain_view_data();
        return $this->content;
    }

    /**
     * Returns the content to display on course/overview page, formatted and passed through filters
     *
     * if $options['context'] is not specified, the module context is used
     *
     * @param array|stdClass $options formatting options, see {@link format_text()}
     * @return string
     */
    public function get_formatted_content($options = array()) {
        $this->obtain_view_data();
        if (empty($this->content)) {
            return '';
        }
        if ($this->contentisformatted) {
            return $this->content;
        }

        // Improve filter performance by preloading filter setttings for all
        // activities on the course (this does nothing if called multiple
        // times)
        filter_preload_activities($this->get_modinfo());

        $options = (array)$options;
        if (!isset($options['context'])) {
            $options['context'] = $this->get_context();
        }
        return format_text($this->content, FORMAT_HTML, $options);
    }

    /**
     * Return the module custom cmlist item flag.
     *
     * Activities like label uses this flag to indicate that it should be
     * displayed as a custom course item instead of a tipical activity card.
     *
     * @return bool
     */
    public function has_custom_cmlist_item(): bool {
        $this->obtain_view_data();
        return $this->customcmlistitem ?? false;
    }

    /**
     * Getter method for property $name, ensures that dynamic data is obtained.
     *
     * This method is normally called by the property ->name, but can be called directly if there
     * is a case when it might be called recursively (you can't call property values recursively).
     *
     * @return string
     */
    public function get_name() {
        $this->obtain_dynamic_data();
        return $this->name;
    }

    /**
     * Returns the name to display on course/overview page, formatted and passed through filters
     *
     * if $options['context'] is not specified, the module context is used
     *
     * @param array|stdClass $options formatting options, see {@link format_string()}
     * @return string
     */
    public function get_formatted_name($options = array()) {
        global $CFG;
        $options = (array)$options;
        if (!isset($options['context'])) {
            $options['context'] = $this->get_context();
        }
        // Improve filter performance by preloading filter setttings for all
        // activities on the course (this does nothing if called multiple
        // times).
        if (!empty($CFG->filterall)) {
            filter_preload_activities($this->get_modinfo());
        }
        return format_string($this->get_name(), true,  $options);
    }

    /**
     * Note: Will collect view data, if not already obtained.
     * @return string Extra CSS classes to add to html output for this activity on main page
     */
    private function get_extra_classes() {
        $this->obtain_view_data();
        return $this->extraclasses;
    }

    /**
     * @return string Content of HTML on-click attribute. This string will be used literally
     * as a string so should be pre-escaped.
     */
    private function get_on_click() {
        // Does not need view data; may be used by navigation
        $this->obtain_dynamic_data();
        return $this->onclick;
    }
    /**
     * Getter method for property $customdata, ensures that dynamic data is retrieved.
     *
     * This method is normally called by the property ->customdata, but can be called directly if there
     * is a case when it might be called recursively (you can't call property values recursively).
     *
     * @return mixed Optional custom data stored in modinfo cache for this activity, or null if none
     */
    public function get_custom_data() {
        $this->obtain_dynamic_data();
        return $this->customdata;
    }

    /**
     * Note: Will collect view data, if not already obtained.
     * @return string Extra HTML code to display after link
     */
    private function get_after_link() {
        $this->obtain_view_data();
        return $this->afterlink;
    }

    /**
     * Get the activity badge data associated to this course module (if the module supports it).
     * Modules can use this method to provide additional data to be displayed in the activity badge.
     *
     * @param renderer_base $output Output render to use, or null for default (global)
     * @return stdClass|null The activitybadge data (badgecontent, badgestyle...) or null if the module doesn't implement it.
     */
    public function get_activitybadge(?renderer_base $output = null): ?stdClass {
        global $OUTPUT;

        $activibybadgeclass = activitybadge::create_instance($this);
        if (empty($activibybadgeclass)) {
            return null;
        }

        if (!isset($output)) {
            $output = $OUTPUT;
        }

        return $activibybadgeclass->export_for_template($output);
    }

    /**
     * Note: Will collect view data, if not already obtained.
     * @return string Extra HTML code to display after editing icons (e.g. more icons)
     */
    private function get_after_edit_icons() {
        $this->obtain_view_data();
        return $this->afterediticons;
    }

    /**
     * Fetch the module's icon URL.
     *
     * This function fetches the course module instance's icon URL.
     * This method adds a `filtericon` parameter in the URL when rendering the monologo version of the course module icon or when
     * the plugin declares, via its `filtericon` custom data, that the icon needs to be filtered.
     * This additional information can be used by plugins when rendering the module icon to determine whether to apply
     * CSS filtering to the icon.
     *
     * @param core_renderer $output Output render to use, or null for default (global)
     * @return moodle_url Icon URL for a suitable icon to put beside this cm
     */
    public function get_icon_url($output = null) {
        global $OUTPUT;
        $this->obtain_dynamic_data();
        if (!$output) {
            $output = $OUTPUT;
        }

        $ismonologo = false;
        if (!empty($this->iconurl)) {
            // Support modules setting their own, external, icon image.
            $icon = $this->iconurl;
        } else if (!empty($this->icon)) {
            // Fallback to normal local icon + component processing.
            if (substr($this->icon, 0, 4) === 'mod/') {
                list($modname, $iconname) = explode('/', substr($this->icon, 4), 2);
                $icon = $output->image_url($iconname, $modname);
            } else {
                if (!empty($this->iconcomponent)) {
                    // Icon has specified component.
                    $icon = $output->image_url($this->icon, $this->iconcomponent);
                } else {
                    // Icon does not have specified component, use default.
                    $icon = $output->image_url($this->icon);
                }
            }
        } else {
            $icon = $output->image_url('monologo', $this->modname);
            // Activity modules may only have an `icon` icon instead of a `monologo` icon.
            // So we need to determine if the module really has a `monologo` icon.
            $ismonologo = core_component::has_monologo_icon('mod', $this->modname);
        }

        // Determine whether the icon will be filtered in the CSS.
        // This can be controlled by the module by declaring a 'filtericon' custom data.
        // If the 'filtericon' custom data is not set, icon filtering will be determined whether the module has a `monologo` icon.
        // Additionally, we need to cast custom data to array as some modules may treat it as an object.
        $filtericon = ((array)$this->customdata)['filtericon'] ?? $ismonologo;
        if ($filtericon) {
            $icon->param('filtericon', 1);
        }
        return $icon;
    }

    /**
     * @param string $textclasses additionnal classes for grouping label
     * @return string An empty string or HTML grouping label span tag
     */
    public function get_grouping_label($textclasses = '') {
        $groupinglabel = '';
        if ($this->effectivegroupmode != NOGROUPS && !empty($this->groupingid) &&
                has_capability('moodle/course:managegroups', context_course::instance($this->course))) {
            $groupings = groups_get_all_groupings($this->course);
            $groupinglabel = html_writer::tag('span', '('.format_string($groupings[$this->groupingid]->name).')',
                array('class' => 'groupinglabel '.$textclasses));
        }
        return $groupinglabel;
    }

    /**
     * Returns a localised human-readable name of the module type.
     *
     * @param bool $plural If true, the function returns the plural form of the name.
     * @return ?lang_string
     */
    public function get_module_type_name($plural = false) {
        $modnames = get_module_types_names($plural);
        if (isset($modnames[$this->modname])) {
            return $modnames[$this->modname];
        } else {
            return null;
        }
    }

    /**
     * Returns a localised human-readable name of the module type in plural form - calculated on request
     *
     * @return string
     */
    private function get_module_type_name_plural() {
        return $this->get_module_type_name(true);
    }

    /**
     * @return course_modinfo Modinfo object that this came from
     */
    public function get_modinfo() {
        return $this->modinfo;
    }

    /**
     * Returns the section this module belongs to
     *
     * @return section_info
     */
    public function get_section_info() {
        return $this->modinfo->get_section_info_by_id($this->sectionid);
    }

    /**
     * Getter method for property $section that returns section id.
     *
     * This method is called by the property ->section.
     *
     * @return int
     */
    private function get_section_id(): int {
        return $this->sectionid;
    }

    /**
     * Returns course object that was used in the first {@link get_fast_modinfo()} call.
     *
     * It may not contain all fields from DB table {course} but always has at least the following:
     * id,shortname,fullname,format,enablecompletion,groupmode,groupmodeforce,cacherev
     *
     * If the course object lacks the field you need you can use the global
     * function {@link get_course()} that will save extra query if you access
     * current course or frontpage course.
     *
     * @return stdClass
     */
    public function get_course() {
        return $this->modinfo->get_course();
    }

    /**
     * Returns course id for which the modinfo was generated.
     *
     * @return int
     */
    private function get_course_id() {
        return $this->modinfo->get_course_id();
    }

    /**
     * Returns group mode used for the course containing the module
     *
     * @return int one of constants NOGROUPS, SEPARATEGROUPS, VISIBLEGROUPS
     */
    private function get_course_groupmode() {
        return $this->modinfo->get_course()->groupmode;
    }

    /**
     * Returns whether group mode is forced for the course containing the module
     *
     * @return bool
     */
    private function get_course_groupmodeforce() {
        return $this->modinfo->get_course()->groupmodeforce;
    }

    /**
     * Returns effective groupmode of the module that may be overwritten by forced course groupmode.
     *
     * @return int one of constants NOGROUPS, SEPARATEGROUPS, VISIBLEGROUPS
     */
    private function get_effective_groupmode() {
        $groupmode = $this->groupmode;
        if ($this->modinfo->get_course()->groupmodeforce) {
            $groupmode = $this->modinfo->get_course()->groupmode;
            if ($groupmode != NOGROUPS && !plugin_supports('mod', $this->modname, FEATURE_GROUPS, false)) {
                $groupmode = NOGROUPS;
            }
        }
        return $groupmode;
    }

    /**
     * @return context_module Current module context
     */
    private function get_context() {
        return context_module::instance($this->id);
    }

    /**
     * Returns itself in the form of stdClass.
     *
     * The object includes all fields that table course_modules has and additionally
     * fields 'name', 'modname', 'sectionnum' (if requested).
     *
     * This can be used as a faster alternative to {@link get_coursemodule_from_id()}
     *
     * @param bool $additionalfields include additional fields 'name', 'modname', 'sectionnum'
     * @return stdClass
     */
    public function get_course_module_record($additionalfields = false) {
        $cmrecord = new stdClass();

        // Standard fields from table course_modules.
        static $cmfields = array('id', 'course', 'module', 'instance', 'section', 'idnumber', 'added',
            'score', 'indent', 'visible', 'visibleoncoursepage', 'visibleold', 'groupmode', 'groupingid',
            'completion', 'completiongradeitemnumber', 'completionview', 'completionexpected', 'completionpassgrade',
            'showdescription', 'availability', 'deletioninprogress', 'downloadcontent', 'lang');

        foreach ($cmfields as $key) {
            $cmrecord->$key = $this->$key;
        }

        // Additional fields that function get_coursemodule_from_id() adds.
        if ($additionalfields) {
            $cmrecord->name = $this->name;
            $cmrecord->modname = $this->modname;
            $cmrecord->sectionnum = $this->sectionnum;
        }

        return $cmrecord;
    }

    /**
     * Return the activity database table record.
     *
     * The instance record will be cached after the first call.
     *
     * @return stdClass
     */
    public function get_instance_record() {
        global $DB;
        if (!isset($this->instancerecord)) {
            $this->instancerecord = $DB->get_record(
                table: $this->modname,
                conditions: ['id' => $this->instance],
                strictness: MUST_EXIST,
            );
        }
        return $this->instancerecord;
    }

    /**
     * Returns the section delegated by this module, if any.
     *
     * @return ?section_info
     */
    public function get_delegated_section_info(): ?section_info {
        $delegatedsections = $this->modinfo->get_sections_delegated_by_cm();
        if (!array_key_exists($this->id, $delegatedsections)) {
            return null;
        }
        return $delegatedsections[$this->id];
    }

    // Set functions
    ////////////////

    /**
     * Sets content to display on course view page below link (if present).
     * @param string $content New content as HTML string (empty string if none)
     * @param bool $isformatted Whether user content is already passed through format_text/format_string and should not
     *    be formatted again. This can be useful when module adds interactive elements on top of formatted user text.
     * @return void
     */
    public function set_content($content, $isformatted = false) {
        $this->content = $content;
        $this->contentisformatted = $isformatted;
    }

    /**
     * Sets extra classes to include in CSS.
     * @param string $extraclasses Extra classes (empty string if none)
     * @return void
     */
    public function set_extra_classes($extraclasses) {
        $this->extraclasses = $extraclasses;
    }

    /**
     * Sets the external full url that points to the icon being used
     * by the activity. Useful for external-tool modules (lti...)
     * If set, takes precedence over $icon and $iconcomponent
     *
     * @param moodle_url $iconurl full external url pointing to icon image for activity
     * @return void
     */
    public function set_icon_url(moodle_url $iconurl) {
        $this->iconurl = $iconurl;
    }

    /**
     * Sets value of on-click attribute for JavaScript.
     * Note: May not be called from _cm_info_view (only _cm_info_dynamic).
     * @param string $onclick New onclick attribute which should be HTML-escaped
     *   (empty string if none)
     * @return void
     */
    public function set_on_click($onclick) {
        $this->check_not_view_only();
        $this->onclick = $onclick;
    }

    /**
     * Overrides the value of an element in the customdata array.
     *
     * @param string $name The key in the customdata array
     * @param mixed $value The value
     */
    public function override_customdata($name, $value) {
        if (!is_array($this->customdata)) {
            $this->customdata = [];
        }
        $this->customdata[$name] = $value;
    }

    /**
     * Sets HTML that displays after link on course view page.
     * @param string $afterlink HTML string (empty string if none)
     * @return void
     */
    public function set_after_link($afterlink) {
        $this->afterlink = $afterlink;
    }

    /**
     * Sets HTML that displays after edit icons on course view page.
     * @param string $afterediticons HTML string (empty string if none)
     * @return void
     */
    public function set_after_edit_icons($afterediticons) {
        $this->afterediticons = $afterediticons;
    }

    /**
     * Changes the name (text of link) for this module instance.
     * Note: May not be called from _cm_info_view (only _cm_info_dynamic).
     * @param string $name Name of activity / link text
     * @return void
     */
    public function set_name($name) {
        if ($this->state < self::STATE_BUILDING_DYNAMIC) {
            $this->update_user_visible();
        }
        $this->name = $name;
    }

    /**
     * Turns off the view link for this module instance.
     * Note: May not be called from _cm_info_view (only _cm_info_dynamic).
     * @return void
     */
    public function set_no_view_link() {
        $this->check_not_view_only();
        $this->url = null;
    }

    /**
     * Sets the 'uservisible' flag. This can be used (by setting false) to prevent access and
     * display of this module link for the current user.
     * Note: May not be called from _cm_info_view (only _cm_info_dynamic).
     * @param bool $uservisible
     * @return void
     */
    public function set_user_visible($uservisible) {
        $this->check_not_view_only();
        $this->uservisible = $uservisible;
    }

    /**
     * Sets the 'customcmlistitem' flag
     *
     * This can be used (by setting true) to prevent the course from rendering the
     * activity item as a regular activity card. This is applied to activities like labels.
     *
     * @param bool $customcmlistitem if the cmlist item of that activity has a special dysplay other than a card.
     */
    public function set_custom_cmlist_item(bool $customcmlistitem) {
        $this->customcmlistitem = $customcmlistitem;
    }

    /**
     * Sets the 'available' flag and related details. This flag is normally used to make
     * course modules unavailable until a certain date or condition is met. (When a course
     * module is unavailable, it is still visible to users who have viewhiddenactivities
     * permission.)
     *
     * When this is function is called, user-visible status is recalculated automatically.
     *
     * The $showavailability flag does not really do anything any more, but is retained
     * for backward compatibility. Setting this to false will cause $availableinfo to
     * be ignored.
     *
     * Note: May not be called from _cm_info_view (only _cm_info_dynamic).
     * @param bool $available False if this item is not 'available'
     * @param int $showavailability 0 = do not show this item at all if it's not available,
     *   1 = show this item greyed out with the following message
     * @param string $availableinfo Information about why this is not available, or
     *   empty string if not displaying
     * @return void
     */
    public function set_available($available, $showavailability=0, $availableinfo='') {
        $this->check_not_view_only();
        $this->available = $available;
        if (!$showavailability) {
            $availableinfo = '';
        }
        $this->availableinfo = $availableinfo;
        $this->update_user_visible();
    }

    /**
     * Some set functions can only be called from _cm_info_dynamic and not _cm_info_view.
     * This is because they may affect parts of this object which are used on pages other
     * than the view page (e.g. in the navigation block, or when checking access on
     * module pages).
     * @return void
     */
    private function check_not_view_only() {
        if ($this->state >= self::STATE_DYNAMIC) {
            throw new coding_exception('Cannot set this data from _cm_info_view because it may ' .
                    'affect other pages as well as view');
        }
    }

    /**
     * Constructor should not be called directly; use {@link get_fast_modinfo()}
     *
     * @param course_modinfo $modinfo Parent object
     * @param mixed $notused1 Argument not used
     * @param stdClass $mod Module object from the modinfo field of course table
     * @param mixed $notused2 Argument not used
     */
    public function __construct(course_modinfo $modinfo, $notused1, $mod, $notused2) {
        $this->modinfo = $modinfo;

        $this->id               = $mod->cm;
        $this->instance         = $mod->id;
        $this->modname          = $mod->mod;
        $this->idnumber         = isset($mod->idnumber) ? $mod->idnumber : '';
        $this->name             = $mod->name;
        $this->visible          = $mod->visible;
        $this->visibleoncoursepage = $mod->visibleoncoursepage;
        $this->sectionnum       = $mod->section; // Note weirdness with name here. Keeping for backwards compatibility.
        $this->groupmode        = isset($mod->groupmode) ? $mod->groupmode : 0;
        $this->groupingid       = isset($mod->groupingid) ? $mod->groupingid : 0;
        $this->indent           = isset($mod->indent) ? $mod->indent : 0;
        $this->extra            = isset($mod->extra) ? $mod->extra : '';
        $this->extraclasses     = isset($mod->extraclasses) ? $mod->extraclasses : '';
        // iconurl may be stored as either string or instance of moodle_url.
        $this->iconurl          = isset($mod->iconurl) ? new moodle_url($mod->iconurl) : '';
        $this->onclick          = isset($mod->onclick) ? $mod->onclick : '';
        $this->content          = isset($mod->content) ? $mod->content : '';
        $this->icon             = isset($mod->icon) ? $mod->icon : '';
        $this->iconcomponent    = isset($mod->iconcomponent) ? $mod->iconcomponent : '';
        $this->customdata       = isset($mod->customdata) ? $mod->customdata : '';
        $this->showdescription  = isset($mod->showdescription) ? $mod->showdescription : 0;
        $this->state = self::STATE_BASIC;

        $this->sectionid = isset($mod->sectionid) ? $mod->sectionid : 0;
        $this->module = isset($mod->module) ? $mod->module : 0;
        $this->added = isset($mod->added) ? $mod->added : 0;
        $this->score = isset($mod->score) ? $mod->score : 0;
        $this->visibleold = isset($mod->visibleold) ? $mod->visibleold : 0;
        $this->deletioninprogress = isset($mod->deletioninprogress) ? $mod->deletioninprogress : 0;
        $this->downloadcontent = $mod->downloadcontent ?? null;
        $this->lang = $mod->lang ?? null;

        // Note: it saves effort and database space to always include the
        // availability and completion fields, even if availability or completion
        // are actually disabled
        $this->completion = isset($mod->completion) ? $mod->completion : 0;
        $this->completionpassgrade = isset($mod->completionpassgrade) ? $mod->completionpassgrade : 0;
        $this->completiongradeitemnumber = isset($mod->completiongradeitemnumber)
                ? $mod->completiongradeitemnumber : null;
        $this->completionview = isset($mod->completionview)
                ? $mod->completionview : 0;
        $this->completionexpected = isset($mod->completionexpected)
                ? $mod->completionexpected : 0;
        $this->availability = isset($mod->availability) ? $mod->availability : null;
        $this->conditionscompletion = isset($mod->conditionscompletion)
                ? $mod->conditionscompletion : array();
        $this->conditionsgrade = isset($mod->conditionsgrade)
                ? $mod->conditionsgrade : array();
        $this->conditionsfield = isset($mod->conditionsfield)
                ? $mod->conditionsfield : array();

        static $modviews = array();
        if (!isset($modviews[$this->modname])) {
            $modviews[$this->modname] = !plugin_supports('mod', $this->modname,
                    FEATURE_NO_VIEW_LINK);
        }
        $this->url = $modviews[$this->modname]
                ? new moodle_url('/mod/' . $this->modname . '/view.php', array('id'=>$this->id))
                : null;
    }

    /**
     * Creates a cm_info object from a database record (also accepts cm_info
     * in which case it is just returned unchanged).
     *
     * @param stdClass|cm_info|null|bool $cm Stdclass or cm_info (or null or false)
     * @param int $userid Optional userid (default to current)
     * @return cm_info|null Object as cm_info, or null if input was null/false
     */
    public static function create($cm, $userid = 0) {
        // Null, false, etc. gets passed through as null.
        if (!$cm) {
            return null;
        }
        // If it is already a cm_info object, just return it.
        if ($cm instanceof cm_info) {
            return $cm;
        }
        // Otherwise load modinfo.
        if (empty($cm->id) || empty($cm->course)) {
            throw new coding_exception('$cm must contain ->id and ->course');
        }
        $modinfo = get_fast_modinfo($cm->course, $userid);
        return $modinfo->get_cm($cm->id);
    }

    /**
     * If dynamic data for this course-module is not yet available, gets it.
     *
     * This function is automatically called when requesting any course_modinfo property
     * that can be modified by modules (have a set_xxx method).
     *
     * Dynamic data is data which does not come directly from the cache but is calculated at
     * runtime based on the current user. Primarily this concerns whether the user can access
     * the module or not.
     *
     * As part of this function, the module's _cm_info_dynamic function from its lib.php will
     * be called (if it exists). Make sure that the functions that are called here do not use
     * any getter magic method from cm_info.
     * @return void
     */
    private function obtain_dynamic_data() {
        global $CFG;
        $userid = $this->modinfo->get_user_id();
        if ($this->state >= self::STATE_BUILDING_DYNAMIC || $userid == -1) {
            return;
        }
        $this->state = self::STATE_BUILDING_DYNAMIC;

        if (!empty($CFG->enableavailability)) {
            // Get availability information.
            $ci = new \core_availability\info_module($this);

            // Note that the modinfo currently available only includes minimal details (basic data)
            // but we know that this function does not need anything more than basic data.
            $this->available = $ci->is_available($this->availableinfo, true,
                    $userid, $this->modinfo);
        } else {
            $this->available = true;
        }

        // Check parent section.
        if ($this->available) {
            $parentsection = $this->modinfo->get_section_info($this->sectionnum);
            if (!$parentsection->get_available()) {
                // Do not store info from section here, as that is already
                // presented from the section (if appropriate) - just change
                // the flag
                $this->available = false;
            }
        }

        // Update visible state for current user.
        $this->update_user_visible();

        // Let module make dynamic changes at this point
        $this->call_mod_function('cm_info_dynamic');
        $this->state = self::STATE_DYNAMIC;
    }

    /**
     * Getter method for property $uservisible, ensures that dynamic data is retrieved.
     *
     * This method is normally called by the property ->uservisible, but can be called directly if
     * there is a case when it might be called recursively (you can't call property values
     * recursively).
     *
     * @return bool
     */
    public function get_user_visible() {
        $this->obtain_dynamic_data();
        return $this->uservisible;
    }

    /**
     * Returns whether this module is visible to the current user on course page
     *
     * Activity may be visible on the course page but not available, for example
     * when it is hidden conditionally but the condition information is displayed.
     *
     * @return bool
     */
    public function is_visible_on_course_page() {
        $this->obtain_dynamic_data();
        return $this->uservisibleoncoursepage;
    }

    /**
     * Use this method if you want to check if the plugin overrides any visibility checks to block rendering to the display.
     *
     * @return bool
     */
    public function is_of_type_that_can_display(): bool {
        return course_modinfo::is_mod_type_visible_on_course($this->modname);
    }

    /**
     * Whether this module is available but hidden from course page
     *
     * "Stealth" modules are the ones that are not shown on course page but available by following url.
     * They are normally also displayed in grade reports and other reports.
     * Module will be stealth either if visibleoncoursepage=0 or it is a visible module inside the hidden
     * section.
     *
     * @return bool
     */
    public function is_stealth() {
        return !$this->visibleoncoursepage ||
            ($this->visible && ($section = $this->get_section_info()) && !$section->visible);
    }

    /**
     * Getter method for property $available, ensures that dynamic data is retrieved
     * @return bool
     */
    private function get_available() {
        $this->obtain_dynamic_data();
        return $this->available;
    }

    /**
     * Getter method for property $availableinfo, ensures that dynamic data is retrieved
     *
     * @return string Available info (HTML)
     */
    private function get_available_info() {
        $this->obtain_dynamic_data();
        return $this->availableinfo;
    }

    /**
     * Works out whether activity is available to the current user
     *
     * If the activity is unavailable, additional checks are required to determine if its hidden or greyed out
     *
     * @return void
     */
    private function update_user_visible() {
        $userid = $this->modinfo->get_user_id();
        if ($userid == -1) {
            return null;
        }
        $this->uservisible = true;

        // If the module is being deleted, set the uservisible state to false and return.
        if ($this->deletioninprogress) {
            $this->uservisible = false;
            return null;
        }

        // If the user cannot access the activity set the uservisible flag to false.
        // Additional checks are required to determine whether the activity is entirely hidden or just greyed out.
        if ((!$this->visible && !has_capability('moodle/course:viewhiddenactivities', $this->get_context(), $userid)) ||
                (!$this->get_available() &&
                !has_capability('moodle/course:ignoreavailabilityrestrictions', $this->get_context(), $userid))) {

            $this->uservisible = false;
        }

        // Check group membership.
        if ($this->is_user_access_restricted_by_capability()) {

             $this->uservisible = false;
            // Ensure activity is completely hidden from the user.
            $this->availableinfo = '';
        }

        $capabilities = [
            'moodle/course:manageactivities',
            'moodle/course:activityvisibility',
            'moodle/course:viewhiddenactivities',
        ];
        $this->uservisibleoncoursepage = $this->uservisible &&
            ($this->visibleoncoursepage || has_any_capability($capabilities, $this->get_context(), $userid));
        // Activity that is not available, not hidden from course page and has availability
        // info is actually visible on the course page (with availability info and without a link).
        if (!$this->uservisible && $this->visibleoncoursepage && $this->availableinfo) {
            $this->uservisibleoncoursepage = true;
        }
    }

    /**
     * Checks whether mod/...:view capability restricts the current user's access.
     *
     * @return bool True if the user access is restricted.
     */
    public function is_user_access_restricted_by_capability() {
        $userid = $this->modinfo->get_user_id();
        if ($userid == -1) {
            return null;
        }
        $capability = 'mod/' . $this->modname . ':view';
        $capabilityinfo = get_capability_info($capability);
        if (!$capabilityinfo) {
            // Capability does not exist, no one is prevented from seeing the activity.
            return false;
        }

        // You are blocked if you don't have the capability.
        return !has_capability($capability, $this->get_context(), $userid);
    }

    /**
     * Calls a module function (if exists), passing in one parameter: this object.
     * @param string $type Name of function e.g. if this is 'grooblezorb' and the modname is
     *   'forum' then it will try to call 'mod_forum_grooblezorb' or 'forum_grooblezorb'
     * @return void
     */
    private function call_mod_function($type) {
        global $CFG;
        $libfile = $CFG->dirroot . '/mod/' . $this->modname . '/lib.php';
        if (file_exists($libfile)) {
            include_once($libfile);
            $function = 'mod_' . $this->modname . '_' . $type;
            if (function_exists($function)) {
                $function($this);
            } else {
                $function = $this->modname . '_' . $type;
                if (function_exists($function)) {
                    $function($this);
                }
            }
        }
    }

    /**
     * If view data for this course-module is not yet available, obtains it.
     *
     * This function is automatically called if any of the functions (marked) which require
     * view data are called.
     *
     * View data is data which is needed only for displaying the course main page (& any similar
     * functionality on other pages) but is not needed in general. Obtaining view data may have
     * a performance cost.
     *
     * As part of this function, the module's _cm_info_view function from its lib.php will
     * be called (if it exists).
     * @return void
     */
    private function obtain_view_data() {
        if ($this->state >= self::STATE_BUILDING_VIEW || $this->modinfo->get_user_id() == -1) {
            return;
        }
        $this->obtain_dynamic_data();
        $this->state = self::STATE_BUILDING_VIEW;

        // Let module make changes at this point
        $this->call_mod_function('cm_info_view');
        $this->state = self::STATE_VIEW;
    }
}


/**
 * Returns reference to full info about modules in course (including visibility).
 * Cached and as fast as possible (0 or 1 db query).
 *
 * use get_fast_modinfo($courseid, 0, true) to reset the static cache for particular course
 * use get_fast_modinfo(0, 0, true) to reset the static cache for all courses
 *
 * use rebuild_course_cache($courseid, true) to reset the application AND static cache
 * for particular course when it's contents has changed
 *
 * @param int|stdClass $courseorid object from DB table 'course' (must have field 'id'
 *     and recommended to have field 'cacherev') or just a course id. Just course id
 *     is enough when calling get_fast_modinfo() for current course or site or when
 *     calling for any other course for the second time.
 * @param int $userid User id to populate 'availble' and 'uservisible' attributes of modules and sections.
 *     Set to 0 for current user (default). Set to -1 to avoid calculation of dynamic user-depended data.
 * @param bool $resetonly whether we want to get modinfo or just reset the cache
 * @return course_modinfo|null Module information for course, or null if resetting
 * @throws moodle_exception when course is not found (nothing is thrown if resetting)
 */
function get_fast_modinfo($courseorid, $userid = 0, $resetonly = false) {
    // compartibility with syntax prior to 2.4:
    if ($courseorid === 'reset') {
        debugging("Using the string 'reset' as the first argument of get_fast_modinfo() is deprecated. Use get_fast_modinfo(0,0,true) instead.", DEBUG_DEVELOPER);
        $courseorid = 0;
        $resetonly = true;
    }

    // Function get_fast_modinfo() can never be called during upgrade unless it is used for clearing cache only.
    if (!$resetonly) {
        upgrade_ensure_not_running();
    }

    // Function is called with $reset = true
    if ($resetonly) {
        course_modinfo::clear_instance_cache($courseorid);
        return null;
    }

    // Function is called with $reset = false, retrieve modinfo
    return course_modinfo::instance($courseorid, $userid);
}

/**
 * Efficiently retrieves the $course (stdclass) and $cm (cm_info) objects, given
 * a cmid. If module name is also provided, it will ensure the cm is of that type.
 *
 * Usage:
 * list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'forum');
 *
 * Using this method has a performance advantage because it works by loading
 * modinfo for the course - which will then be cached and it is needed later
 * in most requests. It also guarantees that the $cm object is a cm_info and
 * not a stdclass.
 *
 * The $course object can be supplied if already known and will speed
 * up this function - although it is more efficient to use this function to
 * get the course if you are starting from a cmid.
 *
 * To avoid security problems and obscure bugs, you should always specify
 * $modulename if the cmid value came from user input.
 *
 * By default this obtains information (for example, whether user can access
 * the activity) for current user, but you can specify a userid if required.
 *
 * @param stdClass|int $cmorid Id of course-module, or database object
 * @param string $modulename Optional modulename (improves security)
 * @param stdClass|int $courseorid Optional course object if already loaded
 * @param int $userid Optional userid (default = current)
 * @return array Array with 2 elements $course and $cm
 * @throws moodle_exception If the item doesn't exist or is of wrong module name
 */
function get_course_and_cm_from_cmid($cmorid, $modulename = '', $courseorid = 0, $userid = 0) {
    global $DB;
    if (is_object($cmorid)) {
        $cmid = $cmorid->id;
        if (isset($cmorid->course)) {
            $courseid = (int)$cmorid->course;
        } else {
            $courseid = 0;
        }
    } else {
        $cmid = (int)$cmorid;
        $courseid = 0;
    }

    // Validate module name if supplied.
    if ($modulename && !core_component::is_valid_plugin_name('mod', $modulename)) {
        throw new coding_exception('Invalid modulename parameter');
    }

    // Get course from last parameter if supplied.
    $course = null;
    if (is_object($courseorid)) {
        $course = $courseorid;
    } else if ($courseorid) {
        $courseid = (int)$courseorid;
    }

    if (!$course) {
        if ($courseid) {
            // If course ID is known, get it using normal function.
            $course = get_course($courseid);
        } else {
            // Get course record in a single query based on cmid.
            $course = $DB->get_record_sql("
                    SELECT c.*
                      FROM {course_modules} cm
                      JOIN {course} c ON c.id = cm.course
                     WHERE cm.id = ?", array($cmid), MUST_EXIST);
        }
    }

    // Get cm from get_fast_modinfo.
    $modinfo = get_fast_modinfo($course, $userid);
    $cm = $modinfo->get_cm($cmid);
    if ($modulename && $cm->modname !== $modulename) {
        throw new moodle_exception('invalidcoursemoduleid', 'error', '', $cmid);
    }
    return array($course, $cm);
}

/**
 * Efficiently retrieves the $course (stdclass) and $cm (cm_info) objects, given
 * an instance id or record and module name.
 *
 * Usage:
 * list($course, $cm) = get_course_and_cm_from_instance($forum, 'forum');
 *
 * Using this method has a performance advantage because it works by loading
 * modinfo for the course - which will then be cached and it is needed later
 * in most requests. It also guarantees that the $cm object is a cm_info and
 * not a stdclass.
 *
 * The $course object can be supplied if already known and will speed
 * up this function - although it is more efficient to use this function to
 * get the course if you are starting from an instance id.
 *
 * By default this obtains information (for example, whether user can access
 * the activity) for current user, but you can specify a userid if required.
 *
 * @param stdclass|int $instanceorid Id of module instance, or database object
 * @param string $modulename Modulename (required)
 * @param stdClass|int $courseorid Optional course object if already loaded
 * @param int $userid Optional userid (default = current)
 * @return array Array with 2 elements $course and $cm
 * @throws moodle_exception If the item doesn't exist or is of wrong module name
 */
function get_course_and_cm_from_instance($instanceorid, $modulename, $courseorid = 0, $userid = 0) {
    global $DB;

    // Get data from parameter.
    if (is_object($instanceorid)) {
        $instanceid = $instanceorid->id;
        if (isset($instanceorid->course)) {
            $courseid = (int)$instanceorid->course;
        } else {
            $courseid = 0;
        }
    } else {
        $instanceid = (int)$instanceorid;
        $courseid = 0;
    }

    // Get course from last parameter if supplied.
    $course = null;
    if (is_object($courseorid)) {
        $course = $courseorid;
    } else if ($courseorid) {
        $courseid = (int)$courseorid;
    }

    // Validate module name if supplied.
    if (!core_component::is_valid_plugin_name('mod', $modulename)) {
        throw new coding_exception('Invalid modulename parameter');
    }

    if (!$course) {
        if ($courseid) {
            // If course ID is known, get it using normal function.
            $course = get_course($courseid);
        } else {
            // Get course record in a single query based on instance id.
            $pagetable = '{' . $modulename . '}';
            $course = $DB->get_record_sql("
                    SELECT c.*
                      FROM $pagetable instance
                      JOIN {course} c ON c.id = instance.course
                     WHERE instance.id = ?", array($instanceid), MUST_EXIST);
        }
    }

    // Get cm from get_fast_modinfo.
    $modinfo = get_fast_modinfo($course, $userid);
    $instances = $modinfo->get_instances_of($modulename);
    if (!array_key_exists($instanceid, $instances)) {
        throw new moodle_exception('invalidmoduleid', 'error', '', $instanceid);
    }
    return array($course, $instances[$instanceid]);
}


/**
 * Rebuilds or resets the cached list of course activities stored in MUC.
 *
 * rebuild_course_cache() must NEVER be called from lib/db/upgrade.php.
 * At the same time course cache may ONLY be cleared using this function in
 * upgrade scripts of plugins.
 *
 * During the bulk operations if it is necessary to reset cache of multiple
 * courses it is enough to call {@link increment_revision_number()} for the
 * table 'course' and field 'cacherev' specifying affected courses in select.
 *
 * Cached course information is stored in MUC core/coursemodinfo and is
 * validated with the DB field {course}.cacherev
 *
 * @global moodle_database $DB
 * @param int $courseid id of course to rebuild, empty means all
 * @param boolean $clearonly only clear the cache, gets rebuild automatically on the fly.
 *     Recommended to set to true to avoid unnecessary multiple rebuilding.
 * @param boolean $partialrebuild will not delete the whole cache when it's true.
 *     use purge_module_cache() or purge_section_cache() must be
 *         called before when partialrebuild is true.
 *     use purge_module_cache() to invalidate mod cache.
 *     use purge_section_cache() to invalidate section cache.
 *
 * @return void
 * @throws coding_exception
 */
function rebuild_course_cache(int $courseid = 0, bool $clearonly = false, bool $partialrebuild = false): void {
    global $COURSE, $SITE, $DB;

    if ($courseid == 0 and $partialrebuild) {
        throw new coding_exception('partialrebuild only works when a valid course id is provided.');
    }

    // Function rebuild_course_cache() can not be called during upgrade unless it's clear only.
    if (!$clearonly && !upgrade_ensure_not_running(true)) {
        $clearonly = true;
    }

    // Destroy navigation caches
    navigation_cache::destroy_volatile_caches();

    core_courseformat\base::reset_course_cache($courseid);

    $cachecoursemodinfo = cache::make('core', 'coursemodinfo');
    if (empty($courseid)) {
        // Clearing caches for all courses.
        increment_revision_number('course', 'cacherev', '');
        if (!$partialrebuild) {
            $cachecoursemodinfo->purge();
        }
        // Clear memory static cache.
        course_modinfo::clear_instance_cache();
        // Update global values too.
        $sitecacherev = $DB->get_field('course', 'cacherev', array('id' => SITEID));
        $SITE->cachrev = $sitecacherev;
        if ($COURSE->id == SITEID) {
            $COURSE->cacherev = $sitecacherev;
        } else {
            $COURSE->cacherev = $DB->get_field('course', 'cacherev', array('id' => $COURSE->id));
        }
    } else {
        // Clearing cache for one course, make sure it is deleted from user request cache as well.
        // Because this is a versioned cache, there is no need to actually delete the cache item,
        // only increase the required version number.
        increment_revision_number('course', 'cacherev', 'id = :id', array('id' => $courseid));
        $cacherev = $DB->get_field('course', 'cacherev', ['id' => $courseid]);
        // Clear memory static cache.
        course_modinfo::clear_instance_cache($courseid, $cacherev);
        // Update global values too.
        if ($courseid == $COURSE->id || $courseid == $SITE->id) {
            if ($courseid == $COURSE->id) {
                $COURSE->cacherev = $cacherev;
            }
            if ($courseid == $SITE->id) {
                $SITE->cacherev = $cacherev;
            }
        }
    }

    if ($clearonly) {
        return;
    }

    if ($courseid) {
        $select = array('id'=>$courseid);
    } else {
        $select = array();
        core_php_time_limit::raise();  // this could take a while!   MDL-10954
    }

    $fields = 'id,' . join(',', course_modinfo::$cachedfields);
    $sort = '';
    $rs = $DB->get_recordset("course", $select, $sort, $fields);

    // Rebuild cache for each course.
    foreach ($rs as $course) {
        course_modinfo::build_course_cache($course, $partialrebuild);
    }
    $rs->close();
}


/**
 * Class that is the return value for the _get_coursemodule_info module API function.
 *
 * Note: For backward compatibility, you can also return a stdclass object from that function.
 * The difference is that the stdclass object may contain an 'extra' field (deprecated,
 * use extraclasses and onclick instead). The stdclass object may not contain
 * the new fields defined here (content, extraclasses, customdata).
 */
class cached_cm_info {
    /**
     * Name (text of link) for this activity; Leave unset to accept default name
     * @var string
     */
    public $name;

    /**
     * Name of icon for this activity. Normally, this should be used together with $iconcomponent
     * to define the icon, as per image_url function.
     * For backward compatibility, if this value is of the form 'mod/forum/icon' then an icon
     * within that module will be used.
     * @see cm_info::get_icon_url()
     * @see renderer_base::image_url()
     * @var string
     */
    public $icon;

    /**
     * Component for icon for this activity, as per image_url; leave blank to use default 'moodle'
     * component
     * @see renderer_base::image_url()
     * @var string
     */
    public $iconcomponent;

    /**
     * HTML content to be displayed on the main page below the link (if any) for this course-module
     * @var string
     */
    public $content;

    /**
     * Custom data to be stored in modinfo for this activity; useful if there are cases when
     * internal information for this activity type needs to be accessible from elsewhere on the
     * course without making database queries. May be of any type but should be short.
     * @var mixed
     */
    public $customdata;

    /**
     * Extra CSS class or classes to be added when this activity is displayed on the main page;
     * space-separated string
     * @var string
     */
    public $extraclasses;

    /**
     * External URL image to be used by activity as icon, useful for some external-tool modules
     * like lti. If set, takes precedence over $icon and $iconcomponent
     * @var $moodle_url
     */
    public $iconurl;

    /**
     * Content of onclick JavaScript; escaped HTML to be inserted as attribute value
     * @var string
     */
    public $onclick;
}


/**
 * Data about a single section on a course. This contains the fields from the
 * course_sections table, plus additional data when required.
 *
 * @property-read int $id Section ID - from course_sections table
 * @property-read int $course Course ID - from course_sections table
 * @property-read int $sectionnum Section number - from course_sections table
 * @property-read string $name Section name if specified - from course_sections table
 * @property-read int $visible Section visibility (1 = visible) - from course_sections table
 * @property-read string $summary Section summary text if specified - from course_sections table
 * @property-read int $summaryformat Section summary text format (FORMAT_xx constant) - from course_sections table
 * @property-read string $availability Availability information as JSON string - from course_sections table
 * @property-read string|null $component Optional section delegate component - from course_sections table
 * @property-read int|null $itemid Optional section delegate item id - from course_sections table
 * @property-read array $conditionscompletion Availability conditions for this section based on the completion of
 *    course-modules (array from course-module id to required completion state
 *    for that module) - from cached data in sectioncache field
 * @property-read array $conditionsgrade Availability conditions for this section based on course grades (array from
 *    grade item id to object with ->min, ->max fields) - from cached data in
 *    sectioncache field
 * @property-read array $conditionsfield Availability conditions for this section based on user fields
 * @property-read bool $available True if this section is available to the given user i.e. if all availability conditions
 *    are met - obtained dynamically
 * @property-read string $availableinfo If section is not available to some users, this string gives information about
 *    availability which can be displayed to students and/or staff (e.g. 'Available from 3 January 2010')
 *    for display on main page - obtained dynamically
 * @property-read bool $uservisible True if this section is available to the given user (for example, if current user
 *    has viewhiddensections capability, they can access the section even if it is not
 *    visible or not available, so this would be true in that case) - obtained dynamically
 * @property-read string $sequence Comma-separated list of all modules in the section. Note, this field may not exactly
 *    match course_sections.sequence if later has references to non-existing modules or not modules of not available module types.
 * @property-read course_modinfo $modinfo
 */
class section_info implements IteratorAggregate {
    /**
     * Section ID - from course_sections table
     * @var int
     */
    private $_id;

    /**
     * Section number - from course_sections table
     * @var int
     */
    private $_sectionnum;

    /**
     * Section name if specified - from course_sections table
     * @var string
     */
    private $_name;

    /**
     * Section visibility (1 = visible) - from course_sections table
     * @var int
     */
    private $_visible;

    /**
     * Section summary text if specified - from course_sections table
     * @var string
     */
    private $_summary;

    /**
     * Section summary text format (FORMAT_xx constant) - from course_sections table
     * @var int
     */
    private $_summaryformat;

    /**
     * Availability information as JSON string - from course_sections table
     * @var string
     */
    private $_availability;

    /**
     * @var string|null the delegated component if any.
     */
    private ?string $_component = null;

    /**
     * @var int|null the delegated instance item id if any.
     */
    private ?int $_itemid = null;

    /**
     * @var sectiondelegate|null Section delegate instance if any.
     */
    private ?sectiondelegate $_delegateinstance = null;

    /** @var cm_info[]|null Section cm_info activities, null when it is not loaded yet. */
    private array|null $_sequencecminfos = null;

    /**
     * @var bool|null $_isorphan True if the section is orphan for some reason.
     */
    private $_isorphan = null;

    /**
     * Availability conditions for this section based on the completion of
     * course-modules (array from course-module id to required completion state
     * for that module) - from cached data in sectioncache field
     * @var array
     */
    private $_conditionscompletion;

    /**
     * Availability conditions for this section based on course grades (array from
     * grade item id to object with ->min, ->max fields) - from cached data in
     * sectioncache field
     * @var array
     */
    private $_conditionsgrade;

    /**
     * Availability conditions for this section based on user fields
     * @var array
     */
    private $_conditionsfield;

    /**
     * True if this section is available to students i.e. if all availability conditions
     * are met - obtained dynamically on request, see function {@link section_info::get_available()}
     * @var bool|null
     */
    private $_available;

    /**
     * If section is not available to some users, this string gives information about
     * availability which can be displayed to students and/or staff (e.g. 'Available from 3
     * January 2010') for display on main page - obtained dynamically on request, see
     * function {@link section_info::get_availableinfo()}
     * @var string
     */
    private $_availableinfo;

    /**
     * True if this section is available to the CURRENT user (for example, if current user
     * has viewhiddensections capability, they can access the section even if it is not
     * visible or not available, so this would be true in that case) - obtained dynamically
     * on request, see function {@link section_info::get_uservisible()}
     * @var bool|null
     */
    private $_uservisible;

    /**
     * Default values for sectioncache fields; if a field has this value, it won't
     * be stored in the sectioncache cache, to save space. Checks are done by ===
     * which means values must all be strings.
     * @var array
     */
    private static $sectioncachedefaults = array(
        'name' => null,
        'summary' => '',
        'summaryformat' => '1', // FORMAT_HTML, but must be a string
        'visible' => '1',
        'availability' => null,
        'component' => null,
        'itemid' => null,
    );

    /**
     * Stores format options that have been cached when building 'coursecache'
     * When the format option is requested we look first if it has been cached
     * @var array
     */
    private $cachedformatoptions = array();

    /**
     * Stores the list of all possible section options defined in each used course format.
     * @var array
     */
    static private $sectionformatoptions = array();

    /**
     * Stores the modinfo object passed in constructor, may be used when requesting
     * dynamically obtained attributes such as available, availableinfo, uservisible.
     * Also used to retrun information about current course or user.
     * @var course_modinfo
     */
    private $modinfo;

    /**
     * True if has activities, otherwise false.
     * @var bool
     */
    public $hasactivites;

    /**
     * List of class read-only properties' getter methods.
     * Used by magic functions __get(), __isset(), __empty()
     * @var array
     */
    private static $standardproperties = [
        'section' => 'get_section_number',
    ];

    /**
     * Constructs object from database information plus extra required data.
     * @param object $data Array entry from cached sectioncache
     * @param int $number Section number (array key)
     * @param mixed $notused1 argument not used (informaion is available in $modinfo)
     * @param mixed $notused2 argument not used (informaion is available in $modinfo)
     * @param course_modinfo $modinfo Owner (needed for checking availability)
     * @param mixed $notused3 argument not used (informaion is available in $modinfo)
     */
    public function __construct($data, $number, $notused1, $notused2, $modinfo, $notused3) {
        global $CFG;
        require_once($CFG->dirroot.'/course/lib.php');

        // Data that is always present
        $this->_id = $data->id;

        $defaults = self::$sectioncachedefaults +
                array('conditionscompletion' => array(),
                    'conditionsgrade' => array(),
                    'conditionsfield' => array());

        // Data that may use default values to save cache size
        foreach ($defaults as $field => $value) {
            if (isset($data->{$field})) {
                $this->{'_'.$field} = $data->{$field};
            } else {
                $this->{'_'.$field} = $value;
            }
        }

        // Other data from constructor arguments.
        $this->_sectionnum = $number;
        $this->modinfo = $modinfo;

        // Cached course format data.
        $course = $modinfo->get_course();
        if (!isset(self::$sectionformatoptions[$course->format])) {
            // Store list of section format options defined in each used course format.
            // They do not depend on particular course but only on its format.
            self::$sectionformatoptions[$course->format] =
                    course_get_format($course)->section_format_options();
        }
        foreach (self::$sectionformatoptions[$course->format] as $field => $option) {
            if (!empty($option['cache'])) {
                if (isset($data->{$field})) {
                    $this->cachedformatoptions[$field] = $data->{$field};
                } else if (array_key_exists('cachedefault', $option)) {
                    $this->cachedformatoptions[$field] = $option['cachedefault'];
                }
            }
        }
    }

    /**
     * Magic method to check if the property is set
     *
     * @param string $name name of the property
     * @return bool
     */
    public function __isset($name) {
        if (isset(self::$standardproperties[$name])) {
            $value = $this->__get($name);
            return isset($value);
        }
        if (method_exists($this, 'get_'.$name) ||
                property_exists($this, '_'.$name) ||
                array_key_exists($name, self::$sectionformatoptions[$this->modinfo->get_course()->format])) {
            $value = $this->__get($name);
            return isset($value);
        }
        return false;
    }

    /**
     * Magic method to check if the property is empty
     *
     * @param string $name name of the property
     * @return bool
     */
    public function __empty($name) {
        if (isset(self::$standardproperties[$name])) {
            $value = $this->__get($name);
            return empty($value);
        }
        if (method_exists($this, 'get_'.$name) ||
                property_exists($this, '_'.$name) ||
                array_key_exists($name, self::$sectionformatoptions[$this->modinfo->get_course()->format])) {
            $value = $this->__get($name);
            return empty($value);
        }
        return true;
    }

    /**
     * Magic method to retrieve the property, this is either basic section property
     * or availability information or additional properties added by course format
     *
     * @param string $name name of the property
     * @return mixed
     */
    public function __get($name) {
        if (isset(self::$standardproperties[$name])) {
            if ($method = self::$standardproperties[$name]) {
                return $this->$method();
            }
        }
        if (method_exists($this, 'get_'.$name)) {
            return $this->{'get_'.$name}();
        }
        if (property_exists($this, '_'.$name)) {
            return $this->{'_'.$name};
        }
        if (array_key_exists($name, $this->cachedformatoptions)) {
            return $this->cachedformatoptions[$name];
        }
        // precheck if the option is defined in format to avoid unnecessary DB queries in get_format_options()
        if (array_key_exists($name, self::$sectionformatoptions[$this->modinfo->get_course()->format])) {
            $formatoptions = course_get_format($this->modinfo->get_course())->get_format_options($this);
            return $formatoptions[$name];
        }
        debugging('Invalid section_info property accessed! '.$name);
        return null;
    }

    /**
     * Finds whether this section is available at the moment for the current user.
     *
     * The value can be accessed publicly as $sectioninfo->available, but can be called directly if there
     * is a case when it might be called recursively (you can't call property values recursively).
     *
     * @return bool
     */
    public function get_available() {
        global $CFG;
        $userid = $this->modinfo->get_user_id();
        if ($this->_available !== null || $userid == -1) {
            // Has already been calculated or does not need calculation.
            return $this->_available;
        }
        $this->_available = true;
        $this->_availableinfo = '';
        if (!empty($CFG->enableavailability)) {
            // Get availability information.
            $ci = new \core_availability\info_section($this);
            $this->_available = $ci->is_available($this->_availableinfo, true,
                    $userid, $this->modinfo);
        }

        if ($this->_available) {
            $this->_available = $this->check_delegated_available();
        }
        // Execute the hook from the course format that may override the available/availableinfo properties.
        $currentavailable = $this->_available;
        course_get_format($this->modinfo->get_course())->
            section_get_available_hook($this, $this->_available, $this->_availableinfo);
        if (!$currentavailable && $this->_available) {
            debugging('section_get_available_hook() can not make unavailable section available', DEBUG_DEVELOPER);
            $this->_available = $currentavailable;
        }
        return $this->_available;
    }

    /**
     * Check if the delegated component is available.
     *
     * @return bool
     */
    private function check_delegated_available(): bool {
        /** @var sectiondelegatemodule $sectiondelegate */
        $sectiondelegate = $this->get_component_instance();
        if (!$sectiondelegate) {
            return true;
        }

        if ($sectiondelegate instanceof sectiondelegatemodule) {
            $parentcm = $sectiondelegate->get_cm();
            if (!$parentcm->available) {
                return false;
            }
            return $parentcm->get_section_info()->available;
        }

        return true;
    }

    /**
     * Returns the availability text shown next to the section on course page.
     *
     * @return string
     */
    private function get_availableinfo() {
        // Calling get_available() will also fill the availableinfo property
        // (or leave it null if there is no userid).
        $this->get_available();
        return $this->_availableinfo;
    }

    /**
     * Implementation of IteratorAggregate::getIterator(), allows to cycle through properties
     * and use {@link convert_to_array()}
     *
     * @return ArrayIterator
     */
    public function getIterator(): Traversable {
        $ret = array();
        foreach (get_object_vars($this) as $key => $value) {
            if (substr($key, 0, 1) == '_') {
                if (method_exists($this, 'get'.$key)) {
                    $ret[substr($key, 1)] = $this->{'get'.$key}();
                } else {
                    $ret[substr($key, 1)] = $this->$key;
                }
            }
        }
        $ret['sequence'] = $this->get_sequence();
        $ret['course'] = $this->get_course();
        $ret = array_merge($ret, course_get_format($this->modinfo->get_course())->get_format_options($this));
        return new ArrayIterator($ret);
    }

    /**
     * Works out whether activity is visible *for current user* - if this is false, they
     * aren't allowed to access it.
     *
     * @return bool
     */
    private function get_uservisible() {
        $userid = $this->modinfo->get_user_id();
        if ($this->_uservisible !== null || $userid == -1) {
            // Has already been calculated or does not need calculation.
            return $this->_uservisible;
        }

        if (!$this->check_delegated_uservisible()) {
            $this->_uservisible = false;
            return $this->_uservisible;
        }

        $this->_uservisible = true;
        if ($this->is_orphan() || !$this->_visible || !$this->get_available()) {
            $coursecontext = context_course::instance($this->get_course());
            if (
                ($this->_isorphan || !$this->_visible)
                && !has_capability('moodle/course:viewhiddensections', $coursecontext, $userid)
            ) {
                $this->_uservisible = false;
            }
            if (
                $this->_uservisible
                && !$this->get_available()
                && !has_capability('moodle/course:ignoreavailabilityrestrictions', $coursecontext, $userid)
            ) {
                $this->_uservisible = false;
            }
        }
        return $this->_uservisible;
    }

    /**
     * Check if the delegated component is user visible.
     *
     * @return bool
     */
    private function check_delegated_uservisible(): bool {
        /** @var sectiondelegatemodule $sectiondelegate */
        $sectiondelegate = $this->get_component_instance();
        if (!$sectiondelegate) {
            return true;
        }

        if ($sectiondelegate instanceof sectiondelegatemodule) {
            $parentcm = $sectiondelegate->get_cm();
            if (!$parentcm->uservisible) {
                return false;
            }
            $result = $parentcm->get_section_info()->uservisible;
            return $result;
        }

        return true;
    }

    /**
     * Restores the course_sections.sequence value
     *
     * @return string
     */
    private function get_sequence() {
        if (!empty($this->modinfo->sections[$this->_sectionnum])) {
            return implode(',', $this->modinfo->sections[$this->_sectionnum]);
        } else {
            return '';
        }
    }

    /**
     * Returns the course modules in this section.
     *
     * @return cm_info[]
     */
    public function get_sequence_cm_infos(): array {
        if ($this->_sequencecminfos !== null) {
            return $this->_sequencecminfos;
        }
        $sequence = $this->modinfo->sections[$this->_sectionnum] ?? [];
        $cms = $this->modinfo->get_cms();
        $result = [];
        foreach ($sequence as $cmid) {
            if (isset($cms[$cmid])) {
                $result[] = $cms[$cmid];
            }
        }
        $this->_sequencecminfos = $result;
        return $result;
    }

    /**
     * Returns course ID - from course_sections table
     *
     * @return int
     */
    private function get_course() {
        return $this->modinfo->get_course_id();
    }

    /**
     * Modinfo object
     *
     * @return course_modinfo
     */
    private function get_modinfo() {
        return $this->modinfo;
    }

    /**
     * Returns section number.
     *
     * This method is called by the property ->section.
     *
     * @return int
     */
    private function get_section_number(): int {
        return $this->sectionnum;
    }

    /**
     * Get the delegate component instance.
     */
    public function get_component_instance(): ?sectiondelegate {
        if (!$this->is_delegated()) {
            return null;
        }
        if ($this->_delegateinstance !== null) {
            return $this->_delegateinstance;
        }
        $this->_delegateinstance = sectiondelegate::instance($this);
        return $this->_delegateinstance;
    }

    /**
     * Returns true if this section is a delegate to a component.
     * @return bool
     */
    public function is_delegated(): bool {
        return !empty($this->_component);
    }

    /**
     * Returns true if this section is orphan.
     *
     * @return bool
     */
    public function is_orphan(): bool {
        if ($this->_isorphan !== null) {
            return $this->_isorphan;
        }

        $courseformat = course_get_format($this->modinfo->get_course());
        // There are some cases where a restored course using third-party formats can
        // have orphaned sections due to a fixed section number.
        if ($this->_sectionnum > $courseformat->get_last_section_number()) {
            $this->_isorphan = true;
            return $this->_isorphan;
        }
        // Some delegated sections can belong to a plugin that is disabled or not present.
        if ($this->is_delegated() && !$this->get_component_instance()) {
            $this->_isorphan = true;
            return $this->_isorphan;
        }

        $this->_isorphan = false;
        return $this->_isorphan;
    }

    /**
     * Prepares section data for inclusion in sectioncache cache, removing items
     * that are set to defaults, and adding availability data if required.
     *
     * Called by build_section_cache in course_modinfo only; do not use otherwise.
     * @param object $section Raw section data object
     */
    public static function convert_for_section_cache($section) {
        global $CFG;

        // Course id stored in course table
        unset($section->course);
        // Sequence stored implicity in modinfo $sections array
        unset($section->sequence);

        // Remove default data
        foreach (self::$sectioncachedefaults as $field => $value) {
            // Exact compare as strings to avoid problems if some strings are set
            // to "0" etc.
            if (isset($section->{$field}) && $section->{$field} === $value) {
                unset($section->{$field});
            }
        }
    }
}
