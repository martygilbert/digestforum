# mod_digestforum Upgrade notes

## 5.3

### Added

- Added the method `discussion::get_discussion_navigation_buttons()` that returns data for the discussion navigation template.

  For more information see [MDL-88602](https://tracker.moodle.org/browse/MDL-88602)

### Changed

- Add new mod_digestforum_set_read_state web service to allow clients to manually mark individual digestforum posts as read or unread (when manual read tracking is enabled), returning a simple status and warnings structure.

  For more information see [MDL-87887](https://tracker.moodle.org/browse/MDL-87887)
- The view and manage subscribers selector of the `mod_digestforum/digestforum_subscription_action` template is now rendered as a tertiary navigation selector (`core/tertiary_navigation_selector`) using the new `viewandmanageselectmenu` context variable, which holds `core\output\select_menu` data. Theme overrides of this template should be updated to use it.

  For more information see [MDL-89890](https://tracker.moodle.org/browse/MDL-89890)

### Deprecated

- The `viewandmanageselect` context variable of the `mod_digestforum/digestforum_subscription_action` template has been deprecated in favour of `viewandmanageselectmenu`. It is still exported with its `url_select` data, but is no longer used by the core template.

  For more information see [MDL-89890](https://tracker.moodle.org/browse/MDL-89890)

## 5.2

### Deprecated

- The digestforum report entity `->get_context_joins()` method is deprecated, replaced with `->get_course_modules_joins(...)`

  For more information see [MDL-86699](https://tracker.moodle.org/browse/MDL-86699)

### Removed

- The `digestforum_print_discussion_header()` has been removed from `public/mod/digestforum/deprecatedlib.php`.

  For more information see [MDL-87425](https://tracker.moodle.org/browse/MDL-87425)

## 5.1

### Deprecated

- The function digestforum_tp_get_untracked_digestforums() has been deprecated because it is no longer used.

  For more information see [MDL-83893](https://tracker.moodle.org/browse/MDL-83893)
