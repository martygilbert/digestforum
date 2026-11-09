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

namespace mod_digestforum\reportbuilder\local\entities;

use core\{context, context_helper};
use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{date, select, text};
use core_reportbuilder\local\helpers\{database, format};
use core_reportbuilder\local\report\{column, filter};
use stdClass;

/**
 * Forum entity
 *
 * @package     mod_digestforum
 * @copyright   2025 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digestforum extends base {
    /**
     * Database tables that this entity uses
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'context',
            'digestforum',
        ];
    }

    /**
     * The default title for this entity
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('digestforum', 'mod_digestforum');
    }

    /**
     * Initialise the entity
     *
     * @return base
     */
    public function initialise(): base {
        $columns = $this->get_all_columns();
        foreach ($columns as $column) {
            $this->add_column($column);
        }

        // All the filters defined by the entity can also be used as conditions.
        $filters = $this->get_all_filters();
        foreach ($filters as $filter) {
            $this
                ->add_filter($filter)
                ->add_condition($filter);
        }

        return $this;
    }

    /**
     * Returns list of all available columns
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        [
            'context' => $contextalias,
            'digestforum' => $digestforumalias,
        ] = $this->get_table_aliases();

        // Name.
        $columns[] = (new column(
            'name',
            new lang_string('name'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->add_field("{$digestforumalias}.name")
            ->add_fields(context_helper::get_preload_record_columns_sql($contextalias))
            ->set_is_sortable(true)
            ->set_callback(static function (?string $name, stdClass $digestforum): string {
                if ($name === null || $digestforum->ctxid === null) {
                    return '';
                }

                context_helper::preload_from_record(clone $digestforum);
                $context = context::instance_by_id($digestforum->ctxid);

                return format_string($name, true, ['context' => $context]);
            });

        // Description.
        $columns[] = (new column(
            'description',
            new lang_string('description'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$digestforumalias}.intro, {$digestforumalias}.introformat")
            ->add_fields(context_helper::get_preload_record_columns_sql($contextalias))
            ->set_is_sortable(true)
            ->set_callback(static function (?string $intro, stdClass $digestforum): string {
                if ($intro === null || $digestforum->ctxid === null) {
                    return '';
                }

                context_helper::preload_from_record(clone $digestforum);
                $context = context::instance_by_id($digestforum->ctxid);

                $intro = file_rewrite_pluginfile_urls($intro, 'pluginfile.php', $context->id, 'mod_digestforum', 'intro', null);

                return format_text($intro, $digestforum->introformat, ['context' => $context]);
            });

        // Type.
        $columns[] = (new column(
            'type',
            new lang_string('digestforumtype', 'mod_digestforum'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$digestforumalias}.type")
            ->set_is_sortable(true)
            ->set_callback(static function (?string $type): string {
                global $CFG;
                require_once("{$CFG->dirroot}/mod/digestforum/lib.php");

                $types = digestforum_get_digestforum_types_all();
                if ($type === null || !array_key_exists($type, $types)) {
                    return '';
                }
                return $types[$type];
            });

        // Due date.
        $columns[] = (new column(
            'duedate',
            new lang_string('duedate', 'mod_digestforum'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_fields("{$digestforumalias}.duedate")
            ->set_is_sortable(true)
            ->set_callback([format::class, 'userdate']);

        // Cut-off date.
        $columns[] = (new column(
            'cutoffdate',
            new lang_string('cutoffdate', 'mod_digestforum'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_fields("{$digestforumalias}.cutoffdate")
            ->set_is_sortable(true)
            ->set_callback([format::class, 'userdate']);

        return $columns;
    }

    /**
     * Return list of all available filters
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $digestforumalias = $this->get_table_alias('digestforum');

        // Name.
        $filters[] = (new filter(
            text::class,
            'name',
            new lang_string('name'),
            $this->get_entity_name(),
            "{$digestforumalias}.name",
        ))
            ->add_joins($this->get_joins());

        // Description.
        $filters[] = (new filter(
            text::class,
            'description',
            new lang_string('description'),
            $this->get_entity_name(),
            "{$digestforumalias}.intro",
        ))
            ->add_joins($this->get_joins());

        // Type.
        $filters[] = (new filter(
            select::class,
            'type',
            new lang_string('digestforumtype', 'mod_digestforum'),
            $this->get_entity_name(),
            "{$digestforumalias}.type",
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                global $CFG;
                require_once("{$CFG->dirroot}/mod/digestforum/lib.php");

                return digestforum_get_digestforum_types_all();
            });

        // Due date.
        $filters[] = (new filter(
            date::class,
            'duedate',
            new lang_string('duedate', 'mod_digestforum'),
            $this->get_entity_name(),
            "{$digestforumalias}.duedate",
        ))
            ->add_joins($this->get_joins());

        // Cut-off date.
        $filters[] = (new filter(
            date::class,
            'cutoffdate',
            new lang_string('cutoffdate', 'mod_digestforum'),
            $this->get_entity_name(),
            "{$digestforumalias}.cutoffdate",
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }

    /**
     * Return context joins
     *
     * @return string[]
     */
    public function get_context_joins(): array {
        [
            'context' => $contextalias,
            'digestforum' => $digestforumalias,
        ] = $this->get_table_aliases();

        [$coursemodulealias, $modulealias] = database::generate_aliases(2);

        return [
            "JOIN {course_modules} {$coursemodulealias} ON {$coursemodulealias}.instance = {$digestforumalias}.id",
            "JOIN {modules} {$modulealias} ON {$modulealias}.id = {$coursemodulealias}.module
                    AND {$modulealias}.name = 'digestforum'",
            "JOIN {context} {$contextalias} ON {$contextalias}.contextlevel = " . CONTEXT_MODULE . "
                    AND {$contextalias}.instanceid = {$coursemodulealias}.id",
        ];
    }
}
